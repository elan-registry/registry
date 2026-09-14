<?php

declare(strict_types=1);

use ElanRegistry\DatabaseInterface;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for usersc/plugins/hooker/hooks/sync_owner_email_on_verify.php
 * (issue #1958), exercising the hook FILE directly rather than reproducing
 * its logic.
 *
 * The hook is a plain included script with no enclosing function, but it has
 * no dependency on users/verify.php itself — it reads a `global $verify` and
 * calls Owner. Both are substitutable here: `$verify` is a minimal stub
 * exposing the two members the hook touches (`exists()` and `data()->id`),
 * and `Owner`'s DB handle is supplied through the `dbi()` seam its
 * constructor falls back to, defined once below. So the file can simply be
 * `require`d, exactly as tests/unit/security/TurnstileTest.php does for the
 * sibling hook login_form_turnstile.php.
 *
 * What only this tier can cover — the hook's own control flow, which the
 * integration suite's direct Owner calls cannot reach:
 *
 *   * the run-once-per-request guard. users/verify.php fires verifySuccess
 *     from three sites, two of which (295 and 315) run on the SAME request
 *     for every real confirmation, because the confirm branch falls through
 *     with $verify_success = TRUE instead of exit()ing. Without the guard the
 *     sync — and its per-car cars_hist trigger rows — happens twice.
 *   * which LogCategories constant each failure branch uses.
 *   * that a partial OwnerSyncResult is logged at all: per-car failures come
 *     back through the return value, never as an exception, so the catch
 *     blocks cannot see them. A car skipped for no longer being owned is not
 *     such a failure — it lands in `skipped`, which leaves isCompleteSuccess()
 *     true, so it must not reach the partial-sync log branch (#1954).
 *   * that a missing/non-existent $verify returns silently.
 *
 * @see usersc/plugins/hooker/hooks/sync_owner_email_on_verify.php
 * @see tests/integration/SyncOwnerEmailOnVerifyHookIntegrationTest.php
 */
#[Group('fast')]
#[Group('unit')]
final class SyncOwnerEmailOnVerifyHookTest extends TestCase
{
    private const HOOK = __DIR__
        . '/../../../usersc/plugins/hooker/hooks/sync_owner_email_on_verify.php';

    protected function setUp(): void
    {
        parent::setUp();
        global $mockLogEntries, $verify;
        $mockLogEntries = [];
        $verify = null;
        // Always a real instance so dbi() and carLookupCount() never face null;
        // each test replaces it with one scripted for the branch it pins.
        $GLOBALS['hookTestDb'] = new HookTestDb();

        // Clear any guard flags left by a previous test in this process — the
        // guard is deliberately per-request state, and each test is a request.
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, '__elanregistry_verify_email_synced_')) {
                unset($GLOBALS[$key]);
            }
        }
    }

    /**
     * The hook is `require`d (not `require_once`) so a single test can fire it
     * twice, reproducing verify.php's two same-request hook fires.
     */
    private function fireHook(): void
    {
        require self::HOOK;
    }

    /**
     * A stand-in for the User instance verify.php leaves in $verify.
     *
     * The hook reads the confirmed email from a fresh Owner lookup (see
     * HookTestDb's $ownerEmail), NOT from $verify->data()->email — $verify is
     * the User object verify.php constructed BEFORE the email-change UPDATE
     * runs, and User::update() never refreshes its cached _data, so on the
     * real email-change path $verify->data()->email is the PRE-change
     * address. $email here is deliberately unused by the hook's bounce-clear
     * block; it exists only because $verify->data()->id is real production
     * shape (an object with an email property alongside id) and because the
     * old, buggy hook code did read it — a handful of tests below construct
     * fakeVerify() with a deliberately WRONG email specifically to prove the
     * hook no longer reads it.
     */
    private function fakeVerify(int $userId, bool $exists = true, string $email = 'unused-old-email@example.com'): object
    {
        return new class ($userId, $exists, $email) {
            public function __construct(private int $userId, private bool $exists, private string $email)
            {
            }
            public function exists(): bool
            {
                return $this->exists;
            }
            public function data(): object
            {
                return (object) ['id' => $this->userId, 'email' => $this->email];
            }
        };
    }

    /**
     * Asserts the sync reached the database (or did not) by counting the car
     * SELECT that Owner::getCarsOwned() issues.
     */
    private function carLookupCount(): int
    {
        return $GLOBALS['hookTestDb']->carLookups;
    }

    public function testMissingVerifyGlobalSyncsNothingAndLogsNothing(): void
    {
        global $mockLogEntries, $verify;
        $verify = null;
        $GLOBALS['hookTestDb'] = new HookTestDb();

        $this->fireHook();

        $this->assertSame(0, $this->carLookupCount(), 'No sync may be attempted without a $verify');
        $this->assertSame([], $mockLogEntries);
    }

    public function testNonExistentVerifyUserSyncsNothingAndLogsNothing(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(42, exists: false);
        $GLOBALS['hookTestDb'] = new HookTestDb();

        $this->fireHook();

        $this->assertSame(0, $this->carLookupCount());
        $this->assertSame([], $mockLogEntries);
    }

    /**
     * The #1958 double-fire regression guard. verify.php's confirm path runs
     * the verifySuccess hook twice in one request (lines 295 and 315); the
     * sync must still execute exactly once.
     */
    public function testHookFiredTwiceInOneRequestSyncsOnlyOnce(): void
    {
        global $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(cars: [(object) ['id' => 100]]);

        $this->fireHook();
        $this->fireHook();

        $this->assertSame(
            1,
            $this->carLookupCount(),
            'The hook fires twice per confirmation (verify.php lines 295 and 315); without the '
            . 'run-once guard the sync — and its per-car cars_hist trigger rows — is duplicated'
        );
    }

    /** A single fire must actually reach the sync — proving the guard is not a blanket block. */
    public function testSingleFireRunsTheSync(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(cars: [(object) ['id' => 100]]);

        $this->fireHook();

        $this->assertSame(1, $this->carLookupCount());
        $this->assertSame(
            [],
            array_filter(
                $mockLogEntries,
                static fn(array $e): bool => str_contains($e['message'], 'sync_owner_email_on_verify:')
            ),
            'A fully successful sync must log nothing from the hook itself'
        );
    }

    /** The guard keys on user id, so a different user is still allowed to sync. */
    public function testGuardIsScopedToTheVerifiedUserId(): void
    {
        global $verify;
        $GLOBALS['hookTestDb'] = new HookTestDb(cars: [(object) ['id' => 100]]);

        $verify = $this->fakeVerify(7);
        $this->fireHook();
        $verify = $this->fakeVerify(8);
        $this->fireHook();

        $this->assertSame(2, $this->carLookupCount());
    }

    /**
     * Per-car failures are returned in OwnerSyncResult, never thrown — so the
     * catch blocks cannot see them. Before this branch existed they vanished
     * entirely, since the hook shows the user nothing.
     */
    public function testPartialSyncResultIsLoggedUnderOwnerErrors(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        // failUpdate makes CarRepository::updateCarForOwner() throw a plain
        // \RuntimeException, which syncOwnerFieldsToCars()'s per-car \Throwable
        // handler records as a failed car rather than propagating.
        $GLOBALS['hookTestDb'] = new HookTestDb(cars: [(object) ['id' => 100]], failUpdate: true);

        $this->fireHook();

        $entry = $this->soleHookLogEntry($mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_OWNER_ERRORS, $entry['category']);
        $this->assertStringContainsString('partial sync for user 7', $entry['message']);
        $this->assertStringContainsString('0 of 1 car(s) updated', $entry['message']);
        $this->assertStringContainsString('Car 100 could not be updated.', $entry['message']);
    }

    /**
     * An infrastructure fault (deadlock, lock-wait timeout) surfaces as
     * OwnerDatabaseException from getCarsOwned() and must log under
     * DATABASE_ERROR without interrupting verify.php's render.
     */
    public function testDatabaseExceptionIsLoggedUnderDatabaseError(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(failCarLookup: true);

        $this->fireHook();

        $entry = $this->soleHookLogEntry($mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_DATABASE_ERROR, $entry['category']);
        $this->assertStringContainsString('car owner-field sync failed for user 7', $entry['message']);
    }

    /**
     * A TypeError is not a database fault, so the \Throwable branch must log
     * under SYSTEM_ERROR — matching usersc/user_settings.php's precedent.
     * PHP's Error hierarchy does not extend Exception, which is why that catch
     * must be \Throwable rather than \Exception.
     */
    public function testThrowableIsLoggedUnderSystemErrorNotDatabaseError(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(throwTypeErrorOnCarLookup: true);

        $this->fireHook();

        $entry = $this->soleHookLogEntry($mockLogEntries);
        $this->assertSame(
            LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            $entry['category'],
            'A TypeError is a system fault, not a database fault'
        );
        $this->assertStringContainsString('unexpected TypeError', $entry['message']);
    }

    /**
     * Isolates the hook's own log line from any Owner-internal logging.
     *
     * @param array<int, array{user_id: mixed, category: string, message: string}> $entries
     * @return array{user_id: mixed, category: string, message: string}
     */
    private function soleHookLogEntry(array $entries): array
    {
        $hookEntries = array_values(array_filter(
            $entries,
            static fn(array $e): bool => str_contains($e['message'], 'sync_owner_email_on_verify:')
        ));

        $this->assertCount(1, $hookEntries, 'The hook must log exactly one line for this outcome');

        return $hookEntries[0];
    }

    /**
     * Isolates log entries produced by the bounce-clear block specifically
     * (its two conditional lines: the integrity-anomaly line and the
     * cleared-count line), distinct from the pre-existing sync-related log
     * lines this same hook can also emit — the two blocks are independent
     * and must be assertable independently.
     *
     * @param array<int, array{user_id: mixed, category: string, message: string}> $entries
     * @return array<int, array{user_id: mixed, category: string, message: string}>
     */
    private function bounceClearLogEntries(array $entries): array
    {
        return array_values(array_filter(
            $entries,
            static fn(array $e): bool => str_contains($e['message'], 'bounce-clear')
                || str_contains($e['message'], 'cleared bounce flag')
                || str_contains($e['message'], 'had email_bounced=1')
        ));
    }

    // =========================================================================
    // Bounce-clear block tests (issue #1890)
    // =========================================================================

    /**
     * Core acceptance-criteria case: a car whose recorded bounced address
     * differs from the just-confirmed email must be cleared, and the
     * cleared-count log line must fire under LOG_CATEGORY_EMAIL_BOUNCED.
     *
     * fakeVerify()'s email is left at its (deliberately wrong/unused)
     * default here — the "confirmed email" the hook actually acts on is
     * HookTestDb's default $ownerEmail ('new-address@example.com'), reached
     * through the fresh Owner::find() lookup, never through $verify.
     */
    public function testBounceClearedOnCarWithNonMatchingBouncedAddress(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(
            cars: [(object) ['id' => 100]],
            bounceClearAffectedRows: 1
        );

        $this->fireHook();

        $this->assertSame(1, $GLOBALS['hookTestDb']->bounceClearCalls, 'The bounce-clear UPDATE must be issued exactly once');

        $entries = $this->bounceClearLogEntries($mockLogEntries);
        $this->assertCount(1, $entries, 'Exactly one bounce-clear log line must fire when rows were cleared');
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, $entries[0]['category']);
        $this->assertStringContainsString('cleared bounce flag on 1 car(s)', $entries[0]['message']);
        $this->assertStringContainsString('for user 7', $entries[0]['message']);
    }

    /**
     * Regression guard for the bug two reviewers independently found: the
     * hook used to read `$verify->data()->email` for the "confirmed email"
     * — but $verify is the User object verify.php constructed BEFORE the
     * email-change UPDATE runs, and User::update() never refreshes its
     * cached _data. So on the real email-change path, $verify->data()->email
     * was the OLD (pre-change) address, inverting the whole feature: the
     * bounce would compare against the stale email and never clear on its
     * primary use case.
     *
     * Modeled here by deliberately mismatching the two: $verify carries the
     * OLD email ('old-address@example.com', matching the car's bounced
     * address — so the buggy code's LOWER() comparison would find them EQUAL
     * and skip the clear), while HookTestDb's $ownerEmail — what the fresh
     * Owner::find() lookup actually returns, i.e. the post-update row — is
     * the NEW, different, confirmed email. Only code that reads the current
     * email from the Owner lookup (not from $verify) can tell these apart
     * and clear the bounce.
     *
     * Against the old buggy code ($currentEmail = $verify->data()->email),
     * this test would fail: bounceClearEmails would capture
     * 'old-address@example.com' (the stale $verify email) instead of
     * 'new-address@example.com' (the fresh Owner-lookup email) — HookTestDb
     * records the literal $currentEmail param bound to the UPDATE, so this
     * assertion is not fooled by a stubbed affected-rows count the way one
     * relying only on bounceClearCalls/log lines could be.
     */
    public function testBounceClearUsesFreshOwnerLookupNotVerifysStaleCachedEmail(): void
    {
        global $verify;
        // The stale, pre-change email $verify was constructed with — exactly
        // matches the car's currently-recorded bounced address.
        $verify = $this->fakeVerify(7, email: 'old-address@example.com');
        $GLOBALS['hookTestDb'] = new HookTestDb(
            cars: [(object) ['id' => 100]],
            // The post-update row: what a fresh Owner::find() SELECT actually
            // returns after users/verify.php's email-change UPDATE committed.
            ownerEmail: 'new-address@example.com',
            bounceClearAffectedRows: 1
        );

        $this->fireHook();

        $this->assertSame(
            1,
            $GLOBALS['hookTestDb']->bounceClearCalls,
            'The bounce-clear UPDATE must be issued exactly once'
        );
        $this->assertSame(
            ['new-address@example.com'],
            $GLOBALS['hookTestDb']->bounceClearEmails,
            'clearBouncedForUser() must be called with the fresh Owner-lookup email, never the stale '
            . '$verify email — under the old bug this would capture old-address@example.com instead'
        );
    }

    /**
     * Defensive branch: if the fresh Owner lookup somehow returns no row (or
     * a row with an empty email) after $verify->exists() already confirmed
     * true, the hook must not attempt the bounce-clear at all — skip it and
     * log under LOG_CATEGORY_SYSTEM_ERROR instead of calling
     * clearBouncedForUser() with an empty-string email (which would make
     * every bounced-but-unaddressed car match the WHERE clause).
     */
    public function testEmptyOwnerLookupEmailSkipsBounceClearAndLogsSystemError(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(
            cars: [(object) ['id' => 100]],
            ownerNotFound: true
        );

        $this->fireHook();

        $this->assertSame(
            0,
            $GLOBALS['hookTestDb']->bounceClearCalls,
            'clearBouncedForUser() must never be called with an unreadable/empty current email'
        );

        $entry = null;
        foreach ($mockLogEntries as $candidate) {
            if (str_contains($candidate['message'], 'skipping bounce-clear for user 7')) {
                $entry = $candidate;
                break;
            }
        }
        $this->assertNotNull($entry, 'The skip must be logged');
        $this->assertSame(LogCategories::LOG_CATEGORY_SYSTEM_ERROR, $entry['category']);
    }

    /**
     * A car whose email_bounced_address already matches the confirmed email
     * (e.g. a stale re-click of a consumed confirmation link, or a join-time
     * verification that never bounced) must be left untouched by the UPDATE
     * — modeled here as 0 rows affected — and must log nothing.
     */
    public function testNoOpWhenBouncedAddressAlreadyMatchesConfirmedEmail(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(
            cars: [(object) ['id' => 100]],
            bounceClearAffectedRows: 0
        );

        $this->fireHook();

        $this->assertSame(1, $GLOBALS['hookTestDb']->bounceClearCalls);
        $this->assertSame(
            [],
            $this->bounceClearLogEntries($mockLogEntries),
            'A no-op bounce-clear (already matching address) must log nothing'
        );
    }

    /**
     * Data-integrity case: a car with email_bounced=1 and a NULL/empty
     * email_bounced_address is a pre-existing anomaly. The hook must log the
     * anomaly (car ids included in the message) under LOG_CATEGORY_EMAIL_BOUNCED
     * AND still proceed to clear it via clearBouncedForUser() — two separate
     * log lines from one run: the anomaly line and the cleared-count line.
     */
    public function testIntegrityAnomalyIsLoggedAndStillCleared(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(
            cars: [(object) ['id' => 100]],
            integrityCarIds: [200, 201],
            bounceClearAffectedRows: 2
        );

        $this->fireHook();

        $entries = $this->bounceClearLogEntries($mockLogEntries);
        $this->assertCount(2, $entries, 'Both the anomaly line and the cleared-count line must fire');

        $anomalyEntry = null;
        $clearedEntry = null;
        foreach ($entries as $entry) {
            if (str_contains($entry['message'], 'had email_bounced=1')) {
                $anomalyEntry = $entry;
            } elseif (str_contains($entry['message'], 'cleared bounce flag')) {
                $clearedEntry = $entry;
            }
        }

        $this->assertNotNull($anomalyEntry, 'The integrity-anomaly line must be present');
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, $anomalyEntry['category']);
        $this->assertStringContainsString('200,201', $anomalyEntry['message']);
        $this->assertStringContainsString('data-integrity anomaly', $anomalyEntry['message']);

        $this->assertNotNull($clearedEntry, 'The cleared-count line must also be present');
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, $clearedEntry['category']);
        $this->assertStringContainsString('cleared bounce flag on 2 car(s)', $clearedEntry['message']);
    }

    /**
     * Double-fire in one request (verify.php's two same-request call sites)
     * must run the bounce-clear exactly once, sharing the same $syncedKey
     * guard as the pre-existing sync block.
     */
    public function testBounceClearRunsExactlyOnceOnDoubleFire(): void
    {
        global $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(
            cars: [(object) ['id' => 100]],
            bounceClearAffectedRows: 1
        );

        $this->fireHook();
        $this->fireHook();

        $this->assertSame(
            1,
            $GLOBALS['hookTestDb']->bounceClearCalls,
            'The shared run-once guard must prevent a second bounce-clear UPDATE on the same request'
        );
    }

    /**
     * An exception in the bounce-clear block (the integrity-check SELECT
     * failing with a DB error) must not prevent the sync block from running
     * afterward — the two operations have independent try/catch blocks.
     */
    public function testExceptionInBounceClearDoesNotBlockSyncBlock(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(
            cars: [(object) ['id' => 100]],
            failIntegrityCheckSelect: true
        );

        $this->fireHook();

        // The bounce-clear block's own failure is logged under DATABASE_ERROR
        // (carIdsWithBouncedFlagButNoAddress() throws CarDatabaseException).
        $bounceClearFailure = array_values(array_filter(
            $mockLogEntries,
            static fn(array $e): bool => str_contains($e['message'], 'bounce-clear failed for user 7')
        ));
        $this->assertCount(1, $bounceClearFailure);
        $this->assertSame(LogCategories::LOG_CATEGORY_DATABASE_ERROR, $bounceClearFailure[0]['category']);

        // The bounce-clear UPDATE itself must never have been reached.
        $this->assertSame(0, $GLOBALS['hookTestDb']->bounceClearCalls);

        // The sync block ran regardless, reaching the car lookup — proving
        // the exception in the first try/catch did not block the second.
        $this->assertSame(1, $this->carLookupCount(), 'The sync block must still run after the bounce-clear block throws');
    }

    /**
     * The symmetric case: an exception in the sync block (a per-car update
     * failure surfaced as a partial OwnerSyncResult, or a genuine DB error)
     * must not retroactively undo or block the bounce-clear block, which
     * runs first and independently. Confirmed here by having the bounce-clear
     * succeed while the sync's car-lookup step fails with a DB error.
     */
    public function testExceptionInSyncBlockDoesNotUndoBounceClearBlock(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(
            cars: [(object) ['id' => 100]],
            bounceClearAffectedRows: 1,
            failCarLookup: true
        );

        $this->fireHook();

        // Bounce-clear succeeded and logged its cleared-count line.
        $this->assertSame(1, $GLOBALS['hookTestDb']->bounceClearCalls);
        $bounceEntries = $this->bounceClearLogEntries($mockLogEntries);
        $this->assertCount(1, $bounceEntries);
        $this->assertStringContainsString('cleared bounce flag on 1 car(s)', $bounceEntries[0]['message']);

        // The sync block's own DB error is logged separately, under DATABASE_ERROR.
        $syncFailure = array_values(array_filter(
            $mockLogEntries,
            static fn(array $e): bool => str_contains($e['message'], 'car owner-field sync failed for user 7')
        ));
        $this->assertCount(1, $syncFailure);
        $this->assertSame(LogCategories::LOG_CATEGORY_DATABASE_ERROR, $syncFailure[0]['category']);
    }

    /**
     * Covers the bounce-clear UPDATE's own failure path — clearBouncedForUser()
     * throwing CarDatabaseException — distinct from the integrity-check
     * SELECT's failure path covered elsewhere in this file.
     *
     * Seeds BOTH an integrity anomaly (so the anomaly line, which runs
     * BEFORE the failing UPDATE, is proven to still fire) and a bounce-clear
     * UPDATE failure. Asserts: the anomaly line fires; the cleared-count
     * line does NOT fire (the UPDATE never succeeded); the hook's
     * CarDatabaseException catch block logs under LOG_CATEGORY_DATABASE_ERROR;
     * the UPDATE was attempted exactly once; and the sync block still ran
     * afterward, proving the failure in this try/catch did not block the
     * second one.
     */
    public function testBounceClearUpdateDatabaseErrorIsLoggedAndDoesNotBlockSyncBlock(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(
            cars: [(object) ['id' => 100]],
            integrityCarIds: [200],
            failBounceClearUpdate: true
        );

        $this->fireHook();

        $entries = $this->bounceClearLogEntries($mockLogEntries);
        $anomalyEntry = null;
        $clearedEntry = null;
        foreach ($entries as $entry) {
            if (str_contains($entry['message'], 'had email_bounced=1')) {
                $anomalyEntry = $entry;
            } elseif (str_contains($entry['message'], 'cleared bounce flag')) {
                $clearedEntry = $entry;
            }
        }
        $this->assertNotNull($anomalyEntry, 'The integrity-anomaly line runs before the failing UPDATE and must still fire');
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, $anomalyEntry['category']);
        $this->assertNull($clearedEntry, 'The cleared-count line must not fire when the UPDATE itself failed');

        $bounceClearFailure = array_values(array_filter(
            $mockLogEntries,
            static fn(array $e): bool => str_contains($e['message'], 'bounce-clear failed for user 7')
        ));
        $this->assertCount(1, $bounceClearFailure, 'The bounce-clear UPDATE failure must be logged exactly once');
        $this->assertSame(LogCategories::LOG_CATEGORY_DATABASE_ERROR, $bounceClearFailure[0]['category']);

        $this->assertSame(
            1,
            $GLOBALS['hookTestDb']->bounceClearCalls,
            'The bounce-clear UPDATE must have been attempted exactly once'
        );

        $this->assertSame(
            1,
            $this->carLookupCount(),
            'The sync block must still run after the bounce-clear block throws on the UPDATE itself'
        );
    }

    /**
     * Issue #1890 regression: the hook used to construct
     * `new \ElanRegistry\Owner($userId)` OUTSIDE both try/catch blocks.
     * Owner::find() (called from the constructor) throws
     * OwnerDatabaseException — not a return value — on a DB error, so an
     * infrastructure fault during that lookup (e.g. a lock-wait timeout)
     * would propagate straight out of this hook file. The hook is a bare
     * `include` with no enclosing try/catch in includeHook()
     * (users/helpers/us_helpers.php), so that exception would escape all the
     * way out and fatal users/verify.php's render — exactly what this hook's
     * own documented contract ("this is a silent background repair and must
     * never interrupt verify.php's render") forbids.
     *
     * The fix moved the Owner construction INSIDE the first try block (so
     * the same \Throwable catch that already handles bounce-clear failures
     * catches this too) and added an `if ($owner === null) return;` guard
     * before the second (sync) try block.
     *
     * Against the old buggy code (construction outside both try blocks),
     * this test would fail with an uncaught OwnerDatabaseException escaping
     * fireHook() itself — not merely a wrong assertion value.
     */
    public function testOwnerLookupFailureIsCaughtAndDoesNotThrowOrRunSyncBlock(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(
            cars: [(object) ['id' => 100]],
            failOwnerLookup: true
        );

        // If the #1890 bug were still present, Owner::find()'s
        // OwnerDatabaseException would propagate straight out of this
        // require() and fail the test with an uncaught exception, not just
        // a wrong assertion below.
        $this->fireHook();

        // The bounce-clear block's own \Throwable catch (which now also
        // covers the Owner construction itself) must have logged this under
        // SYSTEM_ERROR.
        $failureEntries = array_values(array_filter(
            $mockLogEntries,
            static fn(array $e): bool => str_contains($e['message'], 'unexpected')
                && str_contains($e['message'], 'during bounce-clear for user 7')
        ));
        $this->assertCount(1, $failureEntries, 'The Owner construction failure must be logged exactly once');
        $this->assertSame(LogCategories::LOG_CATEGORY_SYSTEM_ERROR, $failureEntries[0]['category']);
        $this->assertStringContainsString('OwnerDatabaseException', $failureEntries[0]['message']);

        // Neither the bounce-clear UPDATE nor the sync block's car lookup
        // may have run — $owner stayed null, so the `if ($owner === null)
        // return;` guard must have skipped the second try block entirely.
        $this->assertSame(0, $GLOBALS['hookTestDb']->bounceClearCalls);
        $this->assertSame(
            0,
            $this->carLookupCount(),
            'The sync block must never run when the Owner lookup itself failed'
        );
    }

    /**
     * Security/scope guard: `email_suppressed` must never be referenced by
     * any SQL this new bounce-clear code issues — the UPDATE's SET clause
     * only ever touches email_bounced/email_bounced_address. Since this
     * fake dispatches purely on SQL string content, a regression that added
     * an email_suppressed reference to either new query would be caught by
     * asserting on the literal SQL text captured for the run.
     */
    public function testEmailSuppressedIsNeverReferencedByBounceClearSql(): void
    {
        global $verify;
        $verify = $this->fakeVerify(7);
        $delegate = new HookTestDb(
            cars: [(object) ['id' => 100]],
            integrityCarIds: [200],
            bounceClearAffectedRows: 1
        );
        $capturingDb = new SqlCapturingHookTestDb($delegate);
        $GLOBALS['hookTestDb'] = $capturingDb;

        $this->fireHook();

        $bounceClearSql = array_values(array_filter(
            $capturingDb->capturedSql,
            static fn(string $sql): bool => str_contains($sql, 'email_bounced')
        ));
        $this->assertNotEmpty($bounceClearSql, 'At least one bounce-clear-related query must have been issued');
        foreach ($bounceClearSql as $sql) {
            $this->assertStringNotContainsStringIgnoringCase('email_suppressed', $sql);
        }
    }
}

