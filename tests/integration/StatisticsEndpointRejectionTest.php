<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The `tab` validation of app/api/shared/statistics.php at the HTTP layer
 * (#2362). One case for each invalid input that the source pins in
 * tests/unit/api/StatisticsEndpointValidationTest.php cover.
 *
 * The endpoint is public and has no CSRF check, so each request is logged
 * out and sends no token.
 */
#[Group('integration')]
#[Group('api')]
final class StatisticsEndpointRejectionTest extends IntegrationTestCase
{
    private const ENDPOINT = 'app/api/shared/statistics.php';

    /** @var int|null Highest logs.id before the test, so tearDown() deletes only rows written after it */
    private ?int $logIdFloor = null;

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
        // The endpoint logs a logged-out request with user_id 0. Thus the
        // exact notes that this class causes select its rows.
        if ($this->databaseConnected && $this->logIdFloor !== null) {
            $this->db->query(
                'DELETE FROM logs WHERE id > ? AND user_id = 0 AND lognote IN (?, ?)',
                [$this->logIdFloor, 'Statistics API called without tab parameter', "Statistics API: Invalid tab 'bogus'"]
            );
            if ($this->db->error()) {
                fwrite(STDERR, "NOTE: tearDown() cleanup failed for statistics logs rows: {$this->db->errorString()}\n");
            }
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{array<string, string>, string, list<string>|null}>
     */
    public static function invalidTabProvider(): array
    {
        return [
            'tab missing' => [[], 'Tab parameter required', null],
            'tab empty'   => [['tab' => ''], 'Tab parameter required', null],
            'tab unknown' => [['tab' => 'bogus'], 'Invalid tab parameter', ['geographic', 'production', 'colors', 'quality']],
        ];
    }

    /**
     * @param array<string, string> $post
     * @param list<string>|null $expectedValidTabs The valid_tabs list in the response, or null when the response has none
     */
    #[DataProvider('invalidTabProvider')]
    public function testInvalidTabIsRejectedWith400(array $post, string $expectedMessage, ?array $expectedValidTabs): void
    {
        $result = $this->invokeEndpoint(self::ENDPOINT, $post, null, 'omit');

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(400, $result['httpCode'], $result['raw']);
        $this->assertIsArray($result['json'], 'Response must be valid JSON: ' . $result['raw']);
        $this->assertFalse($result['status'], $result['raw']);
        $this->assertSame($expectedMessage, $result['message']);
        $this->assertNoPhpErrorText($result['raw']);
        $this->assertSame($expectedValidTabs, $result['json']['valid_tabs'] ?? null);
    }
}
