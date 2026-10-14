<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\LogCategories;
use ElanRegistry\Migration\EmailEventRekeyQueries;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2358: 29-Report-Email-Event-Rekey.php writes nothing.
 *
 * The page runs as an admin in its own `php -r` subprocess. The test compares
 * table checksums before and after. The shared test schema is already migrated,
 * so the page prints "already migrated". The counts and ID lists have their
 * own tests on a pre-migration sandbox (EmailEventRekeyQueriesTest).
 */
#[Group('integration')]
#[Group('admin')]
final class EmailEventRekeyReportReadOnlyTest extends IntegrationTestCase
{
    private const SCRIPT = 'app/admin/scripts/maintenance/29-Report-Email-Event-Rekey.php';

    /** Tables that the report could change. */
    private const TABLES = ['er_email_events', 'cars', 'cars_hist', 'users', 'profiles', 'fix_script_runs'];

    private const PERMISSION_ADMIN = 2;

    private int $adminId = 0;
    private int $permissionMatchId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // securePage() treats `users.permissions = 0` as a banned account.
        $this->adminId = $this->createTestUser(['permissions' => 1]);
        $this->db->insert('user_permission_matches', ['user_id' => $this->adminId, 'permission_id' => self::PERMISSION_ADMIN]);
        $this->assertFalse($this->db->error(), 'Test setup: grant permission: ' . $this->db->errorString());
        $this->permissionMatchId = (int) $this->db->lastId();
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            $this->db->query('DELETE FROM user_permission_matches WHERE id = ?', [$this->permissionMatchId]);
            $this->db->query('DELETE FROM logs WHERE user_id = ?', [$this->adminId]);
        }
        parent::tearDown();
    }

    public function testTheReportChangesNoRowInTheTablesItCouldTouch(): void
    {
        $ownerId = $this->createTestUser(['email' => 'rekey-ro-' . uniqid() . '@example.com'], true);
        $carId = $this->createTestCar($ownerId, ['email' => 'rekey-ro-car-' . uniqid() . '@example.com']);
        $this->insertEvent($ownerId, $carId, 'sent', 'local:ro-1');
        $this->insertEvent($ownerId, null, 'delivered', 'ro-2');

        $before = $this->checksums();
        $logsBefore = $this->reportLogCount();

        $result = $this->invokeEndpoint(self::SCRIPT, [], $this->adminId);

        $this->assertSame(0, $result['exitCode'], substr($result['raw'], 0, 500));
        // The control: the page ran as the admin and printed its report. Without it,
        // an unchanged checksum could mean that the page never ran.
        $this->assertStringContainsString('Email Event Re-key Report', $result['raw']);
        $this->assertStringContainsString('Already migrated', $result['raw']);
        $this->assertSame($before, $this->checksums(), 'The report must not write to any table');
        $this->assertSame($logsBefore, $this->reportLogCount(), 'The report writes no log line on success');
    }

    public function testTheQueriesReadTheKeyedSchemaThroughTheDatabaseInterface(): void
    {
        $email = 'rekey-ro2-' . uniqid() . '@example.com';
        $ownerId = $this->createTestUser(['email' => $email], true);
        $carId = $this->createTestCar($ownerId);
        $sentId = $this->insertEvent($ownerId, $carId, 'sent', 'local:ro-3', $email);

        $queries = new EmailEventRekeyQueries(dbi());

        $this->assertTrue($queries->isRekeyed(), 'The test schema is keyed by user');
        $before = $this->checksums();
        $assignments = $queries->assignments();
        $this->assertSame($before, $this->checksums(), 'The reads must not write');
        $this->assertArrayHasKey($sentId, $assignments);
        $this->assertSame($ownerId, $assignments[$sentId]['user_id']);
    }

    public function testTheScriptAndTheQueriesHoldNoWriteStatement(): void
    {
        foreach ([
            __DIR__ . '/../../app/admin/scripts/maintenance/29-Report-Email-Event-Rekey.php',
            __DIR__ . '/../../usersc/classes/Migration/EmailEventRekeyQueries.php',
        ] as $file) {
            $source = (string) file_get_contents($file);
            $this->assertNotSame('', $source);
            $this->assertSame(
                0,
                preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|ALTER\s+TABLE|DROP\s+|TRUNCATE|->insert\(|->update\(|->delete\()/i', $source),
                basename($file) . ' must hold no write statement'
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function checksums(): array
    {
        $sums = [];
        foreach (self::TABLES as $table) {
            $row = $this->db->query("CHECKSUM TABLE {$table}")->first();
            $this->assertIsObject($row, "CHECKSUM TABLE {$table} must return a row");
            $sums[$table] = (string) $row->Checksum;
        }

        return $sums;
    }

    private function reportLogCount(): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS cnt FROM logs WHERE user_id = ? AND logtype IN (?, ?)',
            [$this->adminId, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR, LogCategories::LOG_CATEGORY_SECURITY]
        )->first()->cnt;
    }

    private function insertEvent(int $userId, ?int $carId, string $event, string $messageId, string $email = 'ro@example.com'): int
    {
        $this->db->query(
            'INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, ?, ?, ?, NULL, ?, ?)',
            [$userId, $carId, $email, $event, $messageId, '2026-05-01 10:00:00']
        );
        $this->assertFalse($this->db->error(), 'Test setup: ' . $this->db->errorString());

        return (int) $this->db->lastId();
    }
}