/**
 * Wraps a HookTestDb (which is `final`, so cannot be extended) to record
 * every SQL string passed through query() while delegating all actual
 * behavior to the wrapped instance. Exists solely for
 * testEmailSuppressedIsNeverReferencedByBounceClearSql()'s SQL-text assertion.
 */
final class SqlCapturingHookTestDb implements DatabaseInterface
{
    /** @var list<string> */
    public array $capturedSql = [];

    public function __construct(private HookTestDb $delegate)
    {
    }

    public function query(string $sql, array $params = []): DatabaseInterface
    {
        $this->capturedSql[] = $sql;
        $this->delegate->query($sql, $params);
        return $this;
    }
    public function get(string $table, array $where): self
    {
        $this->delegate->get($table, $where);
        return $this;
    }
    public function insert(string $table, array $fields = [], bool $update = false): bool
    {
        return $this->delegate->insert($table, $fields, $update);
    }
    public function update(string $table, array|int $id, array $fields): bool
    {
        return $this->delegate->update($table, $id, $fields);
    }
    public function delete(string $table, array|int $where): self
    {
        // HookTestDb::delete() (a plain stub — see its own definition below)
        // always returns itself and never false; the local variable exists
        // only so the call isn't a bare statement-level unused-result call.
        $delegateResult = $this->delegate->delete($table, $where);
        unset($delegateResult);
        return $this;
    }
    public function error(): bool
    {
        return $this->delegate->error();
    }
    public function errorString(): string
    {
        return $this->delegate->errorString();
    }
    public function errorInfo(): array
    {
        return $this->delegate->errorInfo();
    }
    public function count(): int
    {
        return $this->delegate->count();
    }
    public function first(bool $assoc = false): array|object
    {
        return $this->delegate->first($assoc);
    }
    public function results(bool $assoc = false): array
    {
        return $this->delegate->results($assoc);
    }
    public function lastId(): int
    {
        return $this->delegate->lastId();
    }
    public function beginTransaction(): bool
    {
        return $this->delegate->beginTransaction();
    }
    public function commit(): bool
    {
        return $this->delegate->commit();
    }
    public function rollBack(): bool
    {
        return $this->delegate->rollBack();
    }
    public function inTransaction(): bool
    {
        return $this->delegate->inTransaction();
    }
}

