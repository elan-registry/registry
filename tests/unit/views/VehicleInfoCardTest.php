<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rendered tests for the Sold row of app/views/cars/_vehicle_info_card.php
 * (issue #1900).
 *
 * Each test includes the card under ob_start() and parses the output with
 * DOMDocument/DOMXPath, so the assertions read the DOM and not the HTML text.
 */
#[Group('fast')]
#[Group('unit')]
final class VehicleInfoCardTest extends TestCase
{
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
