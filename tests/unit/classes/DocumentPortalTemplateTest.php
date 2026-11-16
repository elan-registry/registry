<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ElanRegistry\Documentation\DocumentPortalTemplate;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * DocumentPortalTemplate class tests
 *
 * Tests static rendering utilities for documentation portal pages.
 *
 * @phpstan-type PortalHeaderConfig array{
 *     title: string,
 *     description: string,
 *     titleIcon?: string,
 *     headerClass?: string,
 *     leadText?: string,
 *     isAdmin?: bool
 * }
 * @phpstan-type DocumentCardConfig array{
 *     title: string,
 *     icon: string,
 *     url: string,
 *     buttonText: string,
 *     headerClass?: string,
 *     buttonClass?: string,
 *     cardClass?: string,
 *     description?: string,
 *     metadata?: string,
 *     listItems?: list<array{icon: string, text: string}>,
 *     buttonIcon?: string,
 *     headerStyle?: string,
 *     buttonStyle?: string,
 *     buttonTarget?: string,
 *     secondaryButton?: array{url: string, text: string, class?: string, icon?: string, download?: bool, target?: string},
 *     colClass?: string
 * }
 * @phpstan-type NavFooterLinks list<array{label: string, url: string, icon?: string, btnClass?: string}>
 */
#[Group('fast')]
#[Group('documentation')]
final class DocumentPortalTemplateTest extends TestCase
{
    private const array BASE_HEADER = [
        'title'       => 'Title',
        'description' => 'Desc',
    ];

    private const array BASE_CARD = [
        'title'      => 'Title',
        'icon'       => 'fa-car',
        'url'        => '/test',
        'buttonText' => 'View',
    ];

    // ============================================================
    // renderPortalHeader TESTS
    // ============================================================

    /**
     * @return array<string, array{PortalHeaderConfig, list<string>, list<string>}>
     */
    public static function portalHeaderProvider(): array
    {
        return [
            'contains title and description' => [
                ['title' => 'Portal Title', 'description' => 'Portal description text'],
                ['Portal Title', 'Portal description text'],
                [],
            ],
            'applies header class' => [
                [...self::BASE_HEADER, 'headerClass' => 'bg-danger'],
                ['card-header bg-danger'],
                [],
            ],
            'shows lead text when provided' => [
                [...self::BASE_HEADER, 'leadText' => 'This is lead text'],
                ["class='lead'", 'This is lead text'],
                [],
            ],
            'omits lead text when absent' => [
                self::BASE_HEADER,
                [],
                ["class='lead'"],
            ],
            'shows admin banner when isAdmin true' => [
                [...self::BASE_HEADER, 'isAdmin' => true],
                ['alert-warning', 'Administrator Access Required'],
                [],
            ],
            'hides admin banner by default' => [
                self::BASE_HEADER,
                [],
                ['alert-warning'],
            ],
            'escapes title' => [
                [...self::BASE_HEADER, 'title' => '<script>alert("xss")</script>'],
                ['&lt;script&gt;'],
                ['<script>'],
            ],
            'shows title icon when provided' => [
                [...self::BASE_HEADER, 'title' => 'FAQ & User Guides', 'titleIcon' => 'fa-question-circle'],
                [
                    "<i class='fas fa-question-circle'></i>",
                    "<h1 class='mb-0 card-header-er-primary-text'><i class='fas fa-question-circle'></i> FAQ &amp; User Guides</h1>",
                ],
                [],
            ],
            'omits title icon when absent' => [
                self::BASE_HEADER,
                [],
                ["<i class='fas"],
            ],
            'escapes lead text' => [
                [...self::BASE_HEADER, 'leadText' => '<script>xss</script>'],
                ['&lt;script&gt;'],
                ['<script>'],
            ],
            // Color token regression rows (issue #757)
            'uses default header class' => [
                self::BASE_HEADER,
                ['card-header card-header-er-primary'],
                [],
            ],
            'description has primary text class' => [
                self::BASE_HEADER,
                ["class='card-header-er-primary-text mb-0'"],
                [],
            ],
        ];
    }

    /**
     * @param PortalHeaderConfig $config
     * @param list<string>       $contains    Fragments the HTML must contain
     * @param list<string>       $notContains Fragments the HTML must not contain
     */
    #[DataProvider('portalHeaderProvider')]
    public function testRenderPortalHeaderRendersExpectedMarkup(array $config, array $contains, array $notContains): void
    {
        $html = DocumentPortalTemplate::renderPortalHeader($config);

        $this->assertMarkup($html, $contains, $notContains);
    }

