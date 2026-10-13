<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PhpBuiltinServer;

/**
 * #1881: real-process tests for app/verify/verify_car.php under `php -S`,
 * driven by curl, so real superglobals, headers, and status codes are used.
 */
#[Group('integration')]
final class VerifyCarLandingPageTest extends IntegrationTestCase
{
    /** @var list<string> */
    private const DB_ENV_VARS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    // Composer URL <-> landing-page contract: one marker per confirm view.
    private const MARKER_VERIFY = 'Yes, this is still accurate';
    private const MARKER_SOLD = 'When was the car sold?';
    private const MARKER_OPTOUT = 'Stop verification emails';
    private const CONFIRM_MARKERS = [self::MARKER_VERIFY, self::MARKER_SOLD, self::MARKER_OPTOUT];
    private const MARKER_LANDING = 'registry entry still correct?';

    private static ?PhpBuiltinServer $server = null;
    private static string $projectRoot = '';

    private int $testUserId = 0;
    private int $testCarId = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$projectRoot = dirname(__DIR__, 2);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->exposeTestDatabaseToEnvironment();
        self::$server ??= PhpBuiltinServer::start(self::$projectRoot, self::routerBody());

        // $withProfile: the opt-out records profiles.email_opted_out on the
        // owner (#1883, #2101), so the fixture needs the profiles row every
        // real owner has.
        $this->testUserId = $this->createTestUser([], true);
        $this->testCarId = $this->createTestCar($this->testUserId, [
            'chassis' => 'VF' . uniqid(),
            'year' => 1968,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            // Clear rate-limit rows this test may have seeded/generated for the
            // verification_code_attempt action so runs stay independent.
            $this->db->query("DELETE FROM us_rate_limits WHERE action = 'verification_code_attempt'");
        }

        foreach (self::DB_ENV_VARS as $var) {
            putenv($var);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Server lifecycle (Tests\Support\PhpBuiltinServer owns the process)
    // ------------------------------------------------------------------

    private static function routerBody(): string
    {
        $projectRoot = self::$projectRoot;

        return <<<PHP
        require '{$projectRoot}/vendor/autoload.php';
        \\Dotenv\\Dotenv::createMutable('{$projectRoot}', '.env.test.local')->load();
        chdir('{$projectRoot}/app/verify');
        require '{$projectRoot}/app/verify/verify_car.php';
        PHP;
    }

    private function exposeTestDatabaseToEnvironment(): void
    {
        foreach (self::DB_ENV_VARS as $var) {
            $value = $_ENV[$var] ?? getenv($var);
            if ($value !== false && $value !== '') {
                putenv("$var=$value");
            }
        }
    }

    // ------------------------------------------------------------------
    // HTTP helpers
    // ------------------------------------------------------------------

    /**
     * @return array{status: int, body: string, location: ?string}
     */
    private function get(string $queryString): array
    {
        return $this->request('GET', $queryString, null);
    }

    /**
     * @param array<string, string> $fields
     * @return array{status: int, body: string, location: ?string}
     */
    private function post(string $queryString, array $fields): array
    {
        return $this->request('POST', $queryString, http_build_query($fields));
    }

    /**
     * @return array{status: int, body: string, location: ?string}
     */
    private function request(string $method, string $queryString, ?string $body): array
    {
        $url = self::$server->url('/app/verify/verify_car.php');
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body ?? '';
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("curl request to verify_car.php failed: {$error}");
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr((string) $response, 0, $headerSize);
        $responseBody = substr((string) $response, $headerSize);

        $location = null;
        if (preg_match('/^Location:\s*(.+)$/mi', $rawHeaders, $matches) === 1) {
            $location = trim($matches[1]);
        }

        return ['status' => $status, 'body' => $responseBody, 'location' => $location];
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /** Returns the plaintext vericode; it is stored hashed. */
    private function issueVericode(?string $sentAt = null): string
    {
        $plaintext = bin2hex(random_bytes(16));

        $this->db->query(
            'UPDATE cars SET vericode = ?, vericode_sent_at = ? WHERE id = ?',
            [hashVericode($plaintext), $sentAt ?? date('Y-m-d H:i:s', strtotime('-10 days')), $this->testCarId]
        );
        $this->assertFalse($this->db->error(), 'Test setup: failed to seed vericode fixture');

        return $plaintext;
    }

    /**
     * @return array<string, mixed>
     */
    private function carRow(): array
    {
        $row = $this->db->query('SELECT * FROM cars WHERE id = ?', [$this->testCarId])->first();
        $this->assertNotNull($row, 'Expected to find the test car row');
        return (array) $row;
    }

    /**
     * Pins the full query string of a PRG redirect, not only one parameter,
     * so a changed parameter order or separator fails the test. A valid
     * vericode is hex, which encodes to itself, so this cannot detect a
     * lost encoding.
     */
    private function assertRedirectsToSelfWithQuery(string $expectedQuery, ?string $location): void
    {
        $this->assertNotNull($location);
        $this->assertStringEndsWith('app/verify/verify_car.php?' . $expectedQuery, (string) $location);
    }

    private function historyCount(string $operation): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ? AND operation = ?',
            [$this->testCarId, $operation]
        )->first();
        return (int) $row->cnt;
    }

    /** The owner-level opt-out flag (#1883, #2101). */
    private function profileOptedOut(int $ownerId): int
    {
        return (int) $this->getOwnerEmailFlags($ownerId)['email_opted_out'];
    }

    /**
     * @return list<array{date: string, flag: string, old: int, new: int, source: string, acting_user_id: int}>
     */
    private function flagChanges(int $ownerId): array
    {
        return (new \ElanRegistry\Car\CarRepository($this->db))->recentFlagChanges($ownerId, 100);
    }

    /** Email operations on cars_hist. The opt-out writes none any more (#2101). */
    private function emailHistoryRows(int $carId): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM cars_hist
              WHERE car_id = ? AND (operation LIKE '%SUPPRESS%' OR operation LIKE '%BOUNCE%')",
            [$carId]
        )->first()->cnt;
    }

    // ------------------------------------------------------------------
    // GET never mutates
    // ------------------------------------------------------------------

    public function testGetActionVerifyDoesNotMutateCarRow(): void
    {
        $code = $this->issueVericode();
        $before = $this->carRow();

        $result = $this->get('vericode=' . $code . '&action=verify');

        $this->assertSame(200, $result['status']);

        $after = $this->carRow();
        $this->assertSame($before['last_verified'], $after['last_verified'], 'GET must not set last_verified');
        $this->assertSame($before['owner_last_updated'], $after['owner_last_updated'], 'GET must not touch owner_last_updated');
        $this->assertSame($before['mtime'], $after['mtime'], 'GET must not touch mtime (proves no UPDATE was issued at all)');
        $this->assertSame(0, $this->historyCount('VERIFIED'), 'GET must not write a VERIFIED cars_hist row');
    }

    public function testGetActionSoldDoesNotMutateCarRow(): void
    {
        $code = $this->issueVericode();
        $before = $this->carRow();

        $result = $this->get('vericode=' . $code . '&action=sold');

        $this->assertSame(200, $result['status']);

        $after = $this->carRow();
        $this->assertSame($before['solddate'], $after['solddate'], 'GET must not set solddate');
        $this->assertSame($before['owner_last_updated'], $after['owner_last_updated'], 'GET must not touch owner_last_updated');
        $this->assertSame($before['mtime'], $after['mtime'], 'GET must not touch mtime (proves no UPDATE was issued at all)');
        $this->assertSame(0, $this->historyCount('VERIFIED SOLD'), 'GET must not write a VERIFIED SOLD cars_hist row');
    }

    public function testGetWithNoActionLandingPageDoesNotMutateCarRow(): void
    {
        $code = $this->issueVericode();
        $before = $this->carRow();

        $result = $this->get('vericode=' . $code);

        $this->assertSame(200, $result['status']);

        $after = $this->carRow();
        $this->assertSame($before, $after, 'Plain landing-page GET must leave every column unchanged');
    }

    // ------------------------------------------------------------------
    // POST action=verify
    // ------------------------------------------------------------------

    public function testPostActionVerifySetsLastVerifiedAndOwnerLastUpdatedAndInsertsHistory(): void
    {
        $code = $this->issueVericode();

        $historyBefore = $this->historyCount('VERIFIED');

        $result = $this->post('vericode=' . $code . '&action=verify', ['vericode' => $code]);

        $this->assertSame(303, $result['status'], 'POST must respond with a PRG redirect');
        $this->assertRedirectsToSelfWithQuery('vericode=' . $code . '&action=verify', $result['location']);

        $after = $this->carRow();
        $this->assertNotNull($after['last_verified'], 'last_verified must be set');
        $this->assertNotNull($after['owner_last_updated'], 'owner_last_updated must be set');

        $this->assertSame(
            $historyBefore + 1,
            $this->historyCount('VERIFIED'),
            'Exactly one VERIFIED cars_hist row must be inserted'
        );
    }

    // ------------------------------------------------------------------
    // POST action=sold
    // ------------------------------------------------------------------

    public function testPostActionSoldWithValidPastDateSetsSubmittedDateNotToday(): void
    {
        $code = $this->issueVericode();
        // Deliberately not today, to prove the submitted date (not date())
        // is what gets written.
        $submittedDate = date('Y-m-d', strtotime('-30 days'));
        $this->assertNotSame(date('Y-m-d'), $submittedDate, 'Test sanity: fixture date must differ from today');

        $historyBefore = $this->historyCount('VERIFIED SOLD');

        $result = $this->post('vericode=' . $code . '&action=sold', [
            'vericode' => $code,
            'solddate' => $submittedDate,
        ]);

        $this->assertSame(303, $result['status']);
        $this->assertRedirectsToSelfWithQuery('vericode=' . $code . '&action=sold', $result['location']);

        $after = $this->carRow();
        $this->assertSame($submittedDate, (string) $after['solddate'], 'solddate must equal the submitted date, not today');
        $this->assertNotNull($after['owner_last_updated']);

        $this->assertSame(
            $historyBefore + 1,
            $this->historyCount('VERIFIED SOLD'),
            'Exactly one VERIFIED SOLD cars_hist row must be inserted'
        );

        $histRow = $this->db->query(
            'SELECT solddate FROM cars_hist WHERE car_id = ? AND operation = ? ORDER BY id DESC LIMIT 1',
            [$this->testCarId, 'VERIFIED SOLD']
        )->first();
        $this->assertSame($submittedDate, (string) $histRow->solddate, 'cars_hist VERIFIED SOLD row must carry the submitted date');
    }

    public function testPostActionSoldWithFutureDateIsRejectedWithNoWrite(): void
    {
        $code = $this->issueVericode();
        $before = $this->carRow();
        $futureDate = date('Y-m-d', strtotime('+5 days'));

        $result = $this->post('vericode=' . $code . '&action=sold', [
            'vericode' => $code,
            'solddate' => $futureDate,
        ]);

        $this->assertSame(200, $result['status'], 'Rejected submission re-renders the form, no redirect');
        $this->assertStringContainsString('can&#039;t be in the future', $result['body']);

        $after = $this->carRow();
        $this->assertSame($before['solddate'], $after['solddate'], 'Future date must not be written');
        $this->assertSame($before['owner_last_updated'], $after['owner_last_updated']);
        $this->assertSame(0, $this->historyCount('VERIFIED SOLD'));
    }

    public function testPostActionSoldWithPreBuildYearDateIsRejectedWithNoWrite(): void
    {
        // Fixture car has year=1968 (set in setUp()), so Jan 1 1968 is the floor.
        $code = $this->issueVericode();
        $before = $this->carRow();
        $tooEarly = '1967-12-31';

        $result = $this->post('vericode=' . $code . '&action=sold', [
            'vericode' => $code,
            'solddate' => $tooEarly,
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('can&#039;t be before the car was built', $result['body']);

        $after = $this->carRow();
        $this->assertSame($before['solddate'], $after['solddate']);
        $this->assertSame($before['owner_last_updated'], $after['owner_last_updated']);
        $this->assertSame(0, $this->historyCount('VERIFIED SOLD'));
    }

    /** A garbage solddate must give an inline validation error, not a write or a fatal. */
    public function testPostActionSoldWithNonsenseDateStringIsRejectedWithNoWrite(): void
    {
        $code = $this->issueVericode();
        $before = $this->carRow();

        $result = $this->post('vericode=' . $code . '&action=sold', [
            'vericode' => $code,
            'solddate' => 'not-a-date',
        ]);

        $this->assertSame(200, $result['status'], 'Malformed date must not crash the page');
        $this->assertStringContainsString('valid date', $result['body']);

        $after = $this->carRow();
        $this->assertSame($before['solddate'], $after['solddate']);
        $this->assertSame(0, $this->historyCount('VERIFIED SOLD'));
    }

    public function testPostActionSoldWithImpossibleCalendarDateIsRejectedWithNoWrite(): void
    {
        $code = $this->issueVericode();
        $before = $this->carRow();

        $result = $this->post('vericode=' . $code . '&action=sold', [
            'vericode' => $code,
            'solddate' => '2026-13-45',
        ]);

        $this->assertSame(200, $result['status'], 'Impossible calendar date must not crash the page');
        $this->assertStringContainsString('valid date', $result['body']);

        $after = $this->carRow();
        $this->assertSame($before['solddate'], $after['solddate']);
        $this->assertSame(0, $this->historyCount('VERIFIED SOLD'));
    }

    // ------------------------------------------------------------------
    // Already-sold idempotency
    // ------------------------------------------------------------------

    public function testAlreadySoldCarRendersIdempotentStateOnGetNoActionAndActionSold(): void
    {
        $code = $this->issueVericode();
        $this->db->query('UPDATE cars SET solddate = ? WHERE id = ?', ['2020-01-01', $this->testCarId]);

        $noAction = $this->get('vericode=' . $code);
        $this->assertSame(200, $noAction['status']);
        // Landing page disables the Sold button for an already-sold car.
        $this->assertStringContainsString('Already recorded as sold', $noAction['body']);

        $withAction = $this->get('vericode=' . $code . '&action=sold');
        $this->assertSame(200, $withAction['status']);
        $this->assertStringContainsString('already recorded as sold', $withAction['body']);
    }

    public function testAlreadySoldCarPostActionSoldIsStrictNoOp(): void
    {
        $code = $this->issueVericode();
        $originalSoldDate = '2020-01-01';
        $this->db->query('UPDATE cars SET solddate = ? WHERE id = ?', [$originalSoldDate, $this->testCarId]);
        $before = $this->carRow();
        $historyBefore = $this->historyCount('VERIFIED SOLD');

        $result = $this->post('vericode=' . $code . '&action=sold', [
            'vericode' => $code,
            'solddate' => date('Y-m-d', strtotime('-2 days')),
        ]);

        $this->assertSame(200, $result['status'], 'Already-sold POST renders the notice directly, no redirect');

        $after = $this->carRow();
        $this->assertSame($before['solddate'], $after['solddate'], 'solddate must not change on a repeat POST');
        $this->assertSame($before['owner_last_updated'], $after['owner_last_updated'], 'owner_last_updated must not change');
        $this->assertSame(
            $historyBefore,
            $this->historyCount('VERIFIED SOLD'),
            'No second VERIFIED SOLD cars_hist row on a repeat POST'
        );
    }

    // ------------------------------------------------------------------
    // VERIFIED SOLD vs ordinary edit-path UPDATE distinguishability
    // ------------------------------------------------------------------

    /**
     * The cars_update trigger also writes an 'UPDATE' row for each verify
     * POST. A query on `operation` must still find only the app's own row.
     */
    public function testVerifiedSoldOperationIsDistinguishableFromOrdinaryUpdateOperation(): void
    {
        $code = $this->issueVericode();
        $soldDate = date('Y-m-d', strtotime('-15 days'));

        $updateCountBefore = $this->historyCount('UPDATE');
        $verifiedSoldCountBefore = $this->historyCount('VERIFIED SOLD');

        $result = $this->post('vericode=' . $code . '&action=sold', [
            'vericode' => $code,
            'solddate' => $soldDate,
        ]);
        $this->assertSame(303, $result['status']);

        $this->assertGreaterThan(
            $updateCountBefore,
            $this->historyCount('UPDATE'),
            'The cars_update trigger must still fire a generic UPDATE row for this same write'
        );

        $this->assertSame($verifiedSoldCountBefore + 1, $this->historyCount('VERIFIED SOLD'));

        $verifiedSoldRow = $this->db->query(
            "SELECT operation FROM cars_hist WHERE car_id = ? AND operation = 'VERIFIED SOLD' ORDER BY id DESC LIMIT 1",
            [$this->testCarId]
        )->first();
        $this->assertSame('VERIFIED SOLD', $verifiedSoldRow->operation);
        $this->assertNotSame('UPDATE', $verifiedSoldRow->operation);
    }

    // ------------------------------------------------------------------
    // 60-day expiry boundary
    // ------------------------------------------------------------------

    public function testVericodeAtFiftyNineDaysIsStillValid(): void
    {
        $code = $this->issueVericode(date('Y-m-d H:i:s', strtotime('-59 days')));

        $result = $this->get('vericode=' . $code);

        $this->assertSame(200, $result['status']);
        $this->assertStringNotContainsString('expired or is no longer valid', $result['body']);
    }

    public function testVericodeAtSixtyOneDaysIsExpired(): void
    {
        $code = $this->issueVericode(date('Y-m-d H:i:s', strtotime('-61 days')));

        $result = $this->get('vericode=' . $code);

        $this->assertSame(404, $result['status']);
        $this->assertStringContainsString('expired or is no longer valid', $result['body']);
    }

    // ------------------------------------------------------------------
    // Vericode format rejection (before any DB query)
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedVericodeProvider(): array
    {
        return [
            'too short' => ['abc123'],
            'too long' => [str_repeat('a', 33)],
            'uppercase hex' => [str_repeat('A', 32)],
            'non-hex characters' => [str_repeat('z', 32)],
            'empty' => [''],
        ] + self::urlReservedVericodeProvider();
    }

    /**
     * 32 characters each after Input::raw() trims them, so only the
     * non-hex characters fail the format check. The format check must
     * reject them, because the views only HTML-escape the vericode.
     *
     * @return array<string, array{0: string}>
     */
    public static function urlReservedVericodeProvider(): array
    {
        return [
            'ampersand' => [str_repeat('a', 31) . '&'],
            'encoded ampersand' => [str_repeat('a', 29) . '%26'],
            'hash' => [str_repeat('a', 31) . '#'],
            'question mark' => [str_repeat('a', 31) . '?'],
            'plus' => [str_repeat('a', 31) . '+'],
            'space' => [str_repeat('a', 15) . ' ' . str_repeat('a', 16)],
            'CR LF' => [str_repeat('a', 15) . "\r\n" . str_repeat('a', 15)],
            'equals' => [str_repeat('a', 31) . '='],
        ];
    }

    #[DataProvider('malformedVericodeProvider')]
    public function testMalformedVericodeIsRejectedBeforeAnyDbQuery(string $badCode): void
    {
        $this->assertSame(0, $this->verificationAttemptRowCount(), 'Rate-limit rows leaked from an earlier run');

        $result = $this->get('vericode=' . urlencode($badCode));

        $this->assertSame(404, $result['status']);
        $this->assertStringContainsString('expired or is no longer valid', $result['body']);
        // A lookup miss gives the same 404 but records a rate-limit row.
        $this->assertSame(0, $this->verificationAttemptRowCount(), 'The format check must reject the code before the rate-limit step');
    }

    #[DataProvider('urlReservedVericodeProvider')]
    public function testPostWithUrlReservedVericodeIsRejectedWithNoRedirectAndNoWrite(string $badCode): void
    {
        $this->issueVericode();
        $before = $this->carRow();
        $this->assertSame(0, $this->verificationAttemptRowCount(), 'Rate-limit rows leaked from an earlier run');

        $result = $this->post(
            'vericode=' . rawurlencode($badCode) . '&action=verify',
            ['vericode' => $badCode]
        );

        $this->assertSame(404, $result['status']);
        $this->assertNull($result['location'], 'A rejected vericode must not reach any redirect');
        $this->assertStringContainsString('expired or is no longer valid', $result['body']);
        $this->assertSame($before, $this->carRow(), 'A rejected vericode must not change the car row');

        // A lookup miss also gives this 404, but it records a rate-limit row.
        // No row proves that the format check rejected the code first.
        // The count is not keyed by token: RateLimit sanitizes the value
        // before it hashes it, so a hash of $badCode would not match for '&'.
        $this->assertSame(0, $this->verificationAttemptRowCount(), 'The format check must reject the code before the rate-limit step');
    }

    private function verificationAttemptRowCount(): int
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM us_rate_limits WHERE action = 'verification_code_attempt'"
        )->first();
        // DB swallows a failed query, and a missing row would read as 0.
        $this->assertFalse($this->db->error(), 'Rate-limit row count query failed');
        return (int) $row->cnt;
    }

    // ------------------------------------------------------------------
    // Mismatched user id in POST body on action=optout (adversarial)
    // ------------------------------------------------------------------

    /**
     * #1883 AC10: the owner to suppress comes from the vericode lookup only.
     * Owner B's id posted under plausible field names must not be read.
     */
    public function testPostWithMismatchedUserIdInBodyOptsOutOnlyTheVericodesOwnOwner(): void
    {
        // Owner A: the fixture from setUp() ($this->testUserId / $this->testCarId).
        $code = $this->issueVericode();

        // Owner B: unrelated to the posted vericode.
        $otherUserId = $this->createTestUser([], true);
        $otherCarId = $this->createTestCar($otherUserId, ['chassis' => 'VF' . uniqid()]);
        $otherBefore = $this->getOwnerEmailFlags($otherUserId);

        $this->assertSame(0, $this->profileOptedOut($this->testUserId), 'Test sanity: owner A must start opted in');
        $this->assertSame(0, $this->profileOptedOut($otherUserId), 'Test sanity: owner B must start opted in');

        // Forged request: these fields are not in the real form.
        $result = $this->post('vericode=' . $code . '&action=optout', [
            'vericode' => $code,
            'user_id' => (string) $otherUserId,
            'owner_id' => (string) $otherUserId,
            'car_id' => (string) $otherCarId,
        ]);

        $this->assertSame(303, $result['status'], 'POST must respond with a PRG redirect');
        $this->assertRedirectsToSelfWithQuery('vericode=' . $code . '&action=optout', $result['location']);

        $this->assertSame(
            1,
            $this->profileOptedOut($this->testUserId),
            "The vericode's own owner (owner A) must be opted out"
        );
        $this->assertSame(
            $otherBefore,
            $this->getOwnerEmailFlags($otherUserId),
            'The owner id named in the POST body (owner B) must NOT change — this is the critical assertion'
        );
        $this->assertCount(1, $this->flagChanges($this->testUserId), 'Exactly one log row for owner A');
        $this->assertSame([], $this->flagChanges($otherUserId), 'Owner B gets no log row');

        $this->deleteTestCar($otherCarId);
        $this->deleteTestUser($otherUserId);
    }

    // ------------------------------------------------------------------
    // Mismatched car id in hidden POST field (adversarial)
    // ------------------------------------------------------------------

    public function testPostWithMismatchedCarIdInHiddenFieldMutatesOnlyTheVericodesOwnCar(): void
    {
        $otherUserId = $this->createTestUser();
        $otherCarId = $this->createTestCar($otherUserId, ['chassis' => 'VF' . uniqid()]);

        $code = $this->issueVericode();
        $otherCarBefore = $this->db->query('SELECT * FROM cars WHERE id = ?', [$otherCarId])->first();

        // Forged request: verify_car.php resolves the car only from the vericode.
        $result = $this->post('vericode=' . $code . '&action=verify', [
            'vericode' => $code,
            'car_id' => (string) $otherCarId,
        ]);

        $this->assertSame(303, $result['status']);

        $mutatedCar = $this->carRow();
        $this->assertNotNull($mutatedCar['last_verified'], "The vericode's own car (testCarId) must be verified");

        $otherCarAfter = $this->db->query('SELECT * FROM cars WHERE id = ?', [$otherCarId])->first();
        $this->assertNull($otherCarAfter->last_verified, 'The car named in the hidden field must NOT be mutated');
        $this->assertEquals(
            $otherCarBefore->owner_last_updated,
            $otherCarAfter->owner_last_updated,
            'The car named in the hidden field must be completely untouched'
        );

        $this->db->query('DELETE FROM cars_hist WHERE car_id = ?', [$otherCarId]);
        $this->deleteTestCar($otherCarId);
    }

    // ------------------------------------------------------------------
    // Cancel link
    // ------------------------------------------------------------------

    public function testCancelGetPerformsNoMutationAndVericodeRemainsUsableAfterward(): void
    {
        $code = $this->issueVericode();

        // Cancel is a plain GET back to the landing URL.
        $this->get('vericode=' . $code . '&action=verify');
        $cancelResult = $this->get('vericode=' . $code);

        $this->assertSame(200, $cancelResult['status']);

        $afterCancel = $this->carRow();
        $this->assertNull($afterCancel['last_verified'], 'Cancel must not have verified the car');

        // The vericode must still work: issue a real action against it now.
        $verifyResult = $this->post('vericode=' . $code . '&action=verify', ['vericode' => $code]);
        $this->assertSame(303, $verifyResult['status'], 'The vericode must remain usable after a Cancel GET');

        $afterVerify = $this->carRow();
        $this->assertNotNull($afterVerify['last_verified'], 'The still-usable vericode must actually verify the car');
    }

    // ------------------------------------------------------------------
    // Ownership defense-in-depth
    // ------------------------------------------------------------------

    public function testOwnerlessCarWithValidUnexpiredVericodeRendersInvalidLink(): void
    {
        $code = $this->issueVericode();

        // Deleted-user shape: cars.user_id has no FK, so point it at a missing user.
        $nonexistentUserId = 999999999;
        $this->db->query('UPDATE cars SET user_id = ? WHERE id = ?', [$nonexistentUserId, $this->testCarId]);

        $result = $this->get('vericode=' . $code);

        $this->assertSame(404, $result['status']);
        $this->assertStringContainsString('expired or is no longer valid', $result['body']);

        // No enumeration signal: identical body to a wholly-unknown vericode.
        $unknownResult = $this->get('vericode=' . bin2hex(random_bytes(16)));
        $this->assertSame($result['body'], $unknownResult['body'], 'Ownerless-car and unknown-code responses must be identical (no enumeration signal)');
    }

    public function testCarReassignedToNoownerWithValidUnexpiredVericodeRendersInvalidLink(): void
    {
        $noownerUserId = $this->createTestUser(['username' => 'noowner_' . uniqid()]);
        // The 'noowner' GDPR placeholder that findVerificationEligible() excludes.
        $this->db->query('UPDATE users SET username = ? WHERE id = ?', ['noowner', $noownerUserId]);

        $code = $this->issueVericode();
        $this->db->query('UPDATE cars SET user_id = ? WHERE id = ?', [$noownerUserId, $this->testCarId]);

        $result = $this->get('vericode=' . $code);

        $this->assertSame(404, $result['status']);
        $this->assertStringContainsString('expired or is no longer valid', $result['body']);

        // Restore so tearDown() cleanup does not depend on the rename.
        $this->db->query('UPDATE users SET username = ? WHERE id = ?', ['restored_' . uniqid(), $noownerUserId]);
    }

    // ------------------------------------------------------------------
    // Rate limiting
    // ------------------------------------------------------------------

    /**
     * RateLimitConfigTest proves only that the config exists. This proves the
     * page records rows under the token identifier, so the limit can trip.
     */
    public function testGetRequestRecordsRateLimitAttemptForTokenIdentifier(): void
    {
        $code = $this->issueVericode();
        $identifierKey = hash('sha256', 'token::' . $code);

        $countRows = fn (): int => (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM us_rate_limits WHERE action = 'verification_code_attempt' AND identifier_key = ?",
            [$identifierKey]
        )->first()->cnt;

        $before = $countRows();

        $result = $this->get('vericode=' . $code);
        $this->assertSame(200, $result['status']);

        $this->assertSame(
            $before + 1,
            $countRows(),
            "A GET request must record exactly one row against action='verification_code_attempt' "
                . "scoped to this vericode's token identifier — without this, checkRateLimit() sees zero "
                . 'attempts and the per-token brute-force limit can never trip'
        );
    }

    /**
     * token_max/ip_max count only success=0 rows. A rejected vericode must be
     * recorded as a failure, or the limits never trip.
     */
    public function testUnknownVericodeRecordsFailedRateLimitAttempt(): void
    {
        $code = bin2hex(random_bytes(16));
        $identifierKey = hash('sha256', 'token::' . $code);

        $result = $this->get('vericode=' . $code);
        $this->assertSame(404, $result['status']);

        $row = $this->db->query(
            "SELECT success FROM us_rate_limits WHERE action = 'verification_code_attempt' "
                . 'AND identifier_key = ? ORDER BY id DESC LIMIT 1',
            [$identifierKey]
        )->first();

        $this->assertNotNull($row, 'An unresolvable vericode must still record a rate-limit attempt row');
        $this->assertSame(
            0,
            (int) $row->success,
            'An unresolvable vericode must record success=0 — RateLimit::check() counts only success=0 rows '
                . 'toward token_max/ip_max, so recording anything else makes those limits permanently inert'
        );
    }

    /**
     * A throttled (429) request must render the same body as any other
     * rejection, so a prober cannot tell throttled from wrong.
     *
     * The php -S process applies the 100x dev relaxation itself, so the test
     * seeds a fixed row count above both thresholds.
     */
    public function testThrottledRequestRendersIdenticalBodyToInvalidLink(): void
    {
        $code = $this->issueVericode();
        $identifierKey = hash('sha256', 'token::' . $code);

        // 1100 failed rows exceed both thresholds (10 and dev-relaxed 1000).
        for ($i = 0; $i < 1100; $i++) {
            $this->db->insert('us_rate_limits', [
                'action' => 'verification_code_attempt',
                'identifier_key' => $identifierKey,
                'success' => 0,
                'attempt_time' => date('Y-m-d H:i:s'),
            ]);
        }

        $result = $this->get('vericode=' . $code);
        $this->assertSame(429, $result['status'], 'A request well past token_max must be throttled');

        $unknownResult = $this->get('vericode=' . bin2hex(random_bytes(16)));
        $this->assertSame(
            $unknownResult['body'],
            $result['body'],
            'A throttled (429) response and an unknown-vericode (404) response must render identical bodies — '
                . 'only the transport-level status may differ, per verify_car.php\'s no-enumeration-signal contract'
        );
    }

    // ------------------------------------------------------------------
    // Dispatch edge cases
    // ------------------------------------------------------------------

    public function testUnknownPostActionRedirectsToLandingPageWithoutMutating(): void
    {
        $code = $this->issueVericode();

        $result = $this->post('vericode=' . $code . '&action=bogus', ['vericode' => $code]);

        $this->assertSame(303, $result['status']);
        $this->assertRedirectsToSelfWithQuery('vericode=' . $code, $result['location']);

        $afterRow = $this->carRow();
        $this->assertNull($afterRow['last_verified'], 'An unrecognized POST action must not verify the car');
        $this->assertNull($afterRow['solddate'], 'An unrecognized POST action must not mark the car sold');
    }

    /**
     * Unlike sold, a repeat verify is deliberately not guarded: re-attesting
     * is harmless.
     */
    public function testRepeatVerifyPostReVerifiesAndInsertsAnotherHistoryRow(): void
    {
        $code = $this->issueVericode();

        $first = $this->post('vericode=' . $code . '&action=verify', ['vericode' => $code]);
        $this->assertSame(303, $first['status']);

        $countHistory = fn (): int => (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ? AND operation = 'VERIFIED'",
            [$this->testCarId]
        )->first()->cnt;

        $afterFirst = $countHistory();
        $this->assertGreaterThanOrEqual(1, $afterFirst);

        $second = $this->post('vericode=' . $code . '&action=verify', ['vericode' => $code]);
        $this->assertSame(303, $second['status'], 'A repeat verify POST must still succeed, not error');

        $this->assertSame(
            $afterFirst + 1,
            $countHistory(),
            'A repeat verify POST currently re-runs markVerified() and inserts another VERIFIED history row — '
                . 'this test pins that as intended behavior rather than leaving it silently unverified'
        );
    }

    // ------------------------------------------------------------------
    // safelyCaptureDest() wiring
    // ------------------------------------------------------------------

    public function testLoggedOutLandingPageReviewLinkPointsAtLoginNotEditPage(): void
    {
        $code = $this->issueVericode();

        $result = $this->get('vericode=' . $code);

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString(
            'usersc/login.php',
            $result['body'],
            'The logged-out landing page\'s "Review & Update" link must point at login, per safelyCaptureDest() wiring'
        );
        $this->assertStringNotContainsString(
            'app/owner/cars/edit.php',
            $result['body'],
            'A logged-out visitor must never be linked straight to the edit page — they cannot reach it without signing in first'
        );
    }

    public function testLandingPageShowsSecondPhotoWhenTheFirstListedFileIsMissing(): void
    {
        // decodeAndProcessImages() drops entries whose base file is missing and
        // re-indexes with array_values(), so element 0 is the first photo on
        // disk. This pins that rule, which the verification email's
        // primaryPhoto() copies.
        $dir = self::$projectRoot . '/userimages/' . $this->testCarId;
        // userimages/ is shared with the dev database. A folder that is already
        // there can hold a real car's photos, so the test must not touch it.
        if (is_dir($dir)) {
            $this->markTestSkipped("{$dir} already exists. Remove it if a stopped test run left it.");
        }
        $this->db->query(
            'UPDATE cars SET image = ? WHERE id = ?',
            [json_encode(['first-missing.jpg', 'second-present.jpg']), $this->testCarId]
        );
        $this->assertFalse($this->db->error(), 'Test setup: failed to seed cars.image');
        $code = $this->issueVericode();
        $files = [$dir . '/second-present.jpg', $dir . '/second-present-resized-300.jpg'];

        try {
            mkdir($dir, 0775, true);
            foreach ($files as $file) {
                file_put_contents($file, '');
            }

            $result = $this->get('vericode=' . $code);
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString(
            'userimages/' . $this->testCarId . '/second-present-resized-300.jpg',
            $result['body']
        );
        $this->assertStringNotContainsString('first-missing', $result['body']);
    }

    // ------------------------------------------------------------------
    // action=optout (#1883 — one-click verification-email opt-out)
    // ------------------------------------------------------------------

    public function testGetActionOptoutNeverMutatesTheOwnerProfile(): void
    {
        $code = $this->issueVericode();
        $before = $this->carRow();
        $flagsBefore = $this->getOwnerEmailFlags($this->testUserId);
        $this->assertSame(0, (int) $flagsBefore['email_opted_out'], 'Test sanity: owner must start opted in');

        $result = $this->get('vericode=' . $code . '&action=optout');

        $this->assertSame(200, $result['status']);

        $after = $this->carRow();
        $this->assertSame($flagsBefore, $this->getOwnerEmailFlags($this->testUserId), 'GET must not change any owner flag');
        $this->assertSame($before['mtime'], $after['mtime'], 'GET must not touch mtime (proves no UPDATE was issued at all)');
        $this->assertSame([], $this->flagChanges($this->testUserId), 'GET must not write a flag-change log row');
    }

    public function testPostActionOptoutSetsOneOwnerProfileRowAndWritesNothingToAnyCar(): void
    {
        $code = $this->issueVericode();
        $secondCarId = $this->createTestCar($this->testUserId, ['chassis' => 'VF' . uniqid()]);
        $firstCarBefore = $this->carRow();
        $secondCarBefore = (array) $this->db->query('SELECT * FROM cars WHERE id = ?', [$secondCarId])->first();

        $result = $this->post('vericode=' . $code . '&action=optout', ['vericode' => $code]);

        $this->assertSame(303, $result['status'], 'POST must respond with a PRG redirect');
        $this->assertRedirectsToSelfWithQuery('vericode=' . $code . '&action=optout', $result['location']);

        $flags = $this->getOwnerEmailFlags($this->testUserId);
        $this->assertSame('1', (string) $flags['email_opted_out'], 'The owner-level email_opted_out flag must be set');
        $this->assertNotNull($flags['email_opted_out_at']);
        $this->assertSame($firstCarBefore, $this->carRow(), 'The vericode\'s own car row must not change');
        $this->assertSame(
            $secondCarBefore,
            (array) $this->db->query('SELECT * FROM cars WHERE id = ?', [$secondCarId])->first(),
            'The owner\'s other car row must not change'
        );
        $this->assertSame(0, $this->emailHistoryRows($this->testCarId), 'No email cars_hist row for the first car');
        $this->assertSame(0, $this->emailHistoryRows($secondCarId), 'No email cars_hist row for the second car');

        $changes = $this->flagChanges($this->testUserId);
        $this->assertCount(1, $changes, 'One flag change writes exactly one log row');
        $this->assertSame('email_opted_out', $changes[0]['flag']);
        $this->assertSame(0, $changes[0]['old']);
        $this->assertSame(1, $changes[0]['new']);
        $this->assertSame('owner', $changes[0]['source']);
    }

    public function testRepeatPostActionOptoutIsIdempotentWithNoSecondLogRow(): void
    {
        $code = $this->issueVericode();

        $first = $this->post('vericode=' . $code . '&action=optout', ['vericode' => $code]);
        $this->assertSame(303, $first['status']);
        $this->assertSame(1, $this->profileOptedOut($this->testUserId));
        $this->assertCount(1, $this->flagChanges($this->testUserId));
        $firstAt = $this->getOwnerEmailFlags($this->testUserId)['email_opted_out_at'];

        $second = $this->post('vericode=' . $code . '&action=optout', ['vericode' => $code]);

        $this->assertSame(303, $second['status'], 'A repeat opt-out POST must still succeed (idempotent), not error');
        $this->assertSame(1, $this->profileOptedOut($this->testUserId), 'email_opted_out must remain 1, not toggle');
        $this->assertSame(
            $firstAt,
            $this->getOwnerEmailFlags($this->testUserId)['email_opted_out_at'],
            'A repeat opt-out keeps the first time'
        );
        $this->assertCount(
            1,
            $this->flagChanges($this->testUserId),
            'A repeat opt-out POST must not write a second flag-change log row'
        );
    }

    /**
     * The owner's own opt-out is set also when email_blocked is set,
     * so it outlives a later clear of the block.
     */
    public function testPostActionOptoutByBlockedOwnerSetsTheOptOutAndKeepsTheBlock(): void
    {
        $this->setOwnerBlocked($this->testUserId, 'blocked-optout@example.com', '2026-02-03 04:05:06', 'spam');
        $code = $this->issueVericode();

        $result = $this->post('vericode=' . $code . '&action=optout', ['vericode' => $code]);

        $this->assertSame(303, $result['status']);
        $flags = $this->getOwnerEmailFlags($this->testUserId);
        $this->assertSame('1', (string) $flags['email_opted_out'], 'The opt-out must be set although the owner is blocked');
        $this->assertSame('1', (string) $flags['email_blocked']);
        $this->assertSame('2026-02-03 04:05:06', $flags['email_blocked_at']);

        // The block is cleared later through the profile writer. The opt-out stays.
        $this->assertSame(1, (new \ElanRegistry\Car\CarRepository($this->db))->clearProfileBlocked($this->testUserId));
        $this->assertSame('0', (string) $this->getOwnerEmailFlags($this->testUserId)['email_blocked']);
        $this->assertSame(1, $this->profileOptedOut($this->testUserId), 'The opt-out outlives the block');
    }

    /**
     * The opt-out page reads email_opted_out only. A visitor sees the
     * same confirm page whether or not the owner has email_blocked or
     * email_bounced.
     */
    public function testGetActionOptoutPageIsTheSameForBlockedOnlyAndBouncedOnlyOwnersAsForACleanOwner(): void
    {
        $cleanCode = $this->issueVericode();
        $clean = $this->get('vericode=' . $cleanCode . '&action=optout');
        $this->assertSame(200, $clean['status']);
        $this->assertStringContainsString('1 registered car<', $clean['body'], 'Test sanity: the confirm page rendered');

        foreach (['blocked', 'bounced'] as $state) {
            $this->clearOwnerEmailFlags($this->testUserId);
            if ($state === 'blocked') {
                $this->setOwnerBlocked($this->testUserId, 'parity@example.com');
            } else {
                $this->setOwnerBounced($this->testUserId, 'parity@example.com');
            }
            $code = $this->issueVericode();

            $page = $this->get('vericode=' . $code . '&action=optout');

            $this->assertSame(200, $page['status']);
            $this->assertSame(
                str_replace($cleanCode, 'CODE', $clean['body']),
                str_replace($code, 'CODE', $page['body']),
                "An owner with only email_{$state} must see the same opt-out page as a clean owner"
            );
        }
    }

    public function testGetActionOptoutAfterOptOutRendersAlreadyUnsubscribedState(): void
    {
        $code = $this->issueVericode();
        $post = $this->post('vericode=' . $code . '&action=optout', ['vericode' => $code]);
        $this->assertSame(303, $post['status']);

        $result = $this->get('vericode=' . $code . '&action=optout');

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString(
            'unsubscribed',
            strtolower($result['body']),
            'Revisiting after the opt-out must render the already-unsubscribed success state'
        );
        $this->assertStringContainsString(
            'usersc/user_settings.php#resume-emails">Account Settings</a>',
            $result['body'],
            'The success card must point the owner to Resume in Account Settings first'
        );
        $this->assertStringContainsString('mailto:registrar@elanregistry.org', $result['body'], 'The email address stays as the fallback');
    }

    /**
     * Opt-out covers every car of the owner, so the confirm card must state
     * the real car count, not a singular fallback.
     */
    public function testGetActionOptoutWithMultipleCarsStatesPluralCarCount(): void
    {
        $code = $this->issueVericode();
        $this->createTestCar($this->testUserId, ['chassis' => 'VF' . uniqid()]);

        $result = $this->get('vericode=' . $code . '&action=optout');

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString(
            '2 registered cars',
            $result['body'],
            'A two-car owner\'s opt-out confirmation must state the real plural car count, not a singular or generic fallback'
        );
    }

    /** Singular copy for a one-car owner. */
    public function testGetActionOptoutWithOneCarStatesSingularCarCount(): void
    {
        $code = $this->issueVericode();

        $result = $this->get('vericode=' . $code . '&action=optout');

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString(
            '1 registered car<',
            $result['body'],
            'A one-car owner\'s opt-out confirmation must state the singular car count, not the plural form'
        );
        $this->assertStringContainsString(
            'its history',
            $result['body'],
            'A one-car owner\'s opt-out confirmation must use the singular "its history" pronoun'
        );
    }

    /**
     * Failure injection: a second connection holds LOCK TABLES profiles WRITE,
     * so the opt-out write on the profile row fails.
     * lock_wait_timeout (not innodb_lock_wait_timeout) is set to 1s globally so
     * the php -S request's new connection does not wait for the 1-year default.
     * The page must answer with renderActionFailed(), not renderInvalidLink().
     */
    public function testOptoutWriteFailureReturns500AndLeavesTheOwnerOptedIn(): void
    {
        $code = $this->issueVericode();
        $beforeCar = $this->carRow();
        $flagsBefore = $this->getOwnerEmailFlags($this->testUserId);

        $lockConn = new PDO(
            $this->buildDsn(),
            $_ENV['DB_USER'] ?? getenv('DB_USER'),
            $_ENV['DB_PASS'] ?? getenv('DB_PASS')
        );

        // Restore the real value, not the documented default.
        $originalTimeout = (string) $this->db->query(
            "SHOW VARIABLES LIKE 'lock_wait_timeout'"
        )->first()->Value;

        // Affects only new connections; restored in finally.
        $this->db->query('SET GLOBAL lock_wait_timeout = 1');

        try {
            $lockConn->exec('LOCK TABLES profiles WRITE');

            $result = $this->post('vericode=' . $code . '&action=optout', ['vericode' => $code]);

            $this->assertSame(
                500,
                $result['status'],
                'A blocked profiles write must surface as renderActionFailed(500)'
            );
            // renderActionFailed() must say the write failed, unlike renderInvalidLink().
            $this->assertStringContainsString(
                'Something went wrong on our end',
                $result['body'],
                'A post-authentication write failure must render renderActionFailed()\'s honest-failure copy'
            );
            $this->assertStringNotContainsString(
                'Nothing is wrong with your car',
                $result['body'],
                'A post-authentication write failure must never reuse renderInvalidLink()\'s "nothing is wrong" copy'
            );
            $this->assertStringContainsString(
                'may still receive verification emails',
                $result['body'],
                'The opt-out-specific failure copy must warn the owner their opt-out did not take effect'
            );
        } finally {
            // Unlock first: a held profiles lock would fail every later test that touches a profile.
            $lockConn->exec('UNLOCK TABLES');
            $lockConn = null;
            $this->db->query('SET GLOBAL lock_wait_timeout = ' . (int) $originalTimeout);
        }

        $this->assertSame(
            $flagsBefore,
            $this->getOwnerEmailFlags($this->testUserId),
            'The owner must remain opted in after the failed write'
        );
        $this->assertSame($beforeCar, $this->carRow(), 'The car row must be untouched');
        $this->assertSame([], $this->flagChanges($this->testUserId), 'No flag-change log row may survive a failed write');
    }

    // ------------------------------------------------------------------
    // Composer URL <-> landing-page parser contract
    // ------------------------------------------------------------------

    /**
     * Each case names the composer method that builds the URL and the one view
     * marker that URL must produce. _verify_landing.php links to action=verify
     * and action=sold, so the markers come from the confirm views instead.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function composerUrlProvider(): array
    {
        return [
            'verify' => ['verifyUrl', self::MARKER_VERIFY],
            'sold' => ['soldUrl', self::MARKER_SOLD],
            'optout' => ['optOutUrl', self::MARKER_OPTOUT],
        ];
    }

    /**
     * The URL the email composer builds must reach the matching view on the
     * landing page. A rename of an action string on either side breaks it.
     */
    #[DataProvider('composerUrlProvider')]
    public function testComposerUrlRendersMatchingConfirmView(string $urlMethod, string $expectedMarker): void
    {
        $code = $this->issueVericode();
        $composer = new \ElanRegistry\Car\CarVerificationEmailComposer();

        $url = $composer->{$urlMethod}($code);
        $this->assertStringEndsWith(
            '/app/verify/verify_car.php',
            (string) parse_url($url, PHP_URL_PATH),
            "Composer {$urlMethod}() must point at the landing page"
        );
        $query = parse_url($url, PHP_URL_QUERY);
        $this->assertIsString($query, "Composer {$urlMethod}() must return a URL with a query string");

        $result = $this->get($query);

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString($expectedMarker, $result['body'], "{$urlMethod}() URL must render its own view");
        foreach (array_diff(self::CONFIRM_MARKERS, [$expectedMarker]) as $marker) {
            $this->assertStringNotContainsString($marker, $result['body'], "{$urlMethod}() URL must not render another view ({$marker})");
        }
    }

    /**
     * Control for the marker test: the markers must belong to the confirm views
     * only, so the plain landing page must show none of them.
     */
    public function testLandingPageWithNoActionRendersNoConfirmView(): void
    {
        $code = $this->issueVericode();

        $result = $this->get('vericode=' . $code);

        $this->assertSame(200, $result['status']);
        // Without this, an error page would also pass the absence checks below.
        $this->assertStringContainsString(self::MARKER_LANDING, $result['body'], 'Plain vericode URL must render the landing view');
        foreach (self::CONFIRM_MARKERS as $marker) {
            $this->assertStringNotContainsString($marker, $result['body'], "Plain landing page must not render a confirm view ({$marker})");
        }
    }

    /**
     * Journey: a car the nightly batch would email, opened with the composer's
     * own opt-out link, must drop out of findVerificationEligible() because its
     * owner's profile is opted out.
     */
    public function testOptOutLinkFromComposerRemovesCarFromEligibleSet(): void
    {
        $stale = date('Y-m-d H:i:s', strtotime('-2 years'));
        // vericode_sent_at NULL: a car emailed within 60 days is on cooldown and
        // would be excluded for that reason, not because of the opt-out.
        $this->db->query(
            'UPDATE cars SET email = ?, last_verified = NULL, vericode_sent_at = NULL WHERE id = ?',
            ['optout-' . uniqid() . '@example.test', $this->testCarId]
        );
        $this->assertFalse($this->db->error(), 'Test setup: failed to make the fixture car eligible');
        $this->seedOwnerLastUpdated($this->testCarId, $stale);

        $this->assertContains($this->testCarId, $this->allVerificationEligibleCarIds(), 'Test setup: fixture car must start in the eligible set');

        $code = $this->issueVericode();
        $query = parse_url((new \ElanRegistry\Car\CarVerificationEmailComposer())->optOutUrl($code), PHP_URL_QUERY);
        $this->assertIsString($query);

        $result = $this->post($query, ['vericode' => $code]);

        $this->assertSame(303, $result['status'], 'POST of the composer opt-out link must redirect');
        // An unknown action also redirects 303, so pin the cause to the opt-out write.
        $this->assertSame(1, $this->profileOptedOut($this->testUserId), 'Opt-out must set profiles.email_opted_out');

        // Clear the send timestamp again so only the opt-out can explain exclusion.
        $this->db->query('UPDATE cars SET vericode_sent_at = NULL WHERE id = ?', [$this->testCarId]);
        $this->assertFalse($this->db->error(), 'Test setup: failed to clear vericode_sent_at after the opt-out');
        $this->assertNotContains($this->testCarId, $this->allVerificationEligibleCarIds(), 'Opted-out car must leave the eligible set');
    }

    /**
     * DSN for the second, lock-holding connection; $this->db must stay free
     * for assertions while the lock is held.
     */
    private function buildDsn(): string
    {
        $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST'));
        $port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306;
        $name = (string) ($_ENV['DB_NAME'] ?? getenv('DB_NAME'));

        // DB_HOST may already carry ':port' (.env.test.local); prefer it.
        if (str_contains($host, ':')) {
            [$host, $port] = explode(':', $host, 2);
        }

        return "mysql:host={$host};port={$port};dbname={$name}";
    }
}
