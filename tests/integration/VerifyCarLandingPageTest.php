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

        // $withProfile: the #1883 opt-out records profiles.email_suppressed on
        // the owner, so the fixture needs the profiles row every real owner has.
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

    private function historyCount(string $operation): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ? AND operation = ?',
            [$this->testCarId, $operation]
        )->first();
        return (int) $row->cnt;
    }

    private function historyCountForCar(int $carId, string $operation): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ? AND operation = ?',
            [$carId, $operation]
        )->first();
        return (int) $row->cnt;
    }

    private function emailSuppressed(int $carId): int
    {
        $row = $this->db->query('SELECT email_suppressed FROM cars WHERE id = ?', [$carId])->first();
        $this->assertNotNull($row, "Test setup: car {$carId} disappeared");
        return (int) $row->email_suppressed;
    }

    /** The owner-level opt-out flag (#1883), counterpart to emailSuppressed(). */
    private function profileEmailSuppressed(int $ownerId): int
    {
        $row = $this->db->query('SELECT email_suppressed FROM profiles WHERE user_id = ?', [$ownerId])->first();
        $this->assertNotNull($row, "Test setup: owner {$ownerId} has no profiles row");
        return (int) $row->email_suppressed;
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
        $this->assertNotNull($result['location']);
        $this->assertStringContainsString('action=verify', (string) $result['location']);

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
        $this->assertNotNull($result['location']);
        $this->assertStringContainsString('action=sold', (string) $result['location']);

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
        ];
    }

    #[DataProvider('malformedVericodeProvider')]
    public function testMalformedVericodeIsRejectedBeforeAnyDbQuery(string $badCode): void
    {
        $result = $this->get('vericode=' . urlencode($badCode));

        $this->assertSame(404, $result['status']);
        $this->assertStringContainsString('expired or is no longer valid', $result['body']);
    }

    // ------------------------------------------------------------------
    // Mismatched user id in POST body on action=optout (adversarial)
    // ------------------------------------------------------------------

    /**
     * #1883 AC10: the owner to suppress comes from the vericode lookup only.
     * Owner B's id posted under plausible field names must not be read.
     */
    public function testPostWithMismatchedUserIdInBodySuppressesOnlyTheVericodesOwnOwner(): void
    {
        // Owner A: the fixture from setUp() ($this->testUserId / $this->testCarId).
        $code = $this->issueVericode();

        // Owner B: unrelated to the posted vericode.
        $otherUserId = $this->createTestUser();
        $otherCarId = $this->createTestCar($otherUserId, ['chassis' => 'VF' . uniqid()]);

        $this->assertSame(0, $this->emailSuppressed($this->testCarId), 'Test sanity: owner A car must start unsuppressed');
        $this->assertSame(0, $this->emailSuppressed($otherCarId), 'Test sanity: owner B car must start unsuppressed');

        $historyBeforeA = $this->historyCount('EMAIL SUPPRESSED');
        $historyBeforeB = $this->historyCountForCar($otherCarId, 'EMAIL SUPPRESSED');

        // Forged request: these fields are not in the real form.
        $result = $this->post('vericode=' . $code . '&action=optout', [
            'vericode' => $code,
            'user_id' => (string) $otherUserId,
            'owner_id' => (string) $otherUserId,
            'car_id' => (string) $otherCarId,
        ]);

        $this->assertSame(303, $result['status'], 'POST must respond with a PRG redirect');
        $this->assertNotNull($result['location']);
        $this->assertStringContainsString('action=optout', (string) $result['location']);

        $this->assertSame(
            1,
            $this->emailSuppressed($this->testCarId),
            "The vericode's own owner (owner A) must be suppressed"
        );
        $this->assertSame(
            0,
            $this->emailSuppressed($otherCarId),
            'The owner id named in the POST body (owner B) must NOT be suppressed — this is the critical assertion'
        );

        $this->assertSame(
            $historyBeforeA + 1,
            $this->historyCount('EMAIL SUPPRESSED'),
            'Exactly one EMAIL SUPPRESSED cars_hist row must be inserted for owner A\'s car'
        );
        $this->assertSame(
            $historyBeforeB,
            $this->historyCountForCar($otherCarId, 'EMAIL SUPPRESSED'),
            'Owner B\'s car must get zero EMAIL SUPPRESSED cars_hist rows'
        );

        $this->db->query('DELETE FROM cars_hist WHERE car_id = ?', [$otherCarId]);
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
        $this->assertNotNull($result['location']);
        $this->assertStringNotContainsString('action=', (string) $result['location']);

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

    public function testGetActionOptoutNeverMutatesEmailSuppressed(): void
    {
        $code = $this->issueVericode();
        $before = $this->carRow();
        $this->assertSame(0, (int) $before['email_suppressed'], 'Test sanity: fixture car must start unsuppressed');

        $result = $this->get('vericode=' . $code . '&action=optout');

        $this->assertSame(200, $result['status']);

        $after = $this->carRow();
        $this->assertSame(0, (int) $after['email_suppressed'], 'GET must not set email_suppressed');
        $this->assertSame(
            0,
            $this->profileEmailSuppressed($this->testUserId),
            'GET must not set the owner-level flag either (#1883)'
        );
        $this->assertSame($before['mtime'], $after['mtime'], 'GET must not touch mtime (proves no UPDATE was issued at all)');
        $this->assertSame(0, $this->historyCount('EMAIL SUPPRESSED'), 'GET must not write an EMAIL SUPPRESSED cars_hist row');
    }

    public function testPostActionOptoutSuppressesEveryOwnerCarAndInsertsOneHistoryRowEach(): void
    {
        $code = $this->issueVericode();
        $secondCarId = $this->createTestCar($this->testUserId, [
            'chassis' => 'VF' . uniqid(),
            'email_suppressed' => 0,
        ]);

        $historyBeforeCar1 = $this->historyCount('EMAIL SUPPRESSED');
        $historyBeforeCar2 = $this->historyCountForCar($secondCarId, 'EMAIL SUPPRESSED');

        $result = $this->post('vericode=' . $code . '&action=optout', ['vericode' => $code]);

        $this->assertSame(303, $result['status'], 'POST must respond with a PRG redirect');
        $this->assertNotNull($result['location']);
        $this->assertStringContainsString('action=optout', (string) $result['location']);

        $this->assertSame(1, $this->emailSuppressed($this->testCarId), 'The vericode\'s own car must be suppressed');
        $this->assertSame(1, $this->emailSuppressed($secondCarId), 'The owner\'s other car must also be suppressed');
        $this->assertSame(
            1,
            $this->profileEmailSuppressed($this->testUserId),
            'The owner-level profiles.email_suppressed flag must also be set (#1883)'
        );

        $this->assertSame(
            $historyBeforeCar1 + 1,
            $this->historyCount('EMAIL SUPPRESSED'),
            'Exactly one EMAIL SUPPRESSED cars_hist row must be inserted for the first car'
        );
        $this->assertSame(
            $historyBeforeCar2 + 1,
            $this->historyCountForCar($secondCarId, 'EMAIL SUPPRESSED'),
            'Exactly one EMAIL SUPPRESSED cars_hist row must be inserted for the second car'
        );
    }

    public function testRepeatPostActionOptoutIsIdempotentWithNoDuplicateHistoryRows(): void
    {
        $code = $this->issueVericode();

        $first = $this->post('vericode=' . $code . '&action=optout', ['vericode' => $code]);
        $this->assertSame(303, $first['status']);
        $this->assertSame(1, $this->emailSuppressed($this->testCarId));

        $historyAfterFirst = $this->historyCount('EMAIL SUPPRESSED');
        $this->assertSame(1, $historyAfterFirst);

        $second = $this->post('vericode=' . $code . '&action=optout', ['vericode' => $code]);

        $this->assertSame(303, $second['status'], 'A repeat opt-out POST must still succeed (idempotent), not error');
        $this->assertSame(1, $this->emailSuppressed($this->testCarId), 'email_suppressed must remain 1, not toggle');
        $this->assertSame(
            1,
            $this->profileEmailSuppressed($this->testUserId),
            'The owner-level flag must also remain 1 across a repeat opt-out (#1883)'
        );
        $this->assertSame(
            $historyAfterFirst,
            $this->historyCount('EMAIL SUPPRESSED'),
            'A repeat opt-out POST must not insert a second EMAIL SUPPRESSED cars_hist row'
        );
    }

    public function testGetActionOptoutAfterSuppressionRendersAlreadyUnsubscribedState(): void
    {
        $code = $this->issueVericode();
        $post = $this->post('vericode=' . $code . '&action=optout', ['vericode' => $code]);
        $this->assertSame(303, $post['status']);

        $result = $this->get('vericode=' . $code . '&action=optout');

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString(
            'unsubscribed',
            strtolower($result['body']),
            'Revisiting after suppression must render the already-unsubscribed success state'
        );
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
     * Failure injection: a second connection holds LOCK TABLES cars_hist WRITE,
     * so the cars_update trigger, and with it the optout UPDATE, fails.
     * lock_wait_timeout (not innodb_lock_wait_timeout) is set to 1s globally so
     * the php -S request's new connection does not wait for the 1-year default.
     * The page must answer with renderActionFailed(), not renderInvalidLink().
     */
    public function testOptoutMidTransactionFailureReturns500AndRollsBackAllCarsAndHistoryRows(): void
    {
        $code = $this->issueVericode();
        $secondCarId = $this->createTestCar($this->testUserId, [
            'chassis' => 'VF' . uniqid(),
            'email_suppressed' => 0,
        ]);

        $beforeCar1 = $this->carRow();
        $beforeCar2Suppressed = $this->emailSuppressed($secondCarId);
        $historyBeforeCar1 = $this->historyCount('EMAIL SUPPRESSED');
        $historyBeforeCar2 = $this->historyCountForCar($secondCarId, 'EMAIL SUPPRESSED');

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
            $lockConn->exec('LOCK TABLES cars_hist WRITE');

            $result = $this->post('vericode=' . $code . '&action=optout', ['vericode' => $code]);

            $this->assertSame(
                500,
                $result['status'],
                'A blocked cars_hist write mid-transaction must surface as renderActionFailed(500)'
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
            // Unlock first: a held cars_hist lock would fail every later test that writes a car.
            $lockConn->exec('UNLOCK TABLES');
            $lockConn = null;
            $this->db->query('SET GLOBAL lock_wait_timeout = ' . (int) $originalTimeout);
        }

        $afterCar1 = $this->carRow();
        $this->assertSame(
            $beforeCar1['email_suppressed'],
            $afterCar1['email_suppressed'],
            'The vericode\'s own car must remain unsuppressed after a rolled-back transaction'
        );
        $this->assertSame(
            $beforeCar1['mtime'],
            $afterCar1['mtime'],
            'A rolled-back UPDATE must leave mtime untouched'
        );
        $this->assertSame(
            $beforeCar2Suppressed,
            $this->emailSuppressed($secondCarId),
            'The owner\'s other car must also remain unsuppressed — the whole fan-out rolls back, not just the first car'
        );

        $this->assertSame(
            $historyBeforeCar1,
            $this->historyCount('EMAIL SUPPRESSED'),
            'No EMAIL SUPPRESSED cars_hist row may survive for the first car'
        );
        $this->assertSame(
            $historyBeforeCar2,
            $this->historyCountForCar($secondCarId, 'EMAIL SUPPRESSED'),
            'No EMAIL SUPPRESSED cars_hist row may survive for the second car'
        );
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
     * own opt-out link, must drop out of findVerificationEligible().
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
        $this->assertSame(1, $this->emailSuppressed($this->testCarId), 'Opt-out must set cars.email_suppressed');
        $this->assertSame(1, $this->profileEmailSuppressed($this->testUserId), 'Opt-out must set profiles.email_suppressed');

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
