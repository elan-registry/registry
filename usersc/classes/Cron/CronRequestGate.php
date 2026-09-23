<?php

declare(strict_types=1);

namespace ElanRegistry\Cron;

use ElanRegistry\Car\VerificationSettings;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\LogCategories;

/**
 * CronRequestGate - the cron_ip allowlist check plus last-cron-request
 * bookkeeping, extracted from users/cron/cron.php (#2086) so this logic is
 * unit-testable.
 *
 * cron.php remains the actual transport entry point (see DEPLOYMENT.md's
 * "Cron Transport" section) — it still owns request bootstrapping ($ip,
 * $settings) and the per-job dispatch loop. This class owns exactly the two
 * things cron.php did inline: deny-and-log on a cron_ip mismatch, and
 * recording a successful request — strictly in that order, since a denied
 * hit must never be recorded.
 *
 * Never throws, and must stay that way: cron.php calls this before its
 * dispatch loop with no try/catch, so an exception here skips every
 * scheduled job for that tick. This relies on
 * {@see VerificationSettings::recordCronRequest()}'s documented
 * never-throws contract — if that contract changes, this call needs a
 * try/catch(\Throwable) that logs and admits.
 */
final class CronRequestGate
{
    public function __construct(
        private readonly DatabaseInterface $db,
    ) {
    }

    /**
     * @param string $requestIp The caller's resolved IP (ipCheck())
     * @param string $configuredCronIp $settings->cron_ip; empty means "no restriction"
     * @return bool True if the request is allowed to proceed (and has just been
     *              recorded); false if it was denied and logged. Never records
     *              a denied request.
     */
    public function admitAndRecord(string $requestIp, string $configuredCronIp): bool
    {
        if ($configuredCronIp !== '' && $requestIp !== $configuredCronIp && $requestIp !== '127.0.0.1') {
            logger('', LogCategories::LOG_CATEGORY_CRON_REQUEST, "Cron request DENIED from {$requestIp}.");
            return false;
        }

        // recordCronRequest() never throws and logs its own failure cause under
        // LOG_CATEGORY_VERIFICATION_CONFIG_WARNING. Its bool is deliberately
        // discarded: this is dashboard bookkeeping, and a failed write must not
        // deny an otherwise-valid cron hit — the dispatch loop below is the
        // actual work, and cronReady() reporting "stalled" is the intended,
        // self-announcing consequence of a persistent failure here.
        (new VerificationSettings($this->db))->recordCronRequest();
        return true;
    }
}
