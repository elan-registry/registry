<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #2356: a deliverability flag applies only while its address matches
 * `users.email`, and only a confirmed email change clears a flag.
 *
 * This test scans the source tree. It fails when old code comes back or when
 * new code skips the address-match rule:
 * - A new code path writes `users.email` and is not in the writers list.
 * - A reader of `email_blocked`, `email_bounced` or their `_address` columns
 *   uses neither `effectiveEmailFlags()` nor `emailFlagActiveSql()`.
 * - A deliverability path reads `cars.email`. The verification send and
 *   the Brevo event match use `users.email` only.
 * - A removed method or constant is back (code and docs).
 * - The account notice template shows "Brevo", or prints an address that it
 *   did not escape.
 * - The `verifySuccess` hook calls more than one clear method.
 *
 * Every check has a negative control. The control runs the same matcher over
 * an in-memory string that holds the forbidden pattern, and asserts a hit.
 *
 * `users/**` is gitignored, so CI has only the few tracked upstream files.
 * When `users/verify.php` is missing, the writer checks skip the upstream
 * `users/` rows and still scan the project code. Outside CI, a missing
 * upstream tree fails testUpstreamWriterRowsAreChecked(), so a local run
 * cannot skip those rows without a signal.
 *
 * @issue 2356
 * @link https://github.com/elan-registry/registry/issues/2356
 */
#[Group('regression')]
final class Issue2356EmailFlagMatchSourceRegressionTest extends TestCase
{
    // ------------------------------------------------------------------
    // Registries
    // ------------------------------------------------------------------

    /**
     * Every code path that updates `users.email`, and how it keeps
     * `email_address_since` and `email_address_seen` right. EMAIL_SYSTEM.md,
     * "Address-Match Rule", gives the rule.
     *
     * Kinds:
     * - sets: the file sets both columns itself.
     * - via_owner: the file writes through Owner::updateProfileAndSync(), which sets them.
     * - hook: upstream code writes the email. The verifySuccess hook then sets them.
     * - generic: a shared helper. A caller must set the columns. See the reason.
     * - exception: a known gap. The reason names the accepted risk.
     *
     * @var array<string, array{0: string, 1: string}> path => [kind, reason]
     */
    private const USERS_EMAIL_WRITERS = [
        'usersc/classes/Owner.php' => [
            'sets',
            'Owner::update() locks the profile row and sets both columns on a normalized change. '
                . 'Owner::create() is a known exception: it has no production caller.',
        ],
        'usersc/user_settings.php' => [
            'via_owner',
            'The email_act == 0 path passes the new address to Owner::updateProfileAndSync().',
        ],
        'app/admin/includes/account-cleanup-helpers.php' => [
            'sets',
            'The restore inserts a new user row and its profile row with both columns.',
        ],
        'users/verify.php' => [
            'hook',
            'Upstream writes users.email at the email_new swap. The verifySuccess hook sets both columns.',
        ],
        'users/classes/User.php' => [
            'generic',
            'User::create() and User::update() are generic. Each caller is a row in this list.',
        ],
        'users/helpers/us_helpers.php' => [
            'generic',
            'updateEmail() has no caller in this project (checked by testUpdateEmailHelperHasNoCaller).',
        ],
        'users/views/_admin_user.php' => [
            'exception',
            'Accepted risk: the upstream admin user form changes users.email and runs no hook. '
                . 'The next verifySuccess counts the change.',
        ],
        'users/user_settings.php' => [
            'exception',
            'Upstream account page. Its email_act == 0 branch writes users.email with no since/seen. '
                . 'Unreachable: users/includes/loader.php redirects this page to the usersc/ override.',
        ],
    ];

    /**
     * Files that may name the four stored flag columns in code. Anything
     * else must read the flags through effectiveEmailFlags() or
     * emailFlagActiveSql().
     *
     * @var array<string, string> path => reason
     */
    private const FLAG_COLUMN_ALLOW_LIST = [
        'usersc/classes/Car/CarRepository.php'
            => 'The repository owns the profile reads and writes. See ALLOWED_REPOSITORY_METHODS.',
        'usersc/classes/Car/DeliverabilityFlagLog.php'
            => 'It holds the flag names as constants. It reads no column.',
        'usersc/classes/Migration/DeliverabilityMigrationQueries.php'
            => 'Migration code copies the old columns to the profile.',
        'usersc/user_settings.php'
            => 'Fallback when the effective read fails. See testUserSettingsNamesOnlyTheFallbackFlag.',
    ];

    /**
     * Methods of CarRepository that may name the stored flag columns:
     * the profile writers, their lock reads, and the readers that apply
     * the address-match rule.
     *
     * `lockProfileAndFindAddresses` is on the list (#2358). It reads the
     * stored address columns, so account deletion can find every address
     * that the plugin message log may hold for the user. It reads no flag
     * value and decides nothing about a flag, so it cannot bypass the match
     * rule. testAddressLockReadsTheAddressColumnsAndNoFlagValue keeps it so.
     *
     * @var list<string>
     */
    private const ALLOWED_REPOSITORY_METHODS = [
        '(class-level)',
        'findProfileEmailState',
        'effectiveEmailFlags',
        'clearFlagsForConfirmedEmailChange',
        'setProfileBlocked',
        'clearProfileBlocked',
        'writeProfileBlockClear',
        'setProfileBounced',
        'clearProfileBounced',
        'writeProfileBounceClear',
        'lockProfileAndFindAddresses',
    ];

