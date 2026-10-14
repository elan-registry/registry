<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * SwitchableVerificationSettingsFakeDatabase - VerificationSettingsFakeDatabase
 * whose verification switch a test can flip between two runs
 *
 * Every read returns a settings row with a batch size of 5 and the switch in
 * the state of {@see self::$on}. The base fake takes its row in the
 * constructor, so it cannot change between two runs of one job.
 *
 * Safe only as the VerificationSettings connection of SendVerificationBatchJob.
 * It answers every first() with {batch_size, enabled}, so a job that reads
 * another row through it gets wrong data.
 *
 * Named class, not anonymous: see FakeDatabase (`impureMethod.pure`).
 *
 * @package Tests\Support
 * @since v2.30.8
 * @see https://github.com/elan-registry/registry/issues/2358
 */
class SwitchableVerificationSettingsFakeDatabase extends VerificationSettingsFakeDatabase
{
    /** True while the verification switch reads as on. */
    public bool $on = false;

    public function first(bool $assoc = false): array|object
    {
        $row = ['batch_size' => 5, 'enabled' => $this->on ? 1 : 0];

        return $assoc ? $row : (object) $row;
    }
}
