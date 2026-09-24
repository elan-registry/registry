<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PhpBuiltinServer;

/**
 * Unit tests for PhpBuiltinServer's pure helpers (issue #2166).
 *
 * These exercise routerFileName()/buildCommand()/parsePsOutput()/findOrphans()/
 * staleRouterFiles()/planSweep()/isReadyResponse() and start()'s `<?php`
 * guard, without spawning any process or touching a database. Process-spawning
 * behaviour (start(), stop(), sweep() and the shutdown hook running real
 * `php -S` servers) is covered in PhpBuiltinServerProcessTest.php. Both files
 * run under `composer test:quick:ci`.
 */
#[Group('fast')]
final class PhpBuiltinServerTest extends TestCase
{
    private const ROUTER_DIR = '/private/var/folders/xy/abc123/T';

    /** PIDs used by the planSweep() fixtures. */
    private const SELF_PID = 50;
    private const LIVE_OWNER_PID = 60;
    private const DEAD_OWNER_PID = 99;
    private const ORPHAN_PID = 70;

    /** Readiness token used by the isReadyResponse() cases. */
    private const TOKEN = '0123456789abcdef0123456789abcdef';

    // ------------------------------------------------------------------
    // buildCommand()
    // ------------------------------------------------------------------

    public function testBuildCommandReturnsExactArgvShape(): void
    {
        $command = PhpBuiltinServer::buildCommand(54321, '/some/docroot', '/some/docroot/router.php');

        $this->assertSame(
            [PHP_BINARY, '-S', '127.0.0.1:54321', '-t', '/some/docroot', '/some/docroot/router.php'],
            $command
        );
    }

    // ------------------------------------------------------------------
    // parsePsOutput()
    // ------------------------------------------------------------------

    public function testParsePsOutputHandlesPaddingCrlfHeaderEmptyArgsAndBlankLines(): void
    {
        $psOutput = "  PID ARGS\n"
            . "    1 /sbin/launchd\n"
            . "  123 /usr/bin/php -S 127.0.0.1:8080 -t /doc /tmp/r.php\r\n"
            . "  456 \n"
            . "\n"
            . "78901 sleep 60   \n";

        $this->assertSame(
            [
                1 => '/sbin/launchd',
                123 => '/usr/bin/php -S 127.0.0.1:8080 -t /doc /tmp/r.php',
                78901 => 'sleep 60',
            ],
            PhpBuiltinServer::parsePsOutput($psOutput)
        );
    }

    // ------------------------------------------------------------------
    // routerFileName() round-trips through parsePsOutput()/findOrphans()
    // ------------------------------------------------------------------

    public function testRouterFileNameRoundTripsThroughParserAndOrphanDetection(): void
    {
        $nonce = str_repeat('a', 32);
        $ownerPid = 4242;
        $routerFile = PhpBuiltinServer::routerFileName($ownerPid, $nonce);

        $this->assertSame('elanreg-phpsrv-' . $ownerPid . '-' . $nonce . '.php', $routerFile);

        $router = self::ROUTER_DIR . '/' . $routerFile;
        $serverPid = 9999;
        $args = "/usr/local/bin/php -S 127.0.0.1:8080 -t /docroot {$router}";

        $snapshot = PhpBuiltinServer::parsePsOutput("{$serverPid} {$args}");
        $this->assertSame([$serverPid => $args], $snapshot);

        // Owner absent from the snapshot => orphan, with the ownerPid recovered
        // from the filename embedded in the command line.
        $orphans = PhpBuiltinServer::findOrphans($snapshot, self::ROUTER_DIR, /* selfPid */ 1);

        $this->assertSame([['pid' => $serverPid, 'args' => $args, 'ownerPid' => $ownerPid]], $orphans);
    }

    // ------------------------------------------------------------------
    // findOrphans()
    // ------------------------------------------------------------------

