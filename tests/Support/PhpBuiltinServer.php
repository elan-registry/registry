<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Throwable;

/**
 * PhpBuiltinServer - leak-proof `php -S` harness for integration tests
 *
 * Integration tests that need a real HTTP layer (`php://input`, real request
 * headers) start PHP's built-in web server as a child process. Before #2166
 * each such test rolled its own spawn/teardown, and a fatal error, a `^C`, or
 * a PHPUnit crash between `setUpBeforeClass()` and `tearDownAfterClass()` left
 * the server running forever, holding its port and its tempnam'd router file.
 * This class centralises that lifecycle and adds the piece the ad-hoc versions
 * lacked: a sweep that reclaims servers leaked by *previous* runs.
 *
 * Usage:
 *
 * ```php
 * $server = PhpBuiltinServer::start($projectRoot, "require '{$endpoint}';");
 * $body   = file_get_contents($server->url('/api/webhooks/brevo.php'));
 * $server->stop();
 * ```
 *
 * Design constraints, all of which the implementation below depends on:
 *
 * - **No `pkill`/`pgrep`, even though `pkill` is present on both sides.** A
 *   pattern match cannot express the safety rule that actually matters here —
 *   "kill only if the owner PID is absent from the same `ps` snapshot" — so it
 *   would happily kill a live owner's server. Process enumeration is therefore
 *   a single `ps -ww -eo pid=,args=`, whose `pid args` output format is
 *   identical on macOS and in the container (verified 2026-09-23); the parser
 *   depends on that.
 * - **No `posix_*`/`pcntl_*`.** `posix` is loaded on the host and in the
 *   container (only `pcntl` is missing there), so this is not a portability
 *   workaround. Signalling goes through the `kill` binary so the whole class
 *   has one code path with no extension dependency, and the same `ps`
 *   re-check that guards each signal works identically everywhere.
 * - **Array-form `proc_open` everywhere**, including the `kill` calls, so no
 *   string ever reaches a shell.
 * - **Ownership marker in the command line.** The router path embeds the
 *   owning PHPUnit process's PID and a 32-hex nonce, so a sweep can tell our
 *   servers from a developer's own `php -S` with certainty.
 *
 * Sweep safety: a leaked server is killed only when its owner PID is absent
 * from the *same* snapshot that found it, and never when the owner is the
 * sweeping process itself. Router files are listed *before* that snapshot, so
 * a file whose owner is missing from it was written by an owner that has
 * since exited. Concurrent runs (two checkouts, two terminals) therefore have
 * live owners and are skipped, and PID reuse can only cause a leak to persist
 * — apart from the narrow re-check-to-signal window documented on
 * killOrphan().
 *
 * Pure helpers (routerDir(), routerFileName(), buildCommand(), parsePsOutput(),
 * findOrphans(), staleRouterFiles(), planSweep(), isReadyResponse()) take their
 * inputs as parameters rather than shelling out, so they are directly
 * unit-testable without spawning anything. See
 * tests/unit/system/PhpBuiltinServerTest.php.
 *
 * This is test-support code: it must never require UserSpice or any app
 * bootstrap.
 *
 * @package Tests\Support
 * @see https://github.com/elan-registry/registry/issues/2166
 */
final class PhpBuiltinServer
{
    /** Filename prefix identifying a router file (and therefore a server) as ours. */
    public const ROUTER_PREFIX = 'elanreg-phpsrv-';

    /** Path component the readiness probe is served from. */
    public const READY_PATH = '/__elanreg_server_ready';

    /** Seconds to wait for a readiness 200 + matching token before giving up. */
    private const READY_TIMEOUT_SECONDS = 10.0;

    /** Seconds to wait after SIGTERM before escalating to SIGKILL. */
    private const TERM_GRACE_SECONDS = 2.0;

    /** Attempts to bind a fresh OS-assigned port when a race steals it. */
    private const PORT_ATTEMPTS = 3;