    /**
     * Files that read a flag and so must call one of the two match methods.
     *
     * @var array<string, string> path => method name the file must call
     */
    private const REQUIRED_MATCH_CALLERS = [
        'usersc/user_settings.php' => 'effectiveEmailFlags(',
        'usersc/classes/Car/CarVerificationSendService.php' => 'effectiveEmailFlags(',
        'usersc/classes/Cron/BrevoSuppressionSyncJob.php' => 'effectiveEmailFlags(',
        'usersc/classes/Car/EmailNoticeBuilder.php' => 'effectiveEmailFlags(',
        'usersc/plugins/hooker/hooks/user_form_hook.php' => 'effectiveEmailFlags(',
        'app/owner/cars/details.php' => 'effectiveEmailFlags(',
        'usersc/classes/Car/CarRepository.php' => 'self::emailFlagActiveSql(',
    ];

    /**
     * Names that #2356 removed. They must not appear in code, tests or docs.
     *
     * @var list<string>
     */
    private const REMOVED_NAMES = [
        'clearBouncedForUser',
        'carIdsWithBouncedFlagButNoAddress',
        'clearBouncedForEmailChange',
        'CAUSE_BREVO_COMPLAINT',
        'hasSuppressed',
        'emailFlagSendBlockingSql',
        'flagsBlockingAddress',
        'FLAG_SEND_BLOCKED',
        'FLAG_SEND_BOUNCED',
        'CAR_ADDRESS_SQL',
    ];

    /**
     * Test files that name a removed name to assert that it is gone.
     *
     * @var array<string, string>
     */
    private const REMOVED_NAME_TEST_ALLOW_LIST = [
        'tests/unit/regression/Issue2356EmailFlagMatchSourceRegressionTest.php'
            => 'This test names them in its list and controls.',
        'tests/unit/cars/EmailNoticeBuilderTest.php'
            => 'It asserts that the removed constants stay removed.',
        'tests/unit/cars/services/CarRepositoryTest.php'
            => 'It asserts that the removed methods stay removed.',
        'tests/unit/admin/UserSettingsWiringTest.php'
            => 'It asserts that user_settings.php and the hook do not call them.',
        'tests/unit/cars/services/EmailFlagActiveSqlTest.php'
            => 'It asserts that the car-address predicate and guard stay removed.',
    ];

    /**
     * Runtime files that normalize an address for a compare or a write.
     *
     * @var list<string>
     */
    private const RUNTIME_ADDRESS_MATCHERS = [
        'usersc/classes/Owner.php',
        'usersc/classes/Car/CarRepository.php',
        'usersc/scripts/during_user_creation.php',
        'app/admin/includes/account-cleanup-helpers.php',
    ];

    private const HOOK = 'usersc/plugins/hooker/hooks/sync_owner_email_on_verify.php';

    private const NOTICE_TEMPLATE = 'app/views/_email_paused_notice.php';

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    // ------------------------------------------------------------------
    // Claim 1: every users.email writer is listed
    // ------------------------------------------------------------------

    public function testEveryUsersEmailWriterIsInTheWritersList(): void
    {
        $files = $this->sourceFiles(['usersc', 'app', 'scripts', 'utilities', 'tools', 'users']);
        $this->assertNotEmpty($files, 'The scan found no files. The directory layout changed.');
        $this->assertContains('usersc/classes/Owner.php', $files, 'The scan must include Owner.php.');
        $upstream = $this->hasUpstreamUsersTree();
        if ($upstream) {
            $this->assertContains('users/verify.php', $files, 'The scan must include the upstream users/ tree.');
        }
        $writers = array_keys($this->writersInScope($upstream));

        $found = [];
        foreach ($files as $relative) {
            if (self::looksLikeUsersEmailWrite($this->codeOnly($relative))) {
                $found[] = $relative;
            }
        }

        $this->assertSame(
            [],
            array_values(array_diff($found, array_keys(self::USERS_EMAIL_WRITERS))),
            'These files write users.email but are not in USERS_EMAIL_WRITERS. '
                . 'Each one must set email_address_since and email_address_seen (#2356).'
        );
        $this->assertSame(
            [],
            array_values(array_diff($writers, $found)),
            'These USERS_EMAIL_WRITERS entries no longer write users.email. Delete the entry.'
        );
    }

    public function testEveryWriterKeepsSinceAndSeenInItsOwnWay(): void
    {
        foreach ($this->writersInScope($this->hasUpstreamUsersTree()) as $relative => [$kind]) {
            $this->assertFileExists($this->root . '/' . $relative, 'Stale writers entry: ' . $relative);
            $code = $this->codeOnly($relative);

            switch ($kind) {
                case 'sets':
                    $this->assertStringContainsString('email_address_since', $code, $relative);
                    $this->assertStringContainsString('email_address_seen', $code, $relative);
                    break;
                case 'via_owner':
                    $this->assertStringContainsString('->updateProfileAndSync(', $code, $relative);
                    break;
                case 'hook':
                    $this->assertStringContainsString("getMyHooks(['page' => 'verifySuccess'])", $code, $relative);
                    break;
                case 'generic':
                case 'exception':
                    break;
                default:
                    $this->fail("Unknown writer kind '{$kind}' for {$relative}");
            }
        }
    }

    /** The project-code rows run with or without the upstream tree. */
    public function testWritersInScopeKeepsTheProjectRowsWithoutTheUpstreamTree(): void
    {
        $without = $this->writersInScope(false);

        $this->assertArrayHasKey('usersc/classes/Owner.php', $without);
        $this->assertArrayHasKey('usersc/user_settings.php', $without);
        $this->assertArrayHasKey('app/admin/includes/account-cleanup-helpers.php', $without);
        $this->assertSame(
            [],
            array_values(array_filter(array_keys($without), static fn (string $p): bool => str_starts_with($p, 'users/')))
        );
        $this->assertSame(self::USERS_EMAIL_WRITERS, $this->writersInScope(true));
    }

