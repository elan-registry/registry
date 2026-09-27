<?php

declare(strict_types=1);

namespace Tests\Unit\System;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\PhpBuiltinServer;

/**
 * Process-level tests for PhpBuiltinServer (#2166).
 *
 * These spawn real `php -S` processes and real helper processes, and use
 * `ps`/`kill` to verify actual OS process state — not just the class's
 * in-memory bookkeeping. Every test cleans up in `finally`/tearDown() so a
 * failure cannot leak a server, a helper process, or a temp directory.
 *
 * Kept out of PhpBuiltinServerTest.php (pure helpers, no process or
 * filesystem access) because these are slower and touch the real OS.
 */
#[Group('system')]
final class PhpBuiltinServerProcessTest extends TestCase
{
    /** @var list<string> Temp directories to remove in tearDown(). */
    private array $tempDirs = [];

    /** @var list<PhpBuiltinServer> Servers to stop() in tearDown() if a test did not. */
    private array $servers = [];

    /** @var list<resource> Helper processes to terminate/close in tearDown() if a test did not. */
    private array $helperProcesses = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        $this->servers = [];

        foreach ($this->helperProcesses as $process) {
            if (is_resource($process)) {
                @proc_terminate($process, 9);
                @proc_close($process);
            }
        }
        $this->helperProcesses = [];

        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];
    }

    // ------------------------------------------------------------------
    // start/stop: real PHP process, no shell wrapper, correct parentage
    // ------------------------------------------------------------------

    public function test_startAndStop_answersProbesAndSpawnsBarePhpChild(): void
    {
        $docroot = $this->makeDocroot();

        $server = PhpBuiltinServer::start(
            $docroot,
            "if (\$_SERVER['REQUEST_URI'] === '/hello') { echo 'hi'; return true; } return false;"
        );
        $this->servers[] = $server;

        $ready = @file_get_contents($server->url(PhpBuiltinServer::READY_PATH));
        self::assertNotFalse($ready, 'readiness probe should answer');

        // The caller-supplied route answers.
        $hello = @file_get_contents($server->url('/hello'));
        self::assertSame('hi', $hello);

        $router = $server->routerPath();
        self::assertFileExists($router);
        self::assertSame(PhpBuiltinServer::routerDir(), dirname($router), 'router file must live in the swept directory');

        $pid = $this->findServerPid($router);
        self::assertNotNull($pid, 'expected to find the php -S process via ps');

        // No `sh` wrapper: the process itself is PHP, and its parent is us.
        $comm = trim($this->runPs(['-o', 'comm=', '-p', (string) $pid]));
        self::assertNotSame('', $comm, 'ps should report the process command');
        self::assertStringContainsString(
            basename(PHP_BINARY),
            basename($comm),
            "server process command '{$comm}' should be the php binary, not a shell wrapper"
        );

        $args = trim($this->runPs(['-o', 'args=', '-p', (string) $pid]));
        self::assertStringStartsWith(
            PHP_BINARY,
            $args,
            "server process args '{$args}' should start with the PHP binary (no sh -c wrapper)"
        );

        $ppid = trim($this->runPs(['-o', 'ppid=', '-p', (string) $pid]));
        self::assertSame(
            (string) getmypid(),
            $ppid,
            'the server process should be a direct child of this PHPUnit process'
        );

        $port = $server->port();

        $server->stop();
        $this->servers = [];

        self::assertFileDoesNotExist($router, 'router file should be removed after stop()');
        self::assertFalse(
            $this->canConnect('127.0.0.1', $port),
            'port should refuse connections after stop()'
        );
        self::assertNull($this->findPid($pid), 'server process should be gone after stop()');

        // Second stop() is a no-op, not an error.
        $server->stop();
    }

    // ------------------------------------------------------------------
    // Orphan swept: dead owner
    // ------------------------------------------------------------------

    public function test_sweep_killsOrphanWhoseOwnerIsDead(): void
    {
        $docroot = $this->makeDocroot();

        $owner = $this->spawnFakeOwner();
        $this->helperProcesses[] = $owner;
        $ownerPid = proc_get_status($owner)['pid'];

        $server = PhpBuiltinServer::start($docroot, 'return false;', $ownerPid);
        $this->servers[] = $server;

        $router = $server->routerPath();
        $port = $server->port();
        $serverPid = $this->findServerPid($router);
        self::assertNotNull($serverPid, 'expected to find the leaked server process via ps');

        // Kill the owner and reap it so it does not linger as a zombie
        // that would otherwise still show up in a `ps` snapshot.
        proc_terminate($owner, 9);
        proc_close($owner);
        $this->helperProcesses = [];
        $this->waitForPidGone($ownerPid);
        self::assertNull($this->findPid($ownerPid), 'owner should be gone before sweeping');

        PhpBuiltinServer::sweep();

        // Every assertion runs BEFORE $server->stop(): stop() itself kills
        // the process and unlinks the router file, so asserting after it
        // would pass even if sweep() did nothing.
        //
        // This test still holds the proc_open() handle (unlike a real
        // leak, where the owner has exited and init reaps the child), so
        // the process sweep() killed lingers as a zombie until stop()'s
        // proc_close() reaps it. A zombie has released its socket, so
        // "dead or zombie" is the right post-sweep condition.
        self::assertTrue(
            $this->waitForPidDeadOrZombie($serverPid),
            'orphaned server process should be killed by sweep()'
        );
        self::assertFalse($this->canConnect('127.0.0.1', $port), 'orphaned server port should refuse connections after sweep()');
        self::assertFileDoesNotExist($router, 'orphaned router file should be removed by sweep()');
    }

    // ------------------------------------------------------------------
    // Live owner kept
    // ------------------------------------------------------------------

    public function test_sweep_leavesServerAloneWhenOwnerIsAlive(): void
    {
        $docroot = $this->makeDocroot();

        $owner = $this->spawnFakeOwner();
        $this->helperProcesses[] = $owner;
        $ownerPid = proc_get_status($owner)['pid'];

        $server = PhpBuiltinServer::start($docroot, 'return false;', $ownerPid);
        $this->servers[] = $server;

        $router = $server->routerPath();

        PhpBuiltinServer::sweep();

        self::assertFileExists($router, 'router file should remain while owner is alive');
        $ready = @file_get_contents($server->url(PhpBuiltinServer::READY_PATH));
        self::assertNotFalse($ready, 'server should still answer after sweep() while owner is alive');
    }

    // ------------------------------------------------------------------
    // Shutdown hook: a process that exits without stop() leaks nothing
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function childExitProvider(): array
    {
        return [
            'exit(0) without stop()' => ['exit(0);'],
            'fatal error' => ['elanreg_2166_undefined_function();'],
        ];
    }

    #[DataProvider('childExitProvider')]
    public function test_shutdownHook_stopsServerWhenOwnerExitsWithoutStop(string $exitStatement): void
    {
        $docroot = $this->makeDocroot();
        $workDir = $this->makeTempDir('child');
        $this->tempDirs[] = $workDir;

        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $script = $workDir . '/child.php';
        file_put_contents(
            $script,
            "<?php\n"
            . 'require ' . var_export($autoload, true) . ";\n"
            . '$server = \\Tests\\Support\\PhpBuiltinServer::start(' . var_export($docroot, true) . ", 'return false;');\n"
            . "fwrite(STDOUT, \$server->routerPath() . ' ' . \$server->port() . \"\\n\");\n"
            . "fflush(STDOUT);\n"
            . $exitStatement . "\n"
        );
        $stderrFile = $workDir . '/stderr.txt';

        $pipes = [];
        $child = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', $script],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderrFile, 'w']],
            $pipes
        );
        self::assertIsResource($child, 'failed to spawn the child PHP process');

        $router = null;
        $port = null;
        try {
            $line = $this->readLineWithTimeout($pipes[1], 15.0);
            fclose($pipes[1]);
            $exitCode = proc_close($child);
            $child = null;

            $stderr = (string) @file_get_contents($stderrFile);
            self::assertMatchesRegularExpression('/^(\S+) (\d+)$/', $line, "child did not report its server; stderr:\n{$stderr}");
            [$router, $portText] = explode(' ', $line);
            $port = (int) $portText;

            if ($exitStatement === 'exit(0);') {
                self::assertSame(0, $exitCode, "child should exit cleanly; stderr:\n{$stderr}");
            } else {
                self::assertNotSame(0, $exitCode, 'child should die from the fatal error');
                self::assertStringContainsString('elanreg_2166_undefined_function', $stderr);
            }

            self::assertFalse($this->canConnect('127.0.0.1', $port), 'shutdown hook should have stopped the server');
            self::assertFileDoesNotExist($router, 'shutdown hook should have removed the router file');
        } finally {
            if (is_resource($child)) {
                proc_terminate($child, 9);
                proc_close($child);
            }
            // If the hook failed, the child (the owner) is dead, so its
            // server is now a genuine orphan that sweep() reclaims.
            PhpBuiltinServer::sweep();
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'elanreg-test-' . $prefix . '-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create temp dir: {$dir}");
        }

        return $dir;
    }

    private function makeDocroot(): string
    {
        $dir = $this->makeTempDir('docroot');
        $this->tempDirs[] = $dir;
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'index.php', "<?php\necho 'ok';\n");

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Spawn a long-lived helper process (`sleep 60`) to act as a fake owner PID.
     *
     * @return resource The proc_open() handle.
     */
    private function spawnFakeOwner()
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $pipes = [];
        $process = proc_open(['sleep', '60'], $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to spawn fake owner process');
        }

        return $process;
    }

    /**
     * Read one line from a pipe, failing the test if none arrives in time.
     *
     * @param resource $pipe
     */
    private function readLineWithTimeout($pipe, float $timeoutSeconds): string
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $buffer = '';
        while (!str_contains($buffer, "\n")) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                self::fail('timed out waiting for the child process to report its server');
            }
            $read = [$pipe];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 0, (int) ($remaining * 1_000_000)) === 0) {
                continue;
            }
            $chunk = fread($pipe, 8192);
            if ($chunk === false || ($chunk === '' && feof($pipe))) {
                break;
            }
            $buffer .= $chunk;
        }

        return trim(strtok($buffer, "\n") ?: '');
    }

    private function findServerPid(string $routerPath): ?int
    {
        $ps = $this->runPs(['-ww', '-eo', 'pid=,args=']);
        foreach (preg_split('/\R/', $ps) ?: [] as $line) {
            if (preg_match('/^\s*(\d+)\s+(\S.*)$/', $line, $m) === 1 && str_contains($m[2], $routerPath)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    private function findPid(int $pid): ?string
    {
        $out = trim($this->runPs(['-o', 'pid=', '-p', (string) $pid]));

        return $out === '' ? null : $out;
    }

    private function waitForPidGone(int $pid, float $timeoutSeconds = 3.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if ($this->findPid($pid) === null) {
                return;
            }
            usleep(50_000);
        }
    }

    /**
     * Poll until $pid is absent from `ps` or is a zombie (STAT starts with Z).
     */
    private function waitForPidDeadOrZombie(int $pid, float $timeoutSeconds = 3.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $stat = trim($this->runPs(['-o', 'stat=', '-p', (string) $pid]));
            if ($stat === '' || str_starts_with($stat, 'Z')) {
                return true;
            }
            usleep(50_000);
        }

        return false;
    }

    /**
     * @param list<string> $args
     */
    private function runPs(array $args): string
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $pipes = [];
        $process = @proc_open(array_merge(['ps'], $args), $descriptors, $pipes);
        if (!is_resource($process)) {
            return '';
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        return $output === false ? '' : $output;
    }

    private function canConnect(string $host, int $port): bool
    {
        $conn = @stream_socket_client("tcp://{$host}:{$port}", $errNo, $errStr, 1.0);
        if ($conn === false) {
            return false;
        }
        fclose($conn);

        return true;
    }
}
