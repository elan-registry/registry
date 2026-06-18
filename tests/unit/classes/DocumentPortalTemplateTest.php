<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ElanRegistry\Documentation\DocumentPortalTemplate;

use PHPUnit\Framework\Attributes\Group;

/**
 * DocumentPortalTemplate class tests
 *
 * Tests static rendering utilities for documentation portal pages.
 */
#[Group('fast')]
#[Group('documentation')]
final class DocumentPortalTemplateTest extends TestCase
{
    // ============================================================
    // renderPortalHeader TESTS
    // ============================================================

    public function testRenderPortalHeaderContainsTitleAndDescription(): void
    {
        $html = DocumentPortalTemplate::renderPortalHeader([
            'title' => 'Portal Title',
            'description' => 'Portal description text',
        ]);

        $this->assertStringContainsString('Portal Title', $html);
        $this->assertStringContainsString('Portal description text', $html);
    }

    public function testRenderPortalHeaderAppliesHeaderClass(): void
    {
        $html = DocumentPortalTemplate::renderPortalHeader([
            'title' => 'Title',
            'description' => 'Desc',
            'headerClass' => 'bg-danger',
        ]);

        $this->assertStringContainsString('card-header bg-danger', $html);
    }

    public function testRenderPortalHeaderShowsLeadTextWhenProvided(): void
    {
        $html = DocumentPortalTemplate::renderPortalHeader([
            'title' => 'Title',
            'description' => 'Desc',
            'leadText' => 'This is lead text',
        ]);

        $this->assertStringContainsString("class='lead'", $html);
        $this->assertStringContainsString('This is lead text', $html);
    }

    public function testRenderPortalHeaderOmitsLeadTextWhenAbsent(): void
    {
        $html = DocumentPortalTemplate::renderPortalHeader([
            'title' => 'Title',
            'description' => 'Desc',
        ]);

        $this->assertStringNotContainsString("class='lead'", $html);
    }

    public function testRenderPortalHeaderShowsAdminBannerWhenIsAdminTrue(): void
    {
        $html = DocumentPortalTemplate::renderPortalHeader([
            'title' => 'Title',
            'description' => 'Desc',
            'isAdmin' => true,
        ]);

        $this->assertStringContainsString('alert-warning', $html);
        $this->assertStringContainsString('Administrator Access Required', $html);
    }

    public function testRenderPortalHeaderHidesAdminBannerByDefault(): void
    {
        $html = DocumentPortalTemplate::renderPortalHeader([
            'title' => 'Title',
            'description' => 'Desc',
        ]);

        $this->assertStringNotContainsString('alert-warning', $html);
    }

    public function testRenderPortalHeaderEscapesTitle(): void
    {
        $html = DocumentPortalTemplate::renderPortalHeader([
            'title' => '<script>alert("xss")</script>',
            'description' => 'Desc',
        ]);

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testRenderPortalHeaderShowsTitleIconWhenProvided(): void
    {
        $html = DocumentPortalTemplate::renderPortalHeader([
            'title' => 'FAQ & User Guides',
            'description' => 'Desc',
            'titleIcon' => 'fa-question-circle',
        ]);

        $this->assertStringContainsString("<i class='fas fa-question-circle'></i>", $html);
        $this->assertStringContainsString("<h1 class='mb-0 card-header-er-primary-text'><i class='fas fa-question-circle'></i> FAQ &amp; User Guides</h1>", $html);
    }

    public function testRenderPortalHeaderOmitsTitleIconWhenAbsent(): void
    {
        $html = DocumentPortalTemplate::renderPortalHeader([
            'title' => 'Title',
            'description' => 'Desc',
        ]);

        $this->assertStringNotContainsString("<i class='fas", $html);
    }

    public function testRenderPortalHeaderEscapesLeadText(): void
    {
        $html = DocumentPortalTemplate::renderPortalHeader([
            'title'       => 'Title',
            'description' => 'Desc',
            'leadText'    => '<script>xss</script>',
        ]);

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testRenderPortalHeaderThrowsOnMissingTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DocumentPortalTemplate::renderPortalHeader([
            'description' => 'Desc',
        ]);
    }

    public function testRenderPortalHeaderThrowsOnMissingDescription(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DocumentPortalTemplate::renderPortalHeader([
            'title' => 'Title',
        ]);
    }

    // ============================================================
    // renderDocumentCard TESTS
    // ============================================================

    public function testRenderDocumentCardContainsTitle(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title' => 'Card Title',
            'icon' => 'fa-car',
            'url' => '/test',
            'buttonText' => 'View',
        ]);