    /**
     * The upstream writer rows are checked only when the upstream tree is
     * present. CI has no such tree, so the test skips there. A local run
     * without the tree fails, because the writer checks then drop the
     * upstream rows with no signal.
     */
    public function testUpstreamWriterRowsAreChecked(): void
    {
        if (!$this->hasUpstreamUsersTree()) {
            if (filter_var(getenv('CI'), FILTER_VALIDATE_BOOLEAN)) {
                $this->markTestSkipped(
                    'CI has no upstream users/ tree. The upstream rows of USERS_EMAIL_WRITERS are not checked in CI.'
                );
            }
            $this->fail(
                'users/verify.php is missing. Install the UserSpice users/ tree '
                    . '(README.md, "Developer Setup", "Requirements": UserSpice 6), then run this test again. '
                    . 'Without it, the upstream rows of USERS_EMAIL_WRITERS are not checked.'
            );
        }

        $upstreamRows = array_values(array_filter(
            array_keys(self::USERS_EMAIL_WRITERS),
            static fn (string $relative): bool => str_starts_with($relative, 'users/')
        ));
        $this->assertNotSame([], $upstreamRows);
        $this->assertSame(
            [],
            array_values(array_diff($upstreamRows, array_keys($this->writersInScope(true)))),
            'With the upstream tree present, every users/ row must be in scope.'
        );
    }

    /** The hook sets since/seen (through the repository method) when upstream swaps in the new address. */
    public function testVerifySuccessHookSetsSinceAndSeenThroughTheRepository(): void
    {
        $hook = $this->codeOnly(self::HOOK);
        $this->assertStringContainsString('->clearFlagsForConfirmedEmailChange($userId, $userId)', $hook);

        $repo = $this->codeOnly('usersc/classes/Car/CarRepository.php');
        $this->assertStringContainsString('email_address_since = ?, email_address_seen = ?', $repo);
    }