    /** killOrphan() identity states: see processIdentity(). */
    private const IDENTITY_SAME = 'same';
    private const IDENTITY_GONE = 'gone';
    private const IDENTITY_OTHER = 'other';
    private const IDENTITY_UNKNOWN = 'unknown';

    /**
     * Live instances started by this process, keyed by spl_object_id.
     *
     * @var array<int, self>
     */
    private static array $live = [];

    /** PID that registered the shutdown hook, or null if not yet registered. */
    private static ?int $hookOwnerPid = null;

    /** @var resource|null The proc_open handle, or null once stopped. */
    private $process;

    /**
     * @param resource $process Handle returned by proc_open().
     * @param int      $port    TCP port on 127.0.0.1 the server listens on.
     * @param string   $router  Absolute path to this instance's router file.
     * @param string   $token   Readiness token this instance's router echoes.
     */
    private function __construct($process, private readonly int $port, private readonly string $router, private readonly string $token)
    {
        $this->process = $process;
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    /**
     * Start a built-in web server and block until it answers its readiness probe.
     *
     * Sweeps orphans from previous runs and registers the shutdown hook (a
     * no-op if already registered), so a caller that forgets to stop() is
     * covered on normal exit, exit(), and fatal errors — provided the hook was
     * registered before any shutdown function that calls exit(). PHPUnit's
     * handler can exit(2) on a fatal, so both test bootstraps register the
     * hook before PHPUnit registers its own; a script that registers an
     * exiting shutdown function before calling start() loses that coverage. A
     * SIGKILL'd or otherwise hard-killed process runs no shutdown functions;
     * its server is reclaimed only by the next run's sweep().
     *
     * The router file always goes in routerDir(), the only directory sweep()
     * scans, so every server started here is reclaimable.
     *
     * @param string   $docroot    Absolute path to the document root passed to `php -S -t`.
     * @param string   $routerBody PHP source for the router, WITHOUT a leading `<?php` tag.
     * @param int|null $ownerPid   Owning PID recorded in the router filename; defaults to getmypid().
     *
     * @return self A running server that has answered its readiness probe.
     *
     * @throws RuntimeException If $routerBody opens with `<?php` (any case, after optional
     *                          whitespace), $docroot is not a directory, the router file
     *                          cannot be created, or the server never becomes ready.
     */
    public static function start(string $docroot, string $routerBody, ?int $ownerPid = null): self
    {
        if (preg_match('/^\s*<\?php/i', $routerBody) === 1) {
            throw new RuntimeException('$routerBody must not start with "<?php" — the opening tag is supplied by PhpBuiltinServer.');
        }

        $resolvedDocroot = realpath($docroot);
        if ($resolvedDocroot === false || !is_dir($resolvedDocroot)) {
            throw new RuntimeException("Document root is not a directory: {$docroot}");
        }

        $ownerPid ??= getmypid();
        if ($ownerPid === false) {
            throw new RuntimeException('Unable to determine the current process ID.');
        }

        self::sweep();
        self::registerShutdownHook();

        $token = bin2hex(random_bytes(16));
        $router = self::writeRouterFile(self::routerDir(), $ownerPid, $token, $routerBody);

        try {
            return self::spawn($resolvedDocroot, $router, $token);
        } catch (Throwable $e) {
            @unlink($router);
            throw $e;
        }
    }

    /**
     * Stop this server and remove its router file. Safe to call repeatedly.
     */
    public function stop(): void
    {
        unset(self::$live[spl_object_id($this)]);

        if (is_resource($this->process)) {
            proc_terminate($this->process, 15);

            $deadline = microtime(true) + self::TERM_GRACE_SECONDS;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($this->process);
                if ($status['running'] === false) {
                    break;
                }
                usleep(50_000);
            }

            $status = proc_get_status($this->process);
            if ($status['running'] === true) {
                proc_terminate($this->process, 9);
            }

            proc_close($this->process);
            $this->process = null;
        }

        if (is_file($this->router)) {
            @unlink($this->router);
        }
    }

