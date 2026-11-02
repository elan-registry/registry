<?php

declare(strict_types=1);

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rendered tests for the Build Date row of app/views/cars/_factory_data_card.php
 * (issue #2495). The build-date parse moved into the card from details.php and
 * account.php, so a broken parse would hide the row on both pages.
 *
 * Each test includes the card under ob_start() and parses the output with
 * DOMDocument/DOMXPath, so the assertions read the DOM and not the HTML text.
 */
#[Group('fast')]
#[Group('unit')]
final class FactoryDataCardTest extends TestCase
{
    private const CARD = __DIR__ . '/../../../app/views/cars/_factory_data_card.php';

    private const BUILD_DATE_DD = '//dt[normalize-space(.)="Build Date"]/following-sibling::dd[1]';

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
     * Include the card once for each set of variables, all in one scope, and
     * return each output. One scope matches the per-car foreach in
     * usersc/account.php, where a value from one car could carry over to the
     * next.
     *
     * @param list<array<string, mixed>> $renders
     * @return list<string>
     */
    private static function renderInOneScope(array $renders): array
    {
        // A PHP warning or deprecation inside the view is a defect, not noise:
        // PHPUnit would only list it as a warning and the test would still pass.
        set_error_handler(static function (int $severity, string $message, string $errFile, int $line): never {
            throw new ErrorException($message, 0, $severity, $errFile, $line);
        });
        $outputs = [];
        try {
            foreach ($renders as $vars) {
                extract($vars, EXTR_OVERWRITE);
                ob_start();
                try {
                    include self::CARD;
                } finally {
                    $outputs[] = (string) ob_get_clean();
                }
            }
        } finally {
            restore_error_handler();
        }

        return $outputs;
    }

    /** @param array<string, mixed> $vars */
    private static function render(array $vars): string
    {
        return self::renderInOneScope([$vars])[0];
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

    private function nodeCount(DOMXPath $xp, string $query): int
    {
        $nodes = $xp->query($query);
        $this->assertNotFalse($nodes);

        return $nodes->length;
    }

    /**
     * Build a factory row with every field the card reads.
     *
     * @param array<string, mixed> $overrides
     */
    private static function factoryRow(array $overrides = []): object
    {
        return (object) array_merge([
            'year' => 1968, 'month' => 3, 'batch' => '12', 'type' => '36',
            'serial' => '7110123456', 'suffix' => 'L', 'engineletter' => 'LP',
            'enginenumber' => '1234', 'gearbox' => 'S', 'color' => 'Red',
            'builddate' => '1968-03-14', 'note' => '',
        ], $overrides);
    }

    public function testFactoryCardWithValidBuildDateShowsLongDate(): void
    {
        $xp = self::dom(self::render(['factoryData' => self::factoryRow(), 'carId' => 42]));

        $dd = $xp->query(self::BUILD_DATE_DD);
        $this->assertNotFalse($dd);
        $this->assertSame(1, $dd->length, 'A valid builddate must render one Build Date row');
        $this->assertSame('March 14, 1968', trim((string) $dd->item(0)?->textContent));
        $this->assertSame([], self::logEntries());
    }

    public function testFactoryCardWithEmptyBuildDateHidesRowAndDoesNotLog(): void
    {
        $xp = self::dom(self::render(['factoryData' => self::factoryRow(['builddate' => '']), 'carId' => 42]));

        $this->assertSame(0, $this->nodeCount($xp, self::BUILD_DATE_DD));
        $this->assertSame(1, $this->nodeCount($xp, '//dt[normalize-space(.)="Production Year"]'), 'The rest of the card must still render');
        $this->assertSame([], self::logEntries());
    }

    public function testFactoryCardWithMalformedBuildDateHidesRowAndLogs(): void
    {
        $xp = self::dom(self::render(['factoryData' => self::factoryRow(['builddate' => 'not-a-date']), 'carId' => 42]));

        $this->assertSame(0, $this->nodeCount($xp, self::BUILD_DATE_DD));
        $this->assertSame(1, $this->nodeCount($xp, '//dt[normalize-space(.)="Production Year"]'), 'A bad builddate must not stop the card');
        $this->assertCount(1, self::logEntries());
        $this->assertSame(LogCategories::LOG_CATEGORY_SYSTEM_ERROR, self::logEntries()[0]['category']);
        $this->assertStringContainsString('car ID 42', (string) self::logEntries()[0]['message']);
        $this->assertStringContainsString('not-a-date', (string) self::logEntries()[0]['message']);
    }

    public function testFactoryCardWithNullDataDrawsNothingAndLogsCarId(): void
    {
        $html = self::render(['factoryData' => null, 'carId' => 42]);

        $this->assertSame('', trim($html));
        $this->assertCount(1, self::logEntries());
        $this->assertSame(LogCategories::LOG_CATEGORY_SYSTEM_ERROR, self::logEntries()[0]['category']);
        $this->assertStringContainsString('car ID 42', (string) self::logEntries()[0]['message']);
    }

    public function testFactoryCardInALoopDoesNotCarryBuildDateToTheNextCar(): void
    {
        [$first, $second] = self::renderInOneScope([
            ['factoryData' => self::factoryRow(), 'carId' => 1],
            ['factoryData' => self::factoryRow(['builddate' => '']), 'carId' => 2],
        ]);

        $this->assertSame(1, $this->nodeCount(self::dom($first), self::BUILD_DATE_DD));
        $this->assertSame(0, $this->nodeCount(self::dom($second), self::BUILD_DATE_DD), 'The second car has no builddate, so it must not show the first car\'s date');
    }
}
