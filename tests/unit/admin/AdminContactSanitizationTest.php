<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for admin contact sanitization bugs.
 *
 * #660: CR/LF stripping on $qualityIssue before use in SMTP subject line.
 * #661: filter_var validation on target_email in the Multiple-owner path.
 *
 * The action file (process-admin-contact.php) cannot be unit-tested directly
 * (requires full framework bootstrap), so these tests validate the sanitization
 * and validation patterns in isolation.
 */
class AdminContactSanitizationTest extends TestCase
{
    /**
     * Mirrors the CR/LF-stripping pattern used in process-admin-contact.php
     * for header-bound values ($toEmail, $fromEmail, $qualityIssue).
     */
    private function stripHeaderChars(?string $value): string
    {
        return preg_replace('/[\r\n\t]/', '', (string) $value);
    }

    // -------------------------------------------------------------------------
    // #660 — CR/LF stripping on $qualityIssue
    // -------------------------------------------------------------------------

    public function testQualityIssueCarriageReturnStripped(): void
    {
        $sanitized = $this->stripHeaderChars("Missing documents\rX-Injected: evil");
        $this->assertStringNotContainsString("\r", $sanitized);
        $this->assertSame('Missing documentsX-Injected: evil', $sanitized);
    }

    public function testQualityIssueLineFeedStripped(): void
    {
        $sanitized = $this->stripHeaderChars("Missing documents\nBcc: attacker@example.com");
        $this->assertStringNotContainsString("\n", $sanitized);
        $this->assertSame('Missing documentsBcc: attacker@example.com', $sanitized);
    }

    public function testQualityIssueCombinedInjectionStripped(): void
    {
        $sanitized = $this->stripHeaderChars("Scratch\r\nBcc: attacker@example.com");
        $this->assertStringNotContainsString("\r", $sanitized);
        $this->assertStringNotContainsString("\n", $sanitized);
        $this->assertSame('ScratchBcc: attacker@example.com', $sanitized);
    }

    public function testQualityIssueTabStripped(): void
    {
        $sanitized = $this->stripHeaderChars("Missing\tdocuments");
        $this->assertStringNotContainsString("\t", $sanitized);
        $this->assertSame('Missingdocuments', $sanitized);
    }

    public function testQualityIssueCleanValueUnchanged(): void
    {
        $raw = 'Missing registration documents';
        $this->assertSame($raw, $this->stripHeaderChars($raw));
    }

    public function testQualityIssueNullHandledSafely(): void
    {
        $this->assertSame('', $this->stripHeaderChars(null));
    }

    // -------------------------------------------------------------------------
    // #661 — filter_var validation on target_email
    // -------------------------------------------------------------------------

    public function testTargetEmailValidAccepted(): void
    {
        $email = 'owner@example.com';
        $result = filter_var($email, FILTER_VALIDATE_EMAIL);
        $this->assertSame($email, $result);
    }

    public function testTargetEmailInvalidRejected(): void
    {
        $email = 'not-an-email';
        $result = filter_var($email, FILTER_VALIDATE_EMAIL);
        $this->assertFalse($result);
    }

    public function testTargetEmailInjectionAttemptRejected(): void
    {
        $email = "victim@example.com\r\nBcc: attacker@evil.com";
        $result = filter_var($email, FILTER_VALIDATE_EMAIL);
        $this->assertFalse($result);
    }

    public function testTargetEmailEmptyRejected(): void
    {
        $result = filter_var('', FILTER_VALIDATE_EMAIL);
        $this->assertFalse($result);
    }
}
