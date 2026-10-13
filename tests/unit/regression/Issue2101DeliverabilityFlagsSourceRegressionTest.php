<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #2101: the owner profile is the only record of the email flags.
 *
 * This test scans the source tree. It fails when old code comes back:
 * a read or write of the removed `email_suppressed` column, a `cars` query
 * that reads `email_bounced`, a profile insert without the address-since
 * columns, the removed history-row code, or an opt-out page that reads the
 * block or bounce flag.
 *
 * Every matcher has a negative control. The control runs the same matcher
 * over an in-memory string that holds a forbidden pattern, and asserts a hit.
 *
 * @issue 2101
 * @link https://github.com/elan-registry/registry/issues/2101
 */
#[Group('regression')]
final class Issue2101DeliverabilityFlagsSourceRegressionTest extends TestCase
{
    /**
     * Files that may name `email_suppressed`. Keep this list short.
     * Path (relative to the project root) => reason.
     *
     * @var array<string, string>
     */
    private const EMAIL_SUPPRESSED_ALLOW_LIST = [
        'tests/unit/regression/Issue2101DeliverabilityFlagsSourceRegressionTest.php'
            => 'This test names the column in its patterns and controls.',
        'usersc/classes/Migration/DeliverabilityMigrationQueries.php'
            => 'The #2101 migration reads the old column to copy the flag to profiles.',
        'tests/integration/database/AddCarsVerificationAttemptsColumnsSandboxTest.php'
            => 'It runs an older migration on a schema that still has the car flag columns.',
        'tests/integration/database/DeliverabilityMigrationFixture.php'
            => 'It seeds the old columns that the #2101 migration copies.',
        'tests/integration/database/DeliverabilityMigrationUpSandboxTest.php'
            => 'It checks that up() removes the old columns.',
        'tests/integration/database/DeliverabilityMigrationRerunSandboxTest.php'
            => 'It stops up() part way, while the old columns still exist.',
        'tests/integration/database/DeliverabilityMigrationDownSandboxTest.php'
            => 'It checks that down() restores the old columns and their values.',
        'tests/integration/database/DeliverabilityMigrationFreshAndRollbackSandboxTest.php'
            => 'It checks the schema before and after the #2101 migration.',
    ];

    private const EMAIL_SUPPRESSED_PATTERN = '/email_suppressed/';

    /** `->insert('profiles'` calls that the matcher cannot see are a known exception: Owner::create(). */
    private const PROFILE_INSERT_REQUIRED_COLUMNS = ['email_address_since', 'email_address_seen'];

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    // ------------------------------------------------------------------
    // Claim 1: no code reads or writes email_suppressed
    // ------------------------------------------------------------------

    public function testNoCodeNamesEmailSuppressedColumn(): void
    {
        $files = $this->sourceFiles(['usersc', 'app', 'tests']);
        $this->assertNotEmpty($files, 'The scan found no files. The directory layout changed.');
        $this->assertContains(
            'usersc/classes/Car/CarRepository.php',
            $files,
            'The scan must include CarRepository.php, or it scans the wrong tree.'
        );

        $hits = [];
        foreach ($files as $relative) {
            if (isset(self::EMAIL_SUPPRESSED_ALLOW_LIST[$relative])) {
                continue;
            }
            $code = $this->codeOnly($relative);
            $count = preg_match_all(self::EMAIL_SUPPRESSED_PATTERN, $code);
            if ($count > 0) {
                $hits[] = "{$relative} ({$count} hit(s))";
            }
        }

        $this->assertSame(
            [],
            $hits,
            "These files still name the removed `email_suppressed` column (#2101):\n" . implode("\n", $hits)
        );
    }

    public function testEmailSuppressedAllowListEntriesExist(): void
    {
        foreach (array_keys(self::EMAIL_SUPPRESSED_ALLOW_LIST) as $relative) {
            $this->assertFileExists(
                $this->root . '/' . $relative,
                'An allow-list entry points to a file that is gone. Delete the entry.'
            );
        }
    }

    public function testNegativeControlEmailSuppressedPatternMatches(): void
    {
        $this->assertSame(1, preg_match(self::EMAIL_SUPPRESSED_PATTERN, "SELECT email_suppressed FROM cars"));
        $this->assertSame(1, preg_match(self::EMAIL_SUPPRESSED_PATTERN, '$car->email_suppressed = 1;'));
        $this->assertSame(0, preg_match(self::EMAIL_SUPPRESSED_PATTERN, 'p.email_opted_out'));
    }

