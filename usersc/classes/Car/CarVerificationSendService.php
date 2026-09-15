<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\AppConstants;
use ElanRegistry\LogCategories;
use ElanRegistry\Owner;

/**
 * CarVerificationSendService - The single shared verification-email send path
 *
 * Owns the whole "pick eligible cars, rotate their verification codes, mail
 * their owners, record the outcome" operation, and is deliberately the ONLY
 * implementation of it. Two callers need this behaviour and must not drift
 * apart: the manual admin tool (app/admin/index.php?tab=verification, #1884)
 * and the automated cron job that follows it (#1885). A second, hand-rolled copy
 * of the eligibility rule or the send sequence in either caller would mean
 * the cron and the admin preview could disagree about which cars are due —
 * the exact failure this class exists to prevent.
 *
 * That shared-ownership requirement constrains the public API: every public
 * method here must remain a pure function of its arguments plus current DB
 * state. No `$_POST`/`$_GET`/`$_SERVER` reads, no session or logged-in-user
 * lookups, no echoing, no redirects, no flash messages — anything specific to
 * one caller's environment belongs in that caller, not here. A cron job runs
 * with no session and no request at all; anything this class reached for
 * beyond its arguments would work under the admin page and fail silently (or
 * fatally) under cron.
 *
 * Eligibility in particular is NOT re-implemented here:
 * {@see self::findEligible()} delegates verbatim to
 * {@see CarRepository::findVerificationEligible()}, which carries the full
 * rule (staleness, bounced/suppressed exclusion, sold cars, erased owners,
 * the attempt cap). Both callers derive their car list from that one query.
 *
 * @package ElanRegistry\Car
 * @since v2.30.3
 * @see https://github.com/elan-registry/registry/issues/1884
 */
final class CarVerificationSendService
{
    public function __construct(
        private CarRepository $repo,
        private CarVerificationManager $verifier,
        private CarVerificationEmailComposer $composer,
    ) {
    }

    /**
     * List cars currently due a verification email
     *
     * A straight delegation to {@see CarRepository::findVerificationEligible()},
     * intentionally adding nothing. This method exists so that callers depend
     * on this service rather than reaching into the repository themselves,
     * keeping one definition of "eligible" shared between the admin preview
     * page and #1885's cron job.
     *
     * @param int $limit Maximum rows to return (values below 1 return no rows)
     * @param int $offset Rows to skip (negative values are treated as 0)
     * @return array<object> Eligible car rows (empty if none)
     * @throws \ElanRegistry\Exceptions\CarDatabaseException If the query fails
     */
    public function findEligible(int $limit, int $offset = 0): array
    {
        return $this->repo->findVerificationEligible($limit, $offset);
    }

    /**
     * Send one car's verification email and report what happened
     *
     * THREE SEPARATE SCOPES, NEVER ONE TRANSACTION. `email()` is a synchronous
     * network call to Brevo that can block for seconds or hang outright. Holding
     * an open MySQL transaction across it would pin row locks on `cars` for the
     * duration of every send in a batch, and a timeout mid-batch would roll back
     * work whose emails had already physically left the building. So the write
     * of the new code, the send, and the bookkeeping are three independent
     * scopes:
     *
     *   A. Rotate `vericode`/`vericode_sent_at` and COMMIT — before any network
     *      call. The code must be durable before the email quoting it is sent;
     *      the reverse order can mail a link that the database never knew about.
     *   B. Compose and send, with no transaction open at all.
     *   C. On success only, record the `er_email_events` row and increment
     *      `verification_attempts`, in their own short transaction.
     *
     * FAILED SENDS DO NOT COUNT. `verification_attempts` is the owner-facing cap
     * on how often we may contact someone in a rolling year, so only a send the
     * mailer confirmed may consume one. This method never calls
     * {@see CarRepository::incrementVerificationAttempts()} on the failure path
     * — a Brevo outage must not burn through every stale car's yearly allowance.
     * The failure path instead restores the previous `vericode`/`vericode_sent_at`
     * in a single atomic `updateCar()` call, so a car that was not emailed is
     * left exactly as it was found and stays eligible for the next run.
     *
     * NEVER REPORT FAILURE AFTER A REAL SEND. Once `email()` has returned true
     * the owner has the message; nothing that happens afterwards can un-send it.
     * Scope C is therefore best-effort: if it throws, the failure is logged
     * loudly as the bookkeeping gap it is (a missing event row, an uncounted
     * attempt) and this method STILL returns {@see SendResult::sent()}. Returning
     * a failure there would tell the admin page — or the cron job — that the car
     * still needs emailing, and the retry would deliver a second copy of the same
     * message to a real person. A stale counter is recoverable; a duplicate email
     * to an owner is not.
     *
     * @param object $carData Eligible car row as returned by {@see self::findEligible()}:
     *                        requires ->id, ->user_id and ->email, plus the display
     *                        fields {@see CarVerificationEmailComposer::compose()} reads.
     *                        Note this method mutates the passed object — the verifier
     *                        writes the new plaintext ->vericode and ->vericode_sent_at
     *                        onto it so the composer can quote them.
     * @return SendResult Sent, sent-but-unrecorded, or failed — each carrying an admin-safe reason string except a clean sent
     */
    public function sendOne(object $carData): SendResult
    {
        // Captured before any write: the failure path in scope B restores these
        // exact values, so they must be read while they are still the stored ones.
        $carId                 = (int) $carData->id;
        $previousVericode      = $carData->vericode ?? null;
        $previousVericodeSentAt = $carData->vericode_sent_at ?? null;

        $owner = (new Owner((int) $carData->user_id))->data();

        if ($owner === null) {
            // Nothing has been written yet, so there is nothing to roll back.
            // The car stays eligible and will be retried by the next run.
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                'CarVerificationSendService::sendOne: owner %d for car %d could not be loaded; send skipped',
                (int) $carData->user_id,
                $carId
            ));

