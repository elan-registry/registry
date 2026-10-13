<?php

declare(strict_types=1);

require_once __DIR__ . '/DeliverabilityMigrationSandboxTestCase.php';

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * Re-run of migration 20261012000000 after a failure at each step (#2101).
 *
 * MySQL commits each DDL statement, so a failure leaves the schema between two
 * steps. Each test stands the sandbox in such a state, runs up() and expects
 * the same profiles rows as one clean run. The sandbox starts at the state that
 * down() leaves after a clean up(): the pre-migration schema with the flags on
 * the cars. Each test ends with down(), which returns to that state.
 */
#[Group('integration')]
#[Group('migration')]
final class DeliverabilityMigrationRerunSandboxTest extends DeliverabilityMigrationSandboxTestCase
{
    protected static function targetVersion(): int
    {
        return self::VERSION_BEFORE;
    }

    /** @var list<array<string, mixed>> The profiles rows after one clean up(). */
    private static array $clean = [];

    public function testPrepareCleanResultAndReturnToThePreMigrationSchema(): void
    {
        $this->upWithOutput();
        self::$clean = $this->profileSnapshot();
        $this->runDown(self::MIGRATION);

        $this->assertNotNull($this->columnInfo('cars', 'email_bounced'), 'down() must bring the car columns back');
        $this->assertNull($this->columnInfo('profiles', 'email_blocked'));
        $this->assertNull($this->profile(110));
    }

    #[Depends('testPrepareCleanResultAndReturnToThePreMigrationSchema')]
    public function testRerunAfterAFailureAfterTheUniqueKey(): void
    {
        $this->pdo()->exec('ALTER TABLE profiles ADD UNIQUE INDEX uniq_profiles_user_id (user_id)');

        $this->upWithOutput();

        $this->assertFinishedLikeACleanRun();
        $this->runDown(self::MIGRATION);
    }

    #[Depends('testRerunAfterAFailureAfterTheUniqueKey')]
    public function testRerunAfterAFailureAfterTheRename(): void
    {
        $this->pdo()->exec('ALTER TABLE profiles ADD UNIQUE INDEX uniq_profiles_user_id (user_id)');
        $this->pdo()->exec('ALTER TABLE profiles RENAME COLUMN email_suppressed TO email_opted_out');

        $this->upWithOutput();

        $this->assertFinishedLikeACleanRun();
        $this->runDown(self::MIGRATION);
    }

    #[Depends('testRerunAfterAFailureAfterTheRename')]
    public function testRerunAfterAFailureBetweenTwoColumnAdds(): void
    {
        $this->pdo()->exec('ALTER TABLE profiles ADD UNIQUE INDEX uniq_profiles_user_id (user_id)');
        $this->pdo()->exec('ALTER TABLE profiles RENAME COLUMN email_suppressed TO email_opted_out');
        $this->pdo()->exec(
            'ALTER TABLE profiles ADD COLUMN email_opted_out_at DATETIME NULL DEFAULT NULL AFTER email_bounced_address,
                                  ADD COLUMN email_blocked TINYINT(1) NOT NULL DEFAULT 0 AFTER email_opted_out_at'
        );

        $this->upWithOutput();

        $this->assertFinishedLikeACleanRun();
        $this->runDown(self::MIGRATION);
    }

    #[Depends('testRerunAfterAFailureBetweenTwoColumnAdds')]
    public function testRerunAfterPhaseOne(): void
    {
        $migration = $this->migration(self::MIGRATION);
        $this->callPrivate($migration, 'phaseOneSchemaAndBackfill');

        $this->upWithOutput();

        $this->assertFinishedLikeACleanRun();
        $this->runDown(self::MIGRATION);
    }

    #[Depends('testRerunAfterPhaseOne')]
    public function testRerunAfterPhaseTwoWritesNothingNewAndKeepsTheFlags(): void
    {
        $this->standAfterPhaseTwo();

        $output = $this->upWithOutput();

        $this->assertFinishedLikeACleanRun();
        $this->assertStringContainsString('Phase 2: bounce copied for user ID(s): none', $output);
        $this->assertStringContainsString('Phase 2: block copied for user ID(s): none', $output);
        $this->runDown(self::MIGRATION);
    }

    /**
     * The guard: phase 2 writes only where the profile flag is 0 or is set for another address.
     */
    #[Depends('testRerunAfterPhaseTwoWritesNothingNewAndKeepsTheFlags')]
    public function testPhaseTwoGuardKeepsAFlagSetForTheCurrentAddressAndRewritesAnotherAddress(): void
    {
        $this->standAfterPhaseTwo();
        // A flag that another writer changed after phase 2: set for the owner's current address.
        $this->pdo()->exec("UPDATE profiles SET email_bounced_at = '2031-01-01 00:00:00' WHERE user_id = 101");
        $this->pdo()->exec("UPDATE profiles SET email_blocked_at = '2031-01-02 00:00:00' WHERE user_id = 103");
        $this->pdo()->exec("UPDATE profiles SET email_opted_out_at = '2031-01-03 00:00:00' WHERE user_id = 106");
        // A flag set for an address that is not the current address.
        $this->pdo()->exec(
            "UPDATE profiles SET email_bounced_address = 'other@example.test', email_bounced_at = '2031-01-04 00:00:00'
              WHERE user_id = 109"
        );

        $this->upWithOutput();

        $expected = self::$clean;
        $changed = 0;
        foreach ($expected as $index => $row) {
            $userId = (int) $row['user_id'];
            if ($userId === 101) {
                $expected[$index]['email_bounced_at'] = '2031-01-01 00:00:00';
                $changed++;
            }
            if ($userId === 103) {
                $expected[$index]['email_blocked_at'] = '2031-01-02 00:00:00';
                $changed++;
            }
            if ($userId === 106) {
                $expected[$index]['email_opted_out_at'] = '2031-01-03 00:00:00';
                $changed++;
            }
        }
        $this->assertSame(3, $changed, 'The fixture must hold owners 101, 103 and 106');
        $this->assertSame($expected, $this->profileSnapshot());
        $this->runDown(self::MIGRATION);
    }

    #[Depends('testPhaseTwoGuardKeepsAFlagSetForTheCurrentAddressAndRewritesAnotherAddress')]
    public function testRerunAfterTheTriggersWereRebuilt(): void
    {
        $this->standAfterPhaseTwo();
        $this->callPrivate($this->migration(self::MIGRATION), 'createTriggers', [['owner_last_updated', 'vericode_sent_at', 'verification_attempts', 'verification_attempts_since']]);

        $this->upWithOutput();

        $this->assertFinishedLikeACleanRun();
        $this->runDown(self::MIGRATION);
    }

    #[Depends('testRerunAfterTheTriggersWereRebuilt')]
    public function testRerunAfterTheCarsColumnsWereDroppedSkipsPhaseTwo(): void
    {
        $this->standAfterPhaseTwo();
        $this->callPrivate($this->migration(self::MIGRATION), 'createTriggers', [['owner_last_updated', 'vericode_sent_at', 'verification_attempts', 'verification_attempts_since']]);
        $this->pdo()->exec('ALTER TABLE cars DROP COLUMN email_bounced, DROP COLUMN email_bounced_address, DROP COLUMN email_suppressed');
        $this->assertNotNull($this->columnInfo('cars_hist', 'email_bounced'), 'The stand must keep the cars_hist columns');

        $output = $this->upWithOutput();

        $this->assertFinishedLikeACleanRun();
        $this->assertStringContainsString('Phase 2 skipped', $output);
        $this->assertStringNotContainsString('Phase 2: bounce copied', $output);
        $this->runDown(self::MIGRATION);
    }

    #[Depends('testRerunAfterTheCarsColumnsWereDroppedSkipsPhaseTwo')]
    public function testRunningUpTwiceIsANoOp(): void
    {
        $this->upWithOutput();
        $output = $this->upWithOutput();

        $this->assertFinishedLikeACleanRun();
        $this->assertStringContainsString('Phase 2 skipped', $output);
        $this->assertStringContainsString('Phase 1: inserted 0 profiles row(s) for user ID(s): none', $output);
        $this->runDown(self::MIGRATION);
    }

    /**
     * Phase 1 and phase 2 done, the old triggers and the car columns still there.
     */
    private function standAfterPhaseTwo(): void
    {
        $migration = $this->migration(self::MIGRATION);
        $this->callPrivate($migration, 'phaseOneSchemaAndBackfill');
        $this->callPrivate($migration, 'phaseTwoCopyFlags');
        $this->assertNotNull($this->columnInfo('cars', 'email_bounced'), 'The stand must keep the cars columns');
    }

    private function assertFinishedLikeACleanRun(): void
    {
        $this->assertSame(self::$clean, $this->profileSnapshot());
        foreach (['cars', 'cars_hist'] as $table) {
            foreach (['email_bounced', 'email_bounced_address', 'email_suppressed'] as $column) {
                $this->assertNull($this->columnInfo($table, $column), "{$table}.{$column} must be dropped");
            }
        }
        $this->assertTrue($this->hasIndex('profiles', 'uniq_profiles_user_id'));
        $bodies = $this->carsTriggerBodies();
        $this->assertCount(3, $bodies);
        foreach ($bodies as $body) {
            $this->assertStringNotContainsString('email_bounced', $body);
        }
    }
}
