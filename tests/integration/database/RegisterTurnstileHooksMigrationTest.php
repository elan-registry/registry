<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Migration 20260817033112_register_turnstile_hooks registers the 5 Turnstile hooks (#1679).
 */
#[Group('integration')]
#[Group('migration')]
final class RegisterTurnstileHooksMigrationTest extends IntegrationTestCase
{
    /** Mirrors the migration's private HOOKS const exactly: [page, position, hook]. */
    private const EXPECTED_HOOKS = [
        ['login.php', 'form', 'hooks/login_form_turnstile.php'],
        ['login.php', 'post', 'hooks/post_turnstile.php'],
        ['join.php', 'form', 'hooks/join_form_turnstile.php'],
        ['joinAttempt', 'body', 'hooks/post_turnstile.php'],
        ['forgot_password.php', 'post', 'hooks/post_turnstile.php'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $applied = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM phinxlog WHERE version = 20260817033112"
        )->first();

        if (!$applied || (int) $applied->cnt === 0) {
            $this->markTestSkipped(
                'Migration 20260817033112 has not been applied. Run: composer migrate'
            );
        }
    }

    #[Group('integration')]
    #[Group('migration')]
    public function test_allTurnstileHooksRegistered(): void
    {
        foreach (self::EXPECTED_HOOKS as [$page, $position, $hook]) {
            $row = $this->db->query(
                "SELECT position, disabled FROM us_plugin_hooks
                 WHERE page = '{$page}' AND folder = 'hooker' AND hook = '{$hook}'"
            )->first();

            $this->assertNotNull(
                $row,
                "Expected hook row missing: page={$page}, hook={$hook}"
            );
            $this->assertSame(
                $position,
                $row->position,
                "Wrong position for page={$page}, hook={$hook}"
            );
            $this->assertSame(
                0,
                (int) $row->disabled,
                "Hook should not be disabled: page={$page}, hook={$hook}"
            );
        }
    }

    /**
     * No duplicates from a non-idempotent re-run.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function test_noDuplicateHookRows(): void
    {
        foreach (self::EXPECTED_HOOKS as [$page, , $hook]) {
            $count = $this->db->query(
                "SELECT COUNT(*) AS cnt FROM us_plugin_hooks
                 WHERE page = '{$page}' AND folder = 'hooker' AND hook = '{$hook}'"
            )->first();

            $this->assertSame(
                1,
                (int) $count->cnt,
                "Expected exactly one row for page={$page}, hook={$hook}"
            );
        }
    }
}
