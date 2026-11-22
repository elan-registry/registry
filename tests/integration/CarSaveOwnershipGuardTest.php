<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The ownership guard in app/api/cars/save.php at the HTTP layer (#970, #2362).
 *
 * updateCar, fetchImages, and removeImages each refuse a user who is not the
 * car's owner and has no admin or editor permission (hasPerm([2, 3])).
 *
 * Each request sends a valid CSRF token, because the CSRF check also answers
 * 403. The car exists and has an owner, because the same `if` also refuses a
 * car that is not found. Thus only the ownership branch can give the 403.
 */
#[Group('integration')]
#[Group('security')]
final class CarSaveOwnershipGuardTest extends IntegrationTestCase
{
    private const ENDPOINT = 'app/api/cars/save.php';

    private const UNAUTHENTICATED_NOTE = 'Unauthenticated access attempt to save.php';

    /** @var int|null Highest logs.id before the test, so tearDown() deletes only rows written after it */
    private ?int $logIdFloor = null;

    /** @var int[] Users this class created, whose new logs rows tearDown() deletes */
    private array $guardUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $max = $this->db->query('SELECT COALESCE(MAX(id), 0) AS max_id FROM logs')->first();
        $this->assertFalse($this->db->error(), 'Failed to read logs MAX(id) for test setup: ' . $this->db->errorString());
        $this->assertIsObject($max, 'logs MAX(id) query returned no row');
        $this->logIdFloor = (int) $max->max_id;
    }

    protected function tearDown(): void
    {
        // A null floor means setUp() skipped or failed before the snapshot.
        // Without a floor, no delete can be limited to this test's rows.
        if ($this->databaseConnected && $this->logIdFloor !== null) {
            foreach ($this->guardUserIds as $userId) {
                $this->db->query('DELETE FROM logs WHERE id > ? AND user_id = ?', [$this->logIdFloor, $userId]);
                if ($this->db->error()) {
                    fwrite(STDERR, "NOTE: tearDown() cleanup failed for logs of user ID {$userId}: {$this->db->errorString()}\n");
                }
            }
            // The endpoint writes the 401 row with user_id 0, so the exact
            // note is the only way to find this test's row.
            $this->db->query(
                'DELETE FROM logs WHERE id > ? AND user_id = 0 AND lognote = ?',
                [$this->logIdFloor, self::UNAUTHENTICATED_NOTE]
            );
            if ($this->db->error()) {
                fwrite(STDERR, "NOTE: tearDown() cleanup failed for unauthenticated logs rows: {$this->db->errorString()}\n");
            }
        }

        parent::tearDown();
    }

    private function createGuardUser(): int
    {
        $userId = $this->createTestUser();
        $this->grantTestPermission($userId, 1); // 1 = User: no admin or editor bypass
        $this->guardUserIds[] = $userId;

        return $userId;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function guardedActionProvider(): array
    {
        return [
            'updateCar'    => ['updateCar', 'car_id'],
            'fetchImages'  => ['fetchImages', 'carID'],
            'removeImages' => ['removeImages', 'carID'],
        ];
    }

    #[DataProvider('guardedActionProvider')]
    public function testNonOwnerIsRefusedWith403AndCarIsUnchanged(string $action, string $carIdField): void
    {
        $ownerId = $this->createGuardUser();
        $nonOwnerId = $this->createGuardUser();
        $carId = $this->createTestCar($ownerId, ['color' => 'Red', 'image' => '["owner-photo.jpg"]']);
        $deniedNote = "{$action}: unauthorized for car {$carId}";

        $result = $this->invokeEndpoint(self::ENDPOINT, [
            'action'    => $action,
            $carIdField => (string) $carId,
            'color'     => 'Blue',
            'file'      => 'owner-photo.jpg',
        ], $nonOwnerId);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(403, $result['httpCode'], $result['raw']);
        $this->assertIsArray($result['json'], 'Response must be valid JSON: ' . $result['raw']);
        $this->assertFalse($result['status'], $result['raw']);
        $this->assertSame('Unauthorized', $result['message'], 'The refusal must come from the ownership guard, not the CSRF check');
        $this->assertNoPhpErrorText($result['raw']);
        $this->assertSame(
            1,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, $deniedNote, $nonOwnerId),
            'Exactly one access-denied log row must name the action and car'
        );

        $row = $this->db->query('SELECT user_id, color, image FROM cars WHERE id = ?', [$carId])->first();
        $this->assertFalse($this->db->error(), 'Failed to read the car row: ' . $this->db->errorString());
        $this->assertIsObject($row, "Car {$carId} must still exist");
        $this->assertSame($ownerId, (int) $row->user_id);
        $this->assertSame('Red', $row->color);
        $this->assertSame('["owner-photo.jpg"]', $row->image);
    }

    #[DataProvider('guardedActionProvider')]
    public function testMissingCarIsRefusedWith403(string $action, string $carIdField): void
    {
        $memberId = $this->createGuardUser();
        $max = $this->db->query('SELECT COALESCE(MAX(id), 0) AS max_id FROM cars')->first();
        $this->assertFalse($this->db->error(), 'Failed to read cars MAX(id): ' . $this->db->errorString());
        $this->assertIsObject($max, 'cars MAX(id) query returned no row');
        // The margin keeps the id free while other tests add cars at the same time.
        $missingCarId = (int) $max->max_id + 1000;

        $result = $this->invokeEndpoint(self::ENDPOINT, [
            'action'    => $action,
            $carIdField => (string) $missingCarId,
            'file'      => 'owner-photo.jpg',
        ], $memberId);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(403, $result['httpCode'], $result['raw']);
        $this->assertIsArray($result['json'], 'Response must be valid JSON: ' . $result['raw']);
        $this->assertFalse($result['status'], $result['raw']);
        $this->assertSame('Unauthorized', $result['message'], 'The refusal must come from the missing-car check, not the CSRF check');
        $this->assertNoPhpErrorText($result['raw']);
        $this->assertSame(
            1,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, "{$action}: unauthorized for car {$missingCarId}", $memberId),
            'Exactly one access-denied log row must name the action and the missing car'
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function badCsrfProvider(): array
    {
        return [
            'fetchImages invalid token'  => ['fetchImages', 'invalid'],
            'fetchImages no token'       => ['fetchImages', 'omit'],
            'removeImages invalid token' => ['removeImages', 'invalid'],
            'removeImages no token'      => ['removeImages', 'omit'],
        ];
    }

    #[DataProvider('badCsrfProvider')]
    public function testOwnerWithBadCsrfTokenIsRefusedWith403(string $action, string $csrfMode): void
    {
        // The owner passes the ownership guard, so only the CSRF check can refuse.
        $ownerId = $this->createGuardUser();
        $carId = $this->createTestCar($ownerId, ['image' => '["owner-photo.jpg"]']);

        $result = $this->invokeEndpoint(self::ENDPOINT, [
            'action' => $action,
            'carID'  => (string) $carId,
            'file'   => 'owner-photo.jpg',
        ], $ownerId, $csrfMode);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(403, $result['httpCode'], $result['raw']);
        $this->assertIsArray($result['json'], 'Response must be valid JSON: ' . $result['raw']);
        $this->assertFalse($result['status'], $result['raw']);
        $this->assertSame('Invalid CSRF token', $result['message'], $result['raw']);
        $this->assertNoPhpErrorText($result['raw']);
        $this->assertSame(
            1,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_SECURITY, 'CSRF check failed in save.php', $ownerId),
            'Exactly one security log row must record the CSRF refusal'
        );

        $row = $this->db->query('SELECT image FROM cars WHERE id = ?', [$carId])->first();
        $this->assertFalse($this->db->error(), 'Failed to read the car row: ' . $this->db->errorString());
        $this->assertIsObject($row, "Car {$carId} must still exist");
        $this->assertSame('["owner-photo.jpg"]', $row->image);
    }

    public function testLoggedOutRequestIsRefusedWith401(): void
    {
        $ownerId = $this->createGuardUser();
        $carId = $this->createTestCar($ownerId);

        $result = $this->invokeEndpoint(self::ENDPOINT, ['action' => 'fetchImages', 'carID' => (string) $carId], null);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(401, $result['httpCode'], $result['raw']);
        $this->assertIsArray($result['json'], 'Response must be valid JSON: ' . $result['raw']);
        $this->assertFalse($result['status'], $result['raw']);
        $this->assertSame('Login required', $result['message'], $result['raw']);
        $this->assertNoPhpErrorText($result['raw']);
        // user_id 0 is shared by every logged-out request, so count only the
        // rows above this test's floor. A row left by an earlier run must not
        // fail the test.
        $count = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM logs WHERE id > ? AND user_id = 0 AND logtype = ? AND lognote = ?',
            [$this->logIdFloor, LogCategories::LOG_CATEGORY_ACCESS_DENIED, self::UNAUTHENTICATED_NOTE]
        );
        $this->assertFalse($this->db->error(), 'Failed to count the access-denied log rows: ' . $this->db->errorString());
        $this->assertSame(1, (int) $count->first()->cnt, 'The refusal must write one access-denied log row');
    }

    public function testOwnerPassesTheOwnershipGuard(): void
    {
        $ownerId = $this->createGuardUser();
        $carId = $this->createTestCar($ownerId);

        $result = $this->invokeEndpoint(self::ENDPOINT, ['action' => 'fetchImages', 'carID' => (string) $carId], $ownerId);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(200, $result['httpCode'], $result['raw']);
        $this->assertIsArray($result['json'], 'Response must be valid JSON: ' . $result['raw']);
        $this->assertTrue($result['status'], $result['raw']);
        $this->assertSame('Images retrieved successfully', $result['message']);
        $this->assertNoPhpErrorText($result['raw']);
        // The success row proves that logging works, so the zero count of
        // access-denied rows below means something.
        $this->assertSame(
            1,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_CAR_ACTIONS, "Images fetched for car: {$carId}", $ownerId),
            'The owner request must reach fetchImages() and log one success row'
        );
        $this->assertSame(
            0,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, "fetchImages: unauthorized for car {$carId}", $ownerId),
            'The owner must not get an access-denied log row'
        );
    }
}
