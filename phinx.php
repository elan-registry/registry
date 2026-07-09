<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

// Parse .env directly — phpdotenv lives in usersc/vendor, not available here
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\n\r\0\x0B\"'");
            if (!isset($_ENV[$key])) { // shell env takes precedence over .env (CI-friendly)
                $_ENV[$key] = $val;
            }
        }
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
