<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the login gate around car history on the car details page.
 *
 * Covers app/owner/cars/details.php. History rows carry past owners' first
 * name and location, so the page must load them for a logged-in member only
 * (#2144).
 *
 * These assertions are source inspection only. details.php cannot be
 * require()'d from PHPUnit: it calls securePage(), reads $_GET, and renders a
 * full page through the site template, all of which need a real HTTP request
 * and a session. So this test proves where the history() call and the
 * history-only assets sit in the source. The rendered guest prompt is covered
 * by the Playwright tests instead.
 *
 * @author Elan Registry Development Team
 */
#[Group('fast')]
#[Group('unit')]
#[Group('car-actions')]
final class CarDetailsHistoryGateWiringTest extends TestCase
{
    /** Page path, relative to the repository root. */
    private const DETAILS_PAGE = 'app/owner/cars/details.php';

    /** The one gate that must precede every history read and history-only asset. */
    private const GATE = 'if ($user->isLoggedIn())';

    /**
     * Read the page's source text for wiring assertions.
     *
     * @param string $relativePath Path relative to the repository root
     * @return string The file contents
     */
    private function readPageSource(string $relativePath): string
    {
        $filePath = __DIR__ . '/../../../' . $relativePath;
        $this->assertFileExists($filePath, "Page file must exist: {$relativePath}");

        $content = file_get_contents($filePath);
        $this->assertIsString($content, "Page file must be readable: {$relativePath}");

        return $content;
    }

    /**
     * details.php loads the history exactly once, and only inside the login gate.
     *
     * A second call site would be a second, ungated read — the whole point of
     * #2144 is that there is one gated one.
     */
    public function testDetailsCallsCarHistoryOnceInsideLoginGate(): void
    {
        $content = $this->readPageSource(self::DETAILS_PAGE);

        $this->assertSame(
            1,
            substr_count($content, '$car->history()'),
            'details.php must call $car->history() exactly once, so there is only one gate to check'
        );

        $callOffset = strpos($content, '$car->history()');
        $this->assertIsInt($callOffset);

        $gateOffset = strrpos(substr($content, 0, $callOffset), self::GATE);
        $this->assertIsInt(
            $gateOffset,
            '$car->history() must be preceded by an ' . self::GATE . ' gate'
        );

        // The gate's block must still be open at the call: a closing brace on its
        // own line between them would mean the call sits after the block ends.
        $this->assertDoesNotMatchRegularExpression(
            '/\n\s*}\s*\n/',
            substr($content, $gateOffset, $callOffset - $gateOffset),
            '$car->history() sits after the login gate, but that block closes before the call'
        );
    }

    /**
     * The guest path initializes every history variable the template reads.
     *
     * Without these defaults a guest render would hit undefined variables in the
     * summary block rather than the login prompt.
     */
    public function testDetailsInitializesHistoryVariablesForGuests(): void
    {
        $content = $this->readPageSource(self::DETAILS_PAGE);

        $this->assertStringContainsString('$carHistory = [];', $content);
        $this->assertStringContainsString('$historyCount = 0;', $content);
    }

    /**
     * The history-only assets are emitted only for a member.
     *
     * highlightDifferences.js targets only #carHistoryTable and car_details.js
     * only builds that table and wires its toggle, so a guest who never receives
     * the table must not receive them either. The DataTables bundle is the same
     * story.
     */
    public function testHistoryOnlyAssetsAreGatedOnLogin(): void
    {
        $content = $this->readPageSource(self::DETAILS_PAGE);

        foreach (['car_details.min.js', 'highlightDifferences.min.js', 'datatables.min.js'] as $asset) {
            $assetOffset = strpos($content, $asset);
            $this->assertIsInt($assetOffset, "details.php must reference {$asset}");

            $gateOffset = strrpos(substr($content, 0, $assetOffset), self::GATE);
            $this->assertIsInt(
                $gateOffset,
                "{$asset} must be emitted only behind `" . self::GATE . '` — it is history-only'
            );

            // The nearest preceding gate must still be open at the asset: if the
            // block had already closed, an `endif;` would sit between the two and
            // the asset would in fact be emitted for everyone.
            $this->assertStringNotContainsString(
                'endif;',
                substr($content, $gateOffset, $assetOffset - $gateOffset),
                "{$asset} sits after `" . self::GATE . '` but that block closes before it, so the asset is not gated'
            );
        }
    }

    /**
     * The page tells every cache not to keep it.
     *
     * Since #2144 the page carries members-only history, so a shared cache that
     * kept a member's copy would serve that history to guests. PHP's session
     * default happens to send no-store today; the page sets it explicitly so it
     * does not depend on that ini setting.
     */
    public function testDetailsSendsPrivateNoStore(): void
    {
        $content = $this->readPageSource(self::DETAILS_PAGE);

        $headerOffset = strpos($content, "header('Cache-Control: private, no-store');");
        $this->assertIsInt($headerOffset, 'details.php must send Cache-Control: private, no-store');

        $securePageOffset = strpos($content, 'securePage($php_self)');
        $this->assertIsInt($securePageOffset);
        $this->assertGreaterThan(
            $securePageOffset,
            $headerOffset,
            'Send the header after securePage(), so a refused request is not affected'
        );
    }
}
