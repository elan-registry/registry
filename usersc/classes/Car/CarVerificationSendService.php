<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\AppConstants;
use ElanRegistry\Exceptions\ExceptionLogSummary;
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
 * rule (staleness, owner profile flags, sold cars, erased owners,
 * the attempt cap). Both callers derive their car list from that one query.
 *
 * @package ElanRegistry\Car
 * @since v2.30.3
 * @see https://github.com/elan-registry/registry/issues/1884
 */
final class CarVerificationSendService
{
    private VerificationSettings $settings;

    /**
     * @param CarRepository $repo Car data access
     * @param CarVerificationManager $verifier Rotates the vericode
     * @param CarVerificationEmailComposer $composer Builds the email subject and body
     * @param VerificationSettings|null $settings Brevo readiness probe; null uses the shared
     *                                            connection. Last, so the production callers
     *                                            need not pass it.
     */
    public function __construct(
        private CarRepository $repo,
        private CarVerificationManager $verifier,
        private CarVerificationEmailComposer $composer,
        ?VerificationSettings $settings = null,
    ) {
        $this->settings = $settings ?? new VerificationSettings(dbi());
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
     * The email goes to the owner's `users.email`. `cars.email` is only a
     * copy and is not read.
     *
     * @param object $carData Eligible car row as returned by {@see self::findEligible()}:
     *                        requires ->id and ->user_id, plus the display
     *                        fields {@see CarVerificationEmailComposer::compose()} reads.
     *                        Note this method mutates the passed object — the verifier
     *                        writes the new plaintext ->vericode and ->vericode_sent_at
     *                        onto it so the composer can quote them, and ->email
     *                        becomes the address the send mails.
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

        // `noowner` is a real, live users row (the GDPR-erasure reassignment
        // target — see CarAdministrationService::SYSTEM_ACCOUNT_USERNAME), so
        // the null check above does not catch it: Owner::data() loads it
        // successfully. CarRepository::findVerificationEligible()'s SQL
        // excludes it via `users.username != 'noowner'`, but
        // VerificationEligibility::skipReason() (the PHP-side re-check used by
        // the admin manual-send path) has no join to check this and relies on
        // this method to enforce it instead — see that class's docblock. Without
        // this check, a `noowner` car would mail the system account.
        if (($owner->username ?? '') === 'noowner') {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                'CarVerificationSendService::sendOne: car %d is owned by the noowner system account; send skipped',
                $carId
            ));

