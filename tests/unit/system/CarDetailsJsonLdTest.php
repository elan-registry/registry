<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

/**
 * Schema.org JSON-LD gate for Issue #1371.
 *
 * app/owner/cars/details.php embeds a Schema.org `Car` JSON-LD block to help
 * search engines and AI crawlers understand car detail pages. The block is
 * placed in the page body (NOT `<head>`), after `elanregistry_prep.php` and
 * before `<div class="page-wrapper">`. The car loads before prep (#1960,
 * #1528), so the not-found redirect is a real 302 and the template header can
 * read the per-car `$pageTitle`, `$pageDescription` and `$pageCanonicalUrl`.
 *
 * The actual JSON-LD payload construction (bodyType mapping, the
 * Federal/Race → vehicleConfiguration exclusion, whitespace defense) lives in
 * `ElanRegistry\CarView::buildCarSchema()` and is behaviorally tested in
 * `tests/unit/classes/CarViewTest.php` — this class only guards the wiring:
 * that details.php still calls it, in the right place, and still encodes the
 * result safely.
 *
 * This test is a pure static-text scan: it reads the file's raw source via
 * file_get_contents() and never requires/executes it, so it needs no
 * database and no bootstrapped UserSpice environment.
 */
#[Group('system')]
#[Group('page-metadata')]
class CarDetailsJsonLdTest extends TestCase
{
    private const DETAILS_PAGE = 'app/owner/cars/details.php';

    private string $rootDir = '';
    private string $content = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->rootDir = dirname(__DIR__, 3);

        $filePath = $this->rootDir . '/' . self::DETAILS_PAGE;
        $this->assertFileExists($filePath, self::DETAILS_PAGE . ' must exist (Issue #1371)');

