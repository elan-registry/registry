<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the profile setup of IntegrationTestCase (#2101).
 *
 * Other integration tests rely on createTestUser(), so it must write the
 * profile columns that the production code reads and keep one profile row.
 */
#[Group('integration')]
class IntegrationTestCaseProfileFlagHelpersTest extends IntegrationTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->userId = $this->createTestUser(['email' => 'flag-helper@example.com'], true);
    }

    public function testCreateTestUserWithProfileSetsAddressSinceAndSeen(): void
    {
        $flags = $this->getOwnerEmailFlags($this->userId);

        $this->assertSame('flag-helper@example.com', $flags['email_address_seen']);
        $this->assertNotNull($flags['email_address_since']);
        $this->assertSame('0', (string) $flags['email_opted_out']);
        $this->assertSame('0', (string) $flags['email_blocked']);
        $this->assertSame('0', (string) $flags['email_bounced']);
    }

    public function testUserHasExactlyOneProfileRow(): void
    {
        $count = (int) $this->db->query('SELECT COUNT(*) AS cnt FROM profiles WHERE user_id = ?', [$this->userId])->first()->cnt;

        $this->assertSame(1, $count);
    }
}