/**
 * Minimal scriptable DatabaseInterface for driving Owner through the hook.
 *
 * Owner::find() is bypassed (the hook's Owner is loaded via the users/profiles
 * SELECT this fake answers), and only the handful of calls
 * syncOwnerFieldsToCars() actually makes are modelled. Each constructor flag
 * selects one failure mode so a single test can pin one hook branch.
 */
final class HookTestDb implements DatabaseInterface
{
    public int $carLookups = 0;

    /** @var array<int, int> Car ids returned by the integrity-check SELECT, in call order. */
    public array $integrityCheckCalls = [];

    /** @var int Number of times the bounce-clear UPDATE was issued. */
    public int $bounceClearCalls = 0;

    /**
     * @var list<string> The $currentEmail param bound to each bounce-clear
     *      UPDATE, in call order — lets a test prove WHICH email value
     *      clearBouncedForUser() was actually invoked with, since
     *      $bounceClearAffectedRows alone is a fixed stub return and cannot
     *      distinguish that on its own.
     */
    public array $bounceClearEmails = [];

    // Unused; satisfies DatabaseInterface's @phpstan-impure contract. carLookups is what tests assert on.
    private int $calls = 0;
    private int $count = 0;
    /** @var array<int, object> */
    private array $rows = [];
    private bool $error = false;