    /**
     * Port this server listens on, on 127.0.0.1.
     *
     * @return int TCP port number.
     */
    public function port(): int
    {
        return $this->port;
    }

    /**
     * Absolute URL for a path on this server.
     *
     * @param string $path Request path; a leading slash is added when missing.
     *
     * @return string `http://127.0.0.1:<port><path>`.
     */
    public function url(string $path = '/'): string
    {
        return 'http://127.0.0.1:' . $this->port . (str_starts_with($path, '/') ? $path : '/' . $path);
    }

    /**
     * Absolute path to this instance's router file.
     *
     * @return string Path inside routerDir().
     */
    public function routerPath(): string
    {
        return $this->router;
    }

    // ------------------------------------------------------------------
    // Process-wide teardown
    // ------------------------------------------------------------------

    /**
     * Register the shutdown hook that stops every server this process started.
     *
     * Idempotent: registering twice registers one hook. The hook body no-ops
     * when getmypid() differs from the registering PID, so a forked child
     * exiting cannot tear down its parent's servers.
     */
    public static function registerShutdownHook(): void
    {
        if (self::$hookOwnerPid !== null) {
            return;
        }

        $pid = getmypid();
        self::$hookOwnerPid = $pid === false ? -1 : $pid;

        register_shutdown_function(static function (): void {
            if (getmypid() !== self::$hookOwnerPid) {
                return;
            }
            try {
                self::stopAll();
            } catch (Throwable $e) {
                // Best-effort: the next run's sweep() reclaims anything missed.
                fwrite(STDERR, 'PhpBuiltinServer: shutdown cleanup failed: ' . $e::class . ': ' . $e->getMessage() . "\n");
            }
        });
    }

    /**
     * Stop every server started by this process that has not been stopped yet.
     *
     * One server failing to stop is logged and does not prevent the rest
     * from being stopped.
     */
    public static function stopAll(): void
    {
        foreach (self::$live as $server) {
            try {
                $server->stop();
            } catch (Throwable $e) {
                fwrite(STDERR, 'PhpBuiltinServer: stop() failed for ' . $server->routerPath() . ': ' . $e::class . ': ' . $e->getMessage() . "\n");
            }
        }
        self::$live = [];
    }

    /**
     * Reclaim servers and router files leaked by previous runs.
     *
     * Router files are listed BEFORE the `ps` snapshot is taken: a file listed
     * first was written by an owner that existed before the snapshot, so an
     * owner missing from the snapshot really has exited. Listing after the
     * snapshot would let a run that started in between lose its live router
     * file. When `ps` fails, nothing is killed or unlinked.
     */
    public static function sweep(): void
    {
        $dir = self::routerDir();
        $routerFiles = self::listRouterFiles($dir);

        $psOutput = self::runCommand(['ps', '-ww', '-eo', 'pid=,args='], $exitCode);

        $selfPid = getmypid();
        $plan = self::planSweep($psOutput, $exitCode, $routerFiles, $dir, $selfPid === false ? -1 : $selfPid);

        if (!$plan['usable']) {
            fwrite(STDERR, "PhpBuiltinServer::sweep(): `ps` snapshot unusable (exit {$exitCode}, own PID not listed); skipping sweep.\n");
        }

        foreach ($plan['kill'] as $orphan) {
            self::killOrphan($orphan['pid'], $orphan['args']);
        }

        foreach ($plan['unlink'] as $file) {
            @unlink($file);
        }
    }

    // ------------------------------------------------------------------
    // Pure helpers (unit-tested directly — no process or filesystem access)
    // ------------------------------------------------------------------

