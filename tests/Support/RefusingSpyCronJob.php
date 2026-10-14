<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * RefusingSpyCronJob - SpyCronJob that refuses to run, like a send job while
 * the verification switch is off
 *
 * {@see \ElanRegistry\Cron\AbstractCronJob::refusesWhileSwitchOff()} returns
 * false in the base class, so AbstractCronJobTest needs a subclass that
 * returns true to test the gate itself. The `$refuse` property lets a test
 * turn the switch back on and show that the next run is not blocked.
 *
 * Named class, not anonymous: see FakeDatabase (`impureMethod.pure`).
 *
 * @package Tests\Support
 * @since v2.30.8
 * @see https://github.com/elan-registry/registry/issues/2358
 */
class RefusingSpyCronJob extends SpyCronJob
{
    /** True while the job must refuse to run. */
    public bool $refuse = true;

    protected function refusesWhileSwitchOff(): bool
    {
        return $this->refuse;
    }
}
