<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for chassis lookup endpoint (app/api/cars/chassis-lookup.php)
 *
 * Source-inspection tests that verify security patterns and response shape
 * without requiring the UserSpice bootstrap or a database connection.
 */
#[Group('fast')]
#[Group('unit')]
#[Group('api')]
final class GetDataTablesFindCarByChassisTest extends TestCase
{
    private string $endpointPath;
    private string $endpointContent;

    protected function setUp(): void
    {
        $this->endpointPath = dirname(__DIR__, 3) . '/app/api/cars/chassis-lookup.php';
        $this->endpointContent = file_get_contents($this->endpointPath);
    }

    public function testEndpointFileExists(): void
    {
        $this->assertFileExists($this->endpointPath);
    }

    public function testSqlQueryUsesPreparedStatement(): void
    {
        $this->assertStringContainsString(
            'SELECT id FROM cars WHERE chassis = ? LIMIT 1',
            $this->endpointContent,
            'Query must use a ? placeholder, not string interpolation'
        );
        $this->assertStringContainsString(
            '[$chassis]',
            $this->endpointContent,
            'Chassis value must be passed as a bound parameter'
        );
    }

    public function testCarIdCastToInt(): void
    {
        $this->assertStringContainsString(
            '(int) $carQuery->first()->id',
            $this->endpointContent,
            'car_id must be cast to int before sending — PDO::ATTR_STRINGIFY_FETCHES returns strings'
        );
    }

    public function testEmptyChassisGuardUsesExactStringComparison(): void
    {
        $this->assertStringContainsString(
            "trim(\$chassis) === ''",
            $this->endpointContent,
            'Empty check must use trim() + strict equality, not empty() which rejects "0"'
        );
        $this->assertStringNotContainsString(
            'empty($chassis)',
            $this->endpointContent,
            'empty() must not be used — it would silently reject chassis "0"'
        );
    }

    public function testCsrfTokenIsValidated(): void
    {
        $this->assertStringContainsString(
            'Token::check(',
            $this->endpointContent,
            'CSRF token must be validated before processing'
        );
    }

    public function testOnlyPostMethodAccepted(): void
    {
        $this->assertStringContainsString(
            "\$method !== 'POST'",
            $this->endpointContent,
            'Endpoint must reject non-POST requests'
        );
    }

    public function testResponseFollowsPatternA(): void
    {
        $this->assertStringContainsString(
            'ApiResponse::success(',
            $this->endpointContent,
            'Success responses must use ApiResponse Pattern A'
        );
        $this->assertStringContainsString(
            'ApiResponse::error(',
            $this->endpointContent,
            'Error responses must use ApiResponse Pattern A'
        );
        $this->assertStringContainsString(
            'ApiResponse::forbidden(',
            $this->endpointContent,
            'CSRF failure must use ApiResponse::forbidden'
        );
    }

    public function testInputUsesRawForDbBoundField(): void
    {
        $this->assertStringContainsString(
            "Input::raw('chassis')",
            $this->endpointContent,
            'DB-bound chassis value must use Input::raw(), not Input::get()'
        );
    }

    public function testStrictTypesEnabled(): void
    {
        $this->assertStringContainsString(
            'declare(strict_types=1)',
            $this->endpointContent
        );
    }
}