        $this->assertStringContainsString('Card Title', $html);
        $this->assertStringContainsString('<h5', $html);
    }

    public function testRenderDocumentCardContainsIcon(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title' => 'Title',
            'icon' => 'fa-car',
            'url' => '/test',
            'buttonText' => 'View',
        ]);

        $this->assertStringContainsString('fas fa-car', $html);
    }

    public function testRenderDocumentCardContainsLink(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title' => 'Title',
            'icon' => 'fa-car',
            'url' => '/docs/page',
            'buttonText' => 'View',
        ]);

        $this->assertStringContainsString("href='/docs/page'", $html);
    }

    public function testRenderDocumentCardContainsButtonText(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title' => 'Title',
            'icon' => 'fa-car',
            'url' => '/test',
            'buttonText' => 'Open Document',
        ]);

        $this->assertStringContainsString('Open Document', $html);
    }

    public function testRenderDocumentCardAppliesHeaderClass(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title' => 'Title',
            'icon' => 'fa-car',
            'url' => '/test',
            'buttonText' => 'View',
            'headerClass' => 'bg-success text-white',
        ]);

        $this->assertStringContainsString('card-header bg-success text-white', $html);
    }

    public function testRenderDocumentCardAppliesButtonClass(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title' => 'Title',
            'icon' => 'fa-car',
            'url' => '/test',
            'buttonText' => 'View',
            'buttonClass' => 'btn-danger btn-lg',
        ]);

        $this->assertStringContainsString('btn btn-danger btn-lg', $html);
    }

    public function testRenderDocumentCardAppliesCustomCardClass(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title' => 'Title',
            'icon' => 'fa-car',
            'url' => '/test',
            'buttonText' => 'View',
            'cardClass' => 'custom-card-class',
        ]);

        $this->assertStringContainsString('custom-card-class', $html);
        $this->assertStringNotContainsString('registry-card', $html);
    }

    public function testRenderDocumentCardUsesDefaultCardClass(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title' => 'Title',
            'icon' => 'fa-car',
            'url' => '/test',
            'buttonText' => 'View',
        ]);

        $this->assertStringContainsString('registry-card', $html);
    }

    public function testRenderDocumentCardShowsDescriptionWhenProvided(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title' => 'Title',
            'icon' => 'fa-car',
            'url' => '/test',
            'buttonText' => 'View',
            'description' => 'A detailed description',
        ]);

        $this->assertStringContainsString('card-text', $html);
        $this->assertStringContainsString('A detailed description', $html);
    }

    public function testRenderDocumentCardOmitsDescriptionWhenAbsent(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title' => 'Title',
            'icon' => 'fa-car',
            'url' => '/test',
            'buttonText' => 'View',
        ]);

        $this->assertStringNotContainsString('card-text', $html);
    }

    public function testRenderDocumentCardShowsMetadataWhenProvided(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title' => 'Title',
            'icon' => 'fa-car',
            'url' => '/test',
            'buttonText' => 'View',
            'metadata' => 'Last updated: 2024-01-01',
        ]);

        $this->assertStringContainsString('small text-muted', $html);
        $this->assertStringContainsString('Last updated: 2024-01-01', $html);
    }

    public function testRenderDocumentCardRendersListItems(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title' => 'Title',
            'icon' => 'fa-car',
            'url' => '/test',
            'buttonText' => 'View',
            'listItems' => [
                ['icon' => 'fa-check', 'text' => 'Item one'],
                ['icon' => 'fa-star', 'text' => 'Item two'],
            ],
        ]);

        $this->assertStringContainsString('list-unstyled', $html);
        $this->assertStringContainsString('Item one', $html);
        $this->assertStringContainsString('Item two', $html);
        $this->assertStringContainsString('fas fa-check', $html);
        $this->assertStringContainsString('fas fa-star', $html);
    }

    public function testRenderDocumentCardOmitsListWhenEmpty(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title' => 'Title',
            'icon' => 'fa-car',
            'url' => '/test',
            'buttonText' => 'View',
        ]);

        $this->assertStringNotContainsString('list-unstyled', $html);
    }

    public function testRenderDocumentCardAppliesHeaderStyle(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title' => 'Title',
            'icon' => 'fa-car',
            'url' => '/test',
            'buttonText' => 'View',
            'headerStyle' => 'background-color: #ff0000',
        ]);

        $this->assertStringContainsString("style='background-color: #ff0000'", $html);
    }

    public function testRenderDocumentCardAppliesButtonStyle(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title' => 'Title',
            'icon' => 'fa-car',
            'url' => '/test',
            'buttonText' => 'View',
            'buttonStyle' => 'min-width: 120px',
        ]);

        $this->assertStringContainsString("style='min-width: 120px'", $html);
    }

    public function testRenderDocumentCardRendersButtonIcon(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title' => 'Title',
            'icon' => 'fa-car',
            'url' => '/test',
            'buttonText' => 'View',
            'buttonIcon' => 'fa-arrow-right',
        ]);

        $this->assertStringContainsString('fas fa-arrow-right', $html);
    }

    public function testRenderDocumentCardEscapesTitle(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title' => '<script>xss</script>',
            'icon' => 'fa-car',
            'url' => '/test',
            'buttonText' => 'View',
        ]);

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testRenderDocumentCardEscapesDescription(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title'       => 'Title',
            'icon'        => 'fa-car',
            'url'         => '/test',
            'buttonText'  => 'View',
            'description' => '<script>xss</script>',
        ]);

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testRenderDocumentCardEscapesMetadata(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title'      => 'Title',
            'icon'       => 'fa-car',
            'url'        => '/test',
            'buttonText' => 'View',
            'metadata'   => '<b onmouseover="evil()">metadata</b>',
        ]);

        $this->assertStringNotContainsString('<b ', $html);
        $this->assertStringContainsString('&lt;b ', $html);
    }

    public function testRenderDocumentCardThrowsOnMissingTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DocumentPortalTemplate::renderDocumentCard([
            'icon'       => 'fa-car',
            'url'        => '/test',
            'buttonText' => 'View',
        ]);
    }

    public function testRenderDocumentCardThrowsOnMissingIcon(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DocumentPortalTemplate::renderDocumentCard([
            'title'      => 'Title',
            'url'        => '/test',
            'buttonText' => 'View',
        ]);
    }

    public function testRenderDocumentCardThrowsOnMissingUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DocumentPortalTemplate::renderDocumentCard([
            'title'      => 'Title',
            'icon'       => 'fa-car',
            'buttonText' => 'View',
        ]);
    }

    public function testRenderDocumentCardThrowsOnMissingButtonText(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DocumentPortalTemplate::renderDocumentCard([
            'title' => 'Title',
            'icon'  => 'fa-car',
            'url'   => '/test',
        ]);
    }

    public function testRenderDocumentCardButtonTargetAddsTargetAttribute(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title'        => 'Title',
            'icon'         => 'fa-car',
            'url'          => '/test',
            'buttonText'   => 'View',
            'buttonTarget' => '_blank',
        ]);

        $this->assertStringContainsString("target='_blank'", $html);
    }

    public function testRenderDocumentCardButtonTargetBlankAddsRelAttribute(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title'        => 'Title',
            'icon'         => 'fa-car',
            'url'          => '/test',
            'buttonText'   => 'View',
            'buttonTarget' => '_blank',
        ]);

        $this->assertStringContainsString("rel='noopener noreferrer'", $html);
    }

    public function testRenderDocumentCardOmitsTargetWhenNotProvided(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title'      => 'Title',
            'icon'       => 'fa-car',
            'url'        => '/test',
            'buttonText' => 'View',
        ]);

        $this->assertStringNotContainsString('target=', $html);
        $this->assertStringNotContainsString('rel=', $html);
    }

    public function testRenderDocumentCardSecondaryButtonRendersUrl(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title'           => 'Title',
            'icon'            => 'fa-car',
            'url'             => '/test',
            'buttonText'      => 'View',
            'secondaryButton' => [
                'url'  => '/download/file.pdf',
                'text' => 'Download PDF',
            ],
        ]);

        $this->assertStringContainsString("href='/download/file.pdf'", $html);
        $this->assertStringContainsString('Download PDF', $html);
    }

    public function testRenderDocumentCardSecondaryButtonAppliesClass(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title'           => 'Title',
            'icon'            => 'fa-car',
            'url'             => '/test',
            'buttonText'      => 'View',
            'secondaryButton' => [
                'url'   => '/download',
                'text'  => 'Download',
                'class' => 'btn-warning btn-sm',
            ],
        ]);

        $this->assertStringContainsString('btn btn-warning btn-sm', $html);
    }

    public function testRenderDocumentCardSecondaryButtonRendersIcon(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title'           => 'Title',
            'icon'            => 'fa-car',
            'url'             => '/test',
            'buttonText'      => 'View',
            'secondaryButton' => [
                'url'  => '/download',
                'text' => 'Download',
                'icon' => 'fa-download',
            ],
        ]);

        $this->assertStringContainsString('fas fa-download', $html);
    }

    public function testRenderDocumentCardSecondaryButtonRendersDownloadAttribute(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title'           => 'Title',
            'icon'            => 'fa-car',
            'url'             => '/test',
            'buttonText'      => 'View',
            'secondaryButton' => [
                'url'      => '/download',
                'text'     => 'Download',
                'download' => true,
            ],
        ]);

        $this->assertStringContainsString(' download', $html);
    }

    public function testRenderDocumentCardSecondaryButtonTargetBlankAddsRelAttribute(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title'           => 'Title',
            'icon'            => 'fa-car',
            'url'             => '/test',
            'buttonText'      => 'View',
            'secondaryButton' => [
                'url'    => '/docs/file.pdf',
                'text'   => 'Open',
                'target' => '_blank',
            ],
        ]);

        $this->assertStringContainsString("target='_blank'", $html);
        $this->assertStringContainsString("rel='noopener noreferrer'", $html);
    }

    public function testRenderDocumentCardOmitsSecondaryButtonWhenAbsent(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard([
            'title'      => 'Title',
            'icon'       => 'fa-car',
            'url'        => '/test',
            'buttonText' => 'View',
        ]);

        // Only one <a> element (the primary button)
        $this->assertSame(1, substr_count($html, '<a href='));
    }

    // ============================================================
    // renderDocumentCardGrid TESTS
    // ============================================================

    public function testRenderDocumentCardGridWrapsInRow(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCardGrid([
            ['title' => 'T', 'icon' => 'fa-car', 'url' => '/', 'buttonText' => 'V'],
        ]);

        $this->assertStringContainsString("class='row", $html);
    }

    public function testRenderDocumentCardGridAppliesDefaultColClass(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCardGrid([
            ['title' => 'T', 'icon' => 'fa-car', 'url' => '/', 'buttonText' => 'V'],
        ]);

        $this->assertStringContainsString('col-lg-4', $html);
    }

    public function testRenderDocumentCardGridAppliesCustomColClass(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCardGrid(
            [['title' => 'T', 'icon' => 'fa-car', 'url' => '/', 'buttonText' => 'V']],
            'col-md-6'
        );

        $this->assertStringContainsString('col-md-6', $html);
    }

    public function testRenderDocumentCardGridPerCardColClassOverride(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCardGrid([
            ['title' => 'T', 'icon' => 'fa-car', 'url' => '/', 'buttonText' => 'V', 'colClass' => 'col-lg-6'],
        ]);

        $this->assertStringContainsString('col-lg-6', $html);
        $this->assertStringNotContainsString('col-lg-4', $html);
    }

    public function testRenderDocumentCardGridReturnsEmptyStringForEmptyArray(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCardGrid([]);

        $this->assertSame('', $html);
    }

    // ============================================================
    // renderSectionHeading TESTS
    // ============================================================

    public function testRenderSectionHeadingContainsTitle(): void
    {
        $html = DocumentPortalTemplate::renderSectionHeading('fa-code', 'Developer Tools');

        $this->assertStringContainsString('Developer Tools', $html);
    }

    public function testRenderSectionHeadingContainsIcon(): void
    {
        $html = DocumentPortalTemplate::renderSectionHeading('fa-code', 'Title');

        $this->assertStringContainsString('fas fa-code', $html);
    }

    public function testRenderSectionHeadingAppliesColorClass(): void
    {
        $html = DocumentPortalTemplate::renderSectionHeading('fa-code', 'Title', 'text-danger');

        $this->assertStringContainsString('fas fa-code text-danger', $html);
    }

    public function testRenderSectionHeadingOmitsColorClassWhenEmpty(): void
    {
        $html = DocumentPortalTemplate::renderSectionHeading('fa-code', 'Title');

        $this->assertStringContainsString("class='fas fa-code'", $html);
        $this->assertStringNotContainsString('text-danger', $html);
    }

    public function testRenderSectionHeadingEscapesTitle(): void
    {
        $html = DocumentPortalTemplate::renderSectionHeading('fa-code', '<script>xss</script>');

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    // ============================================================
    // renderNavFooter TESTS
    // ============================================================

    public function testRenderNavFooterRendersLinks(): void
    {
        $html = DocumentPortalTemplate::renderNavFooter([
            ['label' => 'Home', 'url' => '/'],
            ['label' => 'Docs', 'url' => '/docs'],
        ]);

        $this->assertStringContainsString('Home', $html);
        $this->assertStringContainsString('Docs', $html);
        $this->assertStringContainsString("href='/'", $html);
        $this->assertStringContainsString("href='/docs'", $html);
    }

    public function testRenderNavFooterRendersIcon(): void
    {
        $html = DocumentPortalTemplate::renderNavFooter([
            ['label' => 'Home', 'url' => '/', 'icon' => 'fa-home'],
        ]);

        $this->assertStringContainsString('fas fa-home', $html);
    }

    public function testRenderNavFooterOmitsIconWhenAbsent(): void
    {
        $html = DocumentPortalTemplate::renderNavFooter([
            ['label' => 'Home', 'url' => '/'],
        ]);

        $this->assertStringNotContainsString('fas', $html);
    }

    public function testRenderNavFooterAppliesBtnClass(): void
    {
        $html = DocumentPortalTemplate::renderNavFooter([
            ['label' => 'Home', 'url' => '/', 'btnClass' => 'btn-success'],
        ]);

        $this->assertStringContainsString('btn btn-success', $html);
    }

    public function testRenderNavFooterReturnsEmptyStringForEmptyArray(): void
    {
        $html = DocumentPortalTemplate::renderNavFooter([]);

        $this->assertSame('', $html);
    }

    public function testRenderNavFooterThrowsOnMissingLabel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DocumentPortalTemplate::renderNavFooter([
            ['url' => '/'],
        ]);
    }

    public function testRenderNavFooterThrowsOnMissingUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DocumentPortalTemplate::renderNavFooter([
            ['label' => 'Home'],
        ]);
    }
}
