<?php

declare(strict_types=1);

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationEmailComposer;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\CarVerificationSendService;
use ElanRegistry\Car\VerificationBatchSender;
use ElanRegistry\Car\VerificationSettings;
use ElanRegistry\Cron\SendVerificationBatchJob;

/**
 * Cron shim for the automatic verification-email batch send (#1885).
 *
 * This file exists only to satisfy `users/cron/cron.php`'s dispatch contract:
 * a job is a `crons.file` row resolved against `users/cron/` and pulled in with
 * `include_once`, so every job needs a plain script here even when the work
 * itself lives in a class. All of the actual behaviour — the guard claim, the
 * enabled-flag check, the eligible-car selection, the send loop, and crash
 * isolation — belongs to {@see SendVerificationBatchJob} and its
 * {@see \ElanRegistry\Cron\AbstractCronJob} base, {@see CarVerificationSendService},
 * and {@see VerificationBatchSender}. Nothing here should grow beyond wiring
 * up dependencies — matching `brevo_event_reconciliation.php`'s identical
 * shim contract for #1889.
 *
 * The dependency graph mirrors app/admin/index.php's own construction of
 * `$verificationSendSvc` exactly (see that file's "Verification send
 * services" comment block), so the cron path and the manual admin path build
 * the same collaborators the same way — the only difference between them is
 * what triggers the call and which acting-user id gets logged.
 *
 * NO LOGGED-IN USER. Unlike the admin handler, which passes the acting
 * admin's id to {@see VerificationBatchSender} for log attribution, a
 * cron-triggered run has no session — `0` is passed, matching this job's own
 * `logger()` calls (see {@see SendVerificationBatchJob}) and
 * {@see AbstractCronJob}'s own convention for system-initiated actions.
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
 * @see https://github.com/elan-registry/registry/issues/1885
 * @see docs/development/DEPLOYMENT.md — "Cron Transport" (job-author contract)
 * @since v2.30.3
 */

require_once '../init.php';

$sendRepo = new CarRepository(dbi());
$sendSvc = new CarVerificationSendService(
    $sendRepo,
    new CarVerificationManager($sendRepo),
    new CarVerificationEmailComposer()
);

(new SendVerificationBatchJob(
    dbi(),
    new VerificationSettings(dbi()),
    $sendSvc,
    new VerificationBatchSender($sendRepo, $sendSvc, 0)
))->run();