            return SendResult::failed($carId, 'Car has no live owner.');
        }

        // The owner's deliverability flags (#2101), enforced here for the same
        // reason the `noowner` check above is: they live on `profiles`, and
        // the admin manual-send path re-checks eligibility in PHP against a
        // single `cars` row that has no profile fields
        // (VerificationEligibility::skipReason()). The cron path selects rows
        // with findVerificationEligible(), which already excludes these
        // owners, but a flag can be set between that query and this send.
        //
        // A separate read rather than fields off $owner: Owner::find()'s
        // users-LEFT JOIN-profiles projection selects only city/state/country/
        // lat/lon/website from `profiles`, and widening that shared projection
        // (read by every owner-facing page) for one send-path guard is the
        // larger change. The rules match findVerificationEligible(): no
        // profiles row excludes the car, an opt-out excludes it whatever the
        // address, and a block or bounce excludes it while its address
        // matches the owner's current email (#2356). The send mails that
        // email, so no other address needs a check.
        $ownerId      = (int) $carData->user_id;
        $emailFlags   = $this->repo->effectiveEmailFlags($ownerId);
        $profileBlockReason = match (true) {
            !$emailFlags->hasProfile => 'Owner has no profile.',
            $emailFlags->optedOut    => 'Owner has opted out of verification emails.',
            $emailFlags->blocked     => 'Owner email is blocked.',
            $emailFlags->bounced     => 'Owner email bounced.',
            default                  => null,
        };
        if ($profileBlockReason !== null) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                'CarVerificationSendService::sendOne: owner %d for car %d cannot get mail: %s Send skipped.',
                $ownerId,
                $carId,
                $profileBlockReason
            ));

            return SendResult::failed($carId, $profileBlockReason);
        }

        // The send mails users.email. The eligibility SQL requires it, but an
        // admin can clear it between that query and this send.
        $recipient = trim((string) ($owner->email ?? ''));
        if ($recipient === '') {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                'CarVerificationSendService::sendOne: owner %d for car %d has no account email. Send skipped.',
                $ownerId,
                $carId
            ));

            return SendResult::failed($carId, 'Owner has no email address.');
        }

        // Send only when Brevo is ready (key, active plugin, override file,
        // plugin 1.8.0 or later). Otherwise email() is the core PHPMailer
        // fallback, or a plugin older than 1.8.0 drops $opts['tags'], and the
        // mail can report success with no verification tag, so the webhook
        // never records its delivery. Readiness does not read override.php:
        // an override kept from before 1.8.0 also drops the tag and is not
        // caught here (accepted risk, #2374). Checked before Scope A so a
        // refused send leaves the vericode untouched and costs no attempt.
        $brevoNotReadyReason = $this->settings->brevoNotReadyReason();
        if ($brevoNotReadyReason !== null) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                'CarVerificationSendService::sendOne: Brevo is not ready: %s Send skipped for car %d.',
                $brevoNotReadyReason,
                $carId
            ));

            return SendResult::failed($carId, 'No email was sent. ' . $brevoNotReadyReason);
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
                'CarVerificationSendService::sendOne: verification code rotation failed for car %d (%s).',
                $carId,
                ExceptionLogSummary::of($e)
            ));

            return SendResult::failed($carId, 'The verification code could not be generated.');
        }

        // Scope B — compose and send with no transaction open.
        //
        // compose() is guarded because it does I/O since #1894: logPhotoProblem()
        // calls logger(), which can throw \PDOException. Scope A has already
        // committed the rotated code, so an unguarded throw here would skip the
        // restore and leave the car with a code that no email quoted.
        try {
            $composed = $this->composer->compose($carData, $owner, $newVericode);
        } catch (\Throwable $e) {
            $this->restoreAfterFailedSend($carId, $previousVericode, $previousVericodeSentAt);

            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                'CarVerificationSendService::sendOne: verification email could not be composed for car %d'
                . ' (%s). verification_attempts deliberately not incremented.',
                $carId,
                ExceptionLogSummary::of($e)
            ));

            return SendResult::failed($carId, 'The verification email could not be composed.');
        }

        // Read before the send as well: the plugin resets its value at the start
        // of each send, but a stale value from an earlier send must never pass
        // for this send's ID.
        $messageIdBefore = $this->lastMailerMessageId();

        $sendOk = email(
            $recipient,
            $composed['subject'],
            $composed['html'],
            ['tags' => AppConstants::VERIFICATION_EMAIL_TAG]
        );

        // The tag is how the webhook receiver tells verification events apart
        // from other mail. The readiness check above requires a plugin that forwards it.
        //
        // Strict `!== true` rather than `=== false`: a site-local mailer
        // override could return something else on failure (a message-id
        // string, null, 0 — a common convention) that would pass
        // `=== false` and be misreported as a successful send, incrementing
        // the attempt count and consuming the yearly cap with no email
        // actually delivered.
        if ($sendOk !== true) {
            $this->restoreAfterFailedSend($carId, $previousVericode, $previousVericodeSentAt);

            // The plugin logs its own reason in a separate row with no car id.
            // Copy it here so one row names both the car and the cause.
            $mailerError = function_exists('sendinblueLastError') ? sendinblueLastError() : null;
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                'CarVerificationSendService::sendOne: email() returned %s (expected true) for car %d; '
                . 'treating as failure, verification_attempts deliberately not incremented. Mailer error: %s',
                var_export($sendOk, true),
                $carId,
                is_scalar($mailerError) && (string) $mailerError !== '' ? (string) $mailerError : 'none reported'
            ));

            return SendResult::failed($carId, 'The email could not be sent.');
        }

        // The admin batch report shows ->email in its Sent rows. Put the
        // address this send mailed there, not the cars.email copy.
        $carData->email = $recipient;

        // Scope C — bookkeeping only. The email is already delivered to Brevo.
        //
        // The row keys on the real Brevo message ID so a later webhook event can
        // find it. A value equal to the earlier read is stale, so it does not
        // count. Without a usable ID the row gets a local one. That ID is the
        // hash of the new code, not the code itself, because storing the
        // plaintext code here would defeat the hashing that keeps cars.vericode
        // out of the database in the clear.
        $messageIdAfter = $this->lastMailerMessageId();
        if ($messageIdAfter !== '' && $messageIdAfter !== $messageIdBefore) {
            $brevoMessageId = $messageIdAfter;
        } else {
            $brevoMessageId = 'local:' . hash('sha256', $newVericode);
            $this->logLocalMessageIdFallback($ownerId, $carId);
        }

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
                $recipient,
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
                . '(%s). The er_email_events row and/or the verification_attempts increment are missing. The '
                . 'send is still reported as successful so the owner is not emailed twice.',
                $carId,
                ExceptionLogSummary::of($e)
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
        // insertEmailEvent() reports 0 rows in THREE situations:
        // - nothing was written at all,
        // - the duplicate-key row already held the same values, so the
        //   ON DUPLICATE KEY UPDATE changed nothing,
        // - the car has no owner, or its owner is `noowner`, so the
        //   INSERT ... SELECT found no row to write (#2358).
        // The second case is a fully-recorded send. The checks above stop a
        // send to a car with no live owner, so the third case needs an owner
        // change between those checks and the insert. A sentUnrecorded()
        // result would show the admin a "this car may be emailed again"
        // warning for a send that is recorded, so the code logs a note only.
        if ($eventRowsWritten === 0) {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                'CarVerificationSendService::sendOne: insertEmailEvent reported 0 rows for car %d. '
                . 'Informational only: 0 rows means one of these: nothing was written, the existing row '
                . 'already held identical values, or the car has no owner or a noowner owner. This alone '
                . 'does not mean the send went unrecorded.',
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
     * Read the message ID of the last send from the sendinblue plugin
     *
     * @return string The ID, or an empty string when the plugin has none
     */
    private function lastMailerMessageId(): string
    {
        if (!function_exists('sendinblueLastMessageId')) {
            return '';
        }

        $messageId = sendinblueLastMessageId();

        return is_string($messageId) ? trim($messageId) : '';
    }

    /**
     * Log that the sent row has a local ID instead of a Brevo message ID
     *
     * The email is already sent. A logging failure must not turn into a failed
     * send, so this method swallows it.
     */
    private function logLocalMessageIdFallback(int $ownerId, int $carId): void
    {
        try {
            logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, sprintf(
                'CarVerificationSendService::sendOne: no new Brevo message ID for owner %d, car %d. '
                . 'The sent row has a local ID, so webhook events for this send cannot match it by message ID.',
                $ownerId,
                $carId
            ));
        } catch (\Throwable) {
            // Nothing else can report this failure, and the send is done.
        }
    }

    /**
     * Put back the vericode state that a failed send rotated away
     *
     * @param int $carId Car whose code Scope A rotated
     * @param string|null $previousVericode Stored vericode before the rotation
     * @param string|null $previousVericodeSentAt Stored vericode_sent_at before the rotation
     */
    private function restoreAfterFailedSend(int $carId, ?string $previousVericode, ?string $previousVericodeSentAt): void
    {
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
            // in sendOne() and this restore attempt) — that would make the
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
                . 'sent, and the restore of the previous vericode/vericode_sent_at ALSO failed (%s). '
                . 'Any verification link the owner already holds is now invalid. Manual repair required.',
                $carId,
                ExceptionLogSummary::of($restoreError)
            ));
        }
    }
}
