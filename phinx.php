<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

// createImmutable: won't overwrite keys already in $_ENV (shell env takes precedence — CI-friendly).
// safeLoad() is used so a missing .env is not fatal; CI supplies vars via the process environment.
// The getenv() fallback on each config line handles that case (no .env → $_ENV empty → fall through).
if (file_exists(__DIR__ . '/.env')) {
    \Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
}

foreach (['DB_NAME', 'DB_USER', 'DB_PASS'] as $var) {
    if (($_ENV[$var] ?? getenv($var) ?: '') === '') {
        fwrite(STDERR, "ERROR: Required environment variable '$var' is not set. Check .env file.\n");
        exit(1);
    }
}

$dbHost = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost';

// 'localhost' on macOS resolves to a Unix socket path that differs between CLI PHP
// and MAMP, causing connection failures. Force TCP by using 127.0.0.1 instead.
if ($dbHost === 'localhost') {
    $dbHost = '127.0.0.1';
}

return [
    'paths' => [
        'migrations' => 'database/migrations',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment'     => 'default',
        'default' => [
            'adapter'   => 'mysql',
            'host'      => $dbHost,
            'name'      => $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: '',
            'user'      => $_ENV['DB_USER'] ?? getenv('DB_USER') ?: '',
            'pass'      => $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '',
            'port'      => (int)($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
    ],
    'version_order' => 'creation',
];
