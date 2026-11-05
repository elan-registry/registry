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
 * brevoNotReadyReason() and escapes it where it renders, and that an
 * unreadable switch or a failed check shows the unreadable banner.
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

    public function testUnreadableSwitchOrFailedCheckShowsTheUnreadableBanner(): void
    {
        $source = $this->readIndexSource();

        $this->assertMatchesRegularExpression(
            '/if \(\$verificationEnabled === null\) \{[^}]*\$verificationBannerSeverity = \'danger\';\s*\$verificationBannerUnreadable = true;/s',
            $source,
            'A null from isEnabledOrNull() must set the unreadable state'
        );
        $this->assertStringContainsString(
            '$verificationEnabled = $verificationSettings->isEnabledOrNull();',
            $source,
            'The banner must read the switch with isEnabledOrNull()'
        );
        $this->assertMatchesRegularExpression(
            '/\} catch \(\\\\Throwable \$e\) \{[^}]*\$verificationBannerSeverity = \'danger\';\s*\$verificationBannerUnreadable = true;[^}]*Verification banner check failed/s',
            $source,
            'A failed check must set the unreadable state'
        );
        $this->assertMatchesRegularExpression(
            '/<\?php if \(\$verificationBannerUnreadable\): \?>\s*<strong>The verification switch or its readiness could not be read\.<\/strong>/',
            $source,
            'The template must print the unreadable text when the state is set'
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
