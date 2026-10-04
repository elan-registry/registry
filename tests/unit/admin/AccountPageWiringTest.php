<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for usersc/account.php's DB-failure handling around
 * `new Owner($ownerId)`.
 *
 * Covers:
 * - usersc/account.php (the try/catch around Owner construction/find())
 * - usersc/account.php, app/owner/cars/details.php, and
 *   app/views/cars/_verify_landing.php (solddate through CarBadges::parseSoldDate())
 *
 * The page cannot be require()'d from PHPUnit for a full behavioral test:
 * it renders a complete HTML page inline (no isolated return path), and
 * Owner::find() only throws OwnerDatabaseException on a genuine \Throwable
 * from the DB layer — there is no input- or environment-driven way to
 * trigger that deterministically over a real request without an actual DB
 * fault. Following the established source-text-assertion precedent (#1505
 * PR A), the catch block is instead asserted against the file's source
 * text.
 *
 * The throw condition itself — a DB/query failure surfacing as
 * OwnerDatabaseException from Owner::find() — is already exercised against
 * a stubbed DatabaseInterface in
 * tests/unit/classes/OwnerProfileTest.php::testFindThrowsOwnerDatabaseExceptionOnDatabaseError();
 * this test covers only account.php's handling of that exception once thrown.
 *
 * @author Elan Registry Development Team
 */
#[Group('fast')]
#[Group('unit')]
#[Group('owner-account')]
final class AccountPageWiringTest extends TestCase
{
    /** Endpoint path, relative to the repository root. */
    private const ACCOUNT_ENDPOINT = 'usersc/account.php';

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Read an endpoint's source text for wiring assertions.
     *
     * @param string $relativePath Path relative to the repository root
     * @return string The endpoint file contents
     */
    private function readEndpointSource(string $relativePath): string
    {
        $filePath = __DIR__ . '/../../../' . $relativePath;
        $this->assertFileExists($filePath, "Endpoint file must exist: {$relativePath}");

        $content = file_get_contents($filePath);
        $this->assertIsString($content, "Endpoint file must be readable: {$relativePath}");

        return $content;
    }

    // =========================================================================
    // account.php — DB-failure handling around new Owner($ownerId)
    // (source inspection)
    // =========================================================================

    /**
     * A DB failure inside Owner::find() (triggered from the constructor)
     * must be caught, logged under LOG_CATEGORY_DATABASE_ERROR, and must
     * leave $owner as a valid object (so getProfileQualityScore() further
     * down the file doesn't fatal on an undefined variable) and $ownerData
     * as null (so the existing `if ($ownerData !== null)` guard degrades
     * the page gracefully instead of propagating the exception).
     *
     * Source inspection: reaching this branch needs a genuine \Throwable
     * from the DB layer, which cannot be produced deterministically from a
     * PHPUnit process or a real HTTP request (see class docblock). The
     * assertions below are ordered and scoped so that moving the catch
     * block, changing the caught type, dropping the log call, or leaving
     * $owner/$ownerData unset in the catch body would each fail a specific
     * assertion rather than passing on a loose substring match.
     */
    public function testOwnerConstructionDbFailureIsCaughtLoggedAndDegradesGracefully(): void
    {
        $content = $this->readEndpointSource(self::ACCOUNT_ENDPOINT);

        $this->assertStringContainsString(
            'try {',
            $content,
            'The Owner construction must be wrapped in a try block'
        );
        $this->assertStringContainsString(
            '$owner     = new Owner($ownerId);',
            $content,
            'The endpoint must construct Owner inside the try block'
        );

        // Isolate the catch block that immediately follows the construction,
        // so assertions below can't accidentally match an unrelated catch
        // block elsewhere in the file (e.g. the CarDatabaseException catch
        // around Car::findByOwner()).
        $tryStart = strpos($content, '$owner     = new Owner($ownerId);');
        $this->assertIsInt($tryStart, 'Could not locate the Owner construction call site');
        $catchBlock = substr($content, $tryStart, 700);

        $this->assertMatchesRegularExpression(
            '/}\s*catch\s*\(\\\\?(ElanRegistry\\\\Exceptions\\\\)?OwnerDatabaseException\s+\$e\)\s*{/',
            $catchBlock,
            'The Owner construction must be caught with OwnerDatabaseException specifically'
        );

        // Isolate just the body of this catch block (up to its own closing
        // brace) so the following assertions can't accidentally match
        // content from a later, unrelated block.
        $catchBodyStart = strpos($catchBlock, 'OwnerDatabaseException $e) {');
        $this->assertIsInt($catchBodyStart, 'Could not locate the OwnerDatabaseException catch body');
        $catchBodyEnd = strpos($catchBlock, "\n}\n", $catchBodyStart);
        $this->assertIsInt($catchBodyEnd, 'Could not locate the end of the OwnerDatabaseException catch body');
        $catchBody = substr($catchBlock, $catchBodyStart, $catchBodyEnd - $catchBodyStart);

        $this->assertStringContainsString(
            'LOG_CATEGORY_DATABASE_ERROR',
            $catchBody,
            'The catch block must log the failure under LOG_CATEGORY_DATABASE_ERROR'
        );
        $this->assertStringContainsString(
            '$owner     = new Owner();',
            $catchBody,
            'The catch block must leave $owner as a valid (unloaded) Owner instance, not undefined'
        );
        $this->assertStringContainsString(
            '$ownerData = null;',
            $catchBody,
            'The catch block must set $ownerData to null so the existing null-guard degrades the page'
        );
    }

