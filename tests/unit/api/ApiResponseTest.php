<?php

declare(strict_types=1);

require_once __DIR__ . '/_headers_sent_namespace_overrides.php';

use ElanRegistry\ApiResponse;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

/**
 * Test-only helper exposing ApiResponse::buildAndEmitHeaders() for direct
 * unit testing.
 *
 * ApiResponse's builder methods (withData(), withLogging(), ...) are
 * declared to return `self`, which PHP resolves against the DEFINING class
 * (ApiResponse) rather than the late-static-bound caller. A subclass
 * approach (extend ApiResponse, expose buildAndEmitHeaders() via a public
 * wrapper) would therefore make every builder call in a test — e.g.
 * ApiResponse::success(...)->withData(...) — statically typed as the base
 * ApiResponse and unable to reach a subclass-only wrapper method without an
 * unsound cast. Since buildAndEmitHeaders() is protected (not private), it
 * is reachable on any ApiResponse instance via a same-class-scoped Closure
 * instead — no subclassing or casting required. The private outputBufferFloor
 * is read and set through ReflectionProperty.
 */
final class TestableApiResponse
{
    /**
     * Call $response's protected buildAndEmitHeaders() from outside its
     * class hierarchy, via a Closure bound to the instance.
     *
     * @param ApiResponse $response Response to invoke buildAndEmitHeaders() on
     *
     * @return string JSON-encoded response body
     */
    public static function exposedBuildAndEmitHeaders(ApiResponse $response): string
    {
        $call = Closure::bind(
            static function (ApiResponse $response): string {
                return $response->buildAndEmitHeaders();
            },
            null,
            ApiResponse::class
        );

        if ($call === null) {
            throw new \RuntimeException('Failed to bind buildAndEmitHeaders() closure to ' . ApiResponse::class);
        }

        return $call($response);
    }

    /**
     * Set $response's private outputBufferFloor.
     *
     * @param ApiResponse $response Response to change
     * @param int         $floor    Output buffer level to clean down to
     *
     * @return void
     */
    public static function setOutputBufferFloor(ApiResponse $response, int $floor): void
    {
        (new \ReflectionProperty(ApiResponse::class, 'outputBufferFloor'))->setValue($response, $floor);
    }

    /**
     * Read $response's private outputBufferFloor.
     *
     * @param ApiResponse $response Response to read
     *
     * @return int Output buffer level that buildAndEmitHeaders() cleans down to
     */
    public static function getOutputBufferFloor(ApiResponse $response): int
    {
        $floor = (new \ReflectionProperty(ApiResponse::class, 'outputBufferFloor'))->getValue($response);
        \assert(is_int($floor));

        return $floor;
    }
}

/**
 * Unit tests for ApiResponse class
 *
 * Tests all factory methods, builder methods, output methods, and edge cases
 * for the standardized API response system.
 */
