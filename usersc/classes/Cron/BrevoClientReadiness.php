<?php

declare(strict_types=1);

namespace ElanRegistry\Cron;

/**
 * BrevoClientReadiness - Outcome of BrevoEventReconciliationClient's pre-flight checks
 *
 * `fetchEvents()` returns null both when Brevo is not set up here and when
 * the settings read fails, so the reconciliation job cannot tell them apart.
 * They call for opposite pings: an environment without Brevo is a valid
 * steady state and must ping success, while a failed read is a fault that
 * must ping `/fail`. The case matches the log category that the check
 * already writes: NOT_CONFIGURED logs `CronJobSkipped`, FAILED logs
 * `CronJobFailure`.
 *
 * @package ElanRegistry\Cron
 * @since v2.31.0
 * @see https://github.com/elan-registry/registry/issues/2480
 */
enum BrevoClientReadiness
{
    /** The API key is set and the SDK loaded — the client can poll. */
    case READY;

    /** No plg_sendinblue table, no API key, or no SDK — Brevo is not set up here. */
    case NOT_CONFIGURED;

    /** The plg_sendinblue read failed, or the SDK is present but does not load. */
    case FAILED;
}