    // =========================================================================
    // account.php — status badges in the hero <h3> (#1900, source inspection)
    // =========================================================================

    /**
     * The account page cannot be required from PHPUnit (see class docblock), so
     * the wiring is checked in the source text. The rendered output of the
     * partial itself is covered by tests/unit/views/StatusBadgesPartialTest.php
     * and the key logic by tests/unit/cars/CarBadgesTest.php.
     *
     * The assertions are scoped to the hero heading, so a badge include moved
     * out of the <h3>, a changed key source, or a changed style fails here.
     */
    public function testHeroHeadingIncludesStatusBadgesPartialWithStampStyle(): void
    {
        $content = $this->readEndpointSource(self::ACCOUNT_ENDPOINT);

        $this->assertStringContainsString('use ElanRegistry\\Car\\CarBadges;', $content);

        $matched = preg_match(
            '/<h3 class="mb-2 card-header-er-primary-text">(.*?)<\/h3>/s',
            $content,
            $matches
        );
        $this->assertSame(1, $matched, 'The hero <h3> must exist in account.php');
        $heading = $matches[1];

        $this->assertMatchesRegularExpression(
            '/\$badgeKeys\s+=\s+CarBadges::forCar\(\$carData\);/',
            $heading,
            'The hero heading must take its badge keys from CarBadges::forCar($carData)'
        );
        $this->assertMatchesRegularExpression(
            "/\\\$badgeStyle\\s+=\\s+'stamp';/",
            $heading,
            'The hero heading must use the stamp style'
        );
        $this->assertMatchesRegularExpression(
            '/include\s+\$abs_us_root\s*\.\s*\$us_url_root\s*\.\s*\'app\/views\/cars\/_status_badges\.php\';/',
            $heading,
            'The hero heading must include the _status_badges.php partial'
        );

        // The call must not pass the NEW flag: NEW is a cars-list concept.
        $this->assertStringNotContainsString('forCar($carData, true)', $content);
    }

    public function testStatusBadgesPartialIncludeAppearsOnlyInsideHeroHeading(): void
    {
        $content = $this->readEndpointSource(self::ACCOUNT_ENDPOINT);

        $this->assertSame(
            1,
            substr_count($content, '_status_badges.php'),
            'The account.php source must name the status badges partial once, in the hero <h3>. '
            . 'The Vehicle Information card includes the partial again at render time for its Sold row.'
        );
    }

    /**
     * A bad purchasedate or builddate must not fail silently: each catch logs
     * the value, like app/owner/cars/details.php does. solddate does not use
     * a try/catch: see testSoldDateUsesCarBadgesParseSoldDate().
     *
     * @return array<string, array{string, string}>
     */
    public static function dateParseCatchProvider(): array
    {
        return [
            'purchasedate' => ['$purchaseDate = new DateTime($carData->purchasedate);', 'Invalid purchase date format'],
            'builddate'    => ['$buildDate = new DateTime($factoryData->builddate);', 'Invalid build date format'],
        ];
    }