    // ------------------------------------------------------------------
    // Claim 2: email_bounced is not a cars column any more
    // ------------------------------------------------------------------

    public function testNoCarsQueryOrCarObjectReadsEmailBounced(): void
    {
        $files = $this->sourceFiles(['usersc', 'app', 'tests']);
        $this->assertNotEmpty($files);

        $hits = [];
        foreach ($files as $relative) {
            if (isset(self::EMAIL_SUPPRESSED_ALLOW_LIST[$relative])) {
                continue; // Same two files: this test, and the migration that reads the old cars columns.
            }
            foreach (self::carsBouncedHits($this->codeOnly($relative)) as $hit) {
                $hits[] = "{$relative}: {$hit}";
            }
        }

        $this->assertSame(
            [],
            $hits,
            "These places read `email_bounced` from a car. It lives on profiles now (#2101):\n"
                . implode("\n", $hits)
        );
    }

    public function testNegativeControlCarsBouncedMatcherFindsForbiddenPatterns(): void
    {
        $this->assertNotSame([], self::carsBouncedHits('SELECT cars.email_bounced FROM cars'));
        $this->assertNotSame([], self::carsBouncedHits('if ($car->email_bounced) { }'));
        $this->assertNotSame([], self::carsBouncedHits('if ($carData->email_bounced === 1) { }'));
        $this->assertNotSame([], self::carsBouncedHits("\$db->query('SELECT id, email_bounced FROM cars WHERE id = ?', [1]);"));
        $this->assertNotSame([], self::carsBouncedHits("\$db->query('UPDATE cars SET email_bounced = 1 WHERE id = ?', [1]);"));
    }

    public function testNegativeControlCarsBouncedMatcherAllowsProfileReads(): void
    {
        $this->assertSame([], self::carsBouncedHits('$state->email_bounced'));
        $this->assertSame([], self::carsBouncedHits(
            "\$db->query('SELECT cars.id, p.email_bounced FROM cars LEFT JOIN profiles p ON p.user_id = cars.user_id');"
        ));
        $this->assertSame([], self::carsBouncedHits("\$db->query('UPDATE profiles SET email_bounced = 1 WHERE user_id = ?');"));
    }

    // ------------------------------------------------------------------
    // Claim 3: every profile insert sets the address-since columns
    // ------------------------------------------------------------------

    public function testEveryProfilesInsertSetsAddressSinceAndSeen(): void
    {
        $files = $this->sourceFiles(['usersc', 'app', 'database/seeds']);
        $this->assertNotEmpty($files);

        $inserts = 0;
        $offenders = [];
        foreach ($files as $relative) {
            if (str_starts_with($relative, 'usersc/plugins/') && !str_starts_with($relative, 'usersc/plugins/hooker/')) {
                continue; // Third-party plugin code, not ours.
            }
            foreach (self::profileInsertColumnLists($this->codeOnly($relative)) as $columns) {
                $inserts++;
                $missing = array_values(array_diff(self::PROFILE_INSERT_REQUIRED_COLUMNS, $columns));
                if ($missing !== []) {
                    $offenders[] = "{$relative}: missing " . implode(', ', $missing);
                }
            }
        }

        // The known sites are usersc/scripts/during_user_creation.php and
        // app/admin/includes/account-cleanup-helpers.php. Owner::create() uses
        // a table-name property, so this matcher cannot see it (known exception).
        $this->assertGreaterThanOrEqual(
            2,
            $inserts,
            'The scan found fewer profile inserts than the two known sites. The matcher or the code moved.'
        );
        $this->assertSame(
            [],
            $offenders,
            "These profile inserts do not set email_address_since and email_address_seen (#2101):\n"
                . implode("\n", $offenders)
        );
    }

    public function testNegativeControlProfileInsertMatcherSeesBothStyles(): void
    {
        $bad = "\$db->insert('profiles', ['user_id' => 5, 'bio' => 'x']);";
        $lists = self::profileInsertColumnLists($bad);
        $this->assertCount(1, $lists);
        $this->assertSame(['user_id', 'bio'], $lists[0]);

        $good = "\$db->insert('profiles', ['user_id' => \$id, 'email_address_since' => \$a, "
            . "'email_address_seen' => strtolower(\$b)]);";
        $lists = self::profileInsertColumnLists($good);
        $this->assertCount(1, $lists);
        $this->assertSame([], array_diff(self::PROFILE_INSERT_REQUIRED_COLUMNS, $lists[0]));

        $sql = "\$db->query('INSERT INTO profiles (user_id, bio) VALUES (?, ?)');";
        $lists = self::profileInsertColumnLists($sql);
        $this->assertCount(1, $lists);
        $this->assertSame(['user_id', 'bio'], $lists[0]);
    }