#[Group('fast')]
#[Group('unit')]
#[Group('api')]
final class ApiResponseTest extends TestCase
{
    /**
     * Test success factory method with default message
     *
     * @return void
     */
    #[Group('fast')]
    public function testSuccessWithDefaultMessage(): void
    {
        $response = ApiResponse::success();

        $this->assertTrue($response->isSuccess());
        $this->assertEquals('Operation successful', $response->getMessage());
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Test success factory method with custom message
     *
     * @return void
     */
    #[Group('fast')]
    public function testSuccessWithCustomMessage(): void
    {
        $response = ApiResponse::success('Profile updated successfully!');

        $this->assertTrue($response->isSuccess());
        $this->assertEquals('Profile updated successfully!', $response->getMessage());
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Test error factory method with default message
     *
     * @return void
     */
    #[Group('fast')]
    public function testErrorWithDefaultMessage(): void
    {
        $response = ApiResponse::error();

        $this->assertFalse($response->isSuccess());
        $this->assertEquals('An error occurred', $response->getMessage());
        $this->assertEquals(400, $response->getStatusCode());
    }

    /**
     * Test error factory method with custom message
     *
     * @return void
     */
    #[Group('fast')]
    public function testErrorWithCustomMessage(): void
    {
        $response = ApiResponse::error('Invalid request data');

        $this->assertFalse($response->isSuccess());
        $this->assertEquals('Invalid request data', $response->getMessage());
        $this->assertEquals(400, $response->getStatusCode());
    }

    /**
     * Test error factory method with custom status code
     *
     * @return void
     */
    #[Group('fast')]
    public function testErrorWithCustomStatusCode(): void
    {
        $response = ApiResponse::error('Rate limit exceeded', 429);

        $this->assertFalse($response->isSuccess());
        $this->assertEquals('Rate limit exceeded', $response->getMessage());
        $this->assertEquals(429, $response->getStatusCode());
    }

    /**
     * Test validationError factory method with errors
     *
     * @return void
     */
    #[Group('fast')]
    public function testValidationError(): void
    {
        $errors = [
            'email' => 'Invalid email format',
            'name' => 'Required field',
        ];

        $response = ApiResponse::validationError($errors);

        $this->assertFalse($response->isSuccess());
        $this->assertEquals('Validation failed', $response->getMessage());
        $this->assertEquals(422, $response->getStatusCode());
        $this->assertEquals($errors, $response->getData()['errors']);
    }

    /**
     * Test validationError factory method with custom message
     *
     * @return void
     */
    #[Group('fast')]
    public function testValidationErrorWithCustomMessage(): void
    {
        $errors = ['field' => 'Error'];
        $response = ApiResponse::validationError($errors, 'Please correct the following errors');

        $this->assertEquals('Please correct the following errors', $response->getMessage());
        $this->assertEquals(422, $response->getStatusCode());
    }

    /**
     * Test unauthorized factory method with default message
     *
     * @return void
     */
    #[Group('fast')]
    public function testUnauthorizedWithDefaultMessage(): void
    {
        $response = ApiResponse::unauthorized();

        $this->assertFalse($response->isSuccess());
        $this->assertEquals('Authentication required', $response->getMessage());
        $this->assertEquals(401, $response->getStatusCode());
    }

    /**
     * Test unauthorized factory method with custom message
     *
     * @return void
     */
    #[Group('fast')]
    public function testUnauthorizedWithCustomMessage(): void
    {
        $response = ApiResponse::unauthorized('Session expired');

        $this->assertEquals('Session expired', $response->getMessage());
        $this->assertEquals(401, $response->getStatusCode());
    }

    /**
     * Test forbidden factory method with default message
     *
     * @return void
     */
    #[Group('fast')]
    public function testForbiddenWithDefaultMessage(): void
    {
        $response = ApiResponse::forbidden();

        $this->assertFalse($response->isSuccess());
        $this->assertEquals('Access denied', $response->getMessage());
        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * Test forbidden factory method with custom message
     *
     * @return void
     */
    #[Group('fast')]
    public function testForbiddenWithCustomMessage(): void
    {
        $response = ApiResponse::forbidden('Admin access required');

        $this->assertEquals('Admin access required', $response->getMessage());
        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * Test notFound factory method with default message
     *
     * @return void
     */
    #[Group('fast')]
    public function testNotFoundWithDefaultMessage(): void
    {
        $response = ApiResponse::notFound();

        $this->assertFalse($response->isSuccess());
        $this->assertEquals('Resource not found', $response->getMessage());
        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     * Test notFound factory method with custom message
     *
     * @return void
     */
    #[Group('fast')]
    public function testNotFoundWithCustomMessage(): void
    {
        $response = ApiResponse::notFound('Car not found');

        $this->assertEquals('Car not found', $response->getMessage());
        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     * Test serverError factory method with default message
     *
     * @return void
     */
    #[Group('fast')]
    public function testServerErrorWithDefaultMessage(): void
    {
        $response = ApiResponse::serverError();

        $this->assertFalse($response->isSuccess());
        $this->assertEquals('Internal server error', $response->getMessage());
        $this->assertEquals(500, $response->getStatusCode());
    }

    /**
     * Test serverError factory method with custom message
     *
     * @return void
     */
    #[Group('fast')]
    public function testServerErrorWithCustomMessage(): void
    {
        $response = ApiResponse::serverError('Database connection failed');

        $this->assertEquals('Database connection failed', $response->getMessage());
        $this->assertEquals(500, $response->getStatusCode());
    }

    /**
     * Test withData adds data to response
     *
     * @return void
     */
    #[Group('fast')]
    public function testWithDataAddsSingleValue(): void
    {
        $response = ApiResponse::success('Done')
            ->withData('quality_score', 85);

        $data = $response->getData();
        $this->assertArrayHasKey('quality_score', $data);
        $this->assertEquals(85, $data['quality_score']);
    }

    /**
     * Test withData is immutable
     *
     * @return void
     */
    #[Group('fast')]
    public function testWithDataIsImmutable(): void
    {
        $original = ApiResponse::success('Done');
        $modified = $original->withData('key', 'value');

        $this->assertEmpty($original->getData());
        $this->assertNotEmpty($modified->getData());
        $this->assertNotSame($original, $modified);
    }

    /**
     * Test withData can be chained multiple times
     *
     * @return void
     */
    #[Group('fast')]
    public function testWithDataChaining(): void
    {
        $response = ApiResponse::success('Done')
            ->withData('score', 85)
            ->withData('level', 'gold')
            ->withData('items', ['a', 'b', 'c']);

        $data = $response->getData();
        $this->assertEquals(85, $data['score']);
        $this->assertEquals('gold', $data['level']);
        $this->assertEquals(['a', 'b', 'c'], $data['items']);
    }

    /**
     * Test withDataArray adds multiple values at once
     *
     * @return void
     */
    #[Group('fast')]
    public function testWithDataArray(): void
    {
        $response = ApiResponse::success('Done')
            ->withDataArray([
                'quality_score' => 85,
                'missing_fields' => ['city', 'state'],
            ]);

        $data = $response->getData();
        $this->assertEquals(85, $data['quality_score']);
        $this->assertEquals(['city', 'state'], $data['missing_fields']);
    }

    /**
     * Test withDataArray is immutable
     *
     * @return void
     */
    #[Group('fast')]
    public function testWithDataArrayIsImmutable(): void
    {
        $original = ApiResponse::success('Done');
        $modified = $original->withDataArray(['key' => 'value']);

        $this->assertEmpty($original->getData());
        $this->assertNotEmpty($modified->getData());
    }

    /**
     * Test withLogging sets pending log
     *
     * @return void
     */
    #[Group('fast')]
    public function testWithLogging(): void
    {
        $response = ApiResponse::success('Done')
            ->withLogging(42, LogCategories::LOG_CATEGORY_OWNER_ACTIONS, 'Profile updated');

        $log = $response->getPendingLog();
        $this->assertNotNull($log);
        $this->assertEquals(42, $log['userId']);
        $this->assertEquals(LogCategories::LOG_CATEGORY_OWNER_ACTIONS, $log['category']);
        $this->assertEquals('Profile updated', $log['message']);
    }

    /**
     * Test withLogging is immutable
     *
     * @return void
     */
    #[Group('fast')]
    public function testWithLoggingIsImmutable(): void
    {
        $original = ApiResponse::success('Done');
        $modified = $original->withLogging(1, LogCategories::LOG_CATEGORY_DIAGNOSTICS, 'Message');

        $this->assertNull($original->getPendingLog());
        $this->assertNotNull($modified->getPendingLog());
    }

    /**
     * Test toArray returns minimal response for success
     *
     * @return void
     */
    #[Group('fast')]
    public function testToArrayMinimalSuccess(): void
    {
        $response = ApiResponse::success('Done');
        $array = $response->toArray();

        $this->assertEquals([
            'success' => true,
            'message' => 'Done',
        ], $array);
    }

    /**
     * Test toArray returns minimal response for error
     *
     * @return void
     */
    #[Group('fast')]
    public function testToArrayMinimalError(): void
    {
        $response = ApiResponse::error('Failed');
        $array = $response->toArray();

        $this->assertEquals([
            'success' => false,
            'message' => 'Failed',
        ], $array);
    }

    /**
     * Test toArray includes additional data
     *
     * @return void
     */
    #[Group('fast')]
    public function testToArrayWithData(): void
    {
        $response = ApiResponse::success('Done')
            ->withData('quality_score', 85)
            ->withData('level', 'gold');

        $array = $response->toArray();

        $this->assertTrue($array['success']);
        $this->assertEquals('Done', $array['message']);
        $this->assertEquals(85, $array['quality_score']);
        $this->assertEquals('gold', $array['level']);
    }

    /**
     * Test toArray for validation error includes errors
     *
     * @return void
     */
    #[Group('fast')]
    public function testToArrayForValidationError(): void
    {
        $errors = [
            'email' => 'Invalid email',
            'name' => 'Required',
        ];

        $response = ApiResponse::validationError($errors);
        $array = $response->toArray();

        $this->assertFalse($array['success']);
        $this->assertEquals('Validation failed', $array['message']);
        $this->assertEquals($errors, $array['errors']);
    }

    /**
     * Test response with empty string message
     *
     * @return void
     */
    #[Group('fast')]
    public function testEmptyStringMessage(): void
    {
        $response = ApiResponse::success('');

        $this->assertEquals('', $response->getMessage());
        $array = $response->toArray();
        $this->assertEquals('', $array['message']);
    }

    /**
     * Test response with null data value
     *
     * @return void
     */
    #[Group('fast')]
    public function testNullDataValue(): void
    {
        $response = ApiResponse::success('Done')
            ->withData('nullable_field', null);

        $data = $response->getData();
        $this->assertArrayHasKey('nullable_field', $data);
        $this->assertNull($data['nullable_field']);
    }

    /**
     * Test response with empty array data
     *
     * @return void
     */
    #[Group('fast')]
    public function testEmptyArrayData(): void
    {
        $response = ApiResponse::success('Done')
            ->withData('items', []);

        $data = $response->getData();
        $this->assertEquals([], $data['items']);
    }

    /**
     * Test response with nested array data
     *
     * @return void
     */
    #[Group('fast')]
    public function testNestedArrayData(): void
    {
        $nestedData = [
            'user' => [
                'id' => 1,
                'profile' => [
                    'name' => 'John',
                    'city' => 'Portland',
                ],
            ],
        ];

        $response = ApiResponse::success('Done')
            ->withData('nested', $nestedData);

        $data = $response->getData();
        $this->assertEquals($nestedData, $data['nested']);
    }

    /**
     * Test response with numeric data
     *
     * @return void
     */
    #[Group('fast')]
    public function testNumericData(): void
    {
        $response = ApiResponse::success('Done')
            ->withData('integer', 42)
            ->withData('float', 3.14159)
            ->withData('negative', -100);

        $data = $response->getData();
        $this->assertSame(42, $data['integer']);
        $this->assertSame(3.14159, $data['float']);
        $this->assertSame(-100, $data['negative']);
    }

    /**
     * Test response with boolean data
     *
     * @return void
     */
    #[Group('fast')]
    public function testBooleanData(): void
    {
        $response = ApiResponse::success('Done')
            ->withData('active', true)
            ->withData('deleted', false);

        $data = $response->getData();
        $this->assertTrue($data['active']);
        $this->assertFalse($data['deleted']);
    }

    /**
     * Test getStatusCode for all factory methods
     *
     * @return void
     */
    #[Group('fast')]
    public function testStatusCodesForAllFactoryMethods(): void
    {
        $this->assertEquals(200, ApiResponse::success()->getStatusCode());
        $this->assertEquals(400, ApiResponse::error()->getStatusCode());
        $this->assertEquals(422, ApiResponse::validationError([])->getStatusCode());
        $this->assertEquals(401, ApiResponse::unauthorized()->getStatusCode());
        $this->assertEquals(403, ApiResponse::forbidden()->getStatusCode());
        $this->assertEquals(404, ApiResponse::notFound()->getStatusCode());
        $this->assertEquals(500, ApiResponse::serverError()->getStatusCode());
    }

    /**
     * Test isSuccess for all factory methods
     *
     * @return void
     */
    #[Group('fast')]
    public function testIsSuccessForAllFactoryMethods(): void
    {
        $this->assertTrue(ApiResponse::success()->isSuccess());
        $this->assertFalse(ApiResponse::error()->isSuccess());
        $this->assertFalse(ApiResponse::validationError([])->isSuccess());
        $this->assertFalse(ApiResponse::unauthorized()->isSuccess());
        $this->assertFalse(ApiResponse::forbidden()->isSuccess());
        $this->assertFalse(ApiResponse::notFound()->isSuccess());
        $this->assertFalse(ApiResponse::serverError()->isSuccess());
    }

    /**
     * Test full builder chain maintains all values
     *
     * @return void
     */
    #[Group('fast')]
    public function testFullBuilderChain(): void
    {
        $response = ApiResponse::success('Profile updated!')
            ->withData('quality_score', 85)
            ->withData('missing_fields', ['city'])
            ->withLogging(42, LogCategories::LOG_CATEGORY_OWNER_ACTIONS, 'Profile updated for user 42');

        $this->assertTrue($response->isSuccess());
        $this->assertEquals('Profile updated!', $response->getMessage());

        $data = $response->getData();
        $this->assertEquals(85, $data['quality_score']);
        $this->assertEquals(['city'], $data['missing_fields']);

        $log = $response->getPendingLog();
        $this->assertEquals(42, $log['userId']);
        $this->assertEquals(LogCategories::LOG_CATEGORY_OWNER_ACTIONS, $log['category']);
    }

    /**
     * Test validation error with empty errors array
     *
     * @return void
     */
    #[Group('fast')]
    public function testValidationErrorWithEmptyErrors(): void
    {
        $response = ApiResponse::validationError([]);

        $this->assertFalse($response->isSuccess());
        $this->assertEquals(422, $response->getStatusCode());
        $this->assertEquals([], $response->getData()['errors']);
    }

    /**
     * Test withLogging with zero user ID
     *
     * @return void
     */
    #[Group('fast')]
    public function testWithLoggingZeroUserId(): void
    {
        $response = ApiResponse::forbidden('Access denied')
            ->withLogging(0, LogCategories::LOG_CATEGORY_SECURITY, 'Anonymous access attempt');

        $log = $response->getPendingLog();
        $this->assertEquals(0, $log['userId']);
    }

    /**
     * Test response data does not include internal state
     *
     * @return void
     */
    #[Group('fast')]
    public function testToArrayDoesNotIncludePendingLog(): void
    {
        $response = ApiResponse::success('Done')
            ->withLogging(1, LogCategories::LOG_CATEGORY_DIAGNOSTICS, 'Message');

        $array = $response->toArray();

        $this->assertArrayNotHasKey('pendingLog', $array);
        $this->assertArrayNotHasKey('statusCode', $array);
    }

    /**
     * Test data can overwrite values with same key
     *
     * @return void
     */
    #[Group('fast')]
    public function testWithDataOverwritesSameKey(): void
    {
        $response = ApiResponse::success('Done')
            ->withData('score', 50)
            ->withData('score', 100);

        $this->assertEquals(100, $response->getData()['score']);
    }

    /**
     * Test getMessage getter
     *
     * @return void
     */
    #[Group('fast')]
    public function testGetMessage(): void
    {
        $response = ApiResponse::success('Test message');
        $this->assertEquals('Test message', $response->getMessage());
    }

    /**
     * Test getData returns empty array when no data set
     *
     * @return void
     */
    #[Group('fast')]
    public function testGetDataReturnsEmptyArrayByDefault(): void
    {
        $response = ApiResponse::success('Done');
        $this->assertEquals([], $response->getData());
    }

    /**
     * Test getPendingLog returns null when no log set
     *
     * @return void
     */
    #[Group('fast')]
    public function testGetPendingLogReturnsNullByDefault(): void
    {
        $response = ApiResponse::success('Done');
        $this->assertNull($response->getPendingLog());
    }

    /**
     * Test response with very long message
     *
     * @return void
     */
    #[Group('fast')]
    public function testVeryLongMessage(): void
    {
        $longMessage = str_repeat('A', 10000);
        $response = ApiResponse::success($longMessage);

        $this->assertEquals($longMessage, $response->getMessage());
        $this->assertEquals(10000, strlen($response->getMessage()));
    }

    /**
     * Test withDataArray merges with existing data
     *
     * @return void
     */
    #[Group('fast')]
    public function testWithDataArrayMergesWithExisting(): void
    {
        $response = ApiResponse::success('Done')
            ->withData('existing', 'value')
            ->withDataArray(['new1' => 'a', 'new2' => 'b']);

        $data = $response->getData();
        $this->assertEquals('value', $data['existing']);
        $this->assertEquals('a', $data['new1']);
        $this->assertEquals('b', $data['new2']);
    }

    // =========================================================================
    // Tests: buildAndEmitHeaders() — via TestableApiResponse
    // =========================================================================

    /**
     * Ensure $GLOBALS['mockHeadersSent'] never bleeds into other tests, even
     * if a test fails partway through (mirrors the tearDownApcuSimulation()
     * idiom in tests/unit/location/LocationServiceCacheTest.php).
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['mockHeadersSent'], $GLOBALS['mockHeadersSentFile'], $GLOBALS['mockHeadersSentLine']);
        parent::tearDown();
    }

    /**
     * Invoke buildAndEmitHeaders() on its headers-not-sent branch without
     * closing PHPUnit's own output buffer.
     *
     * PHPUnit 13 marks a test risky when the test closes PHPUnit's buffer,
     * even if the test opens a new one after. In production the cleanup loop
     * cleans every buffer to level 0. This helper sets the floor to the
     * current level, so the loop stops before PHPUnit's buffer.
     *
     * @param ApiResponse $response Response to invoke buildAndEmitHeaders() on
     *
     * @return string JSON-encoded response body
     */
    private function invokeBuildAndEmitHeaders(ApiResponse $response): string
    {
        TestableApiResponse::setOutputBufferFloor($response, ob_get_level());

        return TestableApiResponse::exposedBuildAndEmitHeaders($response);
    }

    /**
     * A response from a factory cleans every output buffer (floor 0). The
     * test seam must not change the production default.
     *
     * @return void
     */
    #[Group('fast')]
    public function testOutputBufferFloorDefaultsToZero(): void
    {
        $response = ApiResponse::success('Done');

        $this->assertSame(0, TestableApiResponse::getOutputBufferFloor($response));
    }

    /**
     * The cleanup loop discards every buffer above the floor, with its
     * content, so stray output cannot reach the JSON body. The other tests
     * that reach the loop set the floor to the current level, so the loop has
     * no work there.
     *
     * @return void
     */
    #[Group('fast')]
    public function testBuildAndEmitHeadersDiscardsBuffersAboveTheFloor(): void
    {
        unset($GLOBALS['mockHeadersSent']);

        $response = ApiResponse::success('Done');
        $floor = ob_get_level();
        TestableApiResponse::setOutputBufferFloor($response, $floor);

        $this->expectOutputString('');

        ob_start();
        echo 'stray output';

        $json = TestableApiResponse::exposedBuildAndEmitHeaders($response);

        $this->assertSame($floor, ob_get_level());
        $this->assertEquals($response->toArray(), json_decode($json, true));
    }

    /**
     * buildAndEmitHeaders() executes the pending log entry via the real
     * global logger() (the ElanRegistry namespace has no logger() override,
     * so PHP's unqualified call in ApiResponse falls back to the global
     * function), which the test bootstrap replaces with a recording stub
     * that appends to $GLOBALS['mockLogEntries']. This lets us assert the
     * exact log entry that was recorded, not just that no error occurred.
     *
     * @return void
     */
    #[Group('fast')]
    public function testBuildAndEmitHeadersExecutesPendingLog(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];

        $response = ApiResponse::success('Done')
            ->withLogging(42, LogCategories::LOG_CATEGORY_OWNER_ACTIONS, 'Profile updated');

        $this->invokeBuildAndEmitHeaders($response);

        $this->assertCount(1, $mockLogEntries);
        $this->assertEquals(42, $mockLogEntries[0]['user_id']);
        $this->assertEquals(LogCategories::LOG_CATEGORY_OWNER_ACTIONS, $mockLogEntries[0]['category']);
        $this->assertEquals('Profile updated', $mockLogEntries[0]['message']);
    }

    /**
     * When no pending log is set, buildAndEmitHeaders() must not record any
     * log entry, and must still return valid JSON.
     *
     * @return void
     */
    #[Group('fast')]
    public function testBuildAndEmitHeadersSkipsLogWhenNoPendingLog(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];

        $response = ApiResponse::success('Done');

        $json = $this->invokeBuildAndEmitHeaders($response);

        // Re-declare $mockLogEntries as global here: PHPStan otherwise narrows
        // it to the literal array{} assigned above and flags the assertion as
        // trivially true, missing that the intervening call can mutate it.
        global $mockLogEntries;
        $this->assertEmpty($mockLogEntries);
        $this->assertEquals($response->toArray(), json_decode($json, true));
    }

    /**
     * When headers_sent() reports true (simulated via the ElanRegistry-namespace
     * override in _headers_sent_namespace_overrides.php), buildAndEmitHeaders()
     * must skip header emission but still return valid JSON matching toArray().
     *
     * @return void
     */
    #[Group('fast')]
    public function testBuildAndEmitHeadersWhenHeadersAlreadySent(): void
    {
        $GLOBALS['mockHeadersSent'] = true;
        $GLOBALS['mockHeadersSentFile'] = 'mock-file.php';
        $GLOBALS['mockHeadersSentLine'] = 99;

        $response = ApiResponse::success('Done')->withData('key', 'value');
        $json = TestableApiResponse::exposedBuildAndEmitHeaders($response);

        $this->assertEquals($response->toArray(), json_decode($json, true));
    }

    /**
     * When headers have not been sent (the default/unset state, matching a
     * normal CLI PHPUnit run), buildAndEmitHeaders() takes the header-emission
     * branch and still returns valid JSON matching toArray().
     *
     * @return void
     */
    #[Group('fast')]
    public function testBuildAndEmitHeadersWhenHeadersNotSent(): void
    {
        unset($GLOBALS['mockHeadersSent']);

        $response = ApiResponse::success('Done')->withData('key', 'value');

        $json = $this->invokeBuildAndEmitHeaders($response);

        $this->assertEquals($response->toArray(), json_decode($json, true));
    }

    /**
     * buildAndEmitHeaders() returns valid JSON matching toArray() for a
     * normal response with data attached.
     *
     * @return void
     */
    #[Group('fast')]
    public function testBuildAndEmitHeadersReturnsValidJsonForNormalData(): void
    {
        $response = ApiResponse::success('Done')->withData('quality_score', 85);

        $json = $this->invokeBuildAndEmitHeaders($response);

        $this->assertEquals($response->toArray(), json_decode($json, true));
    }

    /**
     * When the response data contains a value json_encode() cannot serialize
     * (a resource, under JSON_THROW_ON_ERROR), buildAndEmitHeaders() catches
     * the JsonException and returns the fixed safe-fallback JSON string.
     *
     * @return void
     */
    #[Group('fast')]
    public function testBuildAndEmitHeadersFallsBackOnJsonEncodeFailure(): void
    {
        $resource = fopen('php://memory', 'r');

        $response = ApiResponse::success('Done')->withData('badValue', $resource);

        $json = $this->invokeBuildAndEmitHeaders($response);

        fclose($resource);

        $this->assertEquals('{"success":false,"message":"An internal error occurred"}', $json);
    }
}
