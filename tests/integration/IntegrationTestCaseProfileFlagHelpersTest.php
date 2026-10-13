<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the owner profile flag helpers of IntegrationTestCase (#2101).
 *
 * Other integration tests rely on these helpers, so they must write the
 * columns that the production code reads and must keep one profile row.
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

    public function testSetOwnerBlockedWritesAddressTimeAndCause(): void
    {
        $this->setOwnerBlocked($this->userId, 'blocked@example.com', '2026-01-02 03:04:05', 'spam');

        $flags = $this->getOwnerEmailFlags($this->userId);
        $this->assertSame('1', (string) $flags['email_blocked']);
        $this->assertSame('blocked@example.com', $flags['email_blocked_address']);
        $this->assertSame('2026-01-02 03:04:05', $flags['email_blocked_at']);
        $this->assertSame('spam', $flags['email_blocked_cause']);
    }

    public function testSetOwnerBouncedAndOptedOutWriteTheirColumns(): void
    {
        $this->setOwnerBounced($this->userId, 'bounced@example.com', '2026-02-03 04:05:06');
        $this->setOwnerOptedOut($this->userId, true, '2026-03-04 05:06:07');

        $flags = $this->getOwnerEmailFlags($this->userId);
        $this->assertSame('1', (string) $flags['email_bounced']);
        $this->assertSame('bounced@example.com', $flags['email_bounced_address']);
        $this->assertSame('2026-02-03 04:05:06', $flags['email_bounced_at']);
        $this->assertSame('1', (string) $flags['email_opted_out']);
        $this->assertSame('2026-03-04 05:06:07', $flags['email_opted_out_at']);
    }

    public function testClearOwnerEmailFlagsResetsAllThreeFlags(): void
    {
        $this->setOwnerBounced($this->userId, 'b@example.com');
        $this->setOwnerBlocked($this->userId, 'b@example.com');
        $this->setOwnerOptedOut($this->userId);

        $this->clearOwnerEmailFlags($this->userId);

        $flags = $this->getOwnerEmailFlags($this->userId);
        $this->assertSame('0', (string) $flags['email_bounced']);
        $this->assertSame('0', (string) $flags['email_blocked']);
        $this->assertSame('0', (string) $flags['email_opted_out']);
        $this->assertNull($flags['email_blocked_address']);
    }

    public function testUserHasExactlyOneProfileRow(): void
    {
        $count = (int) $this->db->query('SELECT COUNT(*) AS cnt FROM profiles WHERE user_id = ?', [$this->userId])->first()->cnt;

        $this->assertSame(1, $count);
    }
}
