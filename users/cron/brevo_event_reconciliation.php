<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Cron\BrevoEventReconciliationClient;
use ElanRegistry\Cron\BrevoEventReconciliationJob;

/**
 * Cron shim for the nightly Brevo delivery-event reconciliation job.
 *
 * This file exists only to satisfy `users/cron/cron.php`'s dispatch contract:
 * a job is a `crons.file` row resolved against `users/cron/` and pulled in with
 * `include_once`, so every job needs a plain script here even when the work
 * itself lives in a class. All of the actual behaviour — the guard claim, the
 * 48-hour backfill window, retention pruning, and crash isolation — belongs to
 * {@see BrevoEventReconciliationJob} and its {@see \ElanRegistry\Cron\AbstractCronJob}
 * base. Nothing here should grow beyond wiring up dependencies.
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
 * @see https://github.com/elan-registry/registry/issues/1889
 * @see docs/development/DEPLOYMENT.md — "Cron Transport" (job-author contract)
 * @since v2.30.2
 */

require_once '../init.php';

$reconciliationRepo = new CarRepository(dbi());

(new BrevoEventReconciliationJob(
    dbi(),
    $reconciliationRepo,
    new EmailEventApplier($reconciliationRepo, new CarVerificationManager($reconciliationRepo)),
    new BrevoEventReconciliationClient(dbi())
))->run();
