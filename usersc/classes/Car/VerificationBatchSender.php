<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\Exceptions\ElanRegistryException;
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
 * @since v2.30.4
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
     * filter and report before calling this method — see
     * app/admin/index.php's handling of non-positive `car_ids[]` entries,
     * which never reaches this class.
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
                logger($this->currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
                    'Verification send: findById threw for car %d [%s]: %s',
                    $carId,
                    get_class($e),
                    $e->getMessage()
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
                    "Verification send: eligibility re-check failed for car {$carId}: " . $e->getMessage());
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
                logger($this->currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
                    'Verification send: sendOne threw for car %d [%s]: %s',
                    $carId,
                    get_class($e),
                    $e->getMessage()
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
