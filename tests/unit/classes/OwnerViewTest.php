<?php

declare(strict_types=1);

use ElanRegistry\OwnerView;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

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

    public function testQualityBadgeClass_Score100_ReturnsSuccess(): void
    {
        $this->assertSame('success', OwnerView::qualityBadgeClass(100.0));
    }

    public function testQualityBadgeClass_Score80_ReturnsSuccess(): void
    {
        $this->assertSame('success', OwnerView::qualityBadgeClass(80.0));
    }

    public function testQualityBadgeClass_Score79_9_ReturnsWarning(): void
    {
        $this->assertSame('warning', OwnerView::qualityBadgeClass(79.9));
    }

    public function testQualityBadgeClass_Score60_ReturnsWarning(): void
    {
        $this->assertSame('warning', OwnerView::qualityBadgeClass(60.0));
    }

    public function testQualityBadgeClass_Score59_9_ReturnsDanger(): void
    {
        $this->assertSame('danger', OwnerView::qualityBadgeClass(59.9));
    }

    public function testQualityBadgeClass_Score0_ReturnsDanger(): void
    {
        $this->assertSame('danger', OwnerView::qualityBadgeClass(0.0));
    }

    // ============================================================
    // displayQualityBadge() TESTS
    // ============================================================

    public function testDisplayQualityBadge_Score100_ContainsSuccessClass(): void
    {
        $this->assertStringContainsString('text-bg-success', OwnerView::displayQualityBadge(100.0));
    }

    public function testDisplayQualityBadge_Score60_ContainsWarningClass(): void
    {
        $this->assertStringContainsString('text-bg-warning', OwnerView::displayQualityBadge(60.0));
    }

    public function testDisplayQualityBadge_Score0_ContainsDangerClass(): void
    {
        $this->assertStringContainsString('text-bg-danger', OwnerView::displayQualityBadge(0.0));
    }

    public function testDisplayQualityBadge_ContainsBadge(): void
    {
        $this->assertStringContainsString('badge', OwnerView::displayQualityBadge(75.0));
    }

    public function testDisplayQualityBadge_ContainsQualityLabel(): void
    {
        $this->assertStringContainsString('Quality:', OwnerView::displayQualityBadge(75.0));
    }

    // ============================================================
    // displayQualityProgressBar() TESTS
    // ============================================================

    public function testDisplayQualityProgressBar_Score75_ContainsWidth(): void
    {
        $this->assertStringContainsString('width: 75%', OwnerView::displayQualityProgressBar(75.0));
    }

    public function testDisplayQualityProgressBar_Score75_ContainsWarningClass(): void
    {
        $this->assertStringContainsString('bg-warning', OwnerView::displayQualityProgressBar(75.0));
    }

    public function testDisplayQualityProgressBar_Score100_ContainsSuccessClass(): void
    {
        $this->assertStringContainsString('bg-success', OwnerView::displayQualityProgressBar(100.0));
    }

    public function testDisplayQualityProgressBar_Score0_ContainsDangerClass(): void
    {
        $this->assertStringContainsString('bg-danger', OwnerView::displayQualityProgressBar(0.0));
    }

    public function testDisplayQualityProgressBar_DefaultHeight_Contains8px(): void
    {
        $this->assertStringContainsString('height: 8px', OwnerView::displayQualityProgressBar(50.0));
    }

    public function testDisplayQualityProgressBar_CustomHeight_ContainsCustomValue(): void
    {
        $this->assertStringContainsString('20px', OwnerView::displayQualityProgressBar(50.0, '20px'));
    }

    public function testDisplayQualityProgressBar_ContainsProgressBar(): void
    {
        $this->assertStringContainsString('progress-bar', OwnerView::displayQualityProgressBar(50.0));
    }

    public function testDisplayQualityProgressBar_ContainsAriaValuenow(): void
    {
        $this->assertStringContainsString('aria-valuenow', OwnerView::displayQualityProgressBar(50.0));
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
