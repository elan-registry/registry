<?php

declare(strict_types=1);

require_once __DIR__ . '/MigrationSandboxTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Round trip of the er_verification_settings counter rename (#2085), in a sandbox (#2167).
 *
 * The sandbox stops at the version before the rename, so up(), down() and up()
 * run against the old column name first.
 */
#[Group('integration')]
#[Group('migration')]
final class RenameErVerificationSettingsUnmatchedCounterMigrationTest extends MigrationSandboxTestCase
{
    private const MIGRATION_FILE = '20260916000002_rename_er_verification_settings_unmatched_counter.php';

    private const TABLE = 'er_verification_settings';

    private const OLD_COLUMN = 'unmatched_webhook_recipient_count';

    private const NEW_COLUMN = 'unmatched_recipient_count';

    private const OLD_COMMENT = 'Count of inbound Brevo webhook events whose recipient matched no car.';

    private const NEW_COMMENT = 'Count of inbound Brevo signals (webhook events, '
        . 'reconciliation events, suppression-list contacts) whose recipient matched no car.';

    /** A value that no default or seed sets, so a lost value is visible. */
    private const COUNTER_VALUE = 4242;

    protected static function targetVersion(): int
    {
        return 20260916000001;
    }

    public function testUpDownUpPreservesValueAndSwapsComment(): void
    {
        $this->assertNotNull(
            $this->columnInfo(self::TABLE, self::OLD_COLUMN),
            'The sandbox must start with the old column name'
        );

        $this->pdo()->prepare(
            'INSERT INTO `' . self::TABLE . '` (`id`, `enabled`, `' . self::OLD_COLUMN . '`) VALUES (1, 0, ?)
             ON DUPLICATE KEY UPDATE `' . self::OLD_COLUMN . '` = ?'
        )->execute([self::COUNTER_VALUE, self::COUNTER_VALUE]);

        $this->runUp(self::MIGRATION_FILE);
        $this->assertRenamedTo(self::NEW_COLUMN, self::OLD_COLUMN, self::NEW_COMMENT, 'after up()');

        $this->runDown(self::MIGRATION_FILE);
        $this->assertRenamedTo(self::OLD_COLUMN, self::NEW_COLUMN, self::OLD_COMMENT, 'after down()');

        $this->runUp(self::MIGRATION_FILE);
        $this->assertRenamedTo(self::NEW_COLUMN, self::OLD_COLUMN, self::NEW_COMMENT, 'after the second up()');
    }

    private function assertRenamedTo(string $present, string $absent, string $comment, string $stage): void
    {
        $info = $this->columnInfo(self::TABLE, $present);
        $this->assertNotNull($info, "Column {$present} must exist {$stage}");
        $this->assertSame($comment, $info['COLUMN_COMMENT'], "Column {$present} comment {$stage}");
        $this->assertSame('int unsigned', $info['COLUMN_TYPE'], "Column {$present} type {$stage}");
        $this->assertSame('NO', $info['IS_NULLABLE'], "Column {$present} nullability {$stage}");
        $this->assertSame('0', $info['COLUMN_DEFAULT'], "Column {$present} default {$stage}");

        $this->assertNull($this->columnInfo(self::TABLE, $absent), "Column {$absent} must not exist {$stage}");

        $value = $this->pdo()->query('SELECT `' . $present . '` FROM `' . self::TABLE . '` WHERE `id` = 1');
        $this->assertNotFalse($value);
        $this->assertSame(self::COUNTER_VALUE, (int) $value->fetchColumn(), "Counter value {$stage}");
    }
}
