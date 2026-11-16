<?php

declare(strict_types=1);

require_once __DIR__ . '/_headers_sent_namespace_overrides.php';

use ElanRegistry\ApiResponse;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\DataProvider;
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
     * Factory calls with their expected success flag, message, and status.
     *
     * @return array<string, array{\Closure(): ApiResponse, bool, string, int}>
     */
    public static function factoryResponseProvider(): array
    {
        return [
            'success default message' => [static fn (): ApiResponse => ApiResponse::success(), true, 'Operation successful', 200],
            'success custom message' => [static fn (): ApiResponse => ApiResponse::success('Profile updated successfully!'), true, 'Profile updated successfully!', 200],
            'error default message' => [static fn (): ApiResponse => ApiResponse::error(), false, 'An error occurred', 400],
            'error custom message' => [static fn (): ApiResponse => ApiResponse::error('Invalid request data'), false, 'Invalid request data', 400],
            'error custom status code' => [static fn (): ApiResponse => ApiResponse::error('Rate limit exceeded', 429), false, 'Rate limit exceeded', 429],
            'validationError custom message' => [static fn (): ApiResponse => ApiResponse::validationError(['field' => 'Error'], 'Please correct the following errors'), false, 'Please correct the following errors', 422],
            'unauthorized default message' => [static fn (): ApiResponse => ApiResponse::unauthorized(), false, 'Authentication required', 401],
            'unauthorized custom message' => [static fn (): ApiResponse => ApiResponse::unauthorized('Session expired'), false, 'Session expired', 401],
            'forbidden default message' => [static fn (): ApiResponse => ApiResponse::forbidden(), false, 'Access denied', 403],
            'forbidden custom message' => [static fn (): ApiResponse => ApiResponse::forbidden('Admin access required'), false, 'Admin access required', 403],
            'notFound default message' => [static fn (): ApiResponse => ApiResponse::notFound(), false, 'Resource not found', 404],
            'notFound custom message' => [static fn (): ApiResponse => ApiResponse::notFound('Car not found'), false, 'Car not found', 404],
            'serverError default message' => [static fn (): ApiResponse => ApiResponse::serverError(), false, 'Internal server error', 500],
            'serverError custom message' => [static fn (): ApiResponse => ApiResponse::serverError('Database connection failed'), false, 'Database connection failed', 500],
        ];
    }

    /**
     * Test each factory method sets the success flag, message, and status code
     *
     * @param \Closure(): ApiResponse $factory         Builds the response under test
     * @param bool                    $expectedSuccess Expected isSuccess() value
     * @param string                  $expectedMessage Expected getMessage() value
     * @param int                     $expectedStatus  Expected getStatusCode() value
     *
     * @return void
     */
    #[Group('fast')]
    #[DataProvider('factoryResponseProvider')]
    public function testFactorySetsSuccessMessageAndStatus(
        \Closure $factory,
        bool $expectedSuccess,
        string $expectedMessage,
        int $expectedStatus
    ): void {
        $response = $factory();

        $this->assertSame($expectedSuccess, $response->isSuccess());
        $this->assertSame($expectedMessage, $response->getMessage());
        $this->assertSame($expectedStatus, $response->getStatusCode());
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
     * A field can carry a list of messages (save.php sends one message for
     * each rejected photo, #1844). The body must serialize the list as a JSON
     * array, not as an object and not as a joined string.
     *
     * @return void
     */
    #[Group('fast')]
    public function testValidationErrorSerializesListValueAsJsonList(): void
    {
        $response = ApiResponse::validationError(['general' => ['a', 'b']]);

        $json = $this->invokeBuildAndEmitHeaders($response);

        $this->assertStringContainsString('"errors":{"general":["a","b"]}', $json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame(['a', 'b'], $decoded['errors']['general']);
        $this->assertSame(422, $response->getStatusCode());
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
     * Builder calls with a reader for the state that each one changes.
     *
     * @return array<string, array{\Closure(ApiResponse): ApiResponse, \Closure(ApiResponse): mixed}>
     */
    public static function immutableBuilderProvider(): array
    {
        return [
            'withData' => [
                static fn (ApiResponse $r): ApiResponse => $r->withData('key', 'value'),
                static fn (ApiResponse $r): mixed => $r->getData(),
            ],
            'withDataArray' => [
                static fn (ApiResponse $r): ApiResponse => $r->withDataArray(['key' => 'value']),
                static fn (ApiResponse $r): mixed => $r->getData(),
            ],
            'withLogging' => [
                static fn (ApiResponse $r): ApiResponse => $r->withLogging(1, LogCategories::LOG_CATEGORY_DIAGNOSTICS, 'Message'),
                static fn (ApiResponse $r): mixed => $r->getPendingLog(),
            ],
        ];
    }

    /**
     * Test each builder returns a new instance and leaves the original unchanged
     *
     * @param \Closure(ApiResponse): ApiResponse $build Applies the builder under test
     * @param \Closure(ApiResponse): mixed       $read  Reads the state the builder sets
     *
     * @return void
     */
    #[Group('fast')]
    #[DataProvider('immutableBuilderProvider')]
    public function testBuilderIsImmutable(\Closure $build, \Closure $read): void
    {
        $original = ApiResponse::success('Done');
        $modified = $build($original);

        $this->assertEmpty($read($original));
        $this->assertNotEmpty($read($modified));
        $this->assertNotSame($original, $modified);
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