    /**
     * Directory router files are written to: the resolved system temp dir.
     *
     * `ps` prints argv exactly as given, and findOrphans() anchors on this
     * directory string. realpath() makes runs that see different spellings of
     * the same temp dir (e.g. macOS `/var/...` vs `/private/var/...`, or a
     * TMPDIR with a trailing slash) agree on one canonical string, so each
     * run writes router paths that every other run's sweep recognises.
     *
     * @return string Absolute path, without a trailing slash.
     */
    public static function routerDir(): string
    {
        $resolved = realpath(sys_get_temp_dir());

        return rtrim($resolved === false ? sys_get_temp_dir() : $resolved, DIRECTORY_SEPARATOR);
    }

    /**
     * Build a router filename carrying the owner PID and a unique nonce.
     *
     * @param int    $ownerPid PID of the process that owns the server.
     * @param string $nonce    32 lowercase hex characters.
     *
     * @return string Bare filename, e.g. `elanreg-phpsrv-1234-<32 hex>.php`.
     *
     * @throws RuntimeException If $nonce is not exactly 32 hex characters or $ownerPid is not positive.
     */
    public static function routerFileName(int $ownerPid, string $nonce): string
    {
        if ($ownerPid <= 0) {
            throw new RuntimeException("Owner PID must be positive, got {$ownerPid}.");
        }
        if (preg_match('/^[0-9a-f]{32}$/', $nonce) !== 1) {
            throw new RuntimeException('Router nonce must be exactly 32 lowercase hex characters.');
        }

        return self::ROUTER_PREFIX . $ownerPid . '-' . $nonce . '.php';
    }

    /**
     * Build the argv for `php -S`, for array-form proc_open().
     *
     * @param int    $port    TCP port to bind on 127.0.0.1.
     * @param string $docroot Absolute document root.
     * @param string $router  Absolute router file path.
     *
     * @return list<string> argv, starting with the PHP binary.
     */
    public static function buildCommand(int $port, string $docroot, string $router): array
    {
        return [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot, $router];
    }

    /**
     * Parse `ps -ww -eo pid=,args=` output into a PID => args map.
     *
     * Lines without a leading integer PID (or with empty args) are ignored, so
     * a stray header or blank line is harmless.
     *
     * @param string $psOutput Raw stdout of the `ps` command.
     *
     * @return array<int, string> PID => full command line (args trimmed).
     */
    public static function parsePsOutput(string $psOutput): array
    {
        $processes = [];

        foreach (preg_split('/\R/', $psOutput) ?: [] as $line) {
            if (preg_match('/^\s*(\d+)\s+(\S.*)$/', $line, $m) !== 1) {
                continue;
            }
            $processes[(int) $m[1]] = trim($m[2]);
        }

        return $processes;
    }

    /**
     * Select the leaked servers in a snapshot that are safe to kill.
     *
     * A process qualifies only when every one of these holds:
     * - its PID key is an int and its args value is a string (anything else is
     *   skipped, never a TypeError);
     * - its command line matches `php … -S 127.0.0.1:<port> -t …` and ends in
     *   `{$routerDir}/elanreg-phpsrv-<ownerPid>-<32 hex>.php`;
     * - its owner PID is NOT $selfPid (never kill our own live servers);
     * - its owner PID is absent from $snapshot (the owner really is gone);
     * - the server PID itself is not $selfPid.
     *
     * A developer's own `php -S`, an editor holding a router file open, and the
     * pre-#2166 tempnam-era routers all fail the filename test and are skipped.
     *
     * @param array<mixed, mixed> $snapshot  PID => args, normally from parsePsOutput().
     * @param string              $routerDir Directory router files live in, without a trailing slash.
     * @param int                 $selfPid   PID of the sweeping process.
     *
     * @return list<array{pid: int, args: string, ownerPid: int}> Orphans, in snapshot order.
     */
    public static function findOrphans(array $snapshot, string $routerDir, int $selfPid): array
    {
        // Anchored at both ends: the executable must be a php binary, and the
        // final argument must be a router file of ours in $routerDir. The
        // `.*` runs are optional so both `php -S host:port -t doc router` and
        // a binary invoked with extra flags (`php -d x=y -S …`) match.
        $pattern = '#^\S*(?:^|/)php[\d.]*\s.*-S\s+127\.0\.0\.1:\d+\s.*-t\s.*\s'
            . preg_quote(rtrim($routerDir, '/') . '/' . self::ROUTER_PREFIX, '#')
            . '(\d+)-[0-9a-f]{32}\.php$#';

        $orphans = [];

        foreach ($snapshot as $pid => $args) {
            if (!is_int($pid) || !is_string($args)) {
                continue;
            }
            if ($pid === $selfPid || preg_match($pattern, $args, $m) !== 1) {
                continue;
            }

            $ownerPid = (int) $m[1];
            if ($ownerPid === $selfPid || isset($snapshot[$ownerPid])) {
                continue;
            }

            $orphans[] = ['pid' => $pid, 'args' => $args, 'ownerPid' => $ownerPid];
        }

        return $orphans;
    }