    /**
     * @param array<int, object> $cars
     * @param list<int> $integrityCarIds Ids the integrity-check SELECT
     *        (`carIdsWithBouncedFlagButNoAddress()`) should return.
     * @param int $bounceClearAffectedRows Rows reported changed by the
     *        bounce-clear UPDATE (`clearBouncedForUser()`)'s DB::count().
     * @param string $ownerEmail The email Owner::find()'s fresh `FROM users u`
     *        SELECT returns — this, NOT fakeVerify()'s email, is what the
     *        hook's bounce-clear block actually compares against. Models the
     *        post-email-change row users/verify.php's UPDATE just committed.
     * @param bool $ownerNotFound When true, the `FROM users u` SELECT returns
     *        zero rows, so Owner::find() returns false and Owner::data()
     *        stays null — models the hook's defensive
     *        `$currentEmail === ''` branch.
     * @param bool $failOwnerLookup When true, the `FROM users u` SELECT
     *        (Owner::find()) reports a DB error via error(), so Owner::find()
     *        throws OwnerDatabaseException — models an infrastructure fault
     *        (lock-wait timeout, deadlock) during the hook's own `new
     *        Owner($userId)` construction, issue #1890's regression case.
     */
    public function __construct(
        private array $cars = [],
        private bool $failCarLookup = false,
        private bool $failUpdate = false,
        private bool $throwTypeErrorOnCarLookup = false,
        private array $integrityCarIds = [],
        private int $bounceClearAffectedRows = 0,
        private bool $failBounceClearUpdate = false,
        private bool $failIntegrityCheckSelect = false,
        private string $ownerEmail = 'new-address@example.com',
        private bool $ownerNotFound = false,
        private bool $failOwnerLookup = false
    ) {
    }

