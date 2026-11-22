<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Length and range checks of app/api/cars/chassis-availability.php and
 * app/api/cars/transfer-request.php (#1081, #1107). The checks keep the
 * values inside the DB column widths and the 1963-1974 year domain.
 *
 * Each over-limit row expects a 400 with the message of that check. Each
 * at-limit request must get past all length checks. Its chassis matches no
 * car, so transfer-request.php stops at "No car found" and writes no
 * transfer record and sends no email.
 *
 * transfer-request.php writes a us_rate_limits row before it validates, so
 * the test tracks the 'transfer_request' rows and tearDown() deletes them.
 * Each request uses a new user, so the per-user rate limit cannot refuse a
 * later row.
 */
#[Group('integration')]
final class CarEndpointLengthValidationTest extends IntegrationTestCase
{
    private const CHASSIS_ENDPOINT = 'app/api/cars/chassis-availability.php';
    private const TRANSFER_ENDPOINT = 'app/api/cars/transfer-request.php';
    private const TRANSFER_RATE_LIMIT_ACTION = 'transfer_request';
    private const MALFORMED_MODEL = 'not-a-valid-model-format';

    /** @var int[] User IDs whose logs rows tearDown() deletes */
    private array $loggedUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->loggedUserIds = [];
        $this->trackNewRateLimitRows(self::TRANSFER_RATE_LIMIT_ACTION);
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            foreach ($this->loggedUserIds as $userId) {
                $this->db->query('DELETE FROM logs WHERE user_id = ?', [$userId]);
                if ($this->db->error()) {
                    fwrite(STDERR, "NOTE: tearDown() cleanup failed for logs of user ID {$userId}: {$this->db->errorString()}\n");
                }
            }
        }

        parent::tearDown();
    }

    /** A 15-character chassis (the column limit) that no registered car has. */
    private static function unusedChassisAtLimit(): string
    {
        return 'Z' . strtoupper(bin2hex(random_bytes(7)));
    }

    /** @return array<string, string> chassis-availability.php fields at or under their limits */
    private static function chassisBasePayload(): array
    {
        return [
            'command' => 'chassis_check',
            'chassis' => self::unusedChassisAtLimit(),
            'year' => '1974',
            'model' => str_repeat('A', 26) . '|B|C',
        ];
    }

    /** @return array<string, string> transfer-request.php fields at their limits; the model is 30 characters with a 12-character series and a 3-character type */
    private static function transferBasePayload(): array
    {
        return [
            'chassis' => self::unusedChassisAtLimit(),
            'year' => '1963',
            'color' => str_repeat('c', 50),
            'engine' => str_repeat('9', 15),
            'comments' => str_repeat('A', 1000),
            'model' => str_repeat('S', 12) . '|' . str_repeat('V', 13) . '|FHC',
        ];
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function chassisOverLimitProvider(): array
    {
        return [
            'chassis 16 characters' => [['chassis' => str_repeat('1', 16)], 'Chassis number must be 15 characters or less'],
            'year 1962' => [['year' => '1962'], 'Year must be between 1963 and 1974'],
            'year 1975' => [['year' => '1975'], 'Year must be between 1963 and 1974'],
            'model 31 characters' => [['model' => str_repeat('A', 27) . '|B|C'], 'Model must be 30 characters or less'],
        ];
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function transferOverLimitProvider(): array
    {
        return [
            'chassis 16 characters' => [['chassis' => str_repeat('1', 16)], 'Chassis number must be 15 characters or less'],
            'year 1962' => [['year' => '1962'], 'Year must be between 1963 and 1974'],
            'year 1975' => [['year' => '1975'], 'Year must be between 1963 and 1974'],
            'color 51 characters' => [['color' => str_repeat('c', 51)], 'Color must be 50 characters or less'],
            'engine 16 characters' => [['engine' => str_repeat('9', 16)], 'Engine must be 15 characters or less'],
            'comments 1001 characters' => [['comments' => str_repeat('A', 1001)], 'Transfer explanation must be 1000 characters or less'],
            'model 31 characters' => [['model' => str_repeat('A', 27) . '|B|C'], 'Model identifier must be 30 characters or less'],
            'series 13 characters' => [['model' => str_repeat('A', 13) . '|B|C'], 'Series must be 12 characters or less'],
            'variant 16 characters' => [['model' => 'S4|' . str_repeat('B', 16) . '|C'], 'Variant must be 15 characters or less'],
            'type 4 characters' => [['model' => 'S4|SE|ABCD'], 'Type must be 3 characters or less'],
            // #1854: the user message must not echo the raw model value.
            'malformed model' => [['model' => self::MALFORMED_MODEL], 'Invalid model format. Expected series|variant|type (e.g. S4|SE|FHC).'],
        ];
    }

    /** @return array<string, array{array<string, string>}> */
    public static function transferAtLimitProvider(): array
    {
        return [
            'all fields at limit' => [[]],
            'variant 15 characters' => [['model' => 'S4|' . str_repeat('B', 15) . '|C']],
        ];
    }

    /** A logged-in user for transfer-request.php, new for each request so the per-user rate limit starts empty. */
    private function createRequester(): int
    {
        $userId = $this->createTestUser();
        $this->loggedUserIds[] = $userId;

        return $userId;
    }

    private function countTransferRequestsBy(int $userId): int
    {
        $row = $this->db->query('SELECT COUNT(*) AS cnt FROM car_transfer_requests WHERE requested_by_user_id = ?', [$userId])->first();
        $this->assertFalse($this->db->error(), 'Failed to count car_transfer_requests: ' . $this->db->errorString());
        $this->assertIsObject($row, 'The car_transfer_requests count query returned no row');

        return (int) $row->cnt;
    }

    /**
     * @param array{status: bool|null, message: string, httpCode: int|null, exitCode: int, raw: string, json: array<mixed>|null} $result
     */
    private function assertRefusedWith(array $result, string $expectedMessage): void
    {
        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(400, $result['httpCode'], $result['raw']);
        $this->assertFalse($result['status'], $result['raw']);
        $this->assertSame($expectedMessage, $result['message'], $result['raw']);
        $this->assertNoPhpErrorText($result['raw']);
    }

    /**
     * @param array<string, string> $overrides
     */
    #[DataProvider('chassisOverLimitProvider')]
    public function testChassisAvailabilityRejectsOverLimitValue(array $overrides, string $expectedMessage): void
    {
        $result = $this->invokeEndpoint(self::CHASSIS_ENDPOINT, array_merge(self::chassisBasePayload(), $overrides), null);

        $this->assertRefusedWith($result, $expectedMessage);
    }

    public function testChassisAvailabilityAcceptsAtLimitValues(): void
    {
        $result = $this->invokeEndpoint(self::CHASSIS_ENDPOINT, self::chassisBasePayload(), null);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(200, $result['httpCode'], $result['raw']);
        $this->assertTrue($result['status'], $result['raw']);
        $this->assertSame('Chassis number is available', $result['message'], $result['raw']);
        $this->assertNoPhpErrorText($result['raw']);
    }

    /**
     * @param array<string, string> $overrides
     */
    #[DataProvider('transferOverLimitProvider')]
    public function testTransferRequestRejectsOverLimitValue(array $overrides, string $expectedMessage): void
    {
        $userId = $this->createRequester();

        $result = $this->invokeEndpoint(self::TRANSFER_ENDPOINT, array_merge(self::transferBasePayload(), $overrides), $userId);

        $this->assertRefusedWith($result, $expectedMessage);
        $this->assertStringNotContainsString(self::MALFORMED_MODEL, $result['message']);
        $this->assertSame(0, $this->countTransferRequestsBy($userId), 'A refused request must not create a transfer record');
    }

    /**
     * @param array<string, string> $overrides
     */
    #[DataProvider('transferAtLimitProvider')]
    public function testTransferRequestAtLimitValuesPassLengthChecksAndStopAtCarLookup(array $overrides): void
    {
        $userId = $this->createRequester();

        $result = $this->invokeEndpoint(self::TRANSFER_ENDPOINT, array_merge(self::transferBasePayload(), $overrides), $userId);

        // "No car found" comes after every length check, so no length error fired.
        $this->assertRefusedWith($result, 'No car found with this chassis number');
        $this->assertSame(0, $this->countTransferRequestsBy($userId), 'An unmatched chassis must not create a transfer record');
    }
}