    /**
     * Select router files whose owning process is no longer running.
     *
     * Non-string entries in either list are skipped rather than raising a
     * TypeError or an array_flip() warning.
     *
     * @param array<mixed> $routerFiles Absolute paths of candidate router files.
     * @param array<mixed> $livePids    PIDs present in the `ps` snapshot.
     *
     * @return list<string> Paths safe to unlink, in input order.
     */
    public static function staleRouterFiles(array $routerFiles, array $livePids): array
    {
        $live = array_flip(array_filter($livePids, 'is_int'));
        $stale = [];

        foreach ($routerFiles as $file) {
            if (!is_string($file)) {
                continue;
            }
            if (preg_match('/^' . preg_quote(self::ROUTER_PREFIX, '/') . '(\d+)-[0-9a-f]{32}\.php$/', basename($file), $m) !== 1) {
                continue;
            }
            if (!isset($live[(int) $m[1]])) {
                $stale[] = $file;
            }
        }

        return $stale;
    }

    /**
     * Decide what sweep() should kill and unlink, from inputs it has gathered.
     *
     * $routerFiles must have been listed BEFORE $psOutput was captured; see
     * sweep(). The snapshot is trusted only if `ps` exited 0 AND the parsed
     * snapshot contains $selfPid: the sweeping process is always running, so
     * a snapshot missing it is failed, truncated or unparseable output, not a
     * real process table. An untrusted snapshot would make every owner look
     * dead and unlink every live run's router file, so the plan is then empty
     * and `usable` is false.
     *
     * @param string       $psOutput    Raw stdout of `ps -ww -eo pid=,args=`.
     * @param int          $psExitCode  Exit status of that `ps` call.
     * @param array<mixed> $routerFiles Router file paths, listed before the snapshot.
     * @param string       $routerDir   Directory router files live in, without a trailing slash.
     * @param int          $selfPid     PID of the sweeping process.
     *
     * @return array{usable: bool, kill: list<array{pid: int, args: string, ownerPid: int}>, unlink: list<string>}
     */
    public static function planSweep(string $psOutput, int $psExitCode, array $routerFiles, string $routerDir, int $selfPid): array
    {
        $snapshot = $psExitCode === 0 ? self::parsePsOutput($psOutput) : [];
        if (!isset($snapshot[$selfPid])) {
            return ['usable' => false, 'kill' => [], 'unlink' => []];
        }

        return [
            'usable' => true,
            'kill' => self::findOrphans($snapshot, $routerDir, $selfPid),
            'unlink' => self::staleRouterFiles(array_values(array_filter($routerFiles, 'is_string')), array_keys($snapshot)),
        ];
    }

