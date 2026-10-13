<?php

declare(strict_types=1);

require_once __DIR__ . '/MigrationSandboxTestCase.php';

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * up() and down() of migration 20261012000001_add_opted_out_to_deleted_accounts_archive, in a sandbox (#2101).
 *
 * The sandbox starts with the migration applied. The tests share that state,
 * so a #[Depends] chain fixes their order.
 */
#[Group('integration')]
#[Group('migration')]
final class AddOptedOutToDeletedAccountsArchiveSandboxTest extends MigrationSandboxTestCase
{
    private const MIGRATION = '20261012000001_add_opted_out_to_deleted_accounts_archive.php';

    private const TABLE = 'deleted_accounts_archive';

    protected static function targetVersion(): int
    {
        return 20261012000001;
    }

    public function testTheColumnsMatchTheProfilesColumnsAndOldRowsGetTheDefaults(): void
    {
        $optedOut = $this->columnInfo(self::TABLE, 'email_opted_out');
        $optedOutAt = $this->columnInfo(self::TABLE, 'email_opted_out_at');
        $this->assertNotNull($optedOut, 'The sandbox must start with the migration applied');
        $this->assertNotNull($optedOutAt);

        $this->assertSame('tinyint(1)', strtolower($optedOut['COLUMN_TYPE']));
        $this->assertSame('NO', $optedOut['IS_NULLABLE'], 'A restore must never read NULL as "not opted out" by accident');
        $this->assertSame('0', $optedOut['COLUMN_DEFAULT']);
        $this->assertSame('datetime', strtolower($optedOutAt['COLUMN_TYPE']));
        $this->assertSame('YES', $optedOutAt['IS_NULLABLE']);
        $this->assertNull($optedOutAt['COLUMN_DEFAULT']);

        // A row written without the new columns (the old archive code) reads as not opted out.
        $this->insertArchiveRow(901, null);
        $this->assertSame(0, $this->scalar('SELECT email_opted_out FROM deleted_accounts_archive WHERE original_user_id = 901'));
        $this->assertNull($this->archiveOptedOutAt(901));

        // A row with an opt-out keeps both values.
        $this->insertArchiveRow(902, '2026-06-01 11:00:00');
        $this->assertSame(1, $this->scalar('SELECT email_opted_out FROM deleted_accounts_archive WHERE original_user_id = 902'));
        $this->assertSame('2026-06-01 11:00:00', $this->archiveOptedOutAt(902));
    }

    #[Depends('testTheColumnsMatchTheProfilesColumnsAndOldRowsGetTheDefaults')]
    public function testDownDropsBothColumnsAndKeepsTheRows(): void
    {
        $this->runDown(self::MIGRATION);

        $this->assertNull($this->columnInfo(self::TABLE, 'email_opted_out'), 'down() must drop email_opted_out');
        $this->assertNull($this->columnInfo(self::TABLE, 'email_opted_out_at'), 'down() must drop email_opted_out_at');
        $this->assertSame(2, $this->scalar('SELECT COUNT(*) FROM deleted_accounts_archive WHERE original_user_id IN (901, 902)'));
    }

    #[Depends('testDownDropsBothColumnsAndKeepsTheRows')]
    public function testUpAgainAddsTheColumnsAndExistingRowsReadAsNotOptedOut(): void
    {
        $this->runUp(self::MIGRATION);

        $this->assertNotNull($this->columnInfo(self::TABLE, 'email_opted_out'));
        $this->assertNotNull($this->columnInfo(self::TABLE, 'email_opted_out_at'));
        $this->assertSame(
            0,
            $this->scalar('SELECT SUM(email_opted_out) FROM deleted_accounts_archive WHERE original_user_id IN (901, 902)'),
            'The down() loses the opt-out values: both rows read as not opted out'
        );
        $this->assertNull($this->archiveOptedOutAt(902));
    }

    private function insertArchiveRow(int $originalUserId, ?string $optedOutAt): void
    {
        $columns = 'original_user_id, deleted_by, deleted_at, deletion_type';
        $marks = '?, 1, ?, ?';
        $params = [$originalUserId, '2026-07-01 00:00:00', 'verified'];
        if ($optedOutAt !== null) {
            $columns .= ', email_opted_out, email_opted_out_at';
            $marks .= ', 1, ?';
            $params[] = $optedOutAt;
        }
        $this->pdo()->prepare("INSERT INTO deleted_accounts_archive ({$columns}) VALUES ({$marks})")->execute($params);
    }

    private function archiveOptedOutAt(int $originalUserId): ?string
    {
        $statement = $this->pdo()->prepare('SELECT email_opted_out_at FROM deleted_accounts_archive WHERE original_user_id = ?');
        $statement->execute([$originalUserId]);
        $value = $statement->fetchColumn();
        $this->assertNotFalse($value, 'The archive row must exist');

        return $value === null ? null : (string) $value;
    }
}