    // ------------------------------------------------------------------
    // Claim 4: the removed history-row code is gone
    // ------------------------------------------------------------------

    public function testRemovedHistoryRowCodeIsGone(): void
    {
        $files = $this->sourceFiles(['usersc', 'app', 'tests']);
        $this->assertNotEmpty($files);

        $patterns = self::removedCodePatterns();
        $hits = [];
        foreach ($files as $relative) {
            if ($relative === 'tests/unit/regression/Issue2101DeliverabilityFlagsSourceRegressionTest.php') {
                continue;
            }
            $code = $this->codeOnly($relative);
            foreach ($patterns as $label => $pattern) {
                if (preg_match($pattern, $code) === 1) {
                    $hits[] = "{$relative}: {$label}";
                }
            }
        }

        $this->assertSame([], $hits, "Removed code is back (#2101):\n" . implode("\n", $hits));
    }

    public function testRemovedHistoryRowCodeIsGoneFromItsOwnFiles(): void
    {
        $repo = $this->codeOnly('usersc/classes/Car/CarRepository.php');
        $this->assertStringContainsString('function latestFlagClearAt', $repo, 'The replacement method must exist.');
        $this->assertStringNotContainsString('findLatestHistoryOperationByCarIds', $repo);

        $builder = $this->codeOnly('usersc/classes/Car/EmailNoticeBuilder.php');
        $this->assertStringNotContainsString('const OPERATION_', $builder);

        $tab = $this->codeOnly('app/admin/includes/tab-verification.php');
        $this->assertStringContainsString('email_blocked', $tab, 'The tab must still read the profile flags.');
        $this->assertStringNotContainsString('suppressedHist', $tab);
    }

    public function testNegativeControlRemovedCodePatternsMatch(): void
    {
        $samples = [
            'findLatestHistoryOperationByCarIds' => '$repo->findLatestHistoryOperationByCarIds([1], "EMAIL SUPPRESSED");',
            'EmailNoticeBuilder::OPERATION_*' => 'EmailNoticeBuilder::OPERATION_BOUNCED',
            'suppressedHist' => '$suppressedHist = [];',
            'verifyHistoryFieldsForAdminAction' => 'verifyHistoryFieldsForAdminAction($car);',
            'userSettingsHistoryFields' => 'userSettingsHistoryFields($car, 1)',
        ];
        $patterns = self::removedCodePatterns();
        $this->assertSame(array_keys($patterns), array_keys($samples), 'Add a control for each pattern.');
        foreach ($patterns as $label => $pattern) {
            $this->assertSame(1, preg_match($pattern, $samples[$label]), "Pattern '{$label}' does not match its sample.");
        }
    }

    // ------------------------------------------------------------------
    // Claim 5: the opt-out page reads email_opted_out only
    // ------------------------------------------------------------------

    public function testOptOutPageReadsOnlyOptedOutFlag(): void
    {
        $pages = ['app/verify/verify_car.php', 'app/views/cars/_verify_optout_confirm.php'];
        foreach ($pages as $relative) {
            $code = $this->codeOnly($relative);
            $this->assertNotSame('', $code, "{$relative} has no code.");
            $this->assertSame(
                [],
                self::blockOrBounceReads($code),
                "{$relative} reads email_blocked or email_bounced. The opt-out page reads email_opted_out only."
            );
        }

        $this->assertStringContainsString(
            'findProfileOptedOut',
            $this->codeOnly('app/verify/verify_car.php'),
            'The opt-out branch must read the opt-out flag.'
        );
    }

    public function testNegativeControlBlockOrBounceReadMatcher(): void
    {
        $this->assertNotSame([], self::blockOrBounceReads('$x = $state->email_blocked;'));
        $this->assertNotSame([], self::blockOrBounceReads("\$r = \$db->query('SELECT email_bounced FROM profiles');"));
        $this->assertSame([], self::blockOrBounceReads('$x = $state->email_opted_out;'));
    }

    // ------------------------------------------------------------------
    // Matchers (pure functions of a string, so the controls can reuse them)
    // ------------------------------------------------------------------