    public function testRenderPortalHeaderThrowsOnMissingTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore argument.type (intentional missing 'title' key; asserts the guard throws)
        DocumentPortalTemplate::renderPortalHeader([
            'description' => 'Desc',
        ]);
    }

    public function testRenderPortalHeaderThrowsOnMissingDescription(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore argument.type (intentional missing 'description' key; asserts the guard throws)
        DocumentPortalTemplate::renderPortalHeader([
            'title' => 'Title',
        ]);
    }

    // ============================================================
    // renderDocumentCard TESTS
    // ============================================================

    /**
     * @return array<string, array{DocumentCardConfig, list<string>, list<string>}>
     */
    public static function documentCardProvider(): array
    {
        return [
            'contains title' => [
                [...self::BASE_CARD, 'title' => 'Card Title'],
                ['Card Title', '<h5'],
                [],
            ],
            'contains icon' => [
                self::BASE_CARD,
                ['fas fa-car'],
                [],
            ],
            'contains link' => [
                [...self::BASE_CARD, 'url' => '/docs/page'],
                ["href='/docs/page'"],
                [],
            ],
            'contains button text' => [
                [...self::BASE_CARD, 'buttonText' => 'Open Document'],
                ['Open Document'],
                [],
            ],
            'applies header class' => [
                [...self::BASE_CARD, 'headerClass' => 'bg-success text-white'],
                ['card-header bg-success text-white'],
                [],
            ],
            'applies button class' => [
                [...self::BASE_CARD, 'buttonClass' => 'btn-danger btn-lg'],
                ['btn btn-danger btn-lg'],
                [],
            ],
            'applies custom card class' => [
                [...self::BASE_CARD, 'cardClass' => 'custom-card-class'],
                ['custom-card-class'],
                ['registry-card'],
            ],
            'uses default card class' => [
                self::BASE_CARD,
                ['registry-card'],
                [],
            ],
            'shows description when provided' => [
                [...self::BASE_CARD, 'description' => 'A detailed description'],
                ['card-text', 'A detailed description'],
                [],
            ],
            'omits description when absent' => [
                self::BASE_CARD,
                [],
                ['card-text'],
            ],
            'shows metadata when provided' => [
                [...self::BASE_CARD, 'metadata' => 'Last updated: 2024-01-01'],
                ['small text-muted', 'Last updated: 2024-01-01'],
                [],
            ],
            'renders list items' => [
                [...self::BASE_CARD, 'listItems' => [
                    ['icon' => 'fa-check', 'text' => 'Item one'],
                    ['icon' => 'fa-star', 'text' => 'Item two'],
                ]],
                ['list-unstyled', 'Item one', 'Item two', 'fas fa-check', 'fas fa-star'],
                [],
            ],
            'omits list when empty' => [
                self::BASE_CARD,
                [],
                ['list-unstyled'],
            ],
            'applies header style' => [
                [...self::BASE_CARD, 'headerStyle' => 'background-color: #ff0000'],
                ["style='background-color: #ff0000'"],
                [],
            ],
            'applies button style' => [
                [...self::BASE_CARD, 'buttonStyle' => 'min-width: 120px'],
                ["style='min-width: 120px'"],
                [],
            ],
            'renders button icon' => [
                [...self::BASE_CARD, 'buttonIcon' => 'fa-arrow-right'],
                ['fas fa-arrow-right'],
                [],
            ],
            'escapes title' => [
                [...self::BASE_CARD, 'title' => '<script>xss</script>'],
                ['&lt;script&gt;'],
                ['<script>'],
            ],
            'escapes description' => [
                [...self::BASE_CARD, 'description' => '<script>xss</script>'],
                ['&lt;script&gt;'],
                ['<script>'],
            ],
            'escapes metadata' => [
                [...self::BASE_CARD, 'metadata' => '<b onmouseover="evil()">metadata</b>'],
                ['&lt;b '],
                ['<b '],
            ],
            'button target adds target attribute' => [
                [...self::BASE_CARD, 'buttonTarget' => '_blank'],
                ["target='_blank'"],
                [],
            ],
            'button target blank adds rel attribute' => [
                [...self::BASE_CARD, 'buttonTarget' => '_blank'],
                ["rel='noopener noreferrer'"],
                [],
            ],
            'omits target when not provided' => [
                self::BASE_CARD,
                [],
                ['target=', 'rel='],
            ],
            'secondary button renders url' => [
                [...self::BASE_CARD, 'secondaryButton' => [
                    'url'  => '/download/file.pdf',
                    'text' => 'Download PDF',
                ]],
                ["href='/download/file.pdf'", 'Download PDF'],
                [],
            ],
            'secondary button applies class' => [
                [...self::BASE_CARD, 'secondaryButton' => [
                    'url'   => '/download',
                    'text'  => 'Download',
                    'class' => 'btn-warning btn-sm',
                ]],
                ['btn btn-warning btn-sm'],
                [],
            ],
            'secondary button renders icon' => [
                [...self::BASE_CARD, 'secondaryButton' => [
                    'url'  => '/download',
                    'text' => 'Download',
                    'icon' => 'fa-download',
                ]],
                ['fas fa-download'],
                [],
            ],
            'secondary button renders download attribute' => [
                [...self::BASE_CARD, 'secondaryButton' => [
                    'url'      => '/download',
                    'text'     => 'Download',
                    'download' => true,
                ]],
                [' download'],
                [],
            ],
            'secondary button target blank adds rel attribute' => [
                [...self::BASE_CARD, 'secondaryButton' => [
                    'url'    => '/docs/file.pdf',
                    'text'   => 'Open',
                    'target' => '_blank',
                ]],
                ["target='_blank'", "rel='noopener noreferrer'"],
                [],
            ],
            // Color token regression rows (issue #757)
            'title heading has primary text class' => [
                [...self::BASE_CARD, 'title' => 'Card Title'],
                ["class='mb-0 card-header-er-primary-text'"],
                [],
            ],
            'uses default header class' => [
                self::BASE_CARD,
                ['card-header card-header-er-primary'],
                [],
            ],
            'uses default button class' => [
                self::BASE_CARD,
                ['btn btn-primary btn-sm'],
                [],
            ],
        ];
    }

    /**
     * @param DocumentCardConfig $card
     * @param list<string>       $contains    Fragments the HTML must contain
     * @param list<string>       $notContains Fragments the HTML must not contain
     */
    #[DataProvider('documentCardProvider')]
    public function testRenderDocumentCardRendersExpectedMarkup(array $card, array $contains, array $notContains): void
    {
        $html = DocumentPortalTemplate::renderDocumentCard($card);

        $this->assertMarkup($html, $contains, $notContains);
    }

    public function testRenderDocumentCardThrowsOnMissingTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore argument.type (intentional missing 'title' key; asserts the guard throws)
        DocumentPortalTemplate::renderDocumentCard([
            'icon'       => 'fa-car',
            'url'        => '/test',
            'buttonText' => 'View',
        ]);
    }

    public function testRenderDocumentCardThrowsOnMissingIcon(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore argument.type (intentional missing 'icon' key; asserts the guard throws)
        DocumentPortalTemplate::renderDocumentCard([
            'title'      => 'Title',
            'url'        => '/test',
            'buttonText' => 'View',
        ]);
    }

    public function testRenderDocumentCardThrowsOnMissingUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore argument.type (intentional missing 'url' key; asserts the guard throws)
        DocumentPortalTemplate::renderDocumentCard([
            'title'      => 'Title',
            'icon'       => 'fa-car',
            'buttonText' => 'View',
        ]);
    }

    public function testRenderDocumentCardThrowsOnMissingButtonText(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore argument.type (intentional missing 'buttonText' key; asserts the guard throws)
        DocumentPortalTemplate::renderDocumentCard([
            'title' => 'Title',
            'icon'  => 'fa-car',
            'url'   => '/test',
        ]);
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

    /**
     * @return array<string, array{list<array<string, mixed>>, string|null, list<string>, list<string>}>
     */
    public static function documentCardGridProvider(): array
    {
        $card = self::BASE_CARD;

        return [
            'wraps in row' => [[$card], null, ["class='row"], []],
            'applies default col class' => [[$card], null, ['col-lg-4'], []],
            'applies custom col class' => [[$card], 'col-md-6', ['col-md-6'], []],
            'per-card col class override' => [
                [[...$card, 'colClass' => 'col-lg-6']],
                null,
                ['col-lg-6'],
                ['col-lg-4'],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @param string|null                $colClass    Grid column class, or null for the default
     * @param list<string>               $contains    Fragments the HTML must contain
     * @param list<string>               $notContains Fragments the HTML must not contain
     */
    #[DataProvider('documentCardGridProvider')]
    public function testRenderDocumentCardGridRendersExpectedMarkup(
        array $cards,
        ?string $colClass,
        array $contains,
        array $notContains
    ): void {
        $html = $colClass === null
            ? DocumentPortalTemplate::renderDocumentCardGrid($cards)
            : DocumentPortalTemplate::renderDocumentCardGrid($cards, $colClass);

        $this->assertMarkup($html, $contains, $notContains);
    }

    public function testRenderDocumentCardGridReturnsEmptyStringForEmptyArray(): void
    {
        $html = DocumentPortalTemplate::renderDocumentCardGrid([]);

        $this->assertSame('', $html);
    }

    // ============================================================
    // renderSectionHeading TESTS
    // ============================================================

    /**
     * @return array<string, array{string, string, string|null, list<string>, list<string>}>
     */
    public static function sectionHeadingProvider(): array
    {
        return [
            'contains title' => ['fa-code', 'Developer Tools', null, ['Developer Tools'], []],
            'contains icon' => ['fa-code', 'Title', null, ['fas fa-code'], []],
            'applies color class' => ['fa-code', 'Title', 'text-danger', ['fas fa-code text-danger'], []],
            'omits color class when empty' => ['fa-code', 'Title', null, ["class='fas fa-code'"], ['text-danger']],
            'escapes title' => ['fa-code', '<script>xss</script>', null, ['&lt;script&gt;'], ['<script>']],
        ];
    }

    /**
     * @param string       $icon        Font Awesome icon class
     * @param string       $title       Heading text
     * @param string|null  $colorClass  Icon color class, or null for the default
     * @param list<string> $contains    Fragments the HTML must contain
     * @param list<string> $notContains Fragments the HTML must not contain
     */
    #[DataProvider('sectionHeadingProvider')]
    public function testRenderSectionHeadingRendersExpectedMarkup(
        string $icon,
        string $title,
        ?string $colorClass,
        array $contains,
        array $notContains
    ): void {
        $html = $colorClass === null
            ? DocumentPortalTemplate::renderSectionHeading($icon, $title)
            : DocumentPortalTemplate::renderSectionHeading($icon, $title, $colorClass);

        $this->assertMarkup($html, $contains, $notContains);
    }

    // ============================================================
    // renderNavFooter TESTS
    // ============================================================

    /**
     * @return array<string, array{NavFooterLinks, list<string>, list<string>}>
     */
    public static function navFooterProvider(): array
    {
        return [
            'renders links' => [
                [
                    ['label' => 'Home', 'url' => '/'],
                    ['label' => 'Docs', 'url' => '/docs'],
                ],
                ['Home', 'Docs', "href='/'", "href='/docs'"],
                [],
            ],
            'renders icon' => [
                [['label' => 'Home', 'url' => '/', 'icon' => 'fa-home']],
                ['fas fa-home'],
                [],
            ],
            'omits icon when absent' => [
                [['label' => 'Home', 'url' => '/']],
                [],
                ['fas'],
            ],
            'applies btn class' => [
                [['label' => 'Home', 'url' => '/', 'btnClass' => 'btn-success']],
                ['btn btn-success'],
                [],
            ],
            // Color token regression row (issue #757)
            'uses default btn class' => [
                [['label' => 'Home', 'url' => '/']],
                ['btn btn-outline-primary'],
                [],
            ],
        ];
    }

    /**
     * @param NavFooterLinks $links
     * @param list<string>   $contains    Fragments the HTML must contain
     * @param list<string>   $notContains Fragments the HTML must not contain
     */
    #[DataProvider('navFooterProvider')]
    public function testRenderNavFooterRendersExpectedMarkup(array $links, array $contains, array $notContains): void
    {
        $html = DocumentPortalTemplate::renderNavFooter($links);

        $this->assertMarkup($html, $contains, $notContains);
    }

    public function testRenderNavFooterReturnsEmptyStringForEmptyArray(): void
    {
        $html = DocumentPortalTemplate::renderNavFooter([]);

        $this->assertSame('', $html);
    }

    public function testRenderNavFooterThrowsOnMissingLabel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore argument.type (intentional missing 'label' key; asserts the guard throws)
        DocumentPortalTemplate::renderNavFooter([
            ['url' => '/'],
        ]);
    }

    public function testRenderNavFooterThrowsOnMissingUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore argument.type (intentional missing 'url' key; asserts the guard throws)
        DocumentPortalTemplate::renderNavFooter([
            ['label' => 'Home'],
        ]);
    }

    /**
     * Assert that the HTML contains each fragment in $contains and none in $notContains.
     *
     * @param list<string> $contains    Fragments the HTML must contain
     * @param list<string> $notContains Fragments the HTML must not contain
     */
    private function assertMarkup(string $html, array $contains, array $notContains = []): void
    {
        foreach ($contains as $fragment) {
            $this->assertStringContainsString($fragment, $html);
        }
        foreach ($notContains as $fragment) {
            $this->assertStringNotContainsString($fragment, $html);
        }
    }
}