    /**
     * Whether a readiness probe response proves this instance is serving.
     *
     * True only when the status line reports 200 AND the body is exactly the
     * token, so a stale orphan, or anyone else's server that happens to hold
     * the port, can never be mistaken for this instance.
     *
     * @param array<mixed> $headers Raw response header lines ($http_response_header), status line first.
     * @param string|false $body    Response body, or false when the request failed.
     * @param string       $token   This instance's readiness token.
     */
    public static function isReadyResponse(array $headers, string|false $body, string $token): bool
    {
        if ($body === false || $token === '' || $body !== $token) {
            return false;
        }

        $statusLine = $headers[0] ?? null;

        return is_string($statusLine) && preg_match('#^HTTP/\S+\s+200(?:\s|$)#', $statusLine) === 1;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Create the router file exclusively, retrying on nonce collision.
     *
     * @throws RuntimeException If no file could be created or the body not written.
     */
    private static function writeRouterFile(string $dir, int $ownerPid, string $token, string $routerBody): string
    {
        $source = "<?php\n"
            . 'if (($_SERVER["REQUEST_URI"] ?? "") === ' . var_export(self::READY_PATH, true) . ") {\n"
            . '    header("Content-Type: text/plain"); echo ' . var_export($token, true) . "; return true;\n"
            . "}\n"
            . $routerBody . "\n";

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $path = $dir . DIRECTORY_SEPARATOR . self::routerFileName($ownerPid, bin2hex(random_bytes(16)));

            $handle = @fopen($path, 'x');
            if ($handle === false) {
                continue;
            }

            $written = fwrite($handle, $source);
            fclose($handle);

            if ($written === false) {
                @unlink($path);
                throw new RuntimeException("Failed to write router file: {$path}");
            }

            return $path;
        }

        throw new RuntimeException("Failed to create a router file in {$dir}");
    }

    /**
     * Spawn `php -S` on an OS-assigned port, retrying when the port is stolen.
     *
     * @throws RuntimeException If every attempt fails to become ready.
     */
    private static function spawn(string $docroot, string $router, string $token): self
    {
        $lastError = 'unknown error';

        for ($attempt = 0; $attempt < self::PORT_ATTEMPTS; $attempt++) {
            $port = self::reserveEphemeralPort();
            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ];

            $pipes = [];
            $process = proc_open(self::buildCommand($port, $docroot, $router), $descriptors, $pipes);
            if (!is_resource($process)) {
                $lastError = 'proc_open() failed';
                continue;
            }

            $server = new self($process, $port, $router, $token);

            if ($server->waitUntilReady()) {
                self::$live[spl_object_id($server)] = $server;
                return $server;
            }

            // Keep the router file: stop() would delete it, and the next
            // attempt reuses it on a fresh port.
            $lastError = "server on port {$port} never answered the readiness probe";
            proc_terminate($process, 9);
            proc_close($process);
        }