        $this->content = (string)file_get_contents($filePath);
    }

    public function testContainsJsonLdScriptTag(): void
    {
        $this->assertStringContainsString(
            '<script type="application/ld+json">',
            $this->content,
            self::DETAILS_PAGE . ' must embed a JSON-LD <script> block (Issue #1371)'
        );
    }

    public function testCallsCarViewBuildCarSchema(): void
    {
        $this->assertStringContainsString(
            'CarView::buildCarSchema(',
            $this->content,
            self::DETAILS_PAGE . ' must delegate JSON-LD payload construction to CarView::buildCarSchema() '
            . '(Issue #1371) — the actual field mapping is behaviorally tested in CarViewTest.php'
        );
    }

    public function testJsonEncodesWithHexSafetyFlags(): void
    {
        // JSON_HEX_TAG is the load-bearing flag here — it hex-encodes < and > so a
        // DB-sourced field containing "</script>" can't break out of the script
        // element. Guards against a future edit dropping the flags.
        foreach (['JSON_HEX_TAG', 'JSON_HEX_AMP', 'JSON_HEX_APOS', 'JSON_HEX_QUOT'] as $flag) {
            $this->assertStringContainsString(
                $flag,
                $this->content,
                self::DETAILS_PAGE . " must json_encode() the JSON-LD payload with $flag (Issue #1371)"
            );
        }
    }

    public function testJsonLdBlockFollowsCarDataAssignment(): void
    {
        $carDataMatches = [];
        preg_match(
            '/\$carData\s*=\s*\$car->data\(\);/',
            $this->content,
            $carDataMatches,
            PREG_OFFSET_CAPTURE
        );
        $this->assertNotEmpty(
            $carDataMatches,
            self::DETAILS_PAGE . ' must contain the $carData = $car->data(); assignment to check JSON-LD position (Issue #1371)'
        );

        $scriptTagPosition = strpos($this->content, '<script type="application/ld+json">');
        $this->assertNotFalse(
            $scriptTagPosition,
            self::DETAILS_PAGE . ' must contain the JSON-LD <script> block to check its position (Issue #1371)'
        );

        $this->assertGreaterThan(
            $carDataMatches[0][1],
            $scriptTagPosition,
            self::DETAILS_PAGE . ' JSON-LD block must appear AFTER $carData is loaded — the car ID is not '
            . 'resolved before that point (Issue #1371)'
        );
    }

    public function testJsonLdBlockFollowsElanregistryPrep(): void
    {
        // The JSON-LD block is body output. Before prep it would print ahead
        // of <html>, and the not-found redirect could not send a header.
        $prepPosition = strpos($this->content, 'elanregistry_prep.php');
        $scriptTagPosition = strpos($this->content, '<script type="application/ld+json">');
        $this->assertNotFalse($prepPosition, self::DETAILS_PAGE . ' must require elanregistry_prep.php');
        $this->assertNotFalse($scriptTagPosition, self::DETAILS_PAGE . ' must contain the JSON-LD <script> block');
        $this->assertGreaterThan(
            $prepPosition,
            $scriptTagPosition,
            self::DETAILS_PAGE . ' JSON-LD block must print after elanregistry_prep.php (#1960)'
        );
    }

    public function testCarLoadsAndBuildsMetadataBetweenSecurePageAndPrep(): void
    {
        // #1960, #1528: the template header reads the per-car title,
        // description and canonical, so they must be set before prep, and
        // securePage() must still run before the car loads.
        $needles = [
            "users/init.php'",
            'securePage(',
            'new Car(',
            'CarView::buildPageTitle(',
            'CanonicalUrl::build(',
            "'usersc/includes/elanregistry_prep.php'",
        ];

        $previous = -1;
        $previousNeedle = '(start of file)';
        foreach ($needles as $needle) {
            $position = strpos($this->content, $needle);
            $this->assertNotFalse($position, self::DETAILS_PAGE . " must contain {$needle}");
            $this->assertGreaterThan(
                $previous,
                $position,
                self::DETAILS_PAGE . " must contain {$needle} after {$previousNeedle} (#1960, #1528)"
            );
            $previous = $position;
            $previousNeedle = $needle;
        }
    }

    public function testNotFoundRedirectComesBeforePerCarTitle(): void
    {
        // #1528: a car that does not load keeps the redirect and gets no
        // per-car title.
        $redirectPosition = strpos($this->content, "Redirect::to(\$us_url_root . 'app/owner/cars/index.php')");
        $titlePosition = strpos($this->content, '$pageTitle = htmlspecialchars(CarView::buildPageTitle(');
        $this->assertNotFalse($redirectPosition, self::DETAILS_PAGE . ' must redirect a car that does not load');
        $this->assertNotFalse($titlePosition, self::DETAILS_PAGE . ' must set the per-car $pageTitle');
        $this->assertGreaterThan(
            $redirectPosition,
            $titlePosition,
            self::DETAILS_PAGE . ' must redirect before it sets the per-car $pageTitle (#1528)'
        );
    }

    public function testBuildCarSchemaUsesPageCanonicalUrl(): void
    {
        // #1960: the JSON-LD url must equal rel=canonical, which head_tags.php
        // reads from $pageCanonicalUrl.
        $this->assertMatchesRegularExpression(
            '/CarView::buildCarSchema\(\s*\$carData\s*,\s*\$pageCanonicalUrl\s*\)/',
            $this->content,
            self::DETAILS_PAGE . ' must pass $pageCanonicalUrl to CarView::buildCarSchema() (#1960)'
        );
    }

    public function testHandlesJsonEncodeFailureWithoutEmittingAnEmptyScriptTag(): void
    {
        // json_encode() returns false on malformed UTF-8, which legacy chassis/color/series
        // data (predating the current input-sanitization pipeline — see CarView::buildCarSchema()'s
        // docblock) could in principle contain. Guards against a future edit that drops this
        // check and silently emits an empty, invalid <script type="application/ld+json"></script>.
        $this->assertStringContainsString(
            '$carSchemaJson === false',
            $this->content,
            self::DETAILS_PAGE . ' must check for json_encode() failure before emitting the JSON-LD block (Issue #1371)'
        );
        $this->assertStringContainsString(
            'LogCategories::LOG_CATEGORY_SYSTEM_ERROR',
            $this->content,
            self::DETAILS_PAGE . ' must log a json_encode() failure via LOG_CATEGORY_SYSTEM_ERROR, matching this '
            . 'file\'s existing convention for malformed legacy data (Issue #1371)'
        );
    }
}