    public function testFindOrphansIgnoresLiveOwner(): void
    {
        $ownerPid = 100;
        $serverPid = 200;
        $router = self::ROUTER_DIR . '/' . PhpBuiltinServer::routerFileName($ownerPid, str_repeat('b', 32));

        $snapshot = [
            $ownerPid => 'some other process still running',
            $serverPid => "php -S 127.0.0.1:9000 -t /docroot {$router}",
        ];

        $orphans = PhpBuiltinServer::findOrphans($snapshot, self::ROUTER_DIR, /* selfPid */ 1);

        $this->assertSame([], $orphans, 'A server whose owner PID is present in the snapshot must not be treated as an orphan.');
    }

    public function testFindOrphansDetectsDeadOwner(): void
    {
        $ownerPid = 100;
        $serverPid = 200;
        $router = self::ROUTER_DIR . '/' . PhpBuiltinServer::routerFileName($ownerPid, str_repeat('c', 32));

        // Owner PID 100 is NOT a key in the snapshot => dead.
        $snapshot = [
            $serverPid => "php -S 127.0.0.1:9000 -t /docroot {$router}",
        ];

        $orphans = PhpBuiltinServer::findOrphans($snapshot, self::ROUTER_DIR, /* selfPid */ 1);

        $this->assertCount(1, $orphans);
        $this->assertSame($ownerPid, $orphans[0]['ownerPid']);
        $this->assertSame($serverPid, $orphans[0]['pid']);
    }

    public function testFindOrphansIgnoresOwnerEqualToSelfPid(): void
    {
        $ownerPid = 555;
        $serverPid = 777;
        $router = self::ROUTER_DIR . '/' . PhpBuiltinServer::routerFileName($ownerPid, str_repeat('d', 32));

        // Owner absent as a snapshot key, but selfPid IS the owner PID —
        // must never treat our own still-being-constructed server as an orphan.
        $snapshot = [
            $serverPid => "php -S 127.0.0.1:9000 -t /docroot {$router}",
        ];

        $orphans = PhpBuiltinServer::findOrphans($snapshot, self::ROUTER_DIR, /* selfPid */ $ownerPid);

        $this->assertSame([], $orphans, 'A server whose owner PID equals selfPid must never be treated as an orphan.');
    }

    public function testFindOrphansIgnoresDeveloperOwnServerWithNoMarker(): void
    {
        $snapshot = [
            300 => 'php -S 127.0.0.1:8000 -t /x',
        ];

        $orphans = PhpBuiltinServer::findOrphans($snapshot, self::ROUTER_DIR, 1);

        $this->assertSame([], $orphans, "A developer's own php -S with no router marker must be ignored.");
    }

    public function testFindOrphansIgnoresVimEditingRouterFile(): void
    {
        $router = self::ROUTER_DIR . '/' . PhpBuiltinServer::routerFileName(100, str_repeat('e', 32));

        $snapshot = [
            301 => "vim {$router}",
        ];

        $orphans = PhpBuiltinServer::findOrphans($snapshot, self::ROUTER_DIR, 1);

        $this->assertSame([], $orphans, 'An editor holding the router file open must not match the php -S pattern.');
    }

    public function testFindOrphansIgnoresRouterInDifferentDirectory(): void
    {
        // Same directory, different spelling: /var/... vs /private/var/...
        $unresolvedDir = '/var/folders/xy/abc123/T';
        $ownerPid = 100;
        $router = $unresolvedDir . '/' . PhpBuiltinServer::routerFileName($ownerPid, str_repeat('f', 32));

        $snapshot = [
            302 => "php -S 127.0.0.1:9000 -t /docroot {$router}",
        ];

        $orphans = PhpBuiltinServer::findOrphans($snapshot, self::ROUTER_DIR, 1);

        $this->assertSame([], $orphans, 'A router path spelled with a different directory string must not match.');
    }