        throw new RuntimeException("Failed to start php -S after " . self::PORT_ATTEMPTS . " attempts: {$lastError}");
    }

    /**
     * Ask the OS for a free port by binding, reading, and closing a socket.
     *
     * @throws RuntimeException If no listening socket can be created.
     */
    private static function reserveEphemeralPort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errNo, $errStr);
        if ($socket === false) {
            throw new RuntimeException("Unable to reserve an ephemeral port: {$errStr} ({$errNo})");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if ($name === false || ($colon = strrpos($name, ':')) === false) {
            throw new RuntimeException('Unable to read the reserved port number.');
        }

        return (int) substr($name, $colon + 1);
    }

    /**
     * Poll the readiness probe until isReadyResponse() accepts it.
     */
    private function waitUntilReady(): bool
    {
        $deadline = microtime(true) + self::READY_TIMEOUT_SECONDS;
        $context = stream_context_create(['http' => ['timeout' => 1.0, 'ignore_errors' => true]]);

        while (microtime(true) < $deadline) {
            if (is_resource($this->process) && proc_get_status($this->process)['running'] === false) {
                return false;
            }

            // Reset each attempt.
            $http_response_header = [];
            $body = @file_get_contents($this->url(self::READY_PATH), false, $context);

            if (self::isReadyResponse($http_response_header, $body, $this->token)) {
                return true;
            }

            usleep(50_000);
        }

        return false;
    }

    /**
     * Terminate one orphan, re-verifying its identity before every signal.
     *
     * Before TERM, and on every poll after it, the process's args are re-read
     * with `ps`. If the PID is gone, or now carries different args (reused by
     * an unrelated process), it is left alone. If `ps` itself fails, identity
     * cannot be confirmed, so the failure is logged and no further signal is
     * sent. KILL is sent only when the args still match at the grace deadline.
     *
     * Unavoidable window: between the last re-check and the `kill` call, the
     * orphan could exit and its PID be reused. With no pidfd available to
     * signal a specific process instance, this cannot be closed; it requires
     * the PID space to wrap within the few milliseconds of one `kill`
     * fork/exec.
     */
    private static function killOrphan(int $pid, string $expectedArgs): void
    {
        // `kill -TERM 0` signals our own process group; `1` is init.
        if ($pid <= 1) {
            return;
        }

        if (self::processIdentity($pid, $expectedArgs) !== self::IDENTITY_SAME) {
            return;
        }

        self::runCommand(['kill', '-TERM', (string) $pid], $termExit);
        if ($termExit !== 0) {
            fwrite(STDERR, "PhpBuiltinServer::sweep(): could not TERM orphan PID {$pid}; skipping.\n");
            return;
        }

        $deadline = microtime(true) + 1.0;
        while (microtime(true) < $deadline) {
            if (self::processIdentity($pid, $expectedArgs) !== self::IDENTITY_SAME) {
                return;
            }
            usleep(50_000);
        }

        self::runCommand(['kill', '-KILL', (string) $pid], $killExit);
        if ($killExit !== 0) {
            fwrite(STDERR, "PhpBuiltinServer::sweep(): could not KILL orphan PID {$pid}.\n");
        }
    }

    /**
     * Re-read a PID's args and classify it against the args expected for it.
     *
     * `ps -p` exits 1 with no output when the PID does not exist. Any other
     * non-zero exit means `ps` itself failed; that is logged and reported as
     * IDENTITY_UNKNOWN so the caller sends no signal.
     *
     * @phpstan-impure Re-reads live process state; successive calls differ.
     *
     * @return string One of the IDENTITY_* constants.
     */
    private static function processIdentity(int $pid, string $expectedArgs): string
    {
        $current = trim(self::runCommand(['ps', '-o', 'args=', '-p', (string) $pid], $exitCode));

        if ($exitCode === 0) {
            return $current === $expectedArgs ? self::IDENTITY_SAME : self::IDENTITY_OTHER;
        }
        if ($exitCode === 1 && $current === '') {
            return self::IDENTITY_GONE;
        }

        fwrite(STDERR, "PhpBuiltinServer::sweep(): `ps -p {$pid}` failed (exit {$exitCode}); not signalling it.\n");

        return self::IDENTITY_UNKNOWN;
    }

    /**
     * Router files currently present in $dir.
     *
     * @return list<string> Absolute paths.
     */
    private static function listRouterFiles(string $dir): array
    {
        $matches = glob($dir . DIRECTORY_SEPARATOR . self::ROUTER_PREFIX . '*.php');

        return $matches === false ? [] : $matches;
    }

    /**
     * Run a command in array form (never a shell) and capture stdout.
     *
     * @param list<string> $command  argv.
     * @param int|null     $exitCode Receives the exit status; 127 when proc_open() fails.
     *
     * @param-out int $exitCode
     *
     * @return string Captured stdout, or an empty string on failure.
     */
    private static function runCommand(array $command, ?int &$exitCode = null): string
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $pipes = [];
        // @ hides the posix_spawn warning for a missing `ps`/`kill`; that failure surfaces as exit 127, which callers check.
        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            $exitCode = 127;
            return '';
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);

        return $output === false ? '' : $output;
    }
}
