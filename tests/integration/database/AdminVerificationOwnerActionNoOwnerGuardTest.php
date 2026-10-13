<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\Exceptions\ElanRegistryException;
use ElanRegistry\LogCategories;
use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration (real MySQL) test for the `noowner` guard in the
 * mark_bounced/clear_bounced/clear_suppression POST handler of
 * app/admin/index.php (#1896 Blocking fix).
 *
 * Before the fix, these three commands acted on the whole owner
 * (CarVerificationManager::*ForOwner()) with no check that the owner was a
 * real, individual account. A row for a car owned by the `noowner` system
 * account (the GDPR-erasure reassignment target, which can hold many
 * unrelated cars) would flag or clear every car that account holds from a
 * single click.
 *
 * The handler is not a standalone function — it is a `case` block inside a
 * large `switch` in app/admin/index.php, entangled with `hasPerm()`,
 * `$errors`/`$successes`, and other page-level state. This test extracts
 * the relevant inner block (same source-slice + temp-file pattern
 * AdminVerificationQueueUrlStateTest uses for the simpler URL-state block)
 * — everything from the owner lookup through the three catch clauses,
 * skipping only the outer admin-permission check, which is unrelated to
 * this guard and covered elsewhere. If the source is restructured so the
 * markers move, extraction throws with a message naming the file to update.
 */
#[Group('integration')]
final class AdminVerificationOwnerActionNoOwnerGuardTest extends IntegrationTestCase
{
    private CarRepository $repo;
    private CarVerificationManager $manager;
    private int $actingUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->repo = new CarRepository($this->db);
        $this->manager = new CarVerificationManager($this->repo);
        $this->actingUserId = $this->createTestUser();
        self::loadAdminIndexHelperFunctions();
    }

    /**
     * Load sendEmailOwnerLabel() from app/admin/index.php. It is a plain,
     * function_exists()-guarded function that the extracted handler slice
     * below calls.
     */
    private static function loadAdminIndexHelperFunctions(): void
    {
        if (function_exists('sendEmailOwnerLabel')) {
            return;
        }

        $scriptPath = __DIR__ . '/../../../app/admin/index.php';
        $source = file_get_contents($scriptPath);
        if ($source === false) {
            throw new RuntimeException('Could not read ' . $scriptPath);
        }

        $startMarker = "if (!function_exists('sendEmailOwnerLabel'))";
        $endMarker = '// Process form submissions for car management tab';

        $startPos = strpos($source, $startMarker);
        $endPos = strpos($source, $endMarker);

        if ($startPos === false || $endPos === false || $endPos <= $startPos) {
            throw new RuntimeException(
                'Could not locate the admin/index.php helper functions in ' . $scriptPath
                . ' — the script may have been restructured; update loadAdminIndexHelperFunctions() to match.'
            );
        }

        $slice = substr($source, $startPos, $endPos - $startPos);

        $tempFile = tempnam(sys_get_temp_dir(), 'adminIndexHelpers_');
        if ($tempFile === false) {
            throw new RuntimeException('Could not create temp file for admin/index.php helper extraction');
        }

        $written = file_put_contents($tempFile, "<?php\ndeclare(strict_types=1);\n" . $slice);
        if ($written === false) {
            unlink($tempFile);
            throw new RuntimeException('Could not write extracted admin/index.php helper source to temp file');
        }

        try {
            require $tempFile;
        } finally {
            unlink($tempFile);
        }
    }

    /** The `noowner` system account's id, looked up by username (same query findNoOwnerAccountId() uses). */
    private function noOwnerAccountId(): int
    {
        $result = $this->db->query('SELECT id FROM users WHERE username = ? LIMIT 1', ['noowner']);
        $this->assertFalse($result->error(), 'Test setup: lookup of the noowner account failed');
        $this->assertSame(1, $result->count(), 'Test setup: the noowner system account must exist (seeded by migration)');
        $row = $result->first();

        return (int) $row->id;
    }

    /**
     * @return array{errors: list<string>, successes: list<string>}
     */
    private function runOwnerActionHandler(string $command, int $carId): array
    {
        $scriptPath = __DIR__ . '/../../../app/admin/index.php';
        $source = file_get_contents($scriptPath);
        if ($source === false) {
            throw new RuntimeException('Could not read ' . $scriptPath);
        }

        $startMarker = '$verifyCarId = (int) ElanInput::get(\'car_id\');';
        $endMarker = "                    break;\n            }\n        }\n    }";

        $startPos = strpos($source, $startMarker);
        $endPos = strpos($source, $endMarker, $startPos === false ? 0 : $startPos);

        if ($startPos === false || $endPos === false || $endPos <= $startPos) {
            throw new RuntimeException(
                'Could not locate the owner-action handler block in ' . $scriptPath
                . ' — the script may have been restructured; update runOwnerActionHandler() to match.'
            );
        }

        $slice = substr($source, $startPos, $endPos - $startPos);

        $tempFile = tempnam(sys_get_temp_dir(), 'adminIndexOwnerAction_');
        if ($tempFile === false) {
            throw new RuntimeException('Could not create temp file for owner-action handler extraction');
        }

        $preamble = "<?php\n"
            . "declare(strict_types=1);\n"
            . "use ElanRegistry\\Exceptions\\CarDatabaseException;\n"
            . "use ElanRegistry\\Exceptions\\CarValidationException;\n"
            . "use ElanRegistry\\Exceptions\\ElanRegistryException;\n"
            . "use ElanRegistry\\Input as ElanInput;\n"
            . "use ElanRegistry\\LogCategories;\n"
            . "use ElanRegistry\\Owner;\n"
            . "\$errors = [];\n"
            . "\$successes = [];\n"
            // The slice's early-exit `break;` statements (findById threw, car
            // not found) are written for the enclosing `switch` in the real
            // file, not a loop. Reproduce that exact context here so they
            // resolve the same way, instead of a fatal "'break' not in the
            // 'loop' or 'switch' context".
            . "switch (true) {\ncase true:\n";
        $returnStmt = "\nbreak;\n}\n"
            . "return ['errors' => \$errors, 'successes' => \$successes];\n";
        $written = file_put_contents($tempFile, $preamble . $slice . $returnStmt);
        if ($written === false) {
            unlink($tempFile);
            throw new RuntimeException('Could not write extracted owner-action handler source to temp file');
        }

        // The slice's own first line re-reads $verifyCarId from
        // ElanInput::get('car_id'), which reads $_POST the same way the real
        // request does — set that instead of a local $verifyCarId, which
        // the slice would just overwrite.
        $_POST['car_id'] = (string) $carId;
        $currentUserId   = $this->actingUserId;
        $verificationRepo = $this->repo;
        $verificationManager = $this->manager;

        try {
            return require $tempFile;
        } finally {
            unlink($tempFile);
            unset($_POST['car_id']);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function commandProvider(): array
    {
        return [
            'mark_bounced' => ['mark_bounced'],
            'clear_bounced' => ['clear_bounced'],
            'clear_suppression' => ['clear_suppression'],
        ];
    }

    /**
     * Flag-change log rows for an owner, newest first, read through the
     * repository reader (real logs table).
     *
     * @return list<array{date: string, flag: string, old: int, new: int, source: string, acting_user_id: int}>
     */
    private function flagLogRows(int $ownerId): array
    {
        return $this->repo->recentFlagChanges($ownerId, 100);
    }

    private function flagLogRowCount(int $ownerId): int
    {
        return count($this->flagLogRows($ownerId));
    }

    /**
     * The lognote text of the flag-change rows for an owner. lognote starts
     * with "Owner <id> ", so this LIKE is exact for ids of equal length.
     */
    private function flagLogNotes(int $ownerId): string
    {
        $rows = $this->db->query(
            'SELECT lognote FROM logs WHERE logtype = ? AND lognote LIKE ?',
            [LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, "Owner {$ownerId} %"]
        )->results();

        return implode("\n", array_map(static fn (object $r): string => (string) $r->lognote, $rows));
    }

    #[DataProvider('commandProvider')]
    #[Group('fast')]
    public function testNoOwnerCarIsRejectedAndLeavesTheNoOwnerProfileUnchanged(string $command): void
    {
        $noOwnerId = $this->noOwnerAccountId();

        // The noowner account may have no profile row. Give it one with the
        // flags that the command would change, so that a missing guard would
        // change a real row. Restore or delete it afterwards.
        $existing = $this->db->query('SELECT id FROM profiles WHERE user_id = ?', [$noOwnerId])->first();
        $createdProfile = false;
        if (!$existing) {
            $this->assertTrue($this->db->insert('profiles', [
                'user_id' => $noOwnerId,
                'bio' => '',
                'city' => '',
                'state' => '',
                'country' => '',
                'email_address_since' => date('Y-m-d H:i:s'),
                'email_address_seen' => 'noowner@invalid',
            ]), 'Test setup: could not create a noowner profile: ' . $this->db->errorString());
            $createdProfile = true;
        }
        $original = $this->getOwnerEmailFlags($noOwnerId);

        try {
            $this->clearOwnerEmailFlags($noOwnerId);
            if ($command === 'clear_bounced') {
                $this->setOwnerBounced($noOwnerId, 'noowner@invalid');
            } elseif ($command === 'clear_suppression') {
                $this->setOwnerBlocked($noOwnerId, 'noowner@invalid');
                $this->setOwnerOptedOut($noOwnerId);
            }
            $seeded = $this->getOwnerEmailFlags($noOwnerId);
            $logRowsBefore = $this->flagLogRowCount($noOwnerId);

            $targetCarId = $this->createTestCar($noOwnerId, ['email' => 'noowner@invalid']);

            $result = $this->runOwnerActionHandler($command, $targetCarId);

            $this->assertNotEmpty($result['errors'], "'{$command}' on a noowner-owned car must be rejected");
            $this->assertEmpty($result['successes'], "'{$command}' on a noowner-owned car must not report success");
            $this->assertSame(
                $seeded,
                $this->getOwnerEmailFlags($noOwnerId),
                "A rejected '{$command}' must not change the noowner profile"
            );
            $this->assertSame(
                $logRowsBefore,
                $this->flagLogRowCount($noOwnerId),
                "A rejected '{$command}' must not write a flag-change log row"
            );
        } finally {
            if ($createdProfile) {
                $this->db->query('DELETE FROM profiles WHERE user_id = ?', [$noOwnerId]);
            } else {
                $columns = array_keys($original);
                $set = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", $columns));
                $this->db->query(
                    "UPDATE profiles SET {$set} WHERE user_id = ?",
                    [...array_values($original), $noOwnerId]
                );
            }
        }
    }

    #[Group('fast')]
    public function testMarkBouncedOnARealOwnerWritesOnlyTheProfileAndOneAdminLogRow(): void
    {
        $userId = $this->createTestUser(['email' => 'Owner-Action-Real@Example.com'], true);
        $carId = $this->createTestCar($userId, ['email' => 'owner-action-car@example.com']);
        $carBefore = (array) $this->repo->findById($carId);

        $result = $this->runOwnerActionHandler('mark_bounced', $carId);

        $this->assertEmpty(
            $result['errors'],
            'mark_bounced for a real owner must not be rejected: ' . implode('; ', $result['errors'])
        );
        $this->assertNotEmpty($result['successes'], 'mark_bounced for a real owner must still succeed');

        $flags = $this->getOwnerEmailFlags($userId);
        $this->assertSame('1', (string) $flags['email_bounced'], 'The owner profile must be marked bounced');
        $this->assertSame(
            'owner-action-real@example.com',
            $flags['email_bounced_address'],
            'The address is the owner\'s users.email, normalized, not the car email'
        );
        $this->assertNotNull($flags['email_bounced_at']);
        $this->assertSame($carBefore, (array) $this->repo->findById($carId), 'The car row must not change');

        $logRows = $this->flagLogRows($userId);
        $this->assertCount(1, $logRows, 'One flag change writes exactly one log row');
        $this->assertSame('email_bounced', $logRows[0]['flag']);
        $this->assertSame(0, $logRows[0]['old']);
        $this->assertSame(1, $logRows[0]['new']);
        $this->assertSame('admin', $logRows[0]['source']);
        $this->assertSame($this->actingUserId, $logRows[0]['acting_user_id'], 'The log row names the acting admin');
        $notes = $this->flagLogNotes($userId);
        $this->assertNotSame('', $notes, 'Test setup: the lognote text must exist');
        $this->assertStringNotContainsString('@', $notes, 'The log must hold no email address');
    }

    /** A bounce stored for an old address does not apply now, so Mark Bounced is a change. */
    #[Group('fast')]
    public function testMarkBouncedReportsAChangeWhenTheStoredBounceIsForAnOldAddress(): void
    {
        $userId = $this->createTestUser(['email' => 'owner-action-old@example.com'], true);
        $this->setOwnerBounced($userId, 'owner-action-old@example.com', '2026-01-01 00:00:00');
        $this->db->query('UPDATE users SET email = ? WHERE id = ?', ['owner-action-new@example.com', $userId]);
        $carId = $this->createTestCar($userId, ['email' => 'owner-action-new@example.com']);

        $result = $this->runOwnerActionHandler('mark_bounced', $carId);

        $this->assertEmpty($result['errors'], 'mark_bounced must succeed: ' . implode('; ', $result['errors']));
        $this->assertCount(1, $result['successes']);
        $this->assertStringEndsWith(': marked as bounced.', $result['successes'][0], 'The admin sees a change, not "already marked"');
        $this->assertSame('owner-action-new@example.com', $this->getOwnerEmailFlags($userId)['email_bounced_address']);
        $logRows = $this->flagLogRows($userId);
        $this->assertCount(1, $logRows, 'The change writes one flag-change log row');
        $this->assertSame(0, $logRows[0]['old']);
        $this->assertSame(1, $logRows[0]['new']);
    }

    #[Group('fast')]
    public function testClearBouncedOnARealOwnerClearsOnlyTheBounceAndRecordsTheClearTime(): void
    {
        $userId = $this->createTestUser([], true);
        $this->setOwnerBounced($userId, 'owner-clear@example.com');
        $this->setOwnerOptedOut($userId);
        $carId = $this->createTestCar($userId, ['email' => 'owner-clear@example.com']);

        $result = $this->runOwnerActionHandler('clear_bounced', $carId);

        $this->assertEmpty($result['errors'], 'clear_bounced must succeed: ' . implode('; ', $result['errors']));
        $flags = $this->getOwnerEmailFlags($userId);
        $this->assertSame('0', (string) $flags['email_bounced']);
        $this->assertNull($flags['email_bounced_address']);
        $this->assertNotNull($flags['email_bounce_cleared_at'], 'Clear Bounced records when it cleared');
        $this->assertSame('1', (string) $flags['email_opted_out'], 'Clear Bounced must leave the opt-out');

        $logRows = $this->flagLogRows($userId);
        $this->assertCount(1, $logRows);
        $this->assertSame('email_bounced', $logRows[0]['flag']);
        $this->assertSame(1, $logRows[0]['old']);
        $this->assertSame(0, $logRows[0]['new']);
        $this->assertSame('admin', $logRows[0]['source']);
    }

    #[Group('fast')]
    public function testClearSuppressionOnARealOwnerClearsBlockAndOptOutAndTouchesNoCar(): void
    {
        $userId = $this->createTestUser([], true);
        $this->setOwnerBlocked($userId, 'owner-supp@example.com', null, 'spam');
        $this->setOwnerOptedOut($userId);
        $this->setOwnerBounced($userId, 'owner-supp@example.com');
        $carId = $this->createTestCar($userId, ['email' => 'owner-supp@example.com']);
        $carBefore = (array) $this->repo->findById($carId);

        $result = $this->runOwnerActionHandler('clear_suppression', $carId);

        $this->assertEmpty($result['errors'], 'clear_suppression must succeed: ' . implode('; ', $result['errors']));
        $flags = $this->getOwnerEmailFlags($userId);
        $this->assertSame('0', (string) $flags['email_blocked']);
        $this->assertNull($flags['email_blocked_address']);
        $this->assertNull($flags['email_blocked_cause']);
        $this->assertSame('0', (string) $flags['email_opted_out']);
        $this->assertSame('1', (string) $flags['email_bounced'], 'Clear Suppression must leave the bounce');
        $this->assertSame($carBefore, (array) $this->repo->findById($carId), 'The car row must not change');
        $this->assertCount(2, $this->flagLogRows($userId), 'One row for the block and one for the opt-out');
    }
}