    public function query(string $sql, array $params = []): self
    {
        $this->error = false;

        if (str_contains($sql, 'FROM users u')) {
            // Owner::find() — the owner profile bundle. Issued FRESH, after
            // users/verify.php's email-change UPDATE has already committed —
            // $ownerEmail models that post-update row, independent of
            // whatever fakeVerify() was constructed with.
            if ($this->failOwnerLookup) {
                $this->error = true;
                $this->rows = [];
                $this->count = 0;
                return $this;
            }

            if ($this->ownerNotFound) {
                $this->rows = [];
                $this->count = 0;
                return $this;
            }

            $this->rows = [(object) [
                'id' => $params[0], 'fname' => 'Test', 'lname' => 'Owner',
                'email' => $this->ownerEmail, 'city' => 'Portland',
                'state' => 'Oregon', 'country' => 'United States',
                'lat' => null, 'lon' => null, 'website' => '',
            ]];
            $this->count = 1;
            return $this;
        }

        if (str_contains($sql, 'FROM cars c WHERE c.user_id')) {
            $this->carLookups++;

            if ($this->throwTypeErrorOnCarLookup) {
                throw new \TypeError('simulated: bindValue() received a non-scalar value');
            }
            if ($this->failCarLookup) {
                $this->error = true;
                $this->count = 0;
                $this->rows = [];
                return $this;
            }

            $this->rows = $this->cars;
            $this->count = count($this->cars);
            return $this;
        }

        // CarRepository::carIdsWithBouncedFlagButNoAddress() — the
        // data-integrity-anomaly check, issued BEFORE the bounce-clear UPDATE.
        if (str_starts_with($sql, 'SELECT id FROM cars WHERE')) {
            if ($this->failIntegrityCheckSelect) {
                $this->error = true;
                $this->count = 0;
                $this->rows = [];
                return $this;
            }

            $this->integrityCheckCalls[] = count($this->integrityCarIds);
            $this->rows = array_map(static fn (int $id): object => (object) ['id' => $id], $this->integrityCarIds);
            $this->count = count($this->integrityCarIds);
            return $this;
        }

        // CarRepository::clearBouncedForUser() — the bounce-clear UPDATE.
        if (str_starts_with($sql, 'UPDATE cars')
            && str_contains($sql, 'SET email_bounced = 0, email_bounced_address = NULL')
        ) {
            $this->bounceClearCalls++;
            $this->bounceClearEmails[] = (string) ($params[1] ?? '');

            if ($this->failBounceClearUpdate) {
                $this->error = true;
                $this->count = 0;
                $this->rows = [];
                return $this;
            }

            $this->count = $this->bounceClearAffectedRows;
            $this->rows = [];
            return $this;
        }

        if (str_starts_with($sql, 'UPDATE cars SET')) {
            if ($this->failUpdate) {
                // A plain Exception, so syncOwnerFieldsToCars()'s per-car
                // \Throwable handler records a failed car instead of propagating.
                throw new \RuntimeException('simulated per-car update failure');
            }

            // One row changed, so the caller skips the carBelongsToOwner()
            // ambiguity check and goes straight to the history insert.
            $this->count = 1;
            $this->rows = [];
            return $this;
        }

        $this->count = 0;
        $this->rows = [];
        return $this;
    }

