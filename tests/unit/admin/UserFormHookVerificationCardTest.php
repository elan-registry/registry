<?php

declare(strict_types=1);

use ElanRegistry\DatabaseInterface;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Renders the "Verification & Email" card of user_form_hook.php (#1924) with
 * scripted database doubles. Playwright cannot reach these states: a corrupt
 * row next to a good one, and a database failure mid-render.
 *
 * '0000-00-00 00:00:00' is reachable: users/classes/DB.php sets
 * `sql_mode = ''`, so MySQL stores and returns zero-dates.
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
        $GLOBALS['hookTestDb'] = new VerificationCardTestDb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['hookTestDb']);
        parent::tearDown();
    }

    /**
     * The hook reads both `$db` and `dbi()`; both resolve $GLOBALS['hookTestDb'].
     */
    private function useDb(VerificationCardTestDb $double): void
    {
        $GLOBALS['hookTestDb'] = $double;
    }

    /**
     * $db is a local, not a global: in production the hook inherits it from the
     * scope of users/admin.php, which includes it.
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
     * Scoped to the card: the profile table above it has one `<tr>` that links
     * every car, so an unscoped search would match any car id.
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

    // Per-row "Unknown" isolation: the catch in $isFreshCar.

    /**
     * One corrupt car must not blank out its siblings, which are what the admin
     * opened the panel to read.
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
            'A corrupt timestamp on car #101 must not leak an Unknown badge onto car #102'
        );
    }

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
        $this->assertStringContainsString('No delivery problems recorded', $html);
    }

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
     * CarRepository::isFresh() validates both timestamps before it compares
     * either, so a bad last_verified is not hidden by a fresh owner_last_updated.
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
     * Old is not corrupt. A wider catch would mark every stale car Unknown.
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

    // Whole-panel degraded state: the outer try/catch.

    /**
     * includeHook() has no try/catch, so an escaped exception kills the whole
     * admin user-view page.
     */
    public function testDatabaseFailureRendersTheDegradedAlertInsteadOfThrowing(): void
    {
        $this->useDb(new VerificationCardTestDb(failVerificationStateQuery: true));

        $html = $this->render();

        $this->assertStringContainsString('Verification and email status could not be loaded', $html);
        $this->assertStringContainsString('alert-danger', $html);
    }

    /**
     * A failed query must read as "unknown", never as a clean bill of health.
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
     * Partial data would show "no events recorded" instead of "not loaded".
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
     * \DB::query() prepares outside its own try/catch, so a failed prepare
     * throws \PDOException straight through CarRepository.
     */
    public function testRawPdoExceptionAlsoRendersTheDegradedAlert(): void
    {
        $this->useDb(new VerificationCardTestDb(throwPdoExceptionOnVerificationStateQuery: true));

        $html = $this->render();

        $this->assertStringContainsString('Verification and email status could not be loaded', $html);
    }

    /**
     * The alert says "see the admin logs", so the log line is the only diagnostic.
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

    /**
     * The profile table above the card also emits "No cars registered".
     */
    private function cardHtml(string $html): string
    {
        $offset = strpos($html, 'Verification &amp; Email');
        $this->assertNotFalse($offset, 'The card heading must always render');

        return substr($html, $offset);
    }

    private static function recentTimestamp(): string
    {
        return (new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');
    }

    /**
     * Numeric columns are strings because PDO can return them as strings and
     * the hook casts them.
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
 * Dispatches on SQL text. Named apart from SyncOwnerEmailOnVerifyHookTest's
 * HookTestDb because both files share one PHP process.
 */
final class VerificationCardTestDb implements DatabaseInterface
{
    /** @var array<int, object> Rows for the most recent query. */
    private array $rows = [];
    private int $count = 0;
    private bool $error = false;

    /** Gives the accessors a side effect for DatabaseInterface's `@phpstan-impure`. */
    private int $calls = 0;

    /**
     * @param array<int, object> $cars Verification-state rows; their ids feed the car-button query
     * @param array<int, object> $events Delivery-event rows
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

        if (str_contains($sql, 'FROM cars c WHERE c.user_id')) {
            return $this->respond(array_map(
                static fn(object $car): object => (object) ['id' => $car->id],
                $this->cars
            ));
        }

        if (str_contains($sql, 'email_suppressed, owner_last_updated, last_verified')) {
            if ($this->throwPdoExceptionOnVerificationStateQuery) {
                throw new \PDOException('simulated: SQLSTATE[HY000] server has gone away during prepare()');
            }
            if ($this->failVerificationStateQuery) {
                return $this->fail();
            }

            return $this->respond($this->cars);
        }

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

// SyncOwnerEmailOnVerifyHookTest defines the same seam and whichever file loads
// first wins. Both read $GLOBALS['hookTestDb'].
if (!function_exists('dbi')) {
    function dbi(): DatabaseInterface
    {
        return $GLOBALS['hookTestDb'] ?? new VerificationCardTestDb();
    }
}
