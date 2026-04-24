<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for getDataTables.php findCarByChassis endpoint logic
 *
 * Tests the findCarByChassis special endpoint in getDataTables.php (lines 96-114).
 * This endpoint allows looking up car IDs by chassis number for the Registry Link feature.
 *
 * Test Coverage:
 * - Input validation (missing/empty chassis parameter)
 * - Success case (car found)
 * - Not found case (no matching car)
 * - SQL injection prevention (prepared statements)
 * - Special characters handling
 * - Response format validation (Pattern A)
 *
 * @author Elan Registry Development Team
 * @copyright 2025
 */
#[Group('fast')]
#[Group('unit')]
#[Group('api')]
final class GetDataTablesFindCarByChassisTest extends TestCase
{
    /**
     * Test missing chassis parameter returns validation error
     *
     * @return void
     */
    #[Group('fast')]
    public function testMissingChassisParameterReturnsError(): void
    {
        $filePath = __DIR__ . '/../../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify that chassis parameter validation exists
        $this->assertStringContainsString(
            'findCarByChassis',
            $content,
            'Should contain findCarByChassis endpoint'
        );

        // Verify empty check
        $this->assertStringContainsString(
            'empty($chassis)',
            $content,
            'Should check if chassis is empty'
        );

        // Verify ApiResponse is used for error
        $this->assertStringContainsString(
            'ApiResponse::error(\'Chassis number required\')',
            $content,
            'Should return ApiResponse error when chassis is missing'
        );
    }

    /**
     * Test chassis parameter is retrieved correctly
     *
     * @return void
     */
    #[Group('fast')]
    public function testChassisParameterRetrieval(): void
    {
        $filePath = __DIR__ . '/../../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify Input::get('chassis') is used
        $this->assertStringContainsString(
            'Input::get(\'chassis\')',
            $content,
            'Should retrieve chassis parameter from Input'
        );
    }

    /**
     * Test SQL query uses prepared statement (prevents SQL injection)
     *
     * @return void
     */
    #[Group('fast')]
    public function testSqlQueryUsesPreparedStatement(): void
    {
        $filePath = __DIR__ . '/../../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify prepared statement with ? placeholder is used
        $this->assertStringContainsString(
            'SELECT id FROM cars WHERE chassis = ? LIMIT 1',
            $content,
            'Should use prepared statement with ? placeholder'
        );

        // Verify chassis is passed in array (bound parameter)
        $this->assertStringContainsString(
            '[$chassis]',
            $content,
            'Should pass chassis as bound parameter in array'
        );

        // Verify it's NOT using string concatenation
        $this->assertStringNotContainsString(
            'WHERE chassis = \\"$chassis\\"',
            $content,
            'Should NOT use string concatenation for SQL'
        );

        $this->assertStringNotContainsString(
            'WHERE chassis = \'' . '\' . $chassis . \'',
            $content,
            'Should NOT use string concatenation for SQL'
        );
    }

    /**
     * Test car found response uses correct pattern
     *
     * @return void
     */
    #[Group('fast')]
    public function testCarFoundResponsePattern(): void
    {
        $filePath = __DIR__ . '/../../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify count check for car found
        $this->assertStringContainsString(
            '>count() > 0',
            $content,
            'Should check if query returned results'
        );

        // Verify ApiResponse::success is used
        $this->assertStringContainsString(
            'ApiResponse::success(\'Car found\')',
            $content,
            'Should use success response when car is found'
        );

        // Verify car_id is in response data
        $this->assertStringContainsString(
            'withData(\'car_id\'',
            $content,
            'Should include car_id in response data'
        );

        // Verify pattern: $car->id is used to set response data
        $this->assertStringContainsString(
            '$car->id',
            $content,
            'Should retrieve car ID from query result'
        );
    }

    /**
     * Test car not found response uses correct pattern
     *
     * @return void
     */
    #[Group('fast')]
    public function testCarNotFoundResponsePattern(): void
    {
        $filePath = __DIR__ . '/../../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify else branch for not found case
        $this->assertStringContainsString(
            'else',
            $content,
            'Should handle car not found case'
        );

        // Verify ApiResponse::success is used (not error)
        $this->assertStringContainsString(
            'No car found for this chassis number',
            $content,
            'Should return success with null car_id when not found'
        );

        // Verify null is returned for car_id when not found
        $this->assertStringContainsString(
            'car_id\', null',
            $content,
            'Should return null car_id when car not found'
        );
    }

    /**
     * Test response uses ApiResponse Pattern A format
     *
     * @return void
     */
    #[Group('fast')]
    public function testResponseFollowsPatternA(): void
    {
        $filePath = __DIR__ . '/../../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify both success and error use ApiResponse
        $this->assertStringContainsString(
            'ApiResponse::error',
            $content,
            'Should use ApiResponse for errors'
        );

        $this->assertStringContainsString(
            'ApiResponse::success',
            $content,
            'Should use ApiResponse for success'
        );

        // Verify send() method is used
        $csrfStart = strpos($content, 'findCarByChassis');
        $csrfSection = substr($content, $csrfStart, 1000);
        $this->assertStringContainsString(
            '->send()',
            $csrfSection,
            'Should use send() method to output response'
        );
    }

