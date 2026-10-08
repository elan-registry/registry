<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * #1679: migration 20260817033111 is the only source of ElanRegistry's
 * defaults on the install wizard's `settings` row (id=1).
 */
#[Group('integration')]
#[Group('migration')]
final class UpdateSettingsBaselineDefaultsMigrationTest extends IntegrationTestCase
{
    /** Column => expected value; mirrors the migration's UPDATE statement. */
    private const EXPECTED = [
        'site_name' => 'Lotus Elan Registry',
        'template' => 'customizer',
        'copyright' => 'Lotus Elan Registry and UniBrain',
        'navigation_type' => 0,
        'elan_image_dir' => 'userimages/',
        'elan_image_max' => 6,
        'permission_restriction' => 1,
        'session_manager' => 1,
        'recaptcha' => 0,
        'req_cap' => 1,
        'req_num' => 1,
        'email_login' => 2,
        'min_pw' => 5,
        'max_pw' => 32,
        'min_un' => 5,
        'max_un' => 30,
        'pwl_length' => 5,
        'redirect_uri_after_login' => 'users/account.php',
        'registration' => 1,
        'join_vericode_expiry' => 24,
        'change_un' => 0,
        'reset_vericode_expiry' => 120,
        'err_time' => 20,
        'container_open_class' => 'container-fluid',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $applied = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM phinxlog WHERE version = 20260817033111"
        )->first();

        if (!$applied || (int) $applied->cnt === 0) {
            $this->markTestSkipped(
                'Migration 20260817033111 has not been applied. Run: composer migrate'
            );
        }
    }

    #[Group('integration')]
    #[Group('migration')]
    public function test_settingsRowOneHasBaselineDefaults(): void
    {
        $columns = implode(', ', array_map(
            static fn(string $col): string => "`{$col}`",
            array_keys(self::EXPECTED)
        ));

        $row = $this->db->query(
            "SELECT {$columns} FROM settings WHERE id = 1"
        )->first();

        $this->assertNotNull($row, 'settings row id=1 must exist');

        foreach (self::EXPECTED as $column => $expected) {
            $this->assertEquals(
                $expected,
                $row->$column,
                "settings.{$column} did not match the baseline default written by the migration"
            );
        }
    }
}
