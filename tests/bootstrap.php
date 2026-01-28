<?php

declare(strict_types=1);

/**
 * PHPUnit Bootstrap File for Elan Registry Tests
 *
 * DEPRECATED: This file is kept for backwards compatibility only.
 * New test runs should use the specific bootstrap files:
 *
 * - tests/bootstrap-unit.php for unit tests (with mocks)
 * - tests/bootstrap-integration.php for integration tests (with UserSpice)
 *
 * Or use the specific phpunit configuration files:
 * - phpunit-unit.xml for unit tests
 * - phpunit-integration.xml for integration tests
 *
 * Usage:
 *   composer test:unit              # Fast unit tests only
 *   composer test:integration       # Integration tests requiring database
 *   composer test:full              # Both unit and integration tests
 */

// Default behavior: load unit test bootstrap for backwards compatibility
// This allows: vendor/bin/phpunit to work without additional config
// For explicit control, use phpunit-unit.xml or phpunit-integration.xml

$bootstrapUnit = dirname(__FILE__) . '/bootstrap-unit.php';
if (file_exists($bootstrapUnit)) {
    require_once $bootstrapUnit;
} else {
    echo "ERROR: tests/bootstrap-unit.php not found\n";
    exit(1);
}