    public function get(string $table, array $where): self
    {
        return $this;
    }
    public function insert(string $table, array $fields = [], bool $update = false): bool
    {
        return true;
    }
    public function update(string $table, array|int $id, array $fields): bool
    {
        return true;
    }
    public function delete(string $table, array|int $where): self
    {
        return $this;
    }
    public function error(): bool
    {
        $this->calls++;
        return $this->error;
    }
    public function errorString(): string
    {
        $this->calls++;
        return $this->error ? 'simulated deadlock' : '';
    }
    public function errorInfo(): array
    {
        $this->calls++;
        return [];
    }
    public function count(): int
    {
        $this->calls++;
        return $this->count;
    }
    public function first(bool $assoc = false): array|object
    {
        $this->calls++;
        return $this->rows[0] ?? [];
    }
    public function results(bool $assoc = false): array
    {
        $this->calls++;
        return $this->rows;
    }
    public function lastId(): int
    {
        $this->calls++;
        return 0;
    }
    public function beginTransaction(): bool
    {
        return true;
    }
    public function commit(): bool
    {
        return true;
    }
    public function rollBack(): bool
    {
        return true;
    }
    public function inTransaction(): bool
    {
        $this->calls++;
        return false;
    }
}

// The seam the hook's `new Owner($userId)` falls back to when no DB is passed.
// Defined here rather than in bootstrap-unit.php because this is the only unit
// test that needs it; guarded so it cannot collide if that ever changes.
if (!function_exists('dbi')) {
    function dbi(): DatabaseInterface
    {
        return $GLOBALS['hookTestDb'] ?? new HookTestDb();
    }
}
