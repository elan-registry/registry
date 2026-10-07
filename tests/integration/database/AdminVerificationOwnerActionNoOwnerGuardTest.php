<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\Exceptions\ElanRegistryException;
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
     * Load verifyHistoryFieldsForAdminAction() and sendEmailOwnerLabel()
     * from app/admin/index.php — both are plain, function_exists()-guarded
     * functions the extracted handler slice below calls.
     */
    private static function loadAdminIndexHelperFunctions(): void
    {
        if (function_exists('verifyHistoryFieldsForAdminAction') && function_exists('sendEmailOwnerLabel')) {
            return;
        }

        $scriptPath = __DIR__ . '/../../../app/admin/index.php';
        $source = file_get_contents($scriptPath);
        if ($source === false) {
            throw new RuntimeException('Could not read ' . $scriptPath);
        }

        $startMarker = "if (!function_exists('verifyHistoryFieldsForAdminAction'))";
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

        $preamble = "<?php\ndeclare(strict_types=1);\n"
            . "use ElanRegistry\\AppConstants;\n"
            . "use ElanRegistry\\Exceptions\\OwnerDatabaseException;\n"
            . "use ElanRegistry\\Owner;\n";
        $written = file_put_contents($tempFile, $preamble . $slice);
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

    #[DataProvider('commandProvider')]
    #[Group('fast')]
    public function testNoOwnerCarIsRejectedAndLeavesOtherNoOwnerCarsUnchanged(string $command): void
    {
        $noOwnerId = $this->noOwnerAccountId();

        $targetCarId = $this->createTestCar($noOwnerId, [
            'email' => 'noowner@invalid',
            'email_bounced' => $command === 'clear_bounced' ? 1 : 0,
            'email_suppressed' => $command === 'clear_suppression' ? 1 : 0,
        ]);
        // A second, unrelated noowner car: proves a bad guard would have
        // reached this one too, not just the targeted car.
        $bystanderCarId = $this->createTestCar($noOwnerId, [
            'email' => 'noowner@invalid',
            'email_bounced' => 0,
            'email_suppressed' => 0,
        ]);

        $result = $this->runOwnerActionHandler($command, $targetCarId);

        $this->assertNotEmpty($result['errors'], "'{$command}' on a noowner-owned car must be rejected");
        $this->assertEmpty($result['successes'], "'{$command}' on a noowner-owned car must not report success");

        $bystanderRow = $this->repo->findById($bystanderCarId);
        $this->assertSame(
            0,
            (int) $bystanderRow->email_bounced,
            "A rejected '{$command}' must not have flagged the unrelated bystander noowner car as bounced"
        );
        $this->assertSame(
            0,
            (int) $bystanderRow->email_suppressed,
            "A rejected '{$command}' must not have flagged the unrelated bystander noowner car as suppressed"
        );
    }

    #[Group('fast')]
    public function testMarkBouncedOnARealOwnerStillSucceeds(): void
    {
        // withProfile: true — CarVerificationManager::setBouncedForOwner()
        // requires a profiles row (it aborts with no profiles row at all,
        // same as it would for an owner missing one for any other reason).
        $userId = $this->createTestUser([], true);
        $carId = $this->createTestCar($userId, ['email' => 'owner-action-real@example.com', 'email_bounced' => 0]);

        $result = $this->runOwnerActionHandler('mark_bounced', $carId);

        $this->assertEmpty(
            $result['errors'],
            'mark_bounced for a real owner must not be rejected: ' . implode('; ', $result['errors'])
        );
        $this->assertNotEmpty($result['successes'], 'mark_bounced for a real owner must still succeed');

        $row = $this->repo->findById($carId);
        $this->assertSame(1, (int) $row->email_bounced, 'The real owner\'s car must actually be marked bounced');
    }
}
