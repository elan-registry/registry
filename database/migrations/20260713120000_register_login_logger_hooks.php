<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RegisterLoginLoggerHooks extends AbstractMigration
{
    // Mirrors commented-out logger() calls in users/login.php that UserSpice ships disabled.
    // Each entry: [page-event, position, hook-filename]
    private const HOOKS = [
        ['loginFail',    'body', 'hooks/login_fail_logger.php'],
        ['loginSuccess', 'body', 'hooks/login_success_logger.php'],
    ];

    public function change(): void
    {
        // All interpolated values come from self::HOOKS — a private const of
        // hardcoded strings. Phinx 0.16 uses PDO::query() (not prepare()) so
        // bind-parameter syntax is unavailable here; interpolation is safe.
        foreach (self::HOOKS as [$page, $position, $hook]) {
            $exists = $this->fetchRow(
                "SELECT id FROM us_plugin_hooks
                 WHERE page = '{$page}' AND folder = 'hooker' AND hook = '{$hook}'"
            );
            if (!$exists) {
                $this->execute(
                    "INSERT INTO us_plugin_hooks (page, folder, position, hook, disabled)
                     VALUES ('{$page}', 'hooker', '{$position}', '{$hook}', 0)"
                );
            }
        }
    }
}
