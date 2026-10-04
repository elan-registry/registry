<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rendered tests for app/views/cars/_status_badges.php and the Sold row of
 * app/views/cars/_vehicle_info_card.php (issue #1900).
 *
 * Each test includes the partial under ob_start() and parses the output with
 * DOMDocument/DOMXPath, so the assertions read the DOM and not the HTML text.
 */
#[Group('fast')]
#[Group('unit')]
final class StatusBadgesPartialTest extends TestCase
{
    private const PARTIAL = __DIR__ . '/../../../app/views/cars/_status_badges.php';
    private const CARD = __DIR__ . '/../../../app/views/cars/_vehicle_info_card.php';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['mockLogEntries'] = [];
    }

    /** @return list<array<string, mixed>> */
    private static function logEntries(): array
    {
        $entries = $GLOBALS['mockLogEntries'] ?? [];
        self::assertIsArray($entries);

        return array_values($entries);
    }

    /**
     * Include a view file with the given variables and return its output.
     *
     * @param array<string, mixed> $vars
     */
    private static function render(string $file, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        // A PHP warning or deprecation inside the view is a defect, not noise:
        // PHPUnit would only list it as a warning and the test would still pass.
        set_error_handler(static function (int $severity, string $message, string $errFile, int $line): never {
            throw new ErrorException($message, 0, $severity, $errFile, $line);
        });
        ob_start();
        try {
            include $file;
        } finally {
            $html = (string) ob_get_clean();
            restore_error_handler();
        }

        return $html;
    }

    private static function dom(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><html><body><div id="root">' . $html . '</div></body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($doc);
    }

    /** @return DOMElement */
    private function firstElement(DOMXPath $xp, string $query): DOMElement
    {
        $nodes = $xp->query($query);
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, "No node for {$query}");
        $node = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $node);

        return $node;
    }

    private function nodeCount(DOMXPath $xp, string $query): int
    {
        $nodes = $xp->query($query);
        $this->assertNotFalse($nodes);

        return $nodes->length;
    }

    // ------------------------------------------------------------------
    // Stamp badge
    // ------------------------------------------------------------------

    public function test_stamp_soldBadge_hasClassesTooltipAndTabindex(): void
    {
        $xp = self::dom(self::render(self::PARTIAL, ['badgeKeys' => ['sold'], 'badgeStyle' => 'stamp']));

        $badge = $this->firstElement($xp, '//*[@id="root"]/span');
        $classes = preg_split('/\s+/', $badge->getAttribute('class'));
        $this->assertIsArray($classes);
        $this->assertContains('er-badge', $classes);
        $this->assertContains('er-badge--sold', $classes);
        $this->assertContains('er-badge--stamp', $classes);
        $this->assertSame('tooltip', $badge->getAttribute('data-bs-toggle'));
        $this->assertSame('0', $badge->getAttribute('tabindex'));
        $this->assertSame(
            'Reported sold by the owner — the car and its history stay in the registry.',
            $badge->getAttribute('data-bs-title')
        );
        $this->assertSame('Sold', trim($badge->textContent));
    }

    public function test_stamp_verifiedBadge_textExcludingIconIsVerifiedAndIconIsAriaHidden(): void
    {
        $xp = self::dom(self::render(self::PARTIAL, ['badgeKeys' => ['verified'], 'badgeStyle' => 'stamp']));

        $badge = $this->firstElement($xp, '//*[@id="root"]/span[contains(@class,"er-badge--verified")]');
        $this->assertSame(1, $this->nodeCount($xp, '//span[contains(@class,"er-badge--verified")]/span[@aria-hidden="true"]'));
        $icon = $this->firstElement($xp, '//span[contains(@class,"er-badge--verified")]/span[@aria-hidden="true"]');
        $this->assertSame('✓', $icon->textContent);

        // Text that a screen reader reads: the badge text without the aria-hidden span.
        $readable = $xp->evaluate('normalize-space(string(//span[contains(@class,"er-badge--verified")]/text()))');
        $this->assertSame('Verified', $readable);
        $this->assertNotSame('', $badge->getAttribute('data-bs-title'));
    }

    public function test_verifiedAndNewTooltips_matchTheDefinedWording(): void
    {
        $xp = self::dom(self::render(
            self::PARTIAL,
            ['badgeKeys' => ['new', 'verified'], 'badgeStyle' => 'flat']
        ));

        $this->assertSame(
            'Added to the registry in the last 90 days, or one of the 5 newest cars.',
            $this->firstElement($xp, '//span[contains(@class,"er-badge--new")]')->getAttribute('data-bs-title')
        );
        $this->assertSame(
            "The owner confirmed this car's details within the last year.",
            $this->firstElement($xp, '//span[contains(@class,"er-badge--verified")]')->getAttribute('data-bs-title')
        );
    }

    public function test_stamp_noTitleNoAriaLabelOnAnyBadge(): void
    {
        $xp = self::dom(self::render(
            self::PARTIAL,
            ['badgeKeys' => ['new', 'sold'], 'badgeStyle' => 'stamp']
        ));

        $this->assertSame(2, $this->nodeCount($xp, '//span[contains(@class,"er-badge")]'));
        $this->assertSame(0, $this->nodeCount($xp, '//*[@title]'));
        $this->assertSame(0, $this->nodeCount($xp, '//*[@aria-label]'));
    }

    public function test_everyBadge_hasTooltipTabindexAndNonEmptyTitle(): void
    {
        $xp = self::dom(self::render(
            self::PARTIAL,
            ['badgeKeys' => ['new', 'sold', 'verified'], 'badgeStyle' => 'flat']
        ));

        $badges = $xp->query('//span[contains(@class,"er-badge")]');
        $this->assertNotFalse($badges);
        $this->assertSame(3, $badges->length);
        foreach ($badges as $badge) {
            $this->assertInstanceOf(DOMElement::class, $badge);
            $this->assertSame('tooltip', $badge->getAttribute('data-bs-toggle'));
            $this->assertSame('0', $badge->getAttribute('tabindex'));
            $this->assertNotSame('', trim($badge->getAttribute('data-bs-title')));
        }
    }

    // ------------------------------------------------------------------
    // Flat style, and empty or unusable input
    // ------------------------------------------------------------------

    public function test_flat_hasNoStampClass(): void
    {
        $xp = self::dom(self::render(self::PARTIAL, ['badgeKeys' => ['sold'], 'badgeStyle' => 'flat']));

        $this->assertSame(1, $this->nodeCount($xp, '//span[contains(@class,"er-badge--sold")]'));
        $this->assertSame(0, $this->nodeCount($xp, '//*[contains(@class,"er-badge--stamp")]'));
    }

    public function test_defaultStyle_isFlat(): void
    {
        $xp = self::dom(self::render(self::PARTIAL, ['badgeKeys' => ['sold']]));

        $this->assertSame(1, $this->nodeCount($xp, '//span[contains(@class,"er-badge--sold")]'));
        $this->assertSame(0, $this->nodeCount($xp, '//*[contains(@class,"er-badge--stamp")]'));
    }

    public function test_emptyList_rendersNothing(): void
    {
        $html = self::render(self::PARTIAL, ['badgeKeys' => [], 'badgeStyle' => 'stamp']);

        $this->assertSame('', trim($html));
    }

    public function test_noVariables_rendersNothing(): void
    {
        $this->assertSame('', trim(self::render(self::PARTIAL, [])));
    }

    public function test_unknownKey_rendersNothing(): void
    {
        $html = self::render(self::PARTIAL, ['badgeKeys' => ['bogus', 'SOLD', ''], 'badgeStyle' => 'stamp']);

        $this->assertSame('', trim($html));
    }

    public function test_unknownKeyBetweenKnownKeys_isSkippedAndOrderKept(): void
    {
        $xp = self::dom(self::render(
            self::PARTIAL,
            ['badgeKeys' => ['sold', 'bogus', 'verified'], 'badgeStyle' => 'flat']
        ));

        $badges = $xp->query('//span[contains(@class,"er-badge")]');
        $this->assertNotFalse($badges);
        $this->assertSame(2, $badges->length);
        $first = $badges->item(0);
        $second = $badges->item(1);
        $this->assertInstanceOf(DOMElement::class, $first);
        $this->assertInstanceOf(DOMElement::class, $second);
        $this->assertStringContainsString('er-badge--sold', $first->getAttribute('class'));
        $this->assertStringContainsString('er-badge--verified', $second->getAttribute('class'));
    }

    /** @return array<string, array{mixed}> */
    public static function wrongTypedBadgeKeysProvider(): array
    {
        return [
            'string'        => ['sold'],
            'int'           => [7],
            'bool true'     => [true],
            'object'        => [new stdClass()],
        ];
    }

    #[DataProvider('wrongTypedBadgeKeysProvider')]
    public function test_wrongTypedBadgeKeys_rendersNothingAndLogsOnce(mixed $badgeKeys): void
    {
        $html = self::render(self::PARTIAL, ['badgeKeys' => $badgeKeys, 'badgeStyle' => 'stamp']);

        $this->assertSame('', trim($html));
        $this->assertCount(1, self::logEntries());
        $this->assertSame(
            \ElanRegistry\LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            self::logEntries()[0]['category']
        );
        $this->assertStringContainsString('$badgeKeys', (string) self::logEntries()[0]['message']);
    }

    public function test_arrayBadgeKeys_doNotLog(): void
    {
        self::render(self::PARTIAL, ['badgeKeys' => ['sold'], 'badgeStyle' => 'stamp']);
        self::render(self::PARTIAL, []);

        $this->assertSame([], self::logEntries());
    }

    public function test_partial_unsetsItsInputVariables_soASecondIncludeDrawsNothing(): void
    {
        // Two includes in one function scope, as in a page: the second include
        // sets no variables, so it must not reuse the keys of the first.
        $renderTwice = static function (string $file): array {
            set_error_handler(static function (int $severity, string $message, string $errFile, int $line): never {
                throw new ErrorException($message, 0, $severity, $errFile, $line);
            });
            try {
                $badgeKeys = ['sold'];
                $badgeStyle = 'stamp';
                ob_start();
                include $file;
                $first = (string) ob_get_clean();
                $leftOver = array_values(array_intersect(
                    ['badgeKeys', 'badgeStyle', 'badgeDefs'],
                    array_keys(get_defined_vars())
                ));
                ob_start();
                include $file;
                $second = (string) ob_get_clean();
            } finally {
                restore_error_handler();
            }

            return [$first, $second, $leftOver];
        };

        [$first, $second, $leftOver] = $renderTwice(self::PARTIAL);

        $this->assertStringContainsString('er-badge--sold', $first);
        $this->assertSame([], $leftOver, 'The partial must unset its input variables');
        $this->assertSame('', trim($second));
    }

    public function test_nonStringKeysInsideList_areSkipped(): void
    {
        $html = self::render(
            self::PARTIAL,
            ['badgeKeys' => [1, null, ['sold'], new stdClass()], 'badgeStyle' => 'flat']
        );

        $this->assertSame('', trim($html));
    }

    public function test_wrongTypedBadgeDefs_rendersNothingWithoutError(): void
    {
        $html = self::render(
            self::PARTIAL,
            ['badgeKeys' => ['sold'], 'badgeStyle' => 'flat', 'badgeDefs' => 'nope']
        );

        $this->assertSame('', trim($html));
    }

    // ------------------------------------------------------------------
    // Escaping, through the $badgeDefs seam
    // ------------------------------------------------------------------

    public function test_hostileTooltip_isEscapedAndRoundTrips(): void
    {
        $hostile = '<script>alert("x")</script> "quoted" & \'single\' &amp; done';
        $xp = self::dom(self::render(self::PARTIAL, [
            'badgeKeys'  => ['sold'],
            'badgeStyle' => 'stamp',
            'badgeDefs'  => ['sold' => ['label' => 'Sold', 'tooltip' => $hostile, 'tone' => 'sold', 'icon' => null]],
        ]));

        $this->assertSame(0, $this->nodeCount($xp, '//script'));
        $badge = $this->firstElement($xp, '//span[contains(@class,"er-badge--sold")]');
        $this->assertSame($hostile, $badge->getAttribute('data-bs-title'));
        $this->assertSame('Sold', trim($badge->textContent));
    }

    public function test_hostileLabelAndIcon_areEscapedAsText(): void
    {
        $xp = self::dom(self::render(self::PARTIAL, [
            'badgeKeys'  => ['verified'],
            'badgeStyle' => 'flat',
            'badgeDefs'  => ['verified' => [
                'label'   => '<script>alert(1)</script> & "x"',
                'tooltip' => 'tip',
                'tone'    => 'verified',
                'icon'    => '<img src=x onerror=alert(1)>',
            ]],
        ]));

        $this->assertSame(0, $this->nodeCount($xp, '//script'));
        $this->assertSame(0, $this->nodeCount($xp, '//img'));
        $icon = $this->firstElement($xp, '//span[@aria-hidden="true"]');
        $this->assertSame('<img src=x onerror=alert(1)>', $icon->textContent);
        $badge = $this->firstElement($xp, '//span[contains(@class,"er-badge")]');
        $this->assertStringContainsString('<script>alert(1)</script> & "x"', $badge->textContent);
    }

    public function test_hostileTone_cannotBreakOutOfClassAttribute(): void
    {
        $xp = self::dom(self::render(self::PARTIAL, [
            'badgeKeys'  => ['sold'],
            'badgeStyle' => 'flat',
            'badgeDefs'  => ['sold' => [
                'label' => 'Sold', 'tooltip' => 't', 'icon' => null,
                'tone'  => 'x" onmouseover="alert(1)',
            ]],
        ]));

        $badge = $this->firstElement($xp, '//span[contains(@class,"er-badge")]');
        $this->assertFalse($badge->hasAttribute('onmouseover'));
        $this->assertStringContainsString('onmouseover="alert(1)', $badge->getAttribute('class'));
    }

    // ------------------------------------------------------------------
    // _vehicle_info_card.php — Sold row (AC4)
    // ------------------------------------------------------------------

    /** @return object */
    private static function carData(): object
    {
        return (object) [
            'year' => 1971, 'series' => 'S4', 'variant' => 'SE', 'type' => 'FHC',
            'chassis' => '7110123456', 'color' => 'Red', 'engine' => 'Twin Cam',
            'comments' => '', 'ctime' => '2020-01-02 03:04:05', 'mtime' => '2021-02-03 04:05:06',
        ];
    }

    public function test_vehicleInfoCard_withSoldDate_showsSoldStampAndLongDate(): void
    {
        $xp = self::dom(self::render(self::CARD, [
            'carData'      => self::carData(),
            'purchaseDate' => null,
            'soldDate'     => new DateTime('2025-03-14'),
        ]));

        $this->assertSame(1, $this->nodeCount($xp, '//dt[normalize-space(.)="Sold"]'));
        $this->assertSame(0, $this->nodeCount($xp, '//dt[contains(.,"Sold Date")]'));
        $dd = $this->firstElement($xp, '//dt[normalize-space(.)="Sold"]/following-sibling::dd[1]');
        $stamp = $xp->query('.//span[contains(@class,"er-badge--sold") and contains(@class,"er-badge--stamp")]', $dd);
        $this->assertNotFalse($stamp);
        $this->assertSame(1, $stamp->length);
        $this->assertStringContainsString('March 14, 2025', $dd->textContent);
    }

    public function test_vehicleInfoCard_withoutSoldDate_hasNoSoldRowAndNoBadge(): void
    {
        $xp = self::dom(self::render(self::CARD, [
            'carData'      => self::carData(),
            'purchaseDate' => new DateTime('2019-06-01'),
            'soldDate'     => null,
        ]));

        $this->assertSame(1, $this->nodeCount($xp, '//dt[normalize-space(.)="Purchase Date"]'));
        $this->assertSame(0, $this->nodeCount($xp, '//dt[contains(.,"Sold")]'));
        $this->assertSame(0, $this->nodeCount($xp, '//*[contains(@class,"er-badge")]'));
    }

    public function test_vehicleInfoCard_withNoDates_hasNoSoldRow(): void
    {
        $xp = self::dom(self::render(self::CARD, ['carData' => self::carData()]));

        $this->assertSame(0, $this->nodeCount($xp, '//dt[contains(.,"Sold")]'));
        $this->assertSame(0, $this->nodeCount($xp, '//*[contains(@class,"er-badge")]'));
        $this->assertSame(1, $this->nodeCount($xp, '//dt[normalize-space(.)="Chassis"]'));
    }

    public function test_vehicleInfoCard_withoutCarData_rendersNothing(): void
    {
        $this->assertSame('', trim(self::render(self::CARD, ['soldDate' => new DateTime('2025-03-14')])));
        $this->assertCount(1, self::logEntries());
        $this->assertStringContainsString('null', (string) self::logEntries()[0]['message']);
    }

    public function test_vehicleInfoCard_withNonObjectCarData_logsOnce(): void
    {
        self::render(self::CARD, ['carData' => ['not' => 'an object']]);

        $this->assertCount(1, self::logEntries());
        $this->assertSame(
            \ElanRegistry\LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            self::logEntries()[0]['category']
        );
        $this->assertStringContainsString('array', (string) self::logEntries()[0]['message']);
    }

    public function test_vehicleInfoCard_withCarData_doesNotLog(): void
    {
        self::render(self::CARD, ['carData' => self::carData(), 'soldDate' => new DateTime('2025-03-14')]);

        $this->assertSame([], self::logEntries());
    }
}