    public function testFindOrphansIgnoresLegacyTempnamRouter(): void
    {
        $snapshot = [
            303 => 'php -S 127.0.0.1:34047 -t /x /tmp/brevo_webhook_router_abc.php',
        ];

        $orphans = PhpBuiltinServer::findOrphans($snapshot, '/tmp', 1);

        $this->assertSame([], $orphans, 'A pre-#2166 tempnam-era router filename must not match the new pattern.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedPsLineProvider(): array
    {
        return [
            'no pid' => ['not-a-pid php -S 127.0.0.1:9000 -t /x /tmp/elanreg-phpsrv-1-' . str_repeat('a', 32) . '.php'],
            'blank' => [''],
            'garbage' => ['%%%garbage%%%'],
        ];
    }

    #[DataProvider('malformedPsLineProvider')]
    public function testFindOrphansSkipsMalformedSnapshotLinesWithoutError(string $rawLine): void
    {
        // Route the raw line through parsePsOutput() first, as sweep() does.
        $snapshot = PhpBuiltinServer::parsePsOutput($rawLine);

        $orphans = PhpBuiltinServer::findOrphans($snapshot, self::ROUTER_DIR, 1);

        $this->assertSame([], $orphans);
    }

    public function testFindOrphansMatchesPlainFormWithoutExtraFlags(): void
    {
        $ownerPid = 400;
        $serverPid = 401;
        $router = self::ROUTER_DIR . '/' . PhpBuiltinServer::routerFileName($ownerPid, str_repeat('1', 32));

        $snapshot = [
            $serverPid => "php -S 127.0.0.1:8080 -t /docroot {$router}",
        ];

        $orphans = PhpBuiltinServer::findOrphans($snapshot, self::ROUTER_DIR, 1);

        $this->assertCount(1, $orphans, 'The plain `php -S ... -t ... router` form (no extra flags) must match.');
        $this->assertSame($ownerPid, $orphans[0]['ownerPid']);
    }

    public function testFindOrphansMatchesFormWithExtraDFlag(): void
    {
        $ownerPid = 402;
        $serverPid = 403;
        $router = self::ROUTER_DIR . '/' . PhpBuiltinServer::routerFileName($ownerPid, str_repeat('2', 32));

        $snapshot = [
            $serverPid => "php -d foo=bar -S 127.0.0.1:8080 -t /docroot {$router}",
        ];

        $orphans = PhpBuiltinServer::findOrphans($snapshot, self::ROUTER_DIR, 1);

        $this->assertCount(1, $orphans, 'A binary invoked with extra flags before -S (e.g. `php -d foo=bar -S ...`) must match.');
        $this->assertSame($ownerPid, $orphans[0]['ownerPid']);
    }

    public function testFindOrphansMatchesFullAbsolutePhpBinaryPath(): void
    {
        $ownerPid = 404;
        $serverPid = 405;
        $router = self::ROUTER_DIR . '/' . PhpBuiltinServer::routerFileName($ownerPid, str_repeat('3', 32));

        $snapshot = [
            $serverPid => "/usr/local/bin/php -S 127.0.0.1:8080 -t /docroot {$router}",
        ];

        $orphans = PhpBuiltinServer::findOrphans($snapshot, self::ROUTER_DIR, 1);

        $this->assertCount(1, $orphans, 'A full absolute PHP_BINARY-style path must match.');
        $this->assertSame($ownerPid, $orphans[0]['ownerPid']);
    }

    public function testFindOrphansIgnoresNonLoopbackBind(): void
    {
        $ownerPid = 406;
        $serverPid = 407;
        $router = self::ROUTER_DIR . '/' . PhpBuiltinServer::routerFileName($ownerPid, str_repeat('4', 32));

        $snapshot = [
            $serverPid => "php -S 0.0.0.0:8080 -t /docroot {$router}",
        ];

        $orphans = PhpBuiltinServer::findOrphans($snapshot, self::ROUTER_DIR, 1);

        $this->assertSame([], $orphans, 'Only servers bound to 127.0.0.1 are ours; a non-loopback bind must not match.');
    }

    public function testFindOrphansHandlesMalformedArgsStringsWithoutError(): void
    {
        $snapshot = [
            10 => '',
            11 => 'not even a command line at all !!!! ??? ###',
            12 => "php -S 127.0.0.1:notaport -t /docroot " . self::ROUTER_DIR . '/' . PhpBuiltinServer::routerFileName(1, str_repeat('a', 32)) . '.php.php',
            13 => str_repeat('x', 5000),
        ];

        $orphans = PhpBuiltinServer::findOrphans($snapshot, self::ROUTER_DIR, 1);

        $this->assertSame([], $orphans);
    }

    /**
     * @return array<string, array{0: array<mixed, mixed>}>
     */
    public static function wrongTypedSnapshotProvider(): array
    {
        $validOrphanArgs = 'php -S 127.0.0.1:9000 -t /docroot '
            . self::ROUTER_DIR . '/' . PhpBuiltinServer::routerFileName(100, str_repeat('a', 32));

        return [
            'int args' => [[5 => 123]],
            'null args' => [[5 => null]],
            'string pid with valid orphan args' => [['abc' => $validOrphanArgs]],
        ];
    }

    /**
     * @param array<mixed, mixed> $snapshot
     */
    #[DataProvider('wrongTypedSnapshotProvider')]
    public function testFindOrphansSkipsWrongTypedEntries(array $snapshot): void
    {
        $this->assertSame([], PhpBuiltinServer::findOrphans($snapshot, self::ROUTER_DIR, 1));
    }

    // ------------------------------------------------------------------
    // staleRouterFiles()
    // ------------------------------------------------------------------

    public function testStaleRouterFilesSeparatesByLiveAndDeadOwner(): void
    {
        $liveOwnerFile = self::ROUTER_DIR . '/' . PhpBuiltinServer::routerFileName(500, str_repeat('a', 32));
        $deadOwnerFile = self::ROUTER_DIR . '/' . PhpBuiltinServer::routerFileName(501, str_repeat('b', 32));

        $stale = PhpBuiltinServer::staleRouterFiles([$liveOwnerFile, $deadOwnerFile], [500]);

        $this->assertSame([$deadOwnerFile], $stale);
    }

    public function testStaleRouterFilesIgnoresNonMatchingFileNames(): void
    {
        $files = [
            self::ROUTER_DIR . '/not-a-router-file.php',
            self::ROUTER_DIR . '/elanreg-phpsrv-not-numeric-' . str_repeat('a', 32) . '.php',
            self::ROUTER_DIR . '/elanreg-phpsrv-123-tooshort.php',
            self::ROUTER_DIR . '/random.txt',
        ];

        $stale = PhpBuiltinServer::staleRouterFiles($files, []);

        $this->assertSame([], $stale, 'Non-matching file names must be ignored, not treated as stale.');
    }

    public function testStaleRouterFilesIgnoresMalformedNames(): void
    {
        $files = [
            self::ROUTER_DIR . '/elanreg-phpsrv-abc-' . str_repeat('a', 32) . '.php',    // non-numeric pid
            self::ROUTER_DIR . '/elanreg-phpsrv-123-' . str_repeat('a', 10) . '.php',    // nonce too short
            self::ROUTER_DIR . '/elanreg-phpsrv-123-' . str_repeat('z', 32) . '.php',    // nonce not hex
            self::ROUTER_DIR . '/elanreg-phpsrv--' . str_repeat('a', 32) . '.php',       // empty pid
        ];

        $stale = PhpBuiltinServer::staleRouterFiles($files, [123]);

        $this->assertSame([], $stale);
    }

    public function testStaleRouterFilesSkipsNonStringFile(): void
    {
        $this->assertSame([], PhpBuiltinServer::staleRouterFiles([42], []));
    }

    public function testStaleRouterFilesIgnoresNonIntLivePidsWithoutWarning(): void
    {
        $deadOwnerFile = self::ROUTER_DIR . '/' . PhpBuiltinServer::routerFileName(601, str_repeat('c', 32));

        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;
            return true;
        });
        try {
            $stale = PhpBuiltinServer::staleRouterFiles([$deadOwnerFile], [null, 1.5]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings, 'Non-int live PIDs must be filtered, not handed to array_flip().');
        $this->assertSame([$deadOwnerFile], $stale);
    }

    // ------------------------------------------------------------------
    // planSweep()
    // ------------------------------------------------------------------

    private static function orphanArgs(): string
    {
        return 'php -S 127.0.0.1:9000 -t /doc ' . self::ROUTER_DIR . '/'
            . PhpBuiltinServer::routerFileName(self::DEAD_OWNER_PID, str_repeat('a', 32));
    }

    /**
     * A snapshot containing a live owner, its server, and an orphan whose
     * owner is dead. SELF_PID is deliberately absent, so keeping the
     * sweeper's own file relies on the explicit selfPid rule.
     */
    private static function validSnapshot(): string
    {
        $liveServerArgs = 'php -S 127.0.0.1:9001 -t /doc ' . self::ROUTER_DIR . '/'
            . PhpBuiltinServer::routerFileName(self::LIVE_OWNER_PID, str_repeat('b', 32));

        return "    1 /sbin/launchd\n"
            . '   ' . self::SELF_PID . " php vendor/bin/phpunit\n"
            . '   ' . self::LIVE_OWNER_PID . " sleep 60\n"
            . '   ' . self::ORPHAN_PID . ' ' . self::orphanArgs() . "\n"
            . "   71 {$liveServerArgs}\n";
    }

    /**
     * @return array{dead: string, live: string, own: string}
     */
    private static function routerFiles(): array
    {
        return [
            'dead' => self::ROUTER_DIR . '/' . PhpBuiltinServer::routerFileName(self::DEAD_OWNER_PID, str_repeat('a', 32)),
            'live' => self::ROUTER_DIR . '/' . PhpBuiltinServer::routerFileName(self::LIVE_OWNER_PID, str_repeat('b', 32)),
            'own' => self::ROUTER_DIR . '/' . PhpBuiltinServer::routerFileName(self::SELF_PID, str_repeat('c', 32)),
        ];
    }

    public function testPlanSweepPlansNothingWhenPsFails(): void
    {
        // Even with parseable output, a non-zero exit means the snapshot
        // cannot be trusted as complete.
        $plan = PhpBuiltinServer::planSweep(self::validSnapshot(), 1, array_values(self::routerFiles()), self::ROUTER_DIR, self::SELF_PID);

        $this->assertSame(['usable' => false, 'kill' => [], 'unlink' => []], $plan);
    }

    public function testPlanSweepPlansNothingWhenPsOutputIsEmpty(): void
    {
        $plan = PhpBuiltinServer::planSweep("  \n", 0, array_values(self::routerFiles()), self::ROUTER_DIR, self::SELF_PID);

        $this->assertSame(['usable' => false, 'kill' => [], 'unlink' => []], $plan);
    }

    public function testPlanSweepPlansNothingWhenExitZeroOutputIsUnparseable(): void
    {
        // Exit 0 with non-empty output that parses to nothing: every owner
        // would look dead and every live run's router file would be unlinked.
        $plan = PhpBuiltinServer::planSweep("garbage line\nPID COMMAND\n", 0, array_values(self::routerFiles()), self::ROUTER_DIR, self::SELF_PID);

        $this->assertSame(['usable' => false, 'kill' => [], 'unlink' => []], $plan);
    }

    public function testPlanSweepPlansNothingWhenSnapshotLacksOwnPid(): void
    {
        // Parseable, but missing the sweeping process itself: truncated or
        // otherwise incomplete, so it cannot prove any owner is dead.
        $snapshot = str_replace('   ' . self::SELF_PID . " php vendor/bin/phpunit\n", '', self::validSnapshot());

        $plan = PhpBuiltinServer::planSweep($snapshot, 0, array_values(self::routerFiles()), self::ROUTER_DIR, self::SELF_PID);

        $this->assertSame(['usable' => false, 'kill' => [], 'unlink' => []], $plan);
    }

    public function testPlanSweepKillsOrphanAndUnlinksOnlyDeadOwnersFile(): void
    {
        $files = self::routerFiles();

        $plan = PhpBuiltinServer::planSweep(self::validSnapshot(), 0, array_values($files), self::ROUTER_DIR, self::SELF_PID);

        $this->assertSame(
            [
                'usable' => true,
                'kill' => [['pid' => self::ORPHAN_PID, 'args' => self::orphanArgs(), 'ownerPid' => self::DEAD_OWNER_PID]],
                'unlink' => [$files['dead']],
            ],
            $plan
        );
    }

    public function testPlanSweepSkipsNonStringRouterFiles(): void
    {
        $files = self::routerFiles();

        $plan = PhpBuiltinServer::planSweep(self::validSnapshot(), 0, [42, null, $files['dead']], self::ROUTER_DIR, self::SELF_PID);

        $this->assertSame([$files['dead']], $plan['unlink']);
    }

    // ------------------------------------------------------------------
    // isReadyResponse()
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: array<mixed>, 1: string|false, 2: bool}>
     */
    public static function readinessProvider(): array
    {
        $ok = ['HTTP/1.1 200 OK', 'Content-Type: text/plain'];

        return [
            '200 + right token' => [$ok, self::TOKEN, true],
            '200 + wrong token' => [$ok, 'fedcba9876543210fedcba9876543210', false],
            '200 + token with trailing newline' => [$ok, self::TOKEN . "\n", false],
            '500 + right token' => [['HTTP/1.1 500 Internal Server Error'], self::TOKEN, false],
            '2000 status is not 200' => [['HTTP/1.1 2000 Nope'], self::TOKEN, false],
            'false body' => [$ok, false, false],
            'empty headers' => [[], self::TOKEN, false],
            'non-string status line' => [[200], self::TOKEN, false],
        ];
    }

