<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\Exceptions\ElanRegistryException;
use ElanRegistry\Exceptions\ExceptionLogSummary;
use ElanRegistry\LogCategories;

/**
 * VerificationBatchSender - Per-car loop body for the admin verification
 * batch-send handler (#1884)
 *
 * Extracted from app/admin/index.php's `verification_send_batch` POST
 * handler so the batch-loop failure containment (four independent try/catch
 * guards around findById(), the not-found check, the eligibility re-check,
 * and sendOne()) and the SendResult -> report-bucket routing can be covered
 * by a unit test. app/admin/index.php itself cannot be require()'d directly
 * in a unit test — it needs the full UserSpice framework bootstrap
 * (securePage(), $abs_us_root, etc.) — so this class exists to hold the one
 * piece of that handler's logic worth testing in isolation.
 *
 * Calls {@see VerificationEligibility::skipReason()} directly (not injected)
 * now that the eligibility rule set is its own real, requireable class
 * rather than a global function defined inside app/admin/index.php.
 *
 * NOT a general-purpose service: like the handler it was extracted from,
 * this class assumes the ids it is given already passed the admin
 * authorization check and CSRF validation one layer up. It performs no
 * authorization of its own.
 *
 * @package ElanRegistry\Car
 * @since v2.30.3
 * @see https://github.com/elan-registry/registry/issues/1884
 */
final class VerificationBatchSender
{
    /**
     * @param CarRepository $repo Used only for findById() re-reads of each
     *                             submitted car id
     * @param CarVerificationSendService $sendSvc Used only for sendOne()
     * @param int $currentUserId Passed through to every `logger()` call, matching
     *                             the handler's original per-line logging
     */
    public function __construct(
        private CarRepository $repo,
        private CarVerificationSendService $sendSvc,
        private int $currentUserId,
    ) {
    }

    /**
     * Truncate submitted ids to the configured batch size
     *
     * Static and pure so the cap is testable without a database. The caller
     * logs and reports the messages, and runs this before it filters ids, so
     * a hand-crafted POST cannot start more blocking Brevo calls than the
     * configured batch size allows.
     *
     * @param array<mixed> $ids Submitted ids, unfiltered
     * @param int $batchSize Configured batch size. A value below 1 is treated as 1.
     * @return array{ids: list<mixed>, logMessage: ?string, adminMessage: ?string}
     *         The messages are null when no truncation happened
     */
    public static function capToBatchSize(array $ids, int $batchSize): array
    {
        // A zero or negative length makes array_slice() keep nothing or cut from the end.
        $batchSize = max(1, $batchSize);

        if (count($ids) <= $batchSize) {
            return ['ids' => array_values($ids), 'logMessage' => null, 'adminMessage' => null];
        }

        return [
            'ids' => array_values(array_slice($ids, 0, $batchSize)),
            'logMessage' => sprintf(
                'Verification send: car_ids[] carried %d ids, exceeding the configured batch size '
                . 'of %d; truncated to the first %d.',
                count($ids),
                $batchSize,
                $batchSize
            ),
            'adminMessage' => sprintf(
                'Only the first %d cars were sent — the request exceeded the configured batch size.',
                $batchSize
            ),
        ];
    }

    /**
     * Split submitted ids into positive car ids and rejected values
     *
     * Static and pure so the rule is testable without a database. The caller
     * runs this after capToBatchSize(), and logs and reports each rejected
     * value. An id is kept only if it is a positive int, or a string that is
     * the canonical decimal form of a positive int ('12'). Everything else is
     * rejected: arrays, objects, null, bools, floats, and strings such as
     * 'abc', '3abc', '1.5', ' 3', '+3', '03', or a value past PHP_INT_MAX.
     * A plain (int) cast is not safe here: it turns a nested array into 1 and
     * '3abc' into 3, which are real car ids.
     *
     * @param array<mixed> $submitted Submitted ids, already capped
     * @return array{ids: list<int>, rejected: list<mixed>} Both lists keep the
     *         submitted order
     */
    public static function filterCarIds(array $submitted): array
    {
        $ids = [];
        $rejected = [];

        foreach ($submitted as $value) {
            if (is_int($value) && $value > 0) {
                $ids[] = $value;
                continue;
            }

            if (is_string($value)) {
                $intValue = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

                // filter_var() accepts ' 3' and '+3'. The round trip keeps only the canonical form.
                if (is_int($intValue) && (string) $intValue === $value) {
                    $ids[] = $intValue;
                    continue;
                }
            }

            $rejected[] = $value;
        }

        return ['ids' => $ids, 'rejected' => $rejected];
    }

