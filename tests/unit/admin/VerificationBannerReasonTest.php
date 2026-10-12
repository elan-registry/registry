<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Source-inspection guard for the admin "Brevo is not ready" banner in
 * app/admin/index.php.
 *
 * index.php needs the full UserSpice bootstrap, so a unit test cannot render
 * it (see VerificationEligibilitySkipReasonTest for the same constraint).
 * This test proves only that the banner reads the reason from
 * brevoNotReadyReason() and escapes it where it renders.
 */
final class VerificationBannerReasonTest extends TestCase
{
    private function readIndexSource(): string
    {
        $filePath = dirname(__DIR__, 3) . '/app/admin/index.php';
        $this->assertFileExists($filePath);

        return (string) file_get_contents($filePath);
    }

    public function testBannerReadsTheReasonFromBrevoNotReadyReason(): void
    {
        $this->assertStringContainsString(
            '$verificationBrevoNotReadyReason = $verificationSettings->brevoNotReadyReason();',
            $this->readIndexSource(),
            'The banner must take its reason from brevoNotReadyReason()'
        );
    }

    public function testBannerEscapesTheReasonWhereItRenders(): void
    {
        $source = $this->readIndexSource();

        $this->assertStringContainsString(
            "htmlspecialchars((string) \$verificationBrevoNotReadyReason, ENT_QUOTES, 'UTF-8')",
            $source,
            'The banner must escape the reason with htmlspecialchars()'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<\?=(?:(?!\?>|htmlspecialchars).)*\$verificationBrevoNotReadyReason/s',
            $source,
            'The banner must never echo the reason without htmlspecialchars()'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\b(?:echo|print)\b[^;]*\$verificationBrevoNotReadyReason/',
            $source,
            'The banner must never echo the reason without htmlspecialchars()'
        );
    }
}
