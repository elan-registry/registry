<?php

declare(strict_types=1);

use ElanRegistry\OwnerView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * OwnerView class tests
 *
 * Tests static utility methods for owner display rendering.
 * Focus: HTML structure, escaping/XSS safety, quality score thresholds.
 */
#[Group('fast')]
final class OwnerViewTest extends TestCase
{
    // ============================================================
    // displayName() TESTS
    // ============================================================

    public function testDisplayName_BothNames_ReturnsFullName(): void
    {
        $owner = (object)['fname' => 'First', 'lname' => 'Last'];

        $this->assertStringContainsString('First Last', OwnerView::displayName($owner));
    }

    public function testDisplayName_OnlyFname_ReturnsFname(): void
    {
        $owner = (object)['fname' => 'First', 'lname' => null];

        $this->assertStringContainsString('First', OwnerView::displayName($owner));
    }

    public function testDisplayName_BothNull_ReturnsEmptyString(): void
    {
        $owner = (object)['fname' => null, 'lname' => null];

        $this->assertSame('', OwnerView::displayName($owner));
    }

    public function testDisplayName_BothEmptyString_ReturnsEmptyString(): void
    {
        $owner = (object)['fname' => '', 'lname' => ''];

        $this->assertSame('', OwnerView::displayName($owner));
    }