    #[DataProvider('dateParseCatchProvider')]
    public function testDateParseCatchLogs(string $tryStatement, string $logMessage): void
    {
        $content = $this->readEndpointSource(self::ACCOUNT_ENDPOINT);

        $matched = preg_match(
            '/' . preg_quote($tryStatement, '/') . '\s*\}\s*catch\s*\(\\\\Exception\)\s*\{(.*?)\}/s',
            $content,
            $matches
        );
        $this->assertSame(1, $matched, "account.php must wrap {$tryStatement} in a try/catch");
        $this->assertMatchesRegularExpression(
            '/logger\(\$ownerId,\s*LogCategories::LOG_CATEGORY_SYSTEM_ERROR,\s*"' . preg_quote($logMessage, '/') . '/',
            $matches[1],
            "The catch for {$tryStatement} must log, not swallow the error"
        );
    }

    /**
     * Every page that sets $soldDate for the Vehicle Information card must
     * use CarBadges::parseSoldDate(), the same rule as the Sold badge.
     * `new DateTime()` accepts '0000-00-00' and rolls over '2025-02-30', so
     * the card showed a Sold date that the hero did not.
     *
     * @return array<string, array{string, string}>
     */
    public static function soldDateProducerProvider(): array
    {
        return [
            'account.php'         => [self::ACCOUNT_ENDPOINT, '$carData'],
            'details.php'         => ['app/owner/cars/details.php', '$carData'],
            '_verify_landing.php' => ['app/views/cars/_verify_landing.php', '$verifyCar'],
        ];
    }

    #[DataProvider('soldDateProducerProvider')]
    public function testSoldDateUsesCarBadgesParseSoldDate(string $relativePath, string $carVar): void
    {
        $content = $this->readEndpointSource($relativePath);

        $this->assertMatchesRegularExpression(
            '/\$soldDate\s*=\s*(?:ElanRegistry\\\\Car\\\\)?CarBadges::parseSoldDate\(\s*'
                . preg_quote($carVar, '/') . '->solddate\b/',
            $content,
            "{$relativePath} must set \$soldDate from CarBadges::parseSoldDate()"
        );
        $this->assertDoesNotMatchRegularExpression(
            '/new\s+\\\\?DateTime(?:Immutable)?\(\s*' . preg_quote($carVar, '/') . '->solddate/',
            $content,
            "{$relativePath} must not parse solddate with new DateTime()"
        );
    }

    public function testVerifyLandingAlreadySoldUsesParsedSoldDate(): void
    {
        $content = $this->readEndpointSource('app/views/cars/_verify_landing.php');

        $this->assertMatchesRegularExpression('/\$alreadySold\s*=\s*\$soldDate\s*!==\s*null;/', $content);
        $this->assertStringNotContainsString('!empty($verifyCar->solddate)', $content);
    }

    public function testVerifyDispatcherIsSoldUsesParseSoldDate(): void
    {
        $content = $this->readEndpointSource('app/verify/verify_car.php');

        // The sold action must use the landing page's rule. Otherwise a zero
        // solddate shows "I've sold this car" and then the already-sold notice.
        // It passes false because the landing page logs a bad value.
        $this->assertMatchesRegularExpression(
            '/\$isSold\s*=\s*CarBadges::parseSoldDate\(\s*\$verifyCar->solddate\b[^;]*,\s*false\s*\)\s*!==\s*null;/',
            $content
        );
        $this->assertStringNotContainsString('!empty($verifyCar->solddate)', $content);
    }

    public function testAccountLogsABadSoldDateOnlyThroughForCar(): void
    {
        $content = $this->readEndpointSource(self::ACCOUNT_ENDPOINT);

        // forCar() logs a bad solddate, so the card's parse must not log it again.
        $this->assertMatchesRegularExpression(
            '/\$soldDate\s*=\s*CarBadges::parseSoldDate\([^;]*,\s*false\s*\);/',
            $content
        );
        $this->assertMatchesRegularExpression('/CarBadges::forCar\(\s*\$carData\s*\)/', $content);
    }
}