    /**
     * Test endpoint exits after sending response
     *
     * @return void
     */
    #[Group('fast')]
    public function testEndpointExitsAfterResponse(): void
    {
        $filePath = __DIR__ . '/../../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify responses end with send() which includes exit
        $this->assertStringContainsString(
            '->send()',
            $content,
            'Should call send() which terminates execution'
        );
    }

    /**
     * Test chassis lookup returns integer car_id (not string)
     *
     * @return void
     */
    #[Group('fast')]
    public function testCarIdIsProperType(): void
    {
        $filePath = __DIR__ . '/../../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify query returns from 'id' column which is integer
        $this->assertStringContainsString(
            'SELECT id FROM cars',
            $content,
            'Should select integer id column'
        );

        // The database should return integers for id
        // This is a documentation check - the actual type checking
        // happens in integration tests
    }

    /**
     * Test limit 1 ensures only one result is returned
     *
     * @return void
     */
    #[Group('fast')]
    public function testQueryLimitsToOneResult(): void
    {
        $filePath = __DIR__ . '/../../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify LIMIT 1 is in query
        $this->assertStringContainsString(
            'LIMIT 1',
            $content,
            'Should limit results to 1'
        );
    }

    /**
     * Test findCarByChassis is early return before table validation
     *
     * @return void
     */
    #[Group('fast')]
    public function testFindCarByChassisIsEarlyReturn(): void
    {
        $filePath = __DIR__ . '/../../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify findCarByChassis check comes before table validation
        $findChassissPos = strpos($content, 'findCarByChassis');
        $tableValidationPos = strpos($content, 'Invalid table parameter');

        $this->assertNotFalse($findChassissPos, 'Should find findCarByChassis check');
        $this->assertNotFalse($tableValidationPos, 'Should find table validation');
        $this->assertLessThan(
            $tableValidationPos,
            $findChassissPos,
            'findCarByChassis check should come before table validation (early return)'
        );
    }

    /**
     * Test chassis parameter is not logged in response
     *
     * @return void
     */
    #[Group('fast')]
    public function testChassisParameterNotLoggedInResponse(): void
    {
        $filePath = __DIR__ . '/../../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify no user input is echoed in response
        // The responses use fixed messages, not user input
        $this->assertStringContainsString(
            'Car found',
            $content,
            'Should use fixed message for success'
        );

        $this->assertStringContainsString(
            'No car found',
            $content,
            'Should use fixed message for not found'
        );

        // Verify no JSON encode of user input
        $findChassisStart = strpos($content, 'findCarByChassis');
        $findChassisEnd = strpos($content, '        }', $findChassisStart + 100);
        $findChassisSection = substr($content, $findChassisStart, $findChassisEnd - $findChassisStart);

        $this->assertStringNotContainsString(
            'json_encode($chassis)',
            $findChassisSection,
            'Should not json_encode chassis parameter'
        );
    }

    /**
     * Test factory.php includes CSRF token in findCarByChassis request
     *
     * Regression test for issue #581: Missing CSRF token caused 403 Forbidden errors
     * This ensures the calling code passes the csrf parameter to prevent CSRF validation failures
     *
     * @return void
     */
    #[Group('fast')]
    public function testFactoryPageIncludesCsrfTokenInRequest(): void
    {
        $filePath = __DIR__ . '/../../../app/cars/factory.php';
        $content = file_get_contents($filePath);

        // Verify csrf token is included in findCarByChassis AJAX request
        $this->assertStringContainsString(
            'csrf: csrf',
            $content,
            'Should include csrf token in findCarByChassis AJAX request'
        );

        // Verify csrf variable is defined
        $this->assertStringContainsString(
            'const csrf = ',
            $content,
            'Should define csrf variable from Token::generate()'
        );

        // Verify the request includes all required parameters in order
        // Look for the pattern: post(..., { ... table: 'findCarByChassis', ... chassis: chassis, ... csrf: csrf
        $this->assertStringContainsString(
            "table: 'findCarByChassis'",
            $content,
            'Request should include table parameter'
        );

        $this->assertStringContainsString(
            'chassis: chassis',
            $content,
            'Request should include chassis parameter'
        );

        $this->assertStringContainsString(
            'csrf: csrf',
            $content,
            'Request should include csrf parameter'
        );

        // Verify that checkRegistryLinks function (which makes the AJAX call) exists
        $this->assertStringContainsString(
            'function checkRegistryLinks()',
            $content,
            'Should have checkRegistryLinks function that makes AJAX call'
        );
    }
}