    public function testDisplayName_XssInFname_IsEscaped(): void
    {
        $owner = (object)['fname' => '<script>alert(1)</script>', 'lname' => 'Last'];

        $html = OwnerView::displayName($owner);

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testDisplayName_ApostropheInName_IsEscaped(): void
    {
        $owner = (object)['fname' => "O'Brien", 'lname' => 'Smith'];

        $html = OwnerView::displayName($owner);

        $this->assertStringContainsString('O&#039;Brien', $html);
    }

    // ============================================================
    // qualityBadgeClass() TESTS
    // ============================================================

    /**
     * @return array<string, array{float, string}>
     */
    public static function qualityBadgeClassProvider(): array
    {
        return [
            'score 100' => [100.0, 'success'],
            'score 80 lower success bound' => [80.0, 'success'],
            'score 79.9 just below success' => [79.9, 'warning'],
            'score 60 lower warning bound' => [60.0, 'warning'],
            'score 59.9 just below warning' => [59.9, 'danger'],
            'score 0' => [0.0, 'danger'],
        ];
    }

    #[DataProvider('qualityBadgeClassProvider')]
    public function testQualityBadgeClass_ReturnsClassForScore(float $score, string $expected): void
    {
        $this->assertSame($expected, OwnerView::qualityBadgeClass($score));
    }

    // ============================================================
    // displayQualityBadge() TESTS
    // ============================================================

    /**
     * @return array<string, array{float, string}>
     */
    public static function displayQualityBadgeProvider(): array
    {
        return [
            'score 100 success class' => [100.0, 'text-bg-success'],
            'score 60 warning class' => [60.0, 'text-bg-warning'],
            'score 0 danger class' => [0.0, 'text-bg-danger'],
            'badge class' => [75.0, 'badge'],
            'quality label' => [75.0, 'Quality:'],
        ];
    }

    #[DataProvider('displayQualityBadgeProvider')]
    public function testDisplayQualityBadge_ContainsExpectedMarkup(float $score, string $needle): void
    {
        $this->assertStringContainsString($needle, OwnerView::displayQualityBadge($score));
    }

    // ============================================================
    // displayQualityProgressBar() TESTS
    // ============================================================

    /**
     * A null height calls the method without the argument, to cover the default.
     *
     * @return array<string, array{float, ?string, string}>
     */
    public static function displayQualityProgressBarProvider(): array
    {
        return [
            'score 75 width' => [75.0, null, 'width: 75%'],
            'score 75 warning class' => [75.0, null, 'bg-warning'],
            'score 100 success class' => [100.0, null, 'bg-success'],
            'score 0 danger class' => [0.0, null, 'bg-danger'],
            'default height 8px' => [50.0, null, 'height: 8px'],
            'custom height' => [50.0, '20px', '20px'],
            'progress-bar class' => [50.0, null, 'progress-bar'],
            'aria-valuenow' => [50.0, null, 'aria-valuenow'],
        ];
    }

    #[DataProvider('displayQualityProgressBarProvider')]
    public function testDisplayQualityProgressBar_ContainsExpectedMarkup(float $score, ?string $height, string $needle): void
    {
        $html = $height === null
            ? OwnerView::displayQualityProgressBar($score)
            : OwnerView::displayQualityProgressBar($score, $height);

        $this->assertStringContainsString($needle, $html);
    }

    public function testDisplayQualityProgressBar_InvalidHeight_FallsBackToDefault(): void
    {
        $html = OwnerView::displayQualityProgressBar(50.0, '20px; background:url(x)');

        $this->assertStringContainsString('height: 8px', $html);
        $this->assertStringNotContainsString('background', $html);
    }

    // ============================================================
    // displayLocation() TESTS
    // ============================================================

    public function testDisplayLocation_AllPartsSet_ReturnsFullLocation(): void
    {
        $owner = (object)['city' => 'Portland', 'state' => 'Oregon', 'country' => 'United States'];

        $this->assertStringContainsString('Portland, Oregon, United States', OwnerView::displayLocation($owner));
    }

    public function testDisplayLocation_OnlyCity_ReturnsCity(): void
    {
        $owner = (object)['city' => 'Portland', 'state' => null, 'country' => null];

        $this->assertSame('Portland', OwnerView::displayLocation($owner));
    }

    public function testDisplayLocation_AllNull_ReturnsEmptyString(): void
    {
        $owner = (object)['city' => null, 'state' => null, 'country' => null];

        $this->assertSame('', OwnerView::displayLocation($owner));
    }

    public function testDisplayLocation_AllEmptyString_ReturnsEmptyString(): void
    {
        $owner = (object)['city' => '', 'state' => '', 'country' => ''];

        $this->assertSame('', OwnerView::displayLocation($owner));
    }

    public function testDisplayLocation_ZeroStringCity_IsNotDropped(): void
    {
        $owner = (object)['city' => '0', 'state' => '', 'country' => ''];

        $this->assertSame('0', OwnerView::displayLocation($owner));
    }

    public function testDisplayLocation_XssInCity_IsEscaped(): void
    {
        $owner = (object)['city' => '<b>Bold</b>', 'state' => null, 'country' => null];

        $html = OwnerView::displayLocation($owner);

        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    // ============================================================
    // displayContactInfo() TESTS
    // ============================================================

    public function testDisplayContactInfo_ValidEmail_ContainsMailto(): void
    {
        $owner = (object)['email' => 'john@example.com', 'website' => null];

        $this->assertStringContainsString('mailto:john@example.com', OwnerView::displayContactInfo($owner));
    }

    public function testDisplayContactInfo_ValidEmail_ContainsMailtoHref(): void
    {
        $owner = (object)['email' => 'john@example.com', 'website' => null];

        $this->assertStringContainsString('href="mailto:', OwnerView::displayContactInfo($owner));
    }

    public function testDisplayContactInfo_ValidHttpsWebsite_ContainsHref(): void
    {
        $owner = (object)['email' => 'john@example.com', 'website' => 'https://example.com'];

        $this->assertStringContainsString('href="https://example.com"', OwnerView::displayContactInfo($owner));
    }

    public function testDisplayContactInfo_ValidHttpsWebsite_ContainsTargetBlank(): void
    {
        $owner = (object)['email' => 'john@example.com', 'website' => 'https://example.com'];

        $this->assertStringContainsString('target="_blank"', OwnerView::displayContactInfo($owner));
    }

    public function testDisplayContactInfo_ValidHttpsWebsite_ContainsRelNoopener(): void
    {
        $owner = (object)['email' => 'john@example.com', 'website' => 'https://example.com'];

        $this->assertStringContainsString('rel="noopener noreferrer"', OwnerView::displayContactInfo($owner));
    }

    public function testDisplayContactInfo_ValidHttpWebsite_ContainsHref(): void
    {
        $owner = (object)['email' => 'john@example.com', 'website' => 'http://example.com'];

        $this->assertStringContainsString('href="http://example.com"', OwnerView::displayContactInfo($owner));
    }

    public function testDisplayContactInfo_FtpWebsite_DoesNotContainFtpHref(): void
    {
        $owner = (object)['email' => 'john@example.com', 'website' => 'ftp://example.com'];

        $this->assertStringNotContainsString('href="ftp://', OwnerView::displayContactInfo($owner));
    }

    public function testDisplayContactInfo_UppercaseScheme_RendersLink(): void
    {
        $owner = (object)['email' => null, 'website' => 'HTTPS://example.com'];

        $this->assertStringContainsString('href="', OwnerView::displayContactInfo($owner));
    }

    public function testDisplayContactInfo_NoWebsite_DoesNotContainTargetBlank(): void
    {
        $owner = (object)['email' => 'john@example.com', 'website' => null];

        $this->assertStringNotContainsString('target="_blank"', OwnerView::displayContactInfo($owner));
    }

    public function testDisplayContactInfo_NoEmail_DoesNotContainMailto(): void
    {
        $owner = (object)['email' => null, 'website' => null];

        $this->assertStringNotContainsString('mailto:', OwnerView::displayContactInfo($owner));
    }

    public function testDisplayContactInfo_XssInEmail_IsEscaped(): void
    {
        $owner = (object)['email' => '"onmouseover="alert(1)"', 'website' => null];

        $html = OwnerView::displayContactInfo($owner);

        $this->assertStringNotContainsString('"onmouseover="alert(1)"', $html);
    }

    public function testDisplayContactInfo_EmailAndWebsite_BothRenderedWithBr(): void
    {
        $owner = (object)['email' => 'jane@example.com', 'website' => 'https://example.com'];

        $html = OwnerView::displayContactInfo($owner);

        $this->assertStringContainsString('mailto:jane@example.com', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('<br>', $html);
    }

    // ============================================================
    // websiteUrl() TESTS
    // ============================================================

    public function testWebsiteUrl_ValidHttpsUrl_ReturnsUrl(): void
    {
        $this->assertSame('https://example.com', OwnerView::websiteUrl('https://example.com'));
    }

    public function testWebsiteUrl_ValidHttpUrl_ReturnsUrl(): void
    {
        $this->assertSame('http://example.com', OwnerView::websiteUrl('http://example.com'));
    }

    public function testWebsiteUrl_UppercaseScheme_ReturnsUrl(): void
    {
        $this->assertSame('HTTPS://example.com', OwnerView::websiteUrl('HTTPS://example.com'));
    }

    public function testWebsiteUrl_FtpScheme_ReturnsNull(): void
    {
        $this->assertNull(OwnerView::websiteUrl('ftp://example.com'));
    }

    public function testWebsiteUrl_JavascriptScheme_ReturnsNull(): void
    {
        $this->assertNull(OwnerView::websiteUrl('javascript:alert(1)'));
    }

    public function testWebsiteUrl_EmptyString_ReturnsNull(): void
    {
        $this->assertNull(OwnerView::websiteUrl(''));
    }

    public function testWebsiteUrl_Null_ReturnsNull(): void
    {
        $this->assertNull(OwnerView::websiteUrl(null));
    }

    public function testWebsiteUrl_NonStringValue_ReturnsNull(): void
    {
        $this->assertNull(OwnerView::websiteUrl(12345));
    }

    public function testWebsiteUrl_MalformedUrl_ReturnsNull(): void
    {
        $this->assertNull(OwnerView::websiteUrl('not-a-url'));
    }

    // ============================================================
    // displayMissingFields() TESTS
    // ============================================================

    public function testDisplayMissingFields_EmptyArray_ReturnsEmptyString(): void
    {
        $this->assertSame('', OwnerView::displayMissingFields([]));
    }

    public function testDisplayMissingFields_Fields_ContainsFirstName(): void
    {
        $this->assertStringContainsString('First Name', OwnerView::displayMissingFields(['First Name', 'Location']));
    }

    public function testDisplayMissingFields_Fields_ContainsLocation(): void
    {
        $this->assertStringContainsString('Location', OwnerView::displayMissingFields(['First Name', 'Location']));
    }

    public function testDisplayMissingFields_Fields_ContainsWarningIcon(): void
    {
        $this->assertStringContainsString('fa-exclamation-triangle', OwnerView::displayMissingFields(['First Name']));
    }

    public function testDisplayMissingFields_Fields_ContainsUnorderedList(): void
    {
        $this->assertStringContainsString('<ul', OwnerView::displayMissingFields(['First Name']));
    }

    public function testDisplayMissingFields_XssInFieldName_IsEscaped(): void
    {
        $html = OwnerView::displayMissingFields(['<script>']);

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }
}
