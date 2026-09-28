<?php

declare(strict_types=1);

/**
 * Deployment event logger — invoked by scripts/server-hooks/post-receive
 * after migrations complete and before the .deployignore cleanup step
 * removes scripts/ (Issue #1424).
 *
 * Writes directly to the `logs` table via PDO rather than using UserSpice's
 * logger() — that function (and its $db/$user global dependencies) only
 * exist after the full users/init.php bootstrap runs, which this minimal
 * script deliberately skips to stay fast and dependency-free.
 *
 * Usage: php scripts/log-deployment.php <version> <environment> <branch> <gitHash>
 *
 * Never allowed to fail the deployment: every failure path is caught and
 * exits 0. The hook additionally guards the call with `||` to handle a
 * non-zero PHP process exit (e.g. a parse error) that occurs before this
 * try/catch can even run.
 */

try {
    require_once __DIR__ . '/../vendor/autoload.php';

    [, $version, $environment, $branch, $gitHash] = $argv + [null, null, null, null, null];
    if ($version === null || $environment === null || $branch === null || $gitHash === null) {
        throw new InvalidArgumentException(
            'Usage: php scripts/log-deployment.php <version> <environment> <branch> <gitHash>'
        );
    }
    $shortHash = substr($gitHash, 0, 8);

    $projectRoot = dirname(__DIR__);
    if (file_exists($projectRoot . '/.env')) {
        \Dotenv\Dotenv::createImmutable($projectRoot)->safeLoad();
    }

    foreach (['DB_NAME', 'DB_USER', 'DB_PASS'] as $requiredVar) {
        if (($_ENV[$requiredVar] ?? getenv($requiredVar) ?: '') === '') {
            throw new RuntimeException("Required environment variable '$requiredVar' is not set.");
        }
    }

    // DB_HOST may carry an embedded port (e.g. "127.0.0.1:3307")
    // in addition to — or instead of — a separate DB_PORT. Split it out so
    // both the plain-host (production/dev) and host:port (local) .env forms
    // connect correctly.
    $dbHostRaw = (string)($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost');
    $dbPort = (int)($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
    if (str_contains($dbHostRaw, ':')) {
        [$dbHost, $hostPort] = explode(':', $dbHostRaw, 2);
        $dbPort = (int)$hostPort;
    } else {
        $dbHost = $dbHostRaw;
    }
    // A host MySQL client treats 'localhost' as a Unix socket, and the socket
    // path may not match the server. Force TCP instead.
    if ($dbHost === 'localhost') {
        $dbHost = '127.0.0.1';
    }
    $dbName = (string)($_ENV['DB_NAME'] ?? getenv('DB_NAME'));
    $dbUser = (string)($_ENV['DB_USER'] ?? getenv('DB_USER'));
    $dbPass = (string)($_ENV['DB_PASS'] ?? getenv('DB_PASS'));

    $pdo = new PDO(
        "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $lognote = "Deployed {$version} ({$shortHash}) to {$environment} on branch {$branch}";

    $statement = $pdo->prepare(
        'INSERT INTO logs (user_id, logdate, logtype, lognote, ip) VALUES (0, NOW(), :logtype, :lognote, :ip)'
    );
    $statement->execute([
        'logtype' => \ElanRegistry\LogCategories::LOG_CATEGORY_DEPLOYMENT,
        'lognote' => $lognote,
        'ip' => '',
    ]);

    echo "Deployment logged: $lognote\n";
} catch (\Throwable $e) {
    // Deliberately omit $e->getMessage() here — a PDO connection failure's message can
    // include the DB username/host, and this warning lands in deploy hook output.
    fwrite(STDERR, 'WARNING: Deployment logging failed (non-fatal): ' . get_class($e) . "\n");
}

exit(0);