    /** Owner::create() is a known exception only while it has no production caller. */
    public function testOwnerCreateHasNoProductionCaller(): void
    {
        $callers = [];
        foreach ($this->sourceFiles(['usersc', 'app', 'scripts', 'utilities', 'tools']) as $relative) {
            if ($relative === 'usersc/classes/Owner.php') {
                continue;
            }
            if (self::callsOwnerCreate($this->codeOnly($relative))) {
                $callers[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $callers,
            'Owner::create() has a caller now. It must set email_address_since and email_address_seen '
                . 'on the profile insert, and then the known exception is gone.'
        );
    }

    public function testNegativeControlOwnerCreateMatcher(): void
    {
        $calls = [
            'static' => 'Owner::create($fields);',
            'owner variable' => '$owner->create($fields);',
            'inline new' => '(new Owner($id))->create($fields);',
            'any variable name' => '$o = new Owner($db);' . "\n" . '$o->create($f);',
            'qualified class' => '$x = new \\ElanRegistry\\Owner();' . "\n" . '$x->create($f);',
        ];
        foreach ($calls as $label => $code) {
            $this->assertTrue(self::callsOwnerCreate($code), "Matcher misses: {$label}");
        }

        $notCalls = [
            'another class' => '$car = new Car();' . "\n" . '$car->create($f);',
            'owner without create' => '$o = new Owner($id);' . "\n" . '$o->update($f);',
            'other variable' => '$o = new Owner($id);' . "\n" . '$user->create($f);',
        ];
        foreach ($notCalls as $label => $code) {
            $this->assertFalse(self::callsOwnerCreate($code), "Matcher over-matches: {$label}");
        }
    }

    public function testUpdateEmailHelperHasNoCaller(): void
    {
        $callers = [];
        foreach ($this->sourceFiles(['usersc', 'app', 'scripts', 'utilities', 'tools', 'users']) as $relative) {
            if ($relative === 'users/helpers/us_helpers.php') {
                continue;
            }
            if (preg_match('/(?<![\w>:])updateEmail\(/', $this->codeOnly($relative)) === 1) {
                $callers[] = $relative;
            }
        }

        $this->assertSame([], $callers, 'updateEmail() writes users.email with no since/seen. A caller needs a writers entry.');
    }

    public function testNegativeControlUsersEmailWriterMatcher(): void
    {
        $writers = [
            'fields variable' => "\$fields = ['email' => \$email];\n\$db->update('users', \$id, \$fields);",
            'fields key' => "\$fields['email'] = \$email;\n\$db->update('users', \$id, \$fields);",
            'inline update' => "\$db->update('users', \$id, ['email' => \$new, 'email_new' => null]);",
            'User::update array' => "\$verify->update(array('email_verified' => 1, 'email' => \$new), \$id);",
            'owner field list' => "\$userFieldNames = ['fname', 'lname', 'email', 'password'];\n\$this->_db->update(\$this->userTableName, \$id, \$f);",
            'inline insert' => "\$db->insert('users', ['username' => \$u, 'email' => \$e]);",
            'raw SQL' => "\$db->query('UPDATE users SET email = ? WHERE id = ?', [\$e, \$id]);",
            'raw SQL, later column' => '$db->query("UPDATE `users` SET fname = ?, email=? WHERE id = ?", $p);',
            'raw SQL, alias' => "\$db->query('UPDATE users u SET u.email = ? WHERE u.id = ?', [\$e, \$id]);",
            'raw SQL, AS alias' => "\$db->query('UPDATE users AS u SET u.fname = ?, u.email = ? WHERE u.id = ?', \$p);",
            'raw SQL, insert column list' => "\$db->query('INSERT INTO users (username, email, fname) VALUES (?, ?, ?)', \$p);",
            'raw SQL, insert last column' => "\$db->query('INSERT INTO `users` (`username`, `email`) VALUES (?, ?)', \$p);",
        ];
        foreach ($writers as $label => $code) {
            $this->assertTrue(self::looksLikeUsersEmailWrite($code), "Matcher misses: {$label}");
        }

        $notWriters = [
            'vericode only' => "\$db->update('users', \$id, ['vericode' => \$v, 'vericode_expiry' => \$e]);",
            'email as a mail parameter' => "\$params = ['email' => rawurlencode(\$email)];\n\$db->update('users', \$id, ['vericode' => \$v]);",
            'email on another table' => "\$db->update('cars', \$id, ['email' => \$email]);",
            'a read' => "\$u = \$db->query('SELECT email FROM users WHERE id = ?', [\$id])->first();",
            'raw SQL, other column' => "\$db->query('UPDATE users SET email_verified = 1 WHERE id = ?', [\$id]);",
            'raw SQL, email in WHERE' => "\$db->query('UPDATE users SET vericode = ? WHERE email = ?', [\$v, \$e]);",
            'raw SQL, other table' => "\$db->query('UPDATE cars SET email = ? WHERE id = ?', [\$e, \$id]);",
            'raw SQL, alias on another table' => "\$db->query('UPDATE cars c SET c.email = ? WHERE c.id = ?', [\$e, \$id]);",
            'raw SQL, alias, other column' => "\$db->query('UPDATE users u SET u.email_new = ? WHERE u.id = ?', [\$e, \$id]);",
            'raw SQL, insert on another table' => "\$db->query('INSERT INTO cars (user_id, email) VALUES (?, ?)', \$p);",
            'raw SQL, insert on a history table' => "\$db->query('INSERT INTO users_hist (id, email) VALUES (?, ?)', \$p);",
            'raw SQL, insert other column' => "\$db->query('INSERT INTO users (username, email_new) VALUES (?, ?)', \$p);",
        ];
        foreach ($notWriters as $label => $code) {
            $this->assertFalse(self::looksLikeUsersEmailWrite($code), "Matcher over-matches: {$label}");
        }
    }

    // ------------------------------------------------------------------
    // Claim 2: flag readers use the match methods
    // ------------------------------------------------------------------

    public function testOnlyAllowedFilesNameTheStoredFlagColumns(): void
    {
        $files = $this->sourceFiles(['usersc', 'app', 'scripts', 'utilities', 'tools']);
        $this->assertNotEmpty($files);
        $this->assertContains('usersc/classes/Car/CarRepository.php', $files);

        $hits = [];
        foreach ($files as $relative) {
            if (isset(self::FLAG_COLUMN_ALLOW_LIST[$relative])) {
                continue;
            }
            $tokens = self::storedFlagColumnHits($this->codeOnly($relative));
            if ($tokens !== []) {
                $hits[] = $relative . ' (' . implode(', ', array_unique($tokens)) . ')';
            }
        }

        $this->assertSame(
            [],
            $hits,
            "These files read a stored flag column. A flag applies only for the current address, so use\n"
                . 'CarRepository::effectiveEmailFlags() or emailFlagActiveSql() (#2356):'
                . "\n" . implode("\n", $hits)
        );
    }

    public function testFlagColumnAllowListEntriesExist(): void
    {
        foreach (array_keys(self::FLAG_COLUMN_ALLOW_LIST) as $relative) {
            $this->assertFileExists($this->root . '/' . $relative, 'Stale allow-list entry: ' . $relative);
            $this->assertNotSame(
                [],
                self::storedFlagColumnHits($this->codeOnly($relative)),
                "{$relative} no longer names a flag column. Delete the entry."
            );
        }
    }

    /**
     * user_settings.php reads the stored block flag only in its fallback.
     * Any other flag column there must go through effectiveEmailFlags().
     */
    public function testUserSettingsNamesOnlyTheFallbackFlag(): void
    {
        $code = $this->codeOnly('usersc/user_settings.php');

        $this->assertSame(['email_blocked'], self::storedFlagColumnHits($code));
        $this->assertSame(
            1,
            preg_match_all('/\$emailBlocked = \(int\) \(\$profiledetails->email_blocked \?\? 0\) === 1;/', $code),
            'The stored block flag is read once, in the fallback after a failed effectiveEmailFlags() read.'
        );
    }

    public function testRepositoryNamesTheStoredFlagColumnsOnlyInAllowedMethods(): void
    {
        $byMethod = self::methodsNamingFlagColumns($this->codeOnly('usersc/classes/Car/CarRepository.php'));

        $this->assertArrayHasKey('effectiveEmailFlags', $byMethod, 'The scan must find the reader.');
        $this->assertSame(
            [],
            array_values(array_diff(array_keys($byMethod), self::ALLOWED_REPOSITORY_METHODS)),
            'These CarRepository methods name a stored flag column but are not profile writers '
                . 'or the match readers (#2356).'
        );
    }

    /**
     * The privacy step reads the address columns only. A read of a flag value
     * (`email_blocked`, `email_bounced`) there would be a second reading of
     * the flags outside the match rule.
     */
    public function testAddressLockReadsTheAddressColumnsAndNoFlagValue(): void
    {
        $body = self::methodBody($this->codeOnly('usersc/classes/Car/CarRepository.php'), 'lockProfileAndFindAddresses');

        $this->assertNotSame('', $body, 'The scan must find the method.');
        $this->assertStringContainsString('email_bounced_address', $body);
        $this->assertStringContainsString('email_blocked_address', $body);
        $this->assertDoesNotMatchRegularExpression(
            '/\bemail_(?:blocked|bounced)\b(?!_address)/',
            $body,
            'lockProfileAndFindAddresses must not read a stored flag value.'
        );
    }

    /** The class-level hits are the four predicate constants and nothing else. */
    public function testRepositoryClassLevelFlagColumnsAreThePredicateConstants(): void
    {
        $code = $this->codeOnly('usersc/classes/Car/CarRepository.php');
        $classLevel = [];
        $depth = 0;
        foreach (explode("\n", $code) as $line) {
            if ($depth <= 1 && preg_match('/\bemail_(?:blocked|bounced)(?:_address)?\b/', $line) === 1) {
                $classLevel[] = $line;
            }
            $depth += substr_count($line, '{') - substr_count($line, '}');
        }
        $this->assertNotSame([], $classLevel);
        foreach ($classLevel as $line) {
            $this->assertMatchesRegularExpression(
                '/FLAG_ACTIVE_(?:BLOCKED|BOUNCED)|NORMALIZE_SQL_OPEN\s*\.\s*\'p\.email_(?:blocked|bounced)_address\'/',
                $line,
                'A class-level line names a flag column outside the predicate constants.'
            );
        }
    }

    public function testRequiredReadersCallTheMatchMethods(): void
    {
        foreach (self::REQUIRED_MATCH_CALLERS as $relative => $needle) {
            $this->assertFileExists($this->root . '/' . $relative);
            $this->assertStringContainsString($needle, $this->codeOnly($relative), "{$relative} must call {$needle}");
        }
    }

    /**
     * Runtime code uses the runtime matching rule. The #2101 migration keeps
     * its frozen copy, so a later change to the runtime rule cannot change a
     * migration result (#2358).
     */
    public function testRuntimeAddressComparesUseTheRuntimeRule(): void
    {
        foreach (self::RUNTIME_ADDRESS_MATCHERS as $relative) {
            $this->assertFileExists($this->root . '/' . $relative);
            $code = $this->codeOnly($relative);
            $this->assertStringContainsString('EmailAddress::normalize(', $code, $relative);
            $this->assertStringNotContainsString(
                'DeliverabilityMigrationQueries::normalizeAddress(',
                $code,
                "{$relative} must call EmailAddress::normalize(), not the frozen migration copy."
            );
        }
    }

    public function testEligibilityAndQueueUseThePredicateMethod(): void
    {
        $repo = $this->codeOnly('usersc/classes/Car/CarRepository.php');

        $this->assertGreaterThanOrEqual(
            4,
            substr_count($repo, 'self::emailFlagActiveSql('),
            'Eligibility, the pending, bounced and suppressed helpers and the queue columns use the predicate.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/AND\s+p\.email_(?:blocked|bounced)\s*=\s*0/',
            $repo,
            'Eligibility must not read the stored flag alone.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\(p\.email_(?:blocked|bounced)\s*=\s*1\)/',
            $repo,
            'A dashboard filter must not read the stored flag alone.'
        );
    }

    /**
     * cars.email is only a copy of the owner's email. The verification send
     * mails users.email, and the Brevo event match finds the user by
     * users.email (#2359). Neither may read the car copy.
     */
    public function testSendAndEventMatchUseTheAccountEmailOnly(): void
    {
        $send = $this->codeOnly('usersc/classes/Car/CarVerificationSendService.php');
        $this->assertStringNotContainsString('$carData->email,', $send, 'email() must not take the car copy.');
        $this->assertDoesNotMatchRegularExpression('/\(string\)\s*\$carData->email/', $send);
        $this->assertStringContainsString('$recipient = trim((string) ($owner->email ?? \'\'));', $send);
        $this->assertMatchesRegularExpression('/email\(\s*\$recipient,/', $send);
        $this->assertMatchesRegularExpression(
            '/insertEmailEvent\(\s*\$carId,\s*\$recipient,/',
            $send,
            'The sent event row records the address that the send mailed.'
        );

        $finder = self::methodBody($this->codeOnly('usersc/classes/Car/CarRepository.php'), 'findUserIdsByAddress');
        $this->assertStringContainsString('FROM users u', $finder);
        $this->assertStringContainsString('self::CURRENT_ADDRESS_SQL', $finder);
        $this->assertStringNotContainsString('cars.email', $finder, 'The event match must not use the car copy.');
        $this->assertDoesNotMatchRegularExpression('/\bcars\b/', $finder, 'The event match must not read the cars table.');

        $eligible = self::methodBody($this->codeOnly('usersc/classes/Car/CarRepository.php'), 'verificationEligibleFromSql');
        $this->assertStringContainsString('$address = self::CURRENT_ADDRESS_SQL;', $eligible);
        $this->assertStringContainsString("u.email IS NOT NULL AND {\$address} != ''", $eligible);
        $this->assertStringNotContainsString('cars.email', $eligible);
    }

    public function testNegativeControlMethodBody(): void
    {
        $code = "<?php\nclass A {\n    public function a(): void\n    {\n        \$x = 'cars.email';\n    }\n\n"
            . "    public function b(): void\n    {\n        \$y = 1;\n    }\n}\n";

        $this->assertStringContainsString('cars.email', self::methodBody($code, 'a'));
        $this->assertStringNotContainsString('cars.email', self::methodBody($code, 'b'));
    }

    /** Only the repository reads the raw profile row. Other code gets the applied flags. */
    public function testFindProfileEmailStateIsNotCalledOutsideTheRepository(): void
    {
        $callers = [];
        foreach ($this->sourceFiles(['usersc', 'app', 'scripts', 'utilities', 'tools']) as $relative) {
            if ($relative === 'usersc/classes/Car/CarRepository.php') {
                continue;
            }
            if (str_contains($this->codeOnly($relative), 'findProfileEmailState(')) {
                $callers[] = $relative;
            }
        }

        $this->assertSame([], $callers, 'findProfileEmailState() returns the stored flags without the address match.');
    }

    public function testNegativeControlFlagColumnMatchers(): void
    {
        $this->assertNotSame([], self::storedFlagColumnHits('$x = $state->email_blocked;'));
        $this->assertNotSame([], self::storedFlagColumnHits("\$db->query('SELECT p.email_bounced FROM profiles p');"));
        $this->assertNotSame([], self::storedFlagColumnHits('$row->email_blocked_address'));
        $this->assertNotSame([], self::storedFlagColumnHits("'email_bounced_address' => \$a"));
        $this->assertSame([], self::storedFlagColumnHits('$x = $row->email_blocked_active;'), 'An _active alias is a predicate result.');
        $this->assertSame([], self::storedFlagColumnHits('$x = $row->email_blocked_cause;'));
        $this->assertSame([], self::storedFlagColumnHits('$x = $state->email_opted_out;'));

        $code = "<?php\nclass A {\n"
            . "    public function ok() {\n        return 1;\n    }\n"
            . "    public function bad() {\n        return \$row->email_bounced;\n    }\n"
            . "}\n";
        $this->assertSame(['bad'], array_keys(self::methodsNamingFlagColumns($code)));
    }

    // ------------------------------------------------------------------
    // Claim 3: removed names are gone (code, tests and docs)
    // ------------------------------------------------------------------

    public function testRemovedNamesAreAbsentFromCodeAndTests(): void
    {
        $files = $this->sourceFiles(['usersc', 'app', 'scripts', 'utilities', 'tools', 'tests', 'database']);
        $this->assertNotEmpty($files);

        $hits = [];
        foreach ($files as $relative) {
            if (isset(self::REMOVED_NAME_TEST_ALLOW_LIST[$relative])) {
                continue;
            }
            foreach (self::removedNameHits($this->rawText($relative)) as $name) {
                $hits[] = "{$relative}: {$name}";
            }
        }

        $this->assertSame([], $hits, "Removed names are back (#2356):\n" . implode("\n", $hits));
    }

    public function testRemovedNamesAreAbsentFromDocs(): void
    {
        $files = $this->docFiles();
        $this->assertNotEmpty($files);
        $this->assertContains('docs/development/CLASSES.md', $files, 'The scan must include CLASSES.md.');

        $hits = [];
        foreach ($files as $relative) {
            foreach (self::removedNameHits($this->rawText($relative)) as $name) {
                $hits[] = "{$relative}: {$name}";
            }
        }

        $this->assertSame(
            [],
            $hits,
            "Docs still name a removed method or constant (#2356). Update them:\n" . implode("\n", $hits)
        );
    }

    public function testRemovedNameAllowListEntriesExistAndNameARemovedName(): void
    {
        foreach (array_keys(self::REMOVED_NAME_TEST_ALLOW_LIST) as $relative) {
            $this->assertFileExists($this->root . '/' . $relative, 'Stale allow-list entry: ' . $relative);
            $this->assertNotSame(
                [],
                self::removedNameHits($this->rawText($relative)),
                "{$relative} names no removed name any more. Delete the entry."
            );
        }
    }

    public function testRemovedMethodsAndConstantsAreGoneAtRuntime(): void
    {
        foreach (['clearBouncedForUser', 'carIdsWithBouncedFlagButNoAddress'] as $method) {
            $this->assertFalse(method_exists(\ElanRegistry\Car\CarRepository::class, $method), $method);
        }
        $this->assertFalse(method_exists(\ElanRegistry\Car\CarVerificationManager::class, 'clearBouncedForEmailChange'));
        $this->assertFalse(defined(\ElanRegistry\Car\EmailNoticeBuilder::class . '::CAUSE_BREVO_COMPLAINT'));
    }

    public function testNegativeControlRemovedNameMatcher(): void
    {
        foreach (self::REMOVED_NAMES as $name) {
            $this->assertSame([$name], self::removedNameHits("\$repo->{$name}(1);"), "Matcher misses {$name}");
            $this->assertSame([$name], self::removedNameHits("`{$name}()` clears it."), "Matcher misses {$name} in docs");
        }
        $this->assertSame([], self::removedNameHits('clearFlagsForConfirmedEmailChange and hasSuppressedFlag'));
        $this->assertSame([], self::removedNameHits('EmailNoticeBuilder::KIND_BLOCKED'));
    }

    // ------------------------------------------------------------------
    // Claim 4: the account notice template
    // ------------------------------------------------------------------

    public function testTemplateNeverNamesBrevo(): void
    {
        $raw = $this->rawText(self::NOTICE_TEMPLATE);

        $this->assertNotSame('', $raw);
        $this->assertSame(0, preg_match('/brevo/i', $raw), 'The notice must not name the email provider.');
    }

    public function testRenderedNoticeNeverNamesBrevo(): void
    {
        foreach (['blocked', 'bounced', 'opted_out'] as $kind) {
            foreach (['spam', 'unsubscribed', 'blocklist', null] as $cause) {
                $html = $this->renderNotice($this->notice($kind, 'a@example.com', $cause));
                $this->assertNotSame('', $html);
                $this->assertSame(0, preg_match('/brevo/i', $html), "{$kind}/" . var_export($cause, true));
            }
        }
    }

    public function testTemplateEscapesTheAddressAtRender(): void
    {
        $address = "o'brien&co@example.com";
        $escaped = 'o&#039;brien&amp;co@example.com';

        foreach (['blocked', 'bounced'] as $kind) {
            $html = $this->renderNotice($this->notice($kind, $address, null));

            $this->assertStringContainsString($escaped, $html, "{$kind} address is not escaped");
            $this->assertStringNotContainsString($address, $html, "{$kind} address leaks unescaped");
        }
    }

    public function testTemplateEscapesAHostileAddressAndCause(): void
    {
        $html = $this->renderNotice($this->notice('blocked', '"><script>alert(1)</script>@x.com', 'spam'));

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    /** Every use of the address passes through the $_en escape helper. */
    public function testTemplatePrintsTheAddressOnlyThroughTheEscapeHelper(): void
    {
        $code = $this->codeOnly(self::NOTICE_TEMPLATE);

        $this->assertSame(
            1,
            preg_match_all('/\$emailNotice\[\'address\'\]/', $code),
            'The template must read the address once, so one escape covers blocked and bounced.'
        );
        $this->assertMatchesRegularExpression('/\$_en\(\$emailNotice\[\'address\'\]\s*\?\?\s*\'\'\)/', $code);
        $this->assertStringContainsString('htmlspecialchars($value, ENT_QUOTES, \'UTF-8\')', $code);
    }

    public function testNegativeControlTemplateChecks(): void
    {
        $this->assertSame(1, preg_match('/brevo/i', 'Brevo blocked this address'));
        $this->assertSame(1, preg_match('/brevo/i', 'your BREVO account'));

        // A naive render of the same address leaks it, so the assertion above can fail.
        $naive = '<strong>' . "o'brien&co@example.com" . '</strong>';
        $this->assertStringContainsString("o'brien&co@example.com", $naive);
        $this->assertStringNotContainsString('o&#039;brien&amp;co@example.com', $naive);
    }

    // ------------------------------------------------------------------
    // Claim 5: the hook calls one clear method
    // ------------------------------------------------------------------

    public function testHookCallsExactlyOneClearMethod(): void
    {
        $calls = self::clearMethodCalls($this->codeOnly(self::HOOK));

        $this->assertSame(['clearFlagsForConfirmedEmailChange'], $calls);
    }

    public function testHookDoesNotReadTheFlagsOrTheCarsForTheClear(): void
    {
        $code = $this->codeOnly(self::HOOK);

        $this->assertSame([], self::storedFlagColumnHits($code));
        $this->assertStringNotContainsString('findByOwner', $code);
        $this->assertStringNotContainsString('findProfileEmailState', $code);
        $this->assertStringNotContainsString('$currentEmail', $code, 'The repository reads users.email under the lock.');
    }

    public function testNegativeControlClearMethodMatcher(): void
    {
        $this->assertSame(['clearBouncedForUser'], self::clearMethodCalls('$repo->clearBouncedForUser($u, $e);'));
        $this->assertSame(
            ['clearFlagsForConfirmedEmailChange', 'clearBouncedForEmailChange'],
            self::clearMethodCalls('$m->clearFlagsForConfirmedEmailChange($a, $b); $m->clearBouncedForEmailChange($a, $b, $e);')
        );
        $this->assertSame([], self::clearMethodCalls('$m->setBouncedFromEvent(1);'));
    }

    // ------------------------------------------------------------------
    // Matchers (pure functions of a string, so the controls can reuse them)
    // ------------------------------------------------------------------

    /**
     * Whether the code updates or inserts `users.email`. An `email` key that
     * is only a mail parameter (rawurlencode) or on another table is not a write.
     */
    private static function looksLikeUsersEmailWrite(string $code): bool
    {
        // Raw SQL: UPDATE users [alias] SET ... [alias.]email = ...
        $rawUpdate = '/UPDATE\s+`?users`?(?:\s+(?:AS\s+)?(?!SET\b)\w+)?\s+SET\b'
            . '(?:(?!\bWHERE\b)[^;]){0,300}?\bemail`?\s*=/i';
        if (preg_match($rawUpdate, $code) === 1) {
            return true;
        }
        // Raw SQL: INSERT INTO users (..., email, ...) VALUES
        if (preg_match('/INSERT\s+INTO\s+`?users`?\s*\([^)]*\bemail\b`?\s*[,)]/i', $code) === 1) {
            return true;
        }

        $patterns = [
            // $fields = ['email' => ...]  (then handed to ->update('users', ...))
            '/\$\w*[Ff]ields\s*=\s*\[\s*[\'"]email[\'"]\s*=>/',
            // $fields['email'] = ...
            '/\$\w*[Ff]ields\[[\'"]email[\'"]\]\s*=/',
            // ->update('users', $id, [ ... 'email' => ...]) and the same for insert
            '/->(?:update|insert)\(\s*[\'"]users[\'"][^;]{0,600}?[\'"]email[\'"]\s*=>\s*(?!rawurlencode)/s',
            // User::update(array('email' => ...), $id), as users/verify.php calls it
            '/->update\(\s*array\([^;]{0,600}?[\'"]email[\'"]\s*=>/s',
            // Owner::update() splits its fields by a list that names email
            '/\$userFieldNames\s*=\s*\[[^\]]*[\'"]email[\'"]/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $code) === 1) {
                // A $fields write on another table is not a users.email write.
                if (preg_match('/->(?:update|insert)\(\s*[\'"]users[\'"]|userTableName|->update\(\s*array\(/', $code) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the code calls Owner::create(), on the class or on a variable
     * that holds a new Owner
     */
    private static function callsOwnerCreate(string $code): bool
    {
        $newOwner = 'new\s+\\\\?(?:\w+\\\\)*Owner\(';
        if (preg_match('/Owner::create\(|\$owner\w*->create\(|\(' . $newOwner . '[^)]*\)\)->create\(/', $code) === 1) {
            return true;
        }
        preg_match_all('/\$(\w+)\s*=\s*' . $newOwner . '/', $code, $m);
        foreach (array_unique($m[1]) as $variable) {
            if (preg_match('/\$' . preg_quote($variable, '/') . '->create\(/', $code) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> Each use of a stored flag column or its address column
     */
    private static function storedFlagColumnHits(string $code): array
    {
        preg_match_all('/\bemail_(?:blocked|bounced)(?:_address)?\b/', $code, $m);

        return $m[0];
    }

    /**
     * @return array<string, list<string>> method name => hits ('(class-level)' outside any method)
     */
    private static function methodsNamingFlagColumns(string $code): array
    {
        $current = '(class-level)';
        $byMethod = [];
        foreach (explode("\n", $code) as $line) {
            if (preg_match('/\bfunction\s+(\w+)\s*\(/', $line, $m) === 1) {
                $current = $m[1];
            }
            $hits = self::storedFlagColumnHits($line);
            if ($hits !== []) {
                $byMethod[$current] = array_merge($byMethod[$current] ?? [], $hits);
            }
        }

        return $byMethod;
    }

    /**
     * The lines from one method's `function` line to the next `function` line
     *
     * @return string Empty when the method is not found
     */
    private static function methodBody(string $code, string $method): string
    {
        $lines = [];
        $inside = false;
        foreach (explode("\n", $code) as $line) {
            if (preg_match('/\bfunction\s+(\w+)\s*\(/', $line, $m) === 1) {
                $inside = $m[1] === $method;
            }
            if ($inside) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string> Removed names found in the text
     */
    private static function removedNameHits(string $text): array
    {
        $found = [];
        foreach (self::REMOVED_NAMES as $name) {
            if (preg_match('/(?<![\w])' . preg_quote($name, '/') . '(?![\w])/', $text) === 1) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /**
     * @return list<string> Names of the `->clear...(` methods that the code calls
     */
    private static function clearMethodCalls(string $code): array
    {
        preg_match_all('/->(clear\w*)\s*\(/', $code, $m);

        return $m[1];
    }

    // ------------------------------------------------------------------
    // Template rendering
    // ------------------------------------------------------------------

    /**
     * @return array{kind: string, address: ?string, date: ?string, cause: ?string, alsoOptedOut: bool, optedOutDate: ?string, contentHash: string}
     */
    private function notice(string $kind, ?string $address, ?string $cause): array
    {
        return [
            'kind' => $kind,
            'address' => $kind === 'opted_out' ? null : $address,
            'date' => '2026-09-15',
            'cause' => $kind === 'blocked' ? $cause : null,
            'alsoOptedOut' => $kind !== 'opted_out',
            'optedOutDate' => '2026-08-01',
            'contentHash' => str_repeat('a', 64),
        ];
    }

    /**
     * @param array<string, mixed> $emailNotice
     */
    private function renderNotice(array $emailNotice): string
    {
        $render = function () use ($emailNotice): string {
            $ownerId = 7;
            $us_url_root = '/';
            $userspice_nonce = 'testnonce';
            ob_start();
            try {
                include $this->root . '/' . self::NOTICE_TEMPLATE;
            } finally {
                $html = (string) ob_get_clean();
            }

            return $html;
        };

        return $render();
    }

    // ------------------------------------------------------------------
    // File helpers
    // ------------------------------------------------------------------

    /** Whether the gitignored upstream users/ tree is present (CI does not have it) */
    private function hasUpstreamUsersTree(): bool
    {
        return is_file($this->root . '/users/verify.php');
    }

    /**
     * The writers list, without the upstream users/ rows when that tree is missing
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function writersInScope(bool $upstream): array
    {
        if ($upstream) {
            return self::USERS_EMAIL_WRITERS;
        }

        return array_filter(
            self::USERS_EMAIL_WRITERS,
            static fn (string $relative): bool => !str_starts_with($relative, 'users/'),
            ARRAY_FILTER_USE_KEY
        );
    }

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
                if (preg_match('/\.(php|js)$/', $path) !== 1
                    || str_ends_with($path, '.md.php')
                    || preg_match('#/(vendor|node_modules|updates|ai_prompts|playwright)/#', $path) === 1
                ) {
                    continue;
                }
                $files[] = substr($path, strlen($this->root) + 1);
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Markdown docs that a code change can falsify. `docs/plans/` is private scratch.
     *
     * @return list<string>
     */
    private function docFiles(): array
    {
        $files = [];
        $base = $this->root . '/docs';
        if (is_dir($base)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                if (!str_ends_with($path, '.md') || str_contains($path, '/docs/plans/')) {
                    continue;
                }
                $files[] = substr($path, strlen($this->root) + 1);
            }
        }
        foreach (['README.md', 'CLAUDE.md'] as $rootDoc) {
            if (is_file($this->root . '/' . $rootDoc)) {
                $files[] = $rootDoc;
            }
        }
        sort($files);

        return $files;
    }

    /** File content as written, comments included. */
    private function rawText(string $relative): string
    {
        return (string) file_get_contents($this->root . '/' . $relative);
    }

    /** File content without PHP comments, so that a "why" comment can name a removed name. */
    private function codeOnly(string $relative): string
    {
        $source = $this->rawText($relative);
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
