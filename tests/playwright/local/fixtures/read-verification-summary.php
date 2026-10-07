<?php
declare(strict_types=1);

use ElanRegistry\Car\CarRepository;

/**
 * Read-only helper for tests/playwright/admin-verification-dashboard.spec.js.
 *
 * Prints CarRepository::countVerificationSummary()'s result as JSON, for
 * one window (in days, or `0` for all time — same encoding as
 * app/admin/index.php's `?window=` allow-list). This is the exact
 * production method tab-verification.php calls to render the six summary
 * cards, so the Playwright spec compares each card against an independent
 * read of the same method, not against a hand-computed or re-derived
 * number.
 *
 * Usage: php read-verification-summary.php <windowDays|0>
 * Prints: {"all": n, "eligible": n, "pending": n, "bounced": n,
 *          "suppressed": n, "verified": n, "sold": n}
 */

$projectRoot = dirname(__DIR__, 4);
$_SERVER['DOCUMENT_ROOT'] = $projectRoot;
$_SERVER['PHP_SELF'] = '/tests/';

require_once $projectRoot . '/vendor/autoload.php';

\Dotenv\Dotenv::createImmutable($projectRoot)->safeLoad();

if (($_ENV['US_ENVIRONMENT'] ?? getenv('US_ENVIRONMENT') ?: '') !== 'development') {
    fwrite(STDERR, "ERROR: read-verification-summary.php refused to run — US_ENVIRONMENT is not 'development'.\n");
    exit(1);
}

set_error_handler(static function (): bool {
    return true;
});
ob_start();
try {
    require_once $projectRoot . '/users/init.php';
} catch (\Throwable $e) {
    fwrite(STDERR, "NOTE: users/init.php threw during CLI bootstrap (expected outside a web request): {$e->getMessage()}\n");
}
$initOutput = ob_get_clean();
if (trim((string) $initOutput) !== '') {
    fwrite(STDERR, "NOTE: {$initOutput}\n");
}
restore_error_handler();

$windowArg = $argv[1] ?? '30';
$windowDays = ((string) (int) $windowArg === $windowArg && (int) $windowArg > 0) ? (int) $windowArg : null;

$repo = new CarRepository(dbi());

try {
    $summary = $repo->countVerificationSummary($windowDays);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: countVerificationSummary failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo json_encode($summary) . "\n";
