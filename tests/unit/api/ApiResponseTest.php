<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

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
            ->withLogging(42, 'OwnerActions', 'Profile updated');

        $log = $response->getPendingLog();
        $this->assertNotNull($log);
        $this->assertEquals(42, $log['userId']);
        $this->assertEquals('OwnerActions', $log['category']);
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
        $modified = $original->withLogging(1, 'Test', 'Message');

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
            ->withLogging(42, 'OwnerActions', 'Profile updated for user 42');

        $this->assertTrue($response->isSuccess());
        $this->assertEquals('Profile updated!', $response->getMessage());

        $data = $response->getData();
        $this->assertEquals(85, $data['quality_score']);
        $this->assertEquals(['city'], $data['missing_fields']);

        $log = $response->getPendingLog();
        $this->assertEquals(42, $log['userId']);
        $this->assertEquals('OwnerActions', $log['category']);
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
            ->withLogging(0, 'SecurityError', 'Anonymous access attempt');

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
            ->withLogging(1, 'Test', 'Message');

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
}
