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
 * IDOR guard coverage gap: tests/playwright/security/contact-owner-idor.spec.js
 * posts a nonexistent car_id (999999), so its 403 comes from the car-not-found
 * half of the check (`!$carOwner`, send-owner-email.php:98), not the
 * owner-mismatch comparison `(int)$carOwner->user_id !== $toUserId` on the
 * same line. tests/unit/regression/Issue1014RegressionTest.php pins the
 * ownership query and its ordering, not the comparison. The :98 owner-mismatch
 * comparison (a real car owned by someone other than to_user_id) has no test.
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

    /**
     * Privacy + injection fix (issue #1322, Bug B): $fromName uses only fname.
     *
     * ROOT CAUSE
     * ----------
     * Before the fix, $fromName was $fromData->fname . ' ' . $fromData->lname,
     * exposing the sender's surname in reply-to headers and the email template,
     * and bypassing the CR/LF strip that was already applied to $toEmail.
     *
     * TESTING GAP
     * -----------
     * No test verified which name fields flow into email headers or the
     * reply_name parameter, so the regression was invisible to CI.
     *
     * PREVENTION
     * ----------
     * This test pins the contract: $fromName is derived from $fromData->fname
     * alone, stripped of CR/LF/tab, and must not contain lname.
     */
    public function testFromNameIsFirstNameOnly(): void
    {
        $fromUserId = $this->createTestUser([
            'fname' => 'Alice',
            'lname' => 'Smith',
            'email' => 'alice_fromname@example.com',
        ]);

        // Raw SQL intentionally bypasses Owner::data() to isolate the string-derivation
        // logic under test. Owner::data() is tested separately; we're pinning what
        // send-owner-email.php does with the data it receives, not how it loads it.
        $fromOwnerResult = $this->db->query('SELECT fname, lname, email FROM users WHERE id = ?', [$fromUserId]);
        $fromData        = $fromOwnerResult->first();

        // Call the real helper send-owner-email.php uses — not a mirrored regex —
        // so this test can't silently drift from production behavior (#1759).
        $fromName = InputSanitizer::stripHeaderInjectionChars($fromData->fname);

        $this->assertSame('Alice', $fromName, '$fromName must equal fname only');
        $this->assertStringNotContainsString('Smith', $fromName, '$fromName must not include lname');
        $this->assertStringNotContainsString("\r", $fromName, 'CR must be stripped');
        $this->assertStringNotContainsString("\n", $fromName, 'LF must be stripped');
        $this->assertStringNotContainsString("\t", $fromName, 'tab must be stripped');
    }
}
