<?php

declare(strict_types=1);

require_once __DIR__ . '/MigrationSandboxTestCase.php';

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * up() and down() of migration 20261023000000_add_deleted_user_addresses_capture, in a sandbox (#2419).
 *
 * The sandbox starts with the migration applied. The tests share that state,
 * so a #[Depends] chain fixes their order. The state-changing tests (down,
 * then up) come last.
 */
#[Group('integration')]
#[Group('migration')]
final class AddDeletedUserAddressesSandboxTest extends MigrationSandboxTestCase
{
    private const MIGRATION = '20261023000000_add_deleted_user_addresses_capture.php';

    private const TABLE = 'er_deleted_user_addresses';

    private const TRIGGER = 'users_delete_capture_addresses';

    protected static function targetVersion(): int
    {
        return 20261023000000;
    }

    public function testTableColumnsAndTriggerExist(): void
    {
        $userId = $this->columnInfo(self::TABLE, 'user_id');
        $email = $this->columnInfo(self::TABLE, 'email');
        $emailNew = $this->columnInfo(self::TABLE, 'email_new');
        $deletedAt = $this->columnInfo(self::TABLE, 'deleted_at');
        $this->assertNotNull($userId, 'The sandbox must start with the migration applied');
        $this->assertNotNull($email);
        $this->assertNotNull($emailNew);
        $this->assertNotNull($deletedAt);

        $this->assertSame('int unsigned', strtolower($userId['COLUMN_TYPE']));
        $this->assertSame('NO', $userId['IS_NULLABLE']);
        $this->assertSame('YES', $email['IS_NULLABLE']);
        $this->assertSame('YES', $emailNew['IS_NULLABLE']);
        $this->assertSame('NO', $deletedAt['IS_NULLABLE']);
        $this->assertSame('datetime', strtolower($deletedAt['COLUMN_TYPE']));

        // The holding row must be able to hold any address that users can hold.
        $this->assertSame($this->columnInfo('users', 'email')['COLUMN_TYPE'] ?? null, $email['COLUMN_TYPE']);
        $this->assertSame($this->columnInfo('users', 'email_new')['COLUMN_TYPE'] ?? null, $emailNew['COLUMN_TYPE']);

        $primary = $this->pdo()->query(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . self::TABLE . "' AND INDEX_NAME = 'PRIMARY'"
        );
        $this->assertNotFalse($primary);
        $this->assertSame(['user_id'], $primary->fetchAll(\PDO::FETCH_COLUMN));

        $this->assertSame(
            1,
            $this->scalar(
                "SELECT COUNT(*) FROM information_schema.TRIGGERS
                  WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'users'
                    AND TRIGGER_NAME = ? AND EVENT_MANIPULATION = 'DELETE' AND ACTION_TIMING = 'AFTER'",
                [self::TRIGGER]
            ),
            'One AFTER DELETE trigger on users'
        );
    }

    #[Depends('testTableColumnsAndTriggerExist')]
    public function testARealDeleteWritesEmailAndEmailNew(): void
    {
        $this->insertUser(9101, 'gone-9101@example.test', 'pending-9101@example.test');

        $this->deleteUser(9101);

        $this->assertSame(
            ['email' => 'gone-9101@example.test', 'email_new' => 'pending-9101@example.test'],
            $this->holdingRow(9101)
        );
        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM er_deleted_user_addresses WHERE user_id = 9101 AND deleted_at IS NOT NULL'));
    }

    #[Depends('testARealDeleteWritesEmailAndEmailNew')]
    public function testTheTriggerFiresWhenDisableTriggersIsSet(): void
    {
        $this->insertUser(9102, 'gone-9102@example.test', 'pending-9102@example.test');

        $this->pdo()->exec('SET @disable_triggers = 1');
        try {
            $this->deleteUser(9102);
        } finally {
            $this->pdo()->exec('SET @disable_triggers = NULL');
        }

        $this->assertSame(
            ['email' => 'gone-9102@example.test', 'email_new' => 'pending-9102@example.test'],
            $this->holdingRow(9102),
            'A deletion must never skip the capture'
        );
    }

    #[Depends('testTheTriggerFiresWhenDisableTriggersIsSet')]
    public function testASecondDeleteOfTheSameIdUpsertsWithoutADuplicateKeyError(): void
    {
        $this->insertUser(9103, 'first-9103@example.test', 'first-pending-9103@example.test');
        $this->deleteUser(9103);
        $this->insertUser(9103, 'second-9103@example.test', 'second-pending-9103@example.test');

        $this->deleteUser(9103);

        $this->assertSame(1, $this->scalar('SELECT COUNT(*) FROM er_deleted_user_addresses WHERE user_id = 9103'));
        $this->assertSame(
            ['email' => 'second-9103@example.test', 'email_new' => 'second-pending-9103@example.test'],
            $this->holdingRow(9103),
            'The second delete replaces the first holding row'
        );
    }

    #[Depends('testASecondDeleteOfTheSameIdUpsertsWithoutADuplicateKeyError')]
    public function testANullEmailNewStaysNull(): void
    {
        $this->insertUser(9104, 'gone-9104@example.test', null);

        $this->deleteUser(9104);

        $this->assertSame(
            ['email' => 'gone-9104@example.test', 'email_new' => null],
            $this->holdingRow(9104)
        );
    }

    #[Depends('testANullEmailNewStaysNull')]
    public function testDownDropsTheTriggerAndTheTable(): void
    {
        $this->runDown(self::MIGRATION);

        $this->assertNotContains(self::TRIGGER, $this->sandbox()->triggerNames('users'), 'down() must drop the trigger');
        $this->assertSame(0, $this->scalar(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [self::TABLE]
        ), 'down() must drop the table');

        // With the trigger gone, a user delete must still work.
        $this->insertUser(9105, 'gone-9105@example.test', null);
        $this->deleteUser(9105);
    }

    #[Depends('testDownDropsTheTriggerAndTheTable')]
    public function testUpAfterDownCreatesTheTableAndTheTriggerAgain(): void
    {
        $this->runUp(self::MIGRATION);

        $this->insertUser(9106, 'gone-9106@example.test', 'pending-9106@example.test');
        $this->deleteUser(9106);

        $this->assertSame(
            ['email' => 'gone-9106@example.test', 'email_new' => 'pending-9106@example.test'],
            $this->holdingRow(9106)
        );
    }

    private function insertUser(int $id, string $email, ?string $emailNew): void
    {
        $this->pdo()->prepare(
            'INSERT INTO users (id, permissions, email, email_new, username, created) VALUES (?, 1, ?, ?, ?, NOW())'
        )->execute([$id, $email, $emailNew, 'sandbox_user_' . $id]);
    }

    private function deleteUser(int $id): void
    {
        $statement = $this->pdo()->prepare('DELETE FROM users WHERE id = ?');
        $statement->execute([$id]);
        $this->assertSame(1, $statement->rowCount(), 'Test setup: the users row must exist');
    }

    /**
     * @return array{email: ?string, email_new: ?string}
     */
    private function holdingRow(int $userId): array
    {
        $statement = $this->pdo()->prepare('SELECT email, email_new FROM er_deleted_user_addresses WHERE user_id = ?');
        $statement->execute([$userId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row, "No holding row for user {$userId}");

        /** @var array{email: ?string, email_new: ?string} $row */
        return $row;
    }
}
