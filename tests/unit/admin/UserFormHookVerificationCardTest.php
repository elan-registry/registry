<?php

declare(strict_types=1);

use ElanRegistry\DatabaseInterface;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the "Verification & Email" card in
 * usersc/plugins/hooker/hooks/user_form_hook.php (issue #1924), exercising the
 * hook FILE directly and asserting on the HTML it renders.
 *
 * Follows the pattern established by
 * tests/unit/security/SyncOwnerEmailOnVerifyHookTest.php: the hook is a plain
 * included script with no enclosing function, and every collaborator it touches
 * is substitutable — it reads `global $userId, $us_url_root, $db` and builds its
 * CarRepository through the `dbi()` seam. So the file can simply be `require`d
 * with those globals set to scripted doubles, and its output captured with an
 * output buffer.
 *
 * Two degradation behaviors are covered here that no other tier can reach.
 * Both are about the hook's own control flow and rendering, not about the
 * repository methods underneath (those are covered by
 * tests/unit/cars/services/CarRepositoryTest.php and
 * tests/integration/database/CarRepositoryEmailEventsTest.php), and neither is
 * reachable from the Playwright spec, which can only render whatever state the
 * local database happens to be in:
 *
 *   * PER-ROW "Unknown" ISOLATION. `$isFreshCar` catches CarValidationException
 *     for ONE car with a corrupt timestamp and renders an "Unknown" badge for
 *     that row alone. The point of catching per row rather than around the whole
 *     panel is that a single corrupt car must not blank out its siblings' rows —
 *     which is the diagnosis an admin opened this panel for in the first place.
 *     Asserting that requires rendering a table with a corrupt row NEXT TO a
 *     good row and checking both, which is what these tests do.
 *   * WHOLE-PANEL DB-FAILURE DEGRADED STATE. The outer catch sets
 *     `$verificationLoadError = true`, rendering a distinct alert instead of
 *     letting the exception escape and fatal the entire admin user-view page
 *     (the hook is included mid-render by includeHook(), which wraps it in no
 *     try/catch of its own). Forcing that state from a real page load would mean
 *     breaking the live database mid-request; here it is one constructor flag.
 *
 * The corrupt-timestamp value used below, '0000-00-00 00:00:00', is not
 * hypothetical: users/classes/DB.php sets `sql_mode = ''` on every application
 * connection, so MySQL both accepts and returns a zero-date in a NOT NULL
 * DATETIME column. See CarRepository::parseTimestamp()'s own comment on exactly
 * this reachability.
 *
 * @see usersc/plugins/hooker/hooks/user_form_hook.php
 * @see tests/playwright/admin-user-view-verification.spec.js
 */
#[Group('fast')]
#[Group('unit')]
final class UserFormHookVerificationCardTest extends TestCase
{
    private const HOOK = __DIR__
        . '/../../../usersc/plugins/hooker/hooks/user_form_hook.php';

    /** The seeded owner id every test renders the card for. */
    private const OWNER_ID = 77;

    protected function setUp(): void
    {
        parent::setUp();
        global $mockLogEntries, $userId, $us_url_root;

        $mockLogEntries = [];
        $userId         = self::OWNER_ID;
        $us_url_root    = '/';
        // Replaced per-test with a double scripted for the branch under test.
        // Set here too so render() always has something to bind, even if a
        // test's own arrangement throws before reaching its useDb() call.
        $GLOBALS['hookTestDb'] = new VerificationCardTestDb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['hookTestDb']);
        parent::tearDown();
    }

    /**
     * Installs a scripted database double as BOTH collaborators the hook uses.
     *
     * The hook reaches the database two different ways and they must agree: the
     * inherited `$db` for the profile/car-button queries at the top of the file
     * (see render() on why that one is a local), and `dbi()` for the
     * CarRepository that backs the card. Both resolve $GLOBALS['hookTestDb'],
     * so one assignment here points both at the same instance.
     */
    private function useDb(VerificationCardTestDb $double): void
    {
        $GLOBALS['hookTestDb'] = $double;
    }

    /**
     * Renders the hook and returns its HTML.
     *
     * `require`, not `require_once` — several tests render more than once in a
     * single process, and the hook is written to be included per request.
     *
     * The hook's first three lines are a `count(get_included_files()) == 1`
     * direct-access guard. Under PHPUnit dozens of files are already included,
     * so the guard passes and does not need to be worked around.
     *
     * $db is a LOCAL variable here, not a global. The hook declares `global
     * $userId, $us_url_root` but NOT $db — in production it inherits $db from
     * the scope of users/admin.php, which included it, and an included file
     * shares its includer's local scope. So the double has to be a local in
     * whichever function does the require, which is this one.
     */
    private function render(): string
    {
        $db = $GLOBALS['hookTestDb'];

        ob_start();
        try {
            require self::HOOK;
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * Extracts the single `<tr>` describing a given car from the card's per-car
     * table, so an assertion about one row cannot be satisfied by content that
     * actually belongs to a different row — which is the entire point of the
     * isolation tests below.
     *
     * Scoped to the card via cardHtml() rather than searching the whole
     * document: the profile table ABOVE the card ends with a single `<tr>` that
     * contains a link for EVERY car at once (the car-button list). That row
     * matches every car id, so an unscoped search would return it for any
     * $carId and silently make these assertions meaningless.
     */
    private function rowForCar(string $html, int $carId): string
    {
        preg_match_all('/<tr>(.*?)<\/tr>/s', $this->cardHtml($html), $matches);

        foreach ($matches[1] as $row) {
            if (str_contains($row, 'car_id=' . $carId . '"')) {
                return $row;
            }
        }

        $this->fail("No table row rendered for car #{$carId}");
    }

    // =========================================================================
    // Per-row "Unknown" isolation (the $isFreshCar per-row catch)
    // =========================================================================

    /**
     * The core isolation case: one car with a zero-date `owner_last_updated`
     * sitting alongside a sibling with perfectly good timestamps.
     *
     * The corrupt row must render the "Unknown" badge and its explanatory
     * "Unusable timestamps" line, and the sibling row must be completely
     * unaffected — a normal Verified badge, no Unknown badge of its own.
     *
     * If the CarValidationException catch were moved out of `$isFreshCar` and
     * up to the panel-level try/catch, this test would fail loudly: the whole
     * per-car table would be replaced by the load-error alert and neither row
     * would render at all.
     */
    public function testCorruptTimestampMarksOnlyItsOwnRowUnknown(): void
    {
        $this->useDb(new VerificationCardTestDb(cars: [
            self::car(id: 101, ownerLastUpdated: '0000-00-00 00:00:00'),
            self::car(id: 102, ownerLastUpdated: self::recentTimestamp()),
        ]));

        $html = $this->render();

        $corruptRow = $this->rowForCar($html, 101);
        $this->assertStringContainsString('Unknown', $corruptRow);
        $this->assertStringContainsString('Unusable timestamps', $corruptRow);

        $siblingRow = $this->rowForCar($html, 102);
        $this->assertStringContainsString('Verified', $siblingRow);
        $this->assertStringNotContainsString(
            'Unknown',
            $siblingRow,
            'A corrupt timestamp on car #101 must not leak an Unknown badge onto car #102 — the '
            . 'CarValidationException catch is per row precisely so one corrupt car cannot blank '
            . 'out the sibling rows an admin opened this panel to read'
        );
    }

    /**
     * The corrupt row must not take the rest of the PANEL down with it either:
     * the per-car table itself still renders (both rows present), and the
     * whole-panel load-error alert — which belongs to the outer catch, not this
     * one — must be absent.
     */
    public function testCorruptTimestampStillRendersTheFullTable(): void
    {
        $this->useDb(new VerificationCardTestDb(cars: [
            self::car(id: 101, ownerLastUpdated: '0000-00-00 00:00:00'),
            self::car(id: 102, ownerLastUpdated: self::recentTimestamp()),
        ]));

        $html = $this->render();

        $this->assertStringContainsString('car_id=101"', $html);
        $this->assertStringContainsString('car_id=102"', $html);
        $this->assertStringNotContainsString(
            'Verification and email status could not be loaded',
            $html,
            'A per-row timestamp fault is not a panel-level load failure'
        );
        // A corrupt timestamp says nothing about deliverability, and neither
        // seeded car carries a bounce or suppression flag.
        $this->assertStringContainsString('No delivery problems recorded', $html);
    }

    /**
     * The corruption must be recorded for follow-up, under the validation
     * category and naming the specific car, since the badge alone tells an
     * admin only that something is wrong and not which value is unusable.
     */
    public function testCorruptTimestampIsLoggedAsAValidationErrorNamingTheCar(): void
    {
        global $mockLogEntries;

        $this->useDb(new VerificationCardTestDb(cars: [
            self::car(id: 101, ownerLastUpdated: '0000-00-00 00:00:00'),
            self::car(id: 102, ownerLastUpdated: self::recentTimestamp()),
        ]));

        $this->render();

        $entries = array_values(array_filter(
            $mockLogEntries,
            static fn(array $e): bool => str_contains($e['message'], 'unusable verification timestamps')
        ));

        $this->assertCount(1, $entries, 'Only the corrupt car may log — not its healthy sibling');
        $this->assertSame(LogCategories::LOG_CATEGORY_VALIDATION_ERROR, $entries[0]['category']);
        $this->assertStringContainsString('car_id=101', $entries[0]['message']);
    }

    /**
     * A corrupt `last_verified` reaches the same per-row handler as a corrupt
     * `owner_last_updated`, even though `owner_last_updated` is itself fine.
     *
     * This is deliberate in CarRepository::isFresh(): it validates BOTH operands
     * before either comparison rather than short-circuiting on a fresh
     * `owner_last_updated`, so garbage in `last_verified` surfaces now instead
     * of a year from now. The hook must therefore render Unknown here too, not
     * a confident "Verified" derived from the one operand that happens to parse.
     */
    public function testCorruptLastVerifiedAlsoMarksTheRowUnknown(): void
    {
        $this->useDb(new VerificationCardTestDb(cars: [
            self::car(
                id: 101,
                ownerLastUpdated: self::recentTimestamp(),
                lastVerified: '0000-00-00 00:00:00'
            ),
            self::car(id: 102, ownerLastUpdated: self::recentTimestamp()),
        ]));

        $html = $this->render();

        $this->assertStringContainsString('Unknown', $this->rowForCar($html, 101));
        $this->assertStringNotContainsString('Unknown', $this->rowForCar($html, 102));
    }

    /**
     * Guards the boundary between "Unknown" (corrupt) and "Unverified" (merely
     * old), which the badge colours deliberately distinguish.
     *
     * A stale-but-well-formed timestamp is ordinary data, not corruption, so it
     * must render the Unverified badge — if a future change widened the
     * CarValidationException catch into a general "anything that isn't fresh"
     * fallback, every stale car in the registry would start reporting as
     * corrupt and the Unknown badge would stop meaning anything.
     */
    public function testStaleButWellFormedTimestampRendersUnverifiedNotUnknown(): void
    {
        $this->useDb(new VerificationCardTestDb(cars: [
            self::car(
                id: 101,
                ownerLastUpdated: (new DateTimeImmutable('-2 years'))->format('Y-m-d H:i:s')
            ),
        ]));

        $row = $this->rowForCar($this->render(), 101);

        $this->assertStringContainsString('Unverified', $row);
        $this->assertStringNotContainsString('Unknown', $row);
    }

    // =========================================================================
    // Whole-panel DB-failure degraded state (the outer try/catch)
    // =========================================================================

    /**
     * When the per-car state query fails, the panel must render its distinct
     * load-error alert rather than letting CarDatabaseException escape.
     *
     * The hook is included mid-render by includeHook()
     * (users/helpers/us_helpers.php), which wraps it in no try/catch, so an
     * uncaught exception here takes down the entire admin user-view page —
     * profile table, car buttons, permission management and all. If that catch
     * were removed, this test would fail with the exception escaping render(),
     * not merely with a wrong assertion.
     */
    public function testDatabaseFailureRendersTheDegradedAlertInsteadOfThrowing(): void
    {
        $this->useDb(new VerificationCardTestDb(failVerificationStateQuery: true));

        $html = $this->render();

        $this->assertStringContainsString('Verification and email status could not be loaded', $html);
        $this->assertStringContainsString('alert-danger', $html);
    }

    /**
     * The degraded state must be unambiguous: an empty $verificationState in
     * this state means "unknown", never "no delivery problems" and never "no
     * cars registered". Reporting a clean bill of health from a failed query is
     * the specific wrong answer this branch exists to prevent — an admin would
     * read it as "this owner's email is fine" when nothing was actually checked.
     */
    public function testDatabaseFailureSuppressesTheSummaryAlertsAndPerCarTable(): void
    {
        $this->useDb(new VerificationCardTestDb(failVerificationStateQuery: true));

        $html = $this->render();

        $this->assertStringNotContainsString(
            'No delivery problems recorded',
            $html,
            'A failed query must never be reported as a clean bill of health'
        );
        $this->assertStringNotContainsString('bounced,', $html, 'No bounce summary can be derived from a failed query');
        $this->assertStringNotContainsString(
            'No cars registered',
            $this->cardHtml($html),
            'An empty state after a failed query means "unknown", not "this owner has no cars"'
        );
        $this->assertStringNotContainsString('<thead>', $html, 'The per-car table must not render at all');
    }

    /**
     * The SECOND repository call has its own failure mode: the per-car state
     * query can succeed and the aggregate delivery-event query still fail. Both
     * sit inside the same try block, so the panel must degrade identically —
     * partial data here would pair real car rows with silently missing event
     * history, which reads as "no events recorded" rather than "not loaded".
     */
    public function testEmailEventQueryFailureAlsoRendersTheDegradedAlert(): void
    {
        $this->useDb(new VerificationCardTestDb(
            cars: [self::car(id: 101, ownerLastUpdated: self::recentTimestamp())],
            failEmailEventQuery: true
        ));

        $html = $this->render();

        $this->assertStringContainsString('Verification and email status could not be loaded', $html);
        $this->assertStringNotContainsString('<thead>', $html);
    }

    /**
     * A raw \PDOException must degrade exactly like a CarDatabaseException.
     *
     * This is not a redundant case. CarRepository only converts a FAILED
     * STATEMENT into CarDatabaseException by checking `$db->error()` after the
     * fact; \DB::query() prepares OUTSIDE its own try/catch, so a failing
     * prepare (a dropped connection, a renamed column) throws \PDOException
     * straight through the repository and never becomes a typed exception at
     * all. The catch lists both types for this reason, and dropping the
     * \PDOException arm would reopen the whole-page fatal.
     */
    public function testRawPdoExceptionAlsoRendersTheDegradedAlert(): void
    {
        $this->useDb(new VerificationCardTestDb(throwPdoExceptionOnVerificationStateQuery: true));

        $html = $this->render();

        $this->assertStringContainsString('Verification and email status could not be loaded', $html);
    }

    /**
     * The failure must be recorded under the database category, naming the
     * owner — the alert deliberately tells the admin only to "see the admin
     * logs for details", so the log line is the whole diagnostic.
     */
    public function testDatabaseFailureIsLoggedAsADatabaseErrorNamingTheOwner(): void
    {
        global $mockLogEntries;

        $this->useDb(new VerificationCardTestDb(failVerificationStateQuery: true));

        $this->render();

        $entries = array_values(array_filter(
            $mockLogEntries,
            static fn(array $e): bool => str_contains($e['message'], 'verification/email panel failed to load')
        ));

        $this->assertCount(1, $entries);
        $this->assertSame(LogCategories::LOG_CATEGORY_DATABASE_ERROR, $entries[0]['category']);
        $this->assertStringContainsString('user_id=' . self::OWNER_ID, $entries[0]['message']);
    }

    /**
     * The rest of the page must survive the degraded panel.
     *
     * The card is rendered AFTER the profile table and car-button list, both of
     * which come from the `global $db` queries at the top of the hook and are
     * untouched by the repository failure. The alert's own copy promises "the
     * rest of this page is unaffected" — this asserts that promise holds rather
     * than taking the sentence's word for it.
     */
    public function testDatabaseFailureLeavesTheProfileAndCarButtonsIntact(): void
    {
        $this->useDb(new VerificationCardTestDb(
            cars: [self::car(id: 101, ownerLastUpdated: self::recentTimestamp())],
            failVerificationStateQuery: true
        ));

        $html = $this->render();

        $this->assertStringContainsString('Portland', $html, 'The profile table must still render');
        $this->assertStringContainsString('Car #101', $html, 'The car-button list must still render');
        $this->assertStringContainsString('Verification and email status could not be loaded', $html);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Narrows the rendered HTML to the "Verification & Email" card.
     *
     * The card is the last element the hook emits, so everything from its
     * heading onward is the card. Needed because a couple of strings the card's
     * degraded state must NOT contain ("No cars registered") are also emitted
     * legitimately by the profile table ABOVE the card, and a whole-document
     * assertion could not tell the two apart.
     */
    private function cardHtml(string $html): string
    {
        $offset = strpos($html, 'Verification &amp; Email');
        $this->assertNotFalse($offset, 'The card heading must always render');

        return substr($html, $offset);
    }

    /** A timestamp comfortably inside the one-year freshness window. */
    private static function recentTimestamp(): string
    {
        return (new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');
    }

    /**
     * Builds one row in the shape findVerificationStateByOwner() returns.
     *
     * Numeric columns are strings on purpose: that method's own docblock
     * documents that PDO returns `int|string` depending on driver typing, and
     * the hook is written to cast at the call site. Modelling them as native
     * ints here would quietly stop testing those casts.
     */
    private static function car(
        int $id,
        string $ownerLastUpdated,
        ?string $lastVerified = null,
        string $emailBounced = '0',
        string $emailSuppressed = '0',
        ?string $bouncedAddress = null
    ): object {
        return (object) [
            'id'                    => (string) $id,
            'model'                 => 'Elan',
            'series'                => 'S4',
            'variant'               => 'SE',
            'year'                  => '1968',
            'email'                 => 'owner@example.invalid',
            'email_bounced'         => $emailBounced,
            'email_bounced_address' => $bouncedAddress,
            'email_suppressed'      => $emailSuppressed,
            'owner_last_updated'    => $ownerLastUpdated,
            'last_verified'         => $lastVerified,
        ];
    }
}

/**
 * Scriptable DatabaseInterface for driving user_form_hook.php.
 *
 * Dispatches on SQL text, answering the four distinct queries the hook's render
 * path issues: the profile SELECT and car-button SELECT it makes directly
 * through `global $db`, and the two CarRepository queries behind the card. Each
 * constructor flag selects one failure mode so a single test pins one branch.
 *
 * Named distinctly from SyncOwnerEmailOnVerifyHookTest.php's HookTestDb: with
 * processIsolation="false" both test files share one PHP process, and a
 * duplicate class name would fatal.
 */
final class VerificationCardTestDb implements DatabaseInterface
{
    /** @var array<int, object> Rows for the most recent query. */
    private array $rows = [];
    private int $count = 0;
    private bool $error = false;

    /**
     * Unused by any assertion; exists solely to give the accessors below a
     * side effect, satisfying DatabaseInterface's `@phpstan-impure` contract on
     * each of them. Same device, for the same reason, as HookTestDb::$calls in
     * tests/unit/security/SyncOwnerEmailOnVerifyHookTest.php.
     */
    private int $calls = 0;

    /**
     * @param array<int, object> $cars Rows findVerificationStateByOwner() returns,
     *        and (by id) the rows the car-button SELECT returns.
     * @param array<int, object> $events Rows the aggregate delivery-event query
     *        returns; each needs car_id, event, occurred_at and reason.
     * @param bool $failVerificationStateQuery When true the per-car state SELECT
     *        reports a DB error, so findVerificationStateByOwner() throws
     *        CarDatabaseException.
     * @param bool $failEmailEventQuery When true the aggregate delivery-event
     *        SELECT reports a DB error, so findLatestEmailEventsByCarIds()
     *        throws CarDatabaseException — the second, independently reachable
     *        failure inside the hook's single try block.
     * @param bool $throwPdoExceptionOnVerificationStateQuery When true the
     *        per-car state SELECT throws a raw \PDOException instead of
     *        reporting error(), modelling \DB::query()'s unguarded prepare()
     *        (see the hook's file-header comment on why both types are caught).
     */
    public function __construct(
        private array $cars = [],
        private array $events = [],
        private bool $failVerificationStateQuery = false,
        private bool $failEmailEventQuery = false,
        private bool $throwPdoExceptionOnVerificationStateQuery = false
    ) {
    }

    public function query(string $sql, array $params = []): self
    {
        $this->error = false;

        // The hook's own profile lookup, via `global $db`.
        if (str_contains($sql, 'FROM profiles WHERE user_id')) {
            return $this->respond([(object) [
                'user_id' => $params[0] ?? 0,
                'city'    => 'Portland',
                'state'   => 'Oregon',
                'country' => 'United States',
                'lat'     => null,
                'lon'     => null,
            ]]);
        }

        // The hook's own car-button lookup, via `global $db`. Only the id is
        // read by that loop.
        if (str_contains($sql, 'FROM cars c WHERE c.user_id')) {
            return $this->respond(array_map(
                static fn(object $car): object => (object) ['id' => $car->id],
                $this->cars
            ));
        }

        // CarRepository::findVerificationStateByOwner().
        if (str_contains($sql, 'email_suppressed, owner_last_updated, last_verified')) {
            if ($this->throwPdoExceptionOnVerificationStateQuery) {
                throw new \PDOException('simulated: SQLSTATE[HY000] server has gone away during prepare()');
            }
            if ($this->failVerificationStateQuery) {
                return $this->fail();
            }

            return $this->respond($this->cars);
        }

        // CarRepository::findLatestEmailEventsByCarIds().
        if (str_contains($sql, 'FROM er_email_events')) {
            if ($this->failEmailEventQuery) {
                return $this->fail();
            }

            return $this->respond($this->events);
        }

        return $this->respond([]);
    }

    /**
     * @param array<int, object> $rows
     */
    private function respond(array $rows): self
    {
        $this->rows  = array_values($rows);
        $this->count = count($this->rows);

        return $this;
    }

    private function fail(): self
    {
        $this->error = true;
        $this->rows  = [];
        $this->count = 0;

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
        return $this->error ? 'ERROR #HY000: simulated database failure' : '';
    }
    public function errorInfo(): array
    {
        $this->calls++;
        return $this->error ? ['HY000', 2006, 'simulated database failure'] : [];
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

// The seam the hook's `new CarRepository(dbi())` resolves through. Guarded
// because tests/unit/security/SyncOwnerEmailOnVerifyHookTest.php defines the
// same seam for the sibling hook and, with processIsolation="false", whichever
// test file PHPUnit loads first wins. Both implementations read
// $GLOBALS['hookTestDb'], so either one returns this file's double — which is
// why setUp() assigns there rather than to a private property.
if (!function_exists('dbi')) {
    function dbi(): DatabaseInterface
    {
        return $GLOBALS['hookTestDb'] ?? new VerificationCardTestDb();
    }
}