    /**
     * Process one batch of submitted car ids and bucket the outcome of each
     *
     * Mirrors the original inline loop exactly, including its four
     * independent try/catch guards: one car's findById() throwing,
     * findById() returning null, eligibilitySkipReason() throwing, or
     * sendOne() throwing must never abort processing of the remaining ids —
     * an uncaught throw here would silently drop every subsequent car from
     * the report (and, for a throw inside sendOne(), possibly leave that one
     * car's vericode rotated with no email ever sent quoting it).
     *
     * Ids that are not positive integers are the caller's responsibility to
     * filter and report before calling this method, with filterCarIds().
     *
     * @param array<int> $carIds Positive car ids to process, already cast to int
     * @return array{sent: array<object>, unrecorded: array<array{car: object, reason: string}>,
     *               skipped: array<array{car: object, reason: string}>,
     *               failed: array<array{car: object, reason: string}>}
     */
    public function processBatch(array $carIds): array
    {
        $sent = [];
        $unrecorded = [];
        $skipped = [];
        $failed = [];

        foreach ($carIds as $carId) {
            // findById() throws CarDatabaseException on a query failure —
            // this must be inside the per-car guard, not called ahead of it,
            // or a transient DB error mid-batch aborts the whole send and
            // silently drops the report for every car already processed
            // (and possibly already emailed) before it.
            try {
                $carData = $this->repo->findById($carId);
            } catch (\Throwable $e) {
                // The summary, not the message: CarRepository copies driver error
                // text into its messages, and that text can echo an address.
                logger($this->currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
                    'Verification send: findById threw for car %d: %s',
                    $carId,
                    ExceptionLogSummary::of($e)
                ));
                $failed[] = [
                    'car'    => (object) ['id' => $carId, 'chassis' => '', 'email' => ''],
                    'reason' => 'The car record could not be read.',
                ];
                continue;
            }

            if ($carData === null) {
                logger($this->currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
                    "Verification send: car {$carId} was in the preview but no longer exists; skipped");
                $skipped[] = [
                    'car'    => (object) ['id' => $carId, 'chassis' => '', 'email' => ''],
                    'reason' => 'Car no longer exists',
                ];
                continue;
            }

            // Re-check eligibility against the row as it stands NOW. The
            // preview was rendered by an earlier request and the car may
            // have left the eligible set since.
            try {
                $skipReason = VerificationEligibility::skipReason($carData);
            } catch (ElanRegistryException $e) {
                logger($this->currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
                    "Verification send: eligibility re-check failed for car {$carId}: " . ExceptionLogSummary::of($e));
                // Distinct from every other skip reason below: this one is a
                // data-integrity fault (e.g. a corrupt verification_attempts_since
                // value), not a routine ineligibility state like "Marked sold." The
                // prefix keeps it visually distinguishable in the Skipped table so
                // it doesn't read as ordinary and get lost among expected skips.
                $skipReason = 'Data error — Eligibility could not be determined';
            }

            if ($skipReason !== null) {
                $skipped[] = ['car' => $carData, 'reason' => $skipReason];
                continue;
            }

            // Guarded per-car: sendOne() covers its own three internal
            // scopes (vericode rotation, send, bookkeeping), but
            // Owner::data() and the compose()/email() call sit between
            // those scopes with no catch of their own. An uncaught throw
            // here must not abort the whole batch — it would silently drop
            // every car after it from the report, and if the throw lands
            // after this car's vericode was already rotated, that car is
            // left stranded with no restore attempted.
            try {
                $sendResult = $this->sendSvc->sendOne($carData);
            } catch (\Throwable $e) {
                // The summary, not the message: a send error can echo the recipient address.
                logger($this->currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
                    'Verification send: sendOne threw for car %d: %s',
                    $carId,
                    ExceptionLogSummary::of($e)
                ));
                $sendResult = SendResult::failed($carId, 'An unexpected error occurred during send.');
            }

            if ($sendResult->isUnrecorded()) {
                // sentUnrecorded(): delivered, but the bookkeeping that
                // prevents a duplicate send failed — keep this visibly
                // distinct from a clean send.
                $unrecorded[] = [
                    'car'    => $carData,
                    'reason' => $sendResult->reason,
                ];
            } elseif ($sendResult->status === SendResult::STATUS_SENT) {
                $sent[] = $carData;
            } else {
                $failed[] = [
                    'car'    => $carData,
                    'reason' => $sendResult->reason ?? 'Unknown failure',
                ];
            }
        }

        return [
            'sent'       => $sent,
            'unrecorded' => $unrecorded,
            'skipped'    => $skipped,
            'failed'     => $failed,
        ];
    }
}
