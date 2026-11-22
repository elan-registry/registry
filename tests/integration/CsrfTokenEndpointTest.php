<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * app/api/shared/csrf-token.php gives the session CSRF token only to a
 * logged-in AJAX GET (#1455). The endpoint checks the method first, then the
 * X-Requested-With header, then the login. Each test makes one check fail
 * and keeps the earlier checks satisfied, so each refusal comes from the
 * check under test.
 */
#[Group('integration')]
final class CsrfTokenEndpointTest extends IntegrationTestCase
{
    private const ENDPOINT = 'app/api/shared/csrf-token.php';
    private const AJAX_HEADER = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
    private const UNAUTHENTICATED_NOTE = 'Unauthenticated access attempt to csrf-token.php';

    /** @var int|null Highest logs.id before the test, so tearDown() deletes only rows written after it */
    private ?int $logIdFloor = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->logIdFloor = null;

        $max = $this->db->query('SELECT COALESCE(MAX(id), 0) AS max_id FROM logs')->first();
        $this->assertFalse($this->db->error(), 'Failed to read logs MAX(id) for test setup: ' . $this->db->errorString());
        $this->logIdFloor = (int) ($max->max_id ?? 0);
    }

    protected function tearDown(): void
    {
        // A null floor means setUp() did not take the snapshot. Deleting then
        // would remove rows that other runs wrote.
        if ($this->databaseConnected && $this->logIdFloor !== null) {
            // The endpoint writes the refusal row with user_id 0, so the exact
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

    /** Count the refusal rows that this test wrote, after the setUp() floor. */
    private function countUnauthenticatedLogRows(): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM logs WHERE id > ? AND user_id = 0 AND lognote = ?',
            [$this->logIdFloor, self::UNAUTHENTICATED_NOTE]
        )->first();
        $this->assertFalse($this->db->error(), 'Failed to count unauthenticated logs rows: ' . $this->db->errorString());

        return (int) $row->cnt;
    }

    /**
     * @param array<string, string> $server
     * @return array{status: bool|null, message: string, httpCode: int|null, exitCode: int, raw: string, json: array<mixed>|null, flash: array{error: list<string>, success: list<string>, message: list<string>}}
     */
    private function request(string $method, ?int $userId, array $server): array
    {
        $result = $this->invokeEndpoint(self::ENDPOINT, [], $userId, 'omit', [], $method, $server);
        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertNoPhpErrorText($result['raw']);

        return $result;
    }

    public function testPostIsRejectedWith405(): void
    {
        $result = $this->request('POST', $this->createTestUser(), self::AJAX_HEADER);

        $this->assertSame(405, $result['httpCode'], $result['raw']);
        $this->assertFalse($result['status'], $result['raw']);
        $this->assertArrayNotHasKey('csrf', $result['json'] ?? [], $result['raw']);
    }

    public function testGetWithoutAjaxHeaderIsRejectedWith400(): void
    {
        $result = $this->request('GET', $this->createTestUser(), []);

        $this->assertSame(400, $result['httpCode'], $result['raw']);
        $this->assertFalse($result['status'], $result['raw']);
        $this->assertArrayNotHasKey('csrf', $result['json'] ?? [], $result['raw']);
    }

    public function testGetWithoutLoginIsRejectedWith401(): void
    {
        $result = $this->request('GET', null, self::AJAX_HEADER);

        $this->assertSame(401, $result['httpCode'], $result['raw']);
        $this->assertFalse($result['status'], $result['raw']);
        $this->assertSame('Login required', $result['message'], $result['raw']);
        $this->assertArrayNotHasKey('csrf', $result['json'] ?? [], $result['raw']);
        $this->assertSame(1, $this->countUnauthenticatedLogRows(), 'The refusal must write exactly one logs row');
    }

    public function testLoggedInAjaxGetReturnsToken(): void
    {
        $result = $this->request('GET', $this->createTestUser(), self::AJAX_HEADER);

        $this->assertSame(200, $result['httpCode'], $result['raw']);
        $this->assertTrue($result['status'], $result['raw']);
        $this->assertIsArray($result['json'], $result['raw']);
        $this->assertArrayHasKey('csrf', $result['json'], $result['raw']);
        $this->assertIsString($result['json']['csrf'], $result['raw']);
        $this->assertNotSame('', $result['json']['csrf'], $result['raw']);
    }
}
