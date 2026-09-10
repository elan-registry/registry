<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Cron\BrevoSuppressionSyncClient;
use ElanRegistry\Cron\BrevoSuppressionSyncJob;

/**
 * Cron shim for the nightly Brevo suppression-list sync job.
 *
 * This file exists only to satisfy `users/cron/cron.php`'s dispatch contract:
 * a job is a `crons.file` row resolved against `users/cron/` and pulled in with
 * `include_once`, so every job needs a plain script here even when the work
 * itself lives in a class. All of the actual behaviour — the guard claim, the
 * single bounded page over a narrow lookback window, the reason-code mapping,
 * and crash isolation — belongs to {@see BrevoSuppressionSyncJob} and its
 * {@see \ElanRegistry\Cron\AbstractCronJob} base. Nothing here should grow
 * beyond wiring up dependencies.
 *
 * INCREMENTAL ONLY. `run()` reaches
 * {@see BrevoSuppressionSyncJob::execute()}, which fetches exactly one page of
 * recently-suppressed contacts. The unwindowed full import lives in
 * {@see BrevoSuppressionSyncJob::runFullBackfill()}, which no scheduled path
 * calls — it is operator-triggered from the admin script only. Do not "fix"
 * this shim to invoke it: cron.php dispatches jobs in-process, so an unbounded
 * pagination walk here would hold the whole cron hit open and starve every job
 * behind it.
 *
 * NO IP GATE. `cron.php` applies the `cron_ip` allowlist once, at the
 * dispatcher level, before it includes any job file, so this script is only
 * ever reached through a request that already passed that check. Re-checking
 * here (as the stock `users/cron/sample.php` template does, because it is
 * written to be runnable standalone) would add no protection.
 *
 * NO `crons_logs` INSERT. `cron.php`'s dispatch loop inserts one `crons_logs`
 * row per job per hit itself, immediately after `include_once` returns. A job
 * file writing its own row would double-log every run.
 *
 * @see https://github.com/elan-registry/registry/issues/1923
 * @see docs/development/DEPLOYMENT.md — "Cron Transport" (job-author contract)
 * @since v2.30.2
 */

require_once '../init.php';

$suppressionRepo = new CarRepository(dbi());

(new BrevoSuppressionSyncJob(
    dbi(),
    $suppressionRepo,
    new EmailEventApplier($suppressionRepo, new CarVerificationManager($suppressionRepo)),
    new BrevoSuppressionSyncClient(dbi())
))->run();