            return SendResult::failed($carId, 'Owner record could not be loaded.');
        }

        // Scope A — durable new code BEFORE the network call.
        $this->repo->beginTransaction();

        try {
            $newVericode = $this->verifier->generateVerificationCode();
            $sentAt      = date(AppConstants::DATETIME_FORMAT);

            $this->verifier->setVerificationCode($carData, $newVericode);
            $this->verifier->setVerificationSentAt($carData, $sentAt);

            $this->repo->commit();
        } catch (\Throwable $e) {
            $this->repo->rollback();

            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                'CarVerificationSendService::sendOne: verification code rotation failed for car %d (%s): %s',
                $carId,
                get_class($e),
                $e->getMessage()
            ));

            return SendResult::failed($carId, 'The verification code could not be generated.');
        }

        // Scope B — compose and send with no transaction open.
        $composed = $this->composer->compose($carData, $owner, $newVericode);
        $sendOk   = email((string) $carData->email, $composed['subject'], $composed['html']);

        // Strict `!== true` rather than `=== false`: email() has no single
        // typed contract across the mailers this app can run with. Today
        // it's bool either way — the currently-active core fallback
        // (users/helpers/helpers.php) returns PHPMailer::send()'s typed
        // bool return directly, and the Brevo override (activated by
        // renaming usersc/plugins/sendinblue/override.RENAME.php into
        // place, not currently active in this checkout) only ever returns
        // true/false from sendinblue(). But a future or site-local mailer
        // override could return something else on failure (a message-id
        // string, null, 0 — a very common convention) that would pass
        // `=== false` and be misreported as a successful send, incrementing
        // the attempt count and consuming the yearly cap with no email
        // actually delivered.
        if ($sendOk !== true) {
            // Restore the pre-send state in one atomic update so the car is left
            // exactly as found. This is the one genuinely unrecoverable spot in
            // the whole sequence: if the restore itself fails, the car is stuck
            // with a rotated code that no email ever quoted, which invalidates
            // any verification link the owner may still be holding from a
            // previous send. Log it loudly enough to be actioned by hand.
            try {
                // restoreVerificationCodeState(), not updateCar(): updateCar()
                // returns true for any UPDATE that executes, even one matching
                // zero rows (e.g. this car was deleted between the rotation
                // above and this restore attempt) — that would make the
                // $restored === false check below unreachable for the exact
                // scenario it exists to catch. restoreVerificationCodeState()
                // confirms the row still exists with a follow-up read, so false
                // means only "the car is gone" — never merely "the write
                // changed no columns," which is routine (restoring NULL over
                // NULL for a car never sent to before).
                $restored = $this->repo->restoreVerificationCodeState(
                    $carId,
                    $previousVericode,
                    $previousVericodeSentAt
                );

                if ($restored === false) {
                    logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                        'CarVerificationSendService::sendOne: CRITICAL - car %d has a rotated vericode but no email '
                        . 'was sent, and the restore of the previous vericode/vericode_sent_at matched no row '
                        . '(the car may have been deleted mid-send). Any verification link the owner already '
                        . 'holds is now invalid. Manual repair required.',
                        $carId
                    ));
                }
            } catch (\Throwable $restoreError) {
                logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                    'CarVerificationSendService::sendOne: CRITICAL - car %d has a rotated vericode but no email was '
                    . 'sent, and the restore of the previous vericode/vericode_sent_at ALSO failed (%s): %s. '
                    . 'Any verification link the owner already holds is now invalid. Manual repair required.',
                    $carId,
                    get_class($restoreError),
                    $restoreError->getMessage()
                ));
            }

            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                'CarVerificationSendService::sendOne: email() returned %s (expected true) for car %d; '
                . 'treating as failure, verification_attempts deliberately not incremented',
                var_export($sendOk, true),
                $carId
            ));

            return SendResult::failed($carId, 'The email could not be sent.');
        }

        // Scope C — bookkeeping only. The email is already delivered to Brevo.
        //
        // No real Brevo message id is available from the mailer, so the event row
        // gets a locally-derived one. It is the hash of the new code rather than
        // the code itself: er_email_events keys on brevo_message_id, and storing
        // the plaintext code there would defeat the hashing that keeps cars.vericode
        // out of the database in the clear.
        $brevoMessageId = 'local:' . hash('sha256', $newVericode);

        $this->repo->beginTransaction();

        $eventRowsWritten = 0;
        $attemptsRecorded = false;

        try {
            // Both calls' return values are captured below, but only ONE of
            // them is a reliable failure signal — see the check after the
            // commit for why. Neither signals via exception:
            // insertEmailEvent() returns a row count rather than throwing on
            // an ON DUPLICATE KEY no-op, and incrementVerificationAttempts()
            // returns false rather than throwing when no row matched
            // (documented on the method itself, specifically so a successful
            // send is never reported as a failure).
            $eventRowsWritten = $this->repo->insertEmailEvent(
                $carId,
                (string) $carData->email,
                'sent',
                null,
                $brevoMessageId,
                $sentAt
            );
            $attemptsRecorded = $this->repo->incrementVerificationAttempts($carId);

            $this->repo->commit();
        } catch (\Throwable $e) {
            $this->repo->rollback();

            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                'CarVerificationSendService::sendOne: email for car %d WAS SENT but the send could not be recorded '
                . '(%s): %s. The er_email_events row and/or the verification_attempts increment are missing; the '
                . 'send is still reported as successful so the owner is not emailed twice.',
                $carId,
                get_class($e),
                $e->getMessage()
            ));

            // Status stays "sent" (see the note above — the email really did
            // go out and must never be retried), but sentUnrecorded() carries
            // a non-null reason so the admin report can render a distinct
            // warning rather than showing this identically to an ordinary
            // successful send. Without it, a failed incrementVerificationAttempts()
            // leaves the car eligible again with zero visible signal, and the
            // very next batch quietly re-emails the same owner.
            return SendResult::sentUnrecorded(
                $carId,
                'Email sent, but the send could not be recorded — this car may be emailed again.'
            );
        }

        // $eventRowsWritten === 0 IS NOT A FAILURE SIGNAL ON ITS OWN.
        // insertEmailEvent() uses INSERT ... ON DUPLICATE KEY UPDATE, and
        // MySQL reports 0 affected rows for that statement in TWO different
        // situations: nothing was written at all, and the duplicate-key row
        // already held byte-identical values so the UPDATE changed nothing.
        // The second case is a fully-recorded send. Escalating it to
        // sentUnrecorded() would show the admin a "this car may be emailed
        // again" warning for a send that is, in fact, completely recorded —
        // so it is logged as an informational note below and nothing more.
        if ($eventRowsWritten === 0) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                'CarVerificationSendService::sendOne: insertEmailEvent reported 0 rows for car %d. '
                . 'Informational only: ON DUPLICATE KEY UPDATE reports 0 both when nothing was written '
                . 'and when the existing row already held identical values, so this alone does not mean '
                . 'the send went unrecorded.',
                $carId
            ));
        }

        // $attemptsRecorded === false, by contrast, is unambiguous.
        // incrementVerificationAttempts() writes through a CASE expression
        // that always changes a column on any row it matches (both the
        // reset-to-1 and the plain increment branch report one affected row),
        // so false can only mean no car row matched — the attempt was not
        // counted, the car stays eligible, and the next batch would re-email
        // the same owner. That is exactly what sentUnrecorded() exists to
        // surface.
        if ($attemptsRecorded === false) {
            // No exception was thrown — the transaction committed — but the
            // attempt increment matched no row. Same consequence as the catch
            // above (the car may be re-selected and emailed again), so it gets
            // the same sentUnrecorded() treatment rather than being silently
            // reported as a clean send.
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                'CarVerificationSendService::sendOne: email for car %d WAS SENT but the '
                . 'verification_attempts increment matched no row (er_email_events rows written=%d). '
                . 'This car may be re-selected and emailed again in a future batch.',
                $carId,
                $eventRowsWritten
            ));

            return SendResult::sentUnrecorded(
                $carId,
                'Email sent, but the send could not be fully recorded — this car may be emailed again.'
            );
        }

        return SendResult::sent($carId);
    }

    /**
     * Send verification emails for a list of cars
     *
     * Each car is sent independently — one car's failure neither aborts the batch
     * nor affects any other car's result, which is what lets the admin page render
     * a per-car report and lets the cron job work through a run to completion.
     *
     * NO CALLER TODAY. The admin batch-send handler (app/admin/index.php's
     * verification_send_batch case) calls sendOne() directly in its own
     * per-car loop instead, because it needs to bucket each result into its
     * own report table (sent/unrecorded/skipped/failed) alongside the car
     * row itself — this method's flat array<SendResult> return drops that.
     * This method exists for #1885's cron job, whose simpler run-to-completion
     * use case fits the flat-array shape directly.
     *
     * @param array<object> $cars Eligible car rows, typically from {@see self::findEligible()}
     * @return array<SendResult> One result per input car, in the same order
     */
    public function sendBatch(array $cars): array
    {
        return array_map(fn (object $c) => $this->sendOne($c), $cars);
    }
}
