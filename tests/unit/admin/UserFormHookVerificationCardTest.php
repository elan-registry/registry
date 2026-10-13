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

    // Owner-level flags, read from the profile (#2101).

    /**
     * A profile row joined with `users.email`, as effectiveEmailFlags() reads
     * it. Every flag is clear unless $overrides sets it. A flag shows only
     * while its address matches `user_email`, so a test that sets a flag
     * address sets `user_email` too. Values are strings because PDO may
     * return them so.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function state(array $overrides = []): array
    {
        return array_merge([
            'id' => '5', 'user_id' => (string) self::OWNER_ID,
            'email_opted_out' => '0', 'email_opted_out_at' => null,
            'email_blocked' => '0', 'email_blocked_address' => null,
            'email_blocked_at' => null, 'email_blocked_cause' => null,
            'email_bounced' => '0', 'email_bounced_address' => null,
            'email_bounced_at' => null, 'email_bounce_cleared_at' => null,
            'user_email' => 'owner@example.invalid',
        ], $overrides);
    }

    public function testBouncedProfileShowsTheBouncedBadgeWithAddressAndDate(): void
    {
        $this->useDb(new VerificationCardTestDb(
            cars: [self::car(id: 101, ownerLastUpdated: self::recentTimestamp())],
            state: self::state([
                'email_bounced' => '1',
                'email_bounced_address' => 'bounced@example.invalid',
                'email_bounced_at' => '2026-05-01 10:00:00',
                'user_email' => 'bounced@example.invalid',
            ])
        ));

        $card = $this->cardHtml($this->render());

        $this->assertStringContainsString('Bounced', $card);
        $this->assertStringContainsString('bounced@example.invalid', $card);
        $this->assertStringContainsString('2026-05-01 10:00:00', $card);
        $this->assertStringNotContainsString('No delivery problems recorded', $card);
        $this->assertStringNotContainsString('Owner opted out', $card);
    }

    public function testBlockedProfileShowsSuppressedWithItsCauseAddressAndDate(): void
    {
        $this->useDb(new VerificationCardTestDb(
            cars: [self::car(id: 101, ownerLastUpdated: self::recentTimestamp())],
            state: self::state([
                'email_blocked' => '1',
                'email_blocked_address' => 'blocked@example.invalid',
                'email_blocked_at' => '2026-06-02 11:00:00',
                'email_blocked_cause' => 'spam',
                'user_email' => 'blocked@example.invalid',
            ])
        ));

        $card = $this->cardHtml($this->render());

        $this->assertStringContainsString('Suppressed', $card);
        $this->assertStringContainsString('Blocked (cause: spam)', $card);
        $this->assertStringContainsString('blocked@example.invalid', $card);
        $this->assertStringContainsString('2026-06-02 11:00:00', $card);
    }

    public function testBlockedProfileWithNoCauseSaysCauseUnknown(): void
    {
        $this->useDb(new VerificationCardTestDb(
            cars: [self::car(id: 101, ownerLastUpdated: self::recentTimestamp())],
            state: self::state([
                'email_blocked' => '1', 'email_blocked_address' => 'blocked@example.invalid',
                'user_email' => 'blocked@example.invalid',
            ])
        ));

        $this->assertStringContainsString('Blocked (cause: unknown)', $this->cardHtml($this->render()));
    }

    public function testOptedOutProfileShowsOwnerOptedOutWithItsDate(): void
    {
        $this->useDb(new VerificationCardTestDb(
            cars: [self::car(id: 101, ownerLastUpdated: self::recentTimestamp())],
            state: self::state(['email_opted_out' => '1', 'email_opted_out_at' => '2026-07-03 12:00:00'])
        ));

        $card = $this->cardHtml($this->render());

        $this->assertStringContainsString('Owner opted out', $card);
        $this->assertStringContainsString('2026-07-03 12:00:00', $card);
        $this->assertStringNotContainsString('No delivery problems recorded', $card);
    }

    /** One owner with all three flags shows all three lines. */
    public function testAllThreeFlagsShowTogether(): void
    {
        $this->useDb(new VerificationCardTestDb(
            cars: [self::car(id: 101, ownerLastUpdated: self::recentTimestamp())],
            state: self::state([
                'email_opted_out' => '1',
                'email_blocked' => '1', 'email_blocked_address' => 'b@example.invalid', 'email_blocked_cause' => 'unsubscribed',
                'email_bounced' => '1', 'email_bounced_address' => 'b@example.invalid',
                'user_email' => 'b@example.invalid',
            ])
        ));

        $card = $this->cardHtml($this->render());

        $this->assertStringContainsString('text-bg-danger">Bounced', $card);
        $this->assertStringContainsString('Blocked (cause: unsubscribed)', $card);
        $this->assertStringContainsString('Owner opted out', $card);
    }

    /** A flag stored for an old address does not apply, so the card shows no problem (#2356). */
    public function testFlagsForAnOldAddressAreNotShown(): void
    {
        $this->useDb(new VerificationCardTestDb(
            cars: [self::car(id: 101, ownerLastUpdated: self::recentTimestamp())],
            state: self::state([
                'email_bounced' => '1', 'email_bounced_address' => 'old@example.invalid',
                'email_blocked' => '1', 'email_blocked_address' => 'old@example.invalid',
                'user_email' => 'new@example.invalid',
            ])
        ));

        $card = $this->cardHtml($this->render());

        $this->assertStringContainsString('No delivery problems recorded', $card);
        $this->assertStringNotContainsString('old@example.invalid', $card);
        $this->assertStringNotContainsString('Bounced', $card);
        $this->assertStringNotContainsString('Blocked (cause', $card);
    }

    /** The opt-out is not tied to an address, so it shows after an address change. */
    public function testOptOutShowsAfterTheAddressChanged(): void
    {
        $this->useDb(new VerificationCardTestDb(
            cars: [self::car(id: 101, ownerLastUpdated: self::recentTimestamp())],
            state: self::state([
                'email_opted_out' => '1', 'email_opted_out_at' => '2026-07-03 12:00:00',
                'email_bounced' => '1', 'email_bounced_address' => 'old@example.invalid',
            ])
        ));

        $card = $this->cardHtml($this->render());

        $this->assertStringContainsString('Owner opted out', $card);
        $this->assertStringNotContainsString('old@example.invalid', $card);
    }

    /** A flag with no stored address fails closed, so it shows. */
    public function testFlagWithNoStoredAddressStillShows(): void
    {
        $this->useDb(new VerificationCardTestDb(
            cars: [self::car(id: 101, ownerLastUpdated: self::recentTimestamp())],
            state: self::state(['email_bounced' => '1', 'email_bounced_address' => null])
        ));

        $card = $this->cardHtml($this->render());

        $this->assertStringContainsString('text-bg-danger">Bounced', $card);
        $this->assertStringNotContainsString('No delivery problems recorded', $card);
        $this->assertStringContainsString('<em class="text-muted">no address recorded</em>', $card, 'The admin sees the anomaly');
    }

    /** A block with an empty stored address also names the anomaly. A bounce with an address does not. */
    public function testBlockWithAnEmptyStoredAddressSaysNoAddressRecorded(): void
    {
        $this->useDb(new VerificationCardTestDb(
            cars: [self::car(id: 101, ownerLastUpdated: self::recentTimestamp())],
            state: self::state([
                'email_blocked' => '1', 'email_blocked_address' => '  ',
                'email_bounced' => '1', 'email_bounced_address' => 'owner@example.invalid',
            ])
        ));

        $card = $this->cardHtml($this->render());

        $this->assertSame(1, substr_count($card, 'no address recorded'), 'Only the block has no address');
        $this->assertStringContainsString('owner@example.invalid', $card);
    }

    /** The hook reads the flags through effectiveEmailFlags(), the one owner-level reader. */
    public function testHookReadsTheFlagsThroughEffectiveEmailFlagsOnly(): void
    {
        $source = (string) file_get_contents(self::HOOK);

        $this->assertStringContainsString('->effectiveEmailFlags((int) $user_id)', $source);
        $this->assertStringNotContainsString('findProfileEmailState', $source);
        $this->assertDoesNotMatchRegularExpression('/->email_(?:bounced|blocked|opted_out)\b/', $source);
    }

    /** The flags are per owner, so no car row repeats them. */
    public function testFlagsAppearOnceInTheSummaryAndNotInTheCarRows(): void
    {
        $this->useDb(new VerificationCardTestDb(
            cars: [
                self::car(id: 101, ownerLastUpdated: self::recentTimestamp()),
                self::car(id: 102, ownerLastUpdated: self::recentTimestamp()),
            ],
            state: self::state([
                'email_bounced' => '1', 'email_bounced_address' => 'x@example.invalid',
                'user_email' => 'x@example.invalid',
            ])
        ));

        $html = $this->render();

        $this->assertSame(1, substr_count($html, 'x@example.invalid'));
        $this->assertStringNotContainsString('Bounced', $this->rowForCar($html, 101));
        $this->assertStringNotContainsString('Bounced', $this->rowForCar($html, 102));
    }

    public function testOwnerWithNoProfileRowShowsTheNoProfileWarningNotAFalseAllClear(): void
    {
        $this->useDb(new VerificationCardTestDb(
            cars: [self::car(id: 101, ownerLastUpdated: self::recentTimestamp())],
            noProfileState: true
        ));

        $card = $this->cardHtml($this->render());

        $this->assertStringContainsString('This owner has no profile, so no verification emails are sent.', $card);
        $this->assertStringNotContainsString('No delivery problems recorded', $card);
    }

    public function testProfileStateQueryFailureRendersTheDegradedAlert(): void
    {
        $this->useDb(new VerificationCardTestDb(
            cars: [self::car(id: 101, ownerLastUpdated: self::recentTimestamp())],
            failProfileStateQuery: true
        ));

        $html = $this->render();

        $this->assertStringContainsString('Verification and email status could not be loaded', $html);
        $this->assertStringNotContainsString('No delivery problems recorded', $html);
        $this->assertStringNotContainsString('This owner has no profile', $html, 'A failed read is not "no profile"');
    }

    /**
     * The car-list query failed, so the card cannot tell "no cars" from
     * "could not read". The profile table says so.
     */
    public function testCarListQueryFailureSaysCarsCouldNotBeLoadedNotNoCarsRegistered(): void
    {
        $this->useDb(new VerificationCardTestDb(
            cars: [self::car(id: 101, ownerLastUpdated: self::recentTimestamp())],
            failCarListQuery: true
        ));

        $html = $this->render();

        $this->assertStringContainsString('Cars could not be loaded', $html);
        $this->assertStringNotContainsString('No cars registered', substr($html, 0, (int) strpos($html, 'Verification &amp; Email')));
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
        ?string $lastVerified = null
    ): object {
        return (object) [
            'id'                    => (string) $id,
            'model'                 => 'Elan',
            'series'                => 'S4',
            'variant'               => 'SE',
            'year'                  => '1968',
            'email'                 => 'owner@example.invalid',
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
     * @param array<string, mixed>|null $state The owner's profile row with `user_email`, for effectiveEmailFlags().
     *        Null gives a profile with every flag clear.
     */
    public function __construct(
        private array $cars = [],
        private array $events = [],
        private bool $failVerificationStateQuery = false,
        private bool $failEmailEventQuery = false,
        private bool $throwPdoExceptionOnVerificationStateQuery = false,
        private ?array $state = null,
        private bool $noProfileState = false,
        private bool $failProfileStateQuery = false,
        private bool $failCarListQuery = false
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

        // CarRepository::effectiveEmailFlags()
        if (str_contains($sql, 'FROM profiles') && str_contains($sql, 'email_blocked_cause')) {
            if ($this->failProfileStateQuery) {
                return $this->fail();
            }
            if ($this->noProfileState) {
                return $this->respond([]);
            }

            return $this->respond([(object) ($this->state ?? [
                'id' => '5', 'user_id' => (string) ($params[0] ?? 0),
                'email_opted_out' => '0', 'email_opted_out_at' => null,
                'email_blocked' => '0', 'email_blocked_address' => null,
                'email_blocked_at' => null, 'email_blocked_cause' => null,
                'email_bounced' => '0', 'email_bounced_address' => null,
                'email_bounced_at' => null, 'email_bounce_cleared_at' => null,
                'user_email' => 'owner@example.invalid',
            ])]);
        }

        // CarRepository::findByOwner()
        if (str_starts_with($sql, 'SELECT id FROM cars WHERE user_id')) {
            if ($this->failCarListQuery) {
                return $this->fail();
            }

            return $this->respond(array_map(
                static fn(object $car): object => (object) ['id' => $car->id],
                $this->cars
            ));
        }

        // CarRepository::findVerificationStateByOwner()
        if (str_contains($sql, 'ORDER BY cars.model')) {
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
