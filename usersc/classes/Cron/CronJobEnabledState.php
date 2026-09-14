<?php

declare(strict_types=1);

namespace ElanRegistry\Cron;

/**
 * CronJobEnabledState - Outcome of AbstractCronJob's er_cron_job_runs read
 *
 * A plain boolean cannot distinguish the three reasons a job is "not
 * enabled", and they call for opposite operator responses: DISABLED is a
 * deliberate pause, MISSING is a seeding/migration bug, and UNREADABLE is a
 * database fault. Collapsing them into `false` — as this originally did —
 * makes an infrastructure outage indistinguishable from someone having
 * paused the job on purpose.
 *
 * @package ElanRegistry\Cron
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1889
 */
enum CronJobEnabledState
{
    /** The row exists and `enabled` is truthy — the job may claim a run. */
    case ENABLED;

    /** The row exists and `enabled` is 0 — an operator deliberately paused it. */
    case DISABLED;

    /** No er_cron_job_runs row for this job name — never seeded, or deleted. */
    case MISSING;

    /** The read itself failed (connectivity, grants, missing table). */
    case UNREADABLE;
}