    /**
     * @param array<mixed> $headers
     */
    #[DataProvider('readinessProvider')]
    public function testIsReadyResponse(array $headers, string|false $body, bool $expected): void
    {
        $this->assertSame($expected, PhpBuiltinServer::isReadyResponse($headers, $body, self::TOKEN));
    }

    public function testIsReadyResponseRejectsEmptyToken(): void
    {
        $this->assertFalse(PhpBuiltinServer::isReadyResponse(['HTTP/1.1 200 OK'], '', ''));
    }

    // ------------------------------------------------------------------
    // start(): <?php rejection
    // ------------------------------------------------------------------

    /**
     * PHP opening tags are case-insensitive and may follow whitespace, so
     * each of these would open a second PHP block inside the router.
     *
     * @return array<string, array{0: string}>
     */
    public static function phpTagBodyProvider(): array
    {
        return [
            'plain' => ['<?php echo "hi";'],
            'leading spaces' => ['  <?php echo "hi";'],
            'leading newline' => ["\n<?php echo 'hi';"],
            'upper case' => ['<?PHP echo "hi";'],
            'mixed case' => ['<?Php echo "hi";'],
        ];
    }

    #[DataProvider('phpTagBodyProvider')]
    public function testStartRejectsRouterBodyStartingWithPhpTag(string $routerBody): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not start with "<?php"');

        // A nonexistent docroot: the tag guard must fire before docroot
        // validation (and so before sweep() or any spawn), or the message
        // would be the docroot error instead.
        PhpBuiltinServer::start('/this/path/definitely/does/not/exist/2166', $routerBody);
    }

    public function testStartAllowsPhpTagLaterInRouterBody(): void
    {
        // Not a leading tag, so the guard passes and the docroot check fires.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Document root is not a directory');

        PhpBuiltinServer::start('/this/path/definitely/does/not/exist/2166', 'echo "<?php";');
    }
}
