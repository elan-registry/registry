<?php

declare(strict_types=1);

use ElanRegistry\DatabaseInterface;

/**
 * Global functions that app/admin/includes/tab-email_delivery.php calls and the
 * unit tier does not define. Only a test that runs in its own process may
 * require this file, because it would change the functions of later tests.
 */
if (!function_exists('dbi')) {
    function dbi(): DatabaseInterface
    {
        return $GLOBALS['edTestDb'];
    }
}

if (!function_exists('hasPerm')) {
    function hasPerm(mixed $permissions, mixed $id = null, bool $masterCheck = true): bool
    {
        return (bool) $GLOBALS['edTestIsAdmin'];
    }
}
