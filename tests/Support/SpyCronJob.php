<?php

declare(strict_types=1);

namespace Tests\Support;

use ElanRegistry\Cron\AbstractCronJob;

/**
 * SpyCronJob - Instrumented AbstractCronJob subclass for AbstractCronJobTest
 *
 * Counts execute() invocations so tests can assert whether the template
 * method actually reached the job's work, and can optionally throw from
 * execute() to exercise run()/runNow()'s crash isolation.
 *
 * `jobName()` returns 'brevo_reconciliation', which is in
 * CronJobGuard::ALLOWED_JOB_NAMES — any other name would be rejected by the
 * guard before the fake database saw the claim, preventing the "claim succeeded"
 * test from reaching execute().
 *
 * Deliberately a *named* class rather than an anonymous one, per the
 * `impureMethod.pure` rationale in CronJobGuardFakeDatabase's docblock.
 *
 * @package Tests\Support
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1889
 */
class SpyCronJob extends AbstractCronJob
{
    /** Number of times execute() has been entered. */
    public int $executeCalls = 0;

    /** @var \Throwable|null Thrown from execute() when set. */
    private ?\Throwable $throwOnExecute = null;

    /** Make execute() throw $e on its next (and every subsequent) call. */
    public function throwOnExecute(\Throwable $e): void
    {
        $this->throwOnExecute = $e;
    }

    protected function jobName(): string
    {
        return 'brevo_reconciliation';
    }

    protected function guardIntervalHours(): int
    {
        return 24;
    }

    protected function execute(): void
    {
        $this->executeCalls++;

        if ($this->throwOnExecute !== null) {
            throw $this->throwOnExecute;
        }
    }
}