    /**
     * @return list<string> One entry per place where `email_bounced` is read from a car
     */
    private static function carsBouncedHits(string $code): array
    {
        $hits = [];

        if (preg_match_all('/\bcars\.email_bounced\b/', $code, $m) > 0) {
            $hits[] = 'cars.email_bounced x' . count($m[0]);
        }
        if (preg_match_all('/\$car(?:Data)?->email_bounced\b/', $code, $m) > 0) {
            $hits[] = implode(', ', array_unique($m[0])) . ' x' . count($m[0]);
        }

        // A SQL string on `cars` that names email_bounced without a profile alias.
        $pattern = '/\b(SELECT|UPDATE|INSERT|DELETE)\b[^;]{0,2000}/is';
        if (preg_match_all($pattern, $code, $statements) > 0) {
            foreach ($statements[0] as $statement) {
                if (preg_match('/\b(?:FROM|UPDATE|INTO)\s+`?cars\b/i', $statement) !== 1) {
                    continue;
                }
                // Cut at the end of the PHP string so that code after the query is not read.
                $statement = preg_split('/[\'"]\s*(?:,|\)|;)/', $statement)[0] ?? $statement;
                if (preg_match('/(?<![\w.>$])email_bounced\b/', $statement) === 1) {
                    $hits[] = 'cars SQL names email_bounced: ' . trim(substr($statement, 0, 80));
                }
            }
        }

        return $hits;
    }

    /**
     * @return list<list<string>> Column names set by each `profiles` insert in the code
     */
    private static function profileInsertColumnLists(string $code): array
    {
        $lists = [];

        // $db->insert('profiles', [ 'col' => value, ... ])
        if (preg_match_all('/->insert\(\s*([\'"])profiles\1\s*,\s*\[/', $code, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[0] as [$matched, $offset]) {
                $start = $offset + strlen($matched);
                $body = self::balancedBody($code, $start, '[', ']');
                preg_match_all('/[\'"]([a-z_]+)[\'"]\s*=>/', $body, $keys);
                $lists[] = $keys[1];
            }
        }

        // INSERT INTO profiles (col, col) VALUES ...
        if (preg_match_all('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?profiles`?\s*\(([^)]*)\)/i', $code, $m) > 0) {
            foreach ($m[1] as $columnList) {
                $lists[] = array_values(array_filter(array_map(
                    static fn (string $c): string => trim($c, " \t\r\n`"),
                    explode(',', $columnList)
                )));
            }
        }

        return $lists;
    }

    /**
     * @return array<string, string> label => regex
     */
    private static function removedCodePatterns(): array
    {
        return [
            'findLatestHistoryOperationByCarIds' => '/\bfindLatestHistoryOperationByCarIds\b/',
            'EmailNoticeBuilder::OPERATION_*' => '/\bEmailNoticeBuilder::OPERATION_[A-Z_]+/',
            'suppressedHist' => '/\bsuppressedHist\b/',
            'verifyHistoryFieldsForAdminAction' => '/\bverifyHistoryFieldsForAdminAction\b/',
            'userSettingsHistoryFields' => '/\buserSettingsHistoryFields\b/',
        ];
    }

    /**
     * @return list<string>
     */
    private static function blockOrBounceReads(string $code): array
    {
        preg_match_all('/\bemail_(?:blocked|bounced)\w*/', $code, $m);

        return $m[0];
    }

    /** Text from `$offset` to the bracket that closes the one before it. */
    private static function balancedBody(string $code, int $offset, string $open, string $close): string
    {
        $depth = 1;
        $length = strlen($code);
        for ($i = $offset; $i < $length; $i++) {
            if ($code[$i] === $open) {
                $depth++;
            } elseif ($code[$i] === $close && --$depth === 0) {
                return substr($code, $offset, $i - $offset);
            }
        }

        return substr($code, $offset);
    }

    // ------------------------------------------------------------------
    // File helpers
    // ------------------------------------------------------------------

    /**
     * @param list<string> $directories Relative to the project root
     * @return list<string> Relative paths of .php and .js files, sorted
     */
    private function sourceFiles(array $directories): array
    {
        $files = [];
        foreach ($directories as $directory) {
            $base = $this->root . '/' . $directory;
            if (!is_dir($base)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                if (preg_match('/\.(php|js)$/', $path) !== 1 || preg_match('#/(vendor|node_modules)/#', $path) === 1) {
                    continue;
                }
                $files[] = substr($path, strlen($this->root) + 1);
            }
        }
        sort($files);

        return $files;
    }

    /** File content without PHP comments, so that a "why" comment can name a removed column. */
    private function codeOnly(string $relative): string
    {
        $source = (string) file_get_contents($this->root . '/' . $relative);
        if (!str_ends_with($relative, '.php')) {
            return $source;
        }

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }
}
