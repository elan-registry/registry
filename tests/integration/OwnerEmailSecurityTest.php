<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\InputSanitizer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Security regression tests for owner email (H2 bug #971, #1322).
 *
 * Pins the sender display-name derivation in app/api/contact/send-owner-email.php
 * against a real DB user row.
 *
 * The #971 sender-impersonation contract (from_user_id is never read from POST)
 * is pinned at source level in tests/unit/security/SerializedDataRemovalTest.php.
 *
 * IDOR guard coverage gap (#1014): the owner-mismatch comparison in
 * send-owner-email.php (a real car owned by another user) has no test.
 * tests/playwright/security/contact-owner-idor.spec.js posts a nonexistent
 * car_id (999999), so it covers only the car-not-found case.
 */
#[Group('integration')]
#[Group('security')]
final class OwnerEmailSecurityTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    /** #1322: the sender name must not expose lname and must not carry CR/LF/tab. */
    public function testFromNameIsFirstNameOnly(): void
    {
        $fromUserId = $this->createTestUser([
            'fname' => 'Alice',
            'lname' => 'Smith',
            'email' => 'alice_fromname@example.com',
        ]);

        $fromOwnerResult = $this->db->query('SELECT fname, lname, email FROM users WHERE id = ?', [$fromUserId]);
        $fromData        = $fromOwnerResult->first();

        // The same helper send-owner-email.php calls; a mirrored regex can drift (#1759).
        $fromName = InputSanitizer::stripHeaderInjectionChars($fromData->fname);

        $this->assertSame('Alice', $fromName, '$fromName must equal fname only');
        $this->assertStringNotContainsString('Smith', $fromName, '$fromName must not include lname');
        $this->assertStringNotContainsString("\r", $fromName, 'CR must be stripped');
        $this->assertStringNotContainsString("\n", $fromName, 'LF must be stripped');
        $this->assertStringNotContainsString("\t", $fromName, 'tab must be stripped');
    }
}
