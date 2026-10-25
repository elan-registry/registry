<?php

declare(strict_types=1);

use ElanRegistry\Cron\RetentionPurgeJob;
use ElanRegistry\Retention\RetentionPolicy;

/**
 * Cron shim for the daily data retention purge (#2384).
 *
 * `users/cron/cron.php` runs a job as a `crons.file` row in `users/cron/`
 * with `include_once`. So each job needs a plain script here. The work
 * belongs to {@see RetentionPurgeJob}, its
 * {@see \ElanRegistry\Cron\AbstractCronJob} base, and the rules from
 * {@see RetentionPolicy::rules()}. Keep this file to the wiring only.
 *
 * NO IP GATE. `cron.php` applies the `cron_ip` allowlist before it includes
 * any job file.
 *
 * NO `crons_logs` INSERT. `cron.php` writes one `crons_logs` row for each job
 * after `include_once` returns.
 *
 * @see https://github.com/elan-registry/registry/issues/2384
 * @see docs/development/DEPLOYMENT.md — "Cron Transport" (job-author contract)
 * @since v2.30.10
 */

require_once '../init.php';

(new RetentionPurgeJob(dbi(), RetentionPolicy::rules(dbi())))->run();
