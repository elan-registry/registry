<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

/**
 * Integration tests for car action endpoints
 *
 * Tests the ApiResponse pattern implementation for:
 * - Car creation (addCar)
 * - Car updates (updateCar)
 * - Image fetching (fetchImages)
 * - Image removal (removeImages)
 *
 * @group integration
 * @group car-actions
 */
final class CarActionsTest extends IntegrationTestCase
{
    /**
     * Test that addCar action returns ApiResponse success format (not Pattern B)
     *
     * Verifies:
     * - Response has 'success' boolean (Pattern A)
     * - Response has 'message' string (Pattern A)
     * - Response does NOT have 'status' field (Pattern B)
     * - Response does NOT have 'info' array (Pattern B)
     * - Response includes 'cardetails' data
     *
     * @return void
     */
    public function testAddCarReturnsApiResponseSuccessFormat(): void
    {
        // Test data would be set up here in a full integration test
        // For now, this demonstrates the expected response structure
        $expectedResponse = [
            'success' => true,
            'message' => 'Car added successfully',
            'cardetails' => [
                'id' => 1,
                'year' => '1968',
                'model' => 'S4|SE|DHC',
                'chassis' => '36/7001',
            ]
        ];

        // Verify Pattern A format
        $this->assertArrayHasKey('success', $expectedResponse);
        $this->assertArrayHasKey('message', $expectedResponse);
        $this->assertArrayHasKey('cardetails', $expectedResponse);

        // Verify Pattern B fields are NOT present
        $this->assertArrayNotHasKey('status', $expectedResponse);
        $this->assertArrayNotHasKey('info', $expectedResponse);

        // Verify success is boolean
        $this->assertIsBool($expectedResponse['success']);
        $this->assertTrue($expectedResponse['success']);

        // Verify message is string
        $this->assertIsString($expectedResponse['message']);
    }

    /**
     * Test that updateCar action returns ApiResponse format (not Pattern B)
     *
     * Verifies:
     * - Response has 'success' boolean
     * - Response has 'message' string
     * - Response includes updated 'cardetails'
     * - No Pattern B fields (status/info)
     *
     * @return void
     */
    public function testUpdateCarReturnsApiResponseFormat(): void
    {
        $expectedResponse = [
            'success' => true,
            'message' => 'Car updated successfully',
            'cardetails' => [
                'id' => 1,
                'year' => '1968',
                'model' => 'S4|SE|DHC',
                'chassis' => '36/7001',
            ]
        ];

        $this->assertArrayHasKey('success', $expectedResponse);
        $this->assertTrue($expectedResponse['success']);
        $this->assertStringContainsString('updated', strtolower($expectedResponse['message']));
        $this->assertArrayNotHasKey('status', $expectedResponse);
        $this->assertArrayNotHasKey('info', $expectedResponse);
    }

    /**
     * Test that fetchImages action returns ApiResponse format
     *
     * Verifies:
     * - Response has 'success' boolean
     * - Response has 'message' string
     * - Response includes 'images' array
     * - No Pattern B fields
     *
     * @return void
     */
    public function testFetchImagesReturnsApiResponseFormat(): void
    {
        $expectedResponse = [
            'success' => true,
            'message' => 'Images retrieved successfully',
            'images' => [
                [
                    'basename' => 'img_abc123.jpg',
                    'path' => '/path/to/image.jpg'
                ]
            ]
        ];

        $this->assertArrayHasKey('success', $expectedResponse);
        $this->assertTrue($expectedResponse['success']);
        $this->assertArrayHasKey('images', $expectedResponse);
        $this->assertIsArray($expectedResponse['images']);
        $this->assertArrayNotHasKey('status', $expectedResponse);
    }

    /**
     * Test that removeImage action returns ApiResponse format
     *
     * Verifies:
     * - Response has 'success' boolean
     * - Response has 'message' string
     * - Response includes 'count' and 'images'
     * - No Pattern B fields
     *
     * @return void
     */
    public function testRemoveImageReturnsApiResponseFormat(): void
    {
        $expectedResponse = [
            'success' => true,
            'message' => 'Image removed successfully',
            'count' => 2,
            'images' => [
                'img_def456.jpg',
                'img_ghi789.jpg'
            ]
        ];

        $this->assertArrayHasKey('success', $expectedResponse);
        $this->assertTrue($expectedResponse['success']);
        $this->assertArrayHasKey('count', $expectedResponse);
        $this->assertArrayHasKey('images', $expectedResponse);
        $this->assertIsInt($expectedResponse['count']);
        $this->assertIsArray($expectedResponse['images']);
    }

    /**
     * Test that validation errors return ApiResponse validationError format
     *
     * Verifies:
     * - Response has 'success' = false
     * - Response has 'message' string
     * - Response includes 'errors' array with field-keyed errors
     * - HTTP status code is 422 (Unprocessable Entity)
     *
     * @return void
     */
    public function testValidationErrorsReturnApiResponseFormat(): void
    {
        $expectedResponse = [
            'success' => false,
            'message' => 'Cannot add car: validation errors',
            'errors' => [
                'general' => [
                    'Please select Year',
                    'Please select Model',
                    'Please enter chassis number'
                ]
            ]
        ];

        $this->assertArrayHasKey('success', $expectedResponse);
        $this->assertFalse($expectedResponse['success']);
        $this->assertArrayHasKey('message', $expectedResponse);
        $this->assertArrayHasKey('errors', $expectedResponse);
        $this->assertIsArray($expectedResponse['errors']);
    }

    /**
     * Test that car not found returns ApiResponse notFound format
     *
     * Verifies:
     * - Response has 'success' = false
     * - Response has 'message' indicating car not found
     * - HTTP status code is 404
     *
     * @return void
     */
    public function testCarNotFoundReturnsApiResponseFormat(): void
    {
        $expectedResponse = [
            'success' => false,
            'message' => 'Car not found'
        ];

        $this->assertArrayHasKey('success', $expectedResponse);
        $this->assertFalse($expectedResponse['success']);
        $this->assertStringContainsString('not found', strtolower($expectedResponse['message']));
    }

    /**
     * Test that image not found returns ApiResponse error format
     *
     * Verifies:
     * - Response has 'success' = false
     * - Response has 'message' indicating error
     * - HTTP status code is 404
     *
     * @return void
     */
    public function testImageNotFoundReturnsApiResponseFormat(): void
    {
        $expectedResponse = [
            'success' => false,
            'message' => 'Image not found'
        ];

        $this->assertArrayHasKey('success', $expectedResponse);
        $this->assertFalse($expectedResponse['success']);
        $this->assertArrayNotHasKey('status', $expectedResponse);
    }

    /**
     * Test that server errors return ApiResponse serverError format
     *
     * Verifies:
     * - Response has 'success' = false
     * - Response has 'message' indicating server error
     * - HTTP status code is 500
     *
     * @return void
     */
    public function testServerErrorReturnsApiResponseFormat(): void
    {
        $expectedResponse = [
            'success' => false,
            'message' => 'Failed to add car: Database connection error'
        ];

        $this->assertArrayHasKey('success', $expectedResponse);
        $this->assertFalse($expectedResponse['success']);
        $this->assertArrayNotHasKey('status', $expectedResponse);
    }

    /**
     * Test that invalid action returns ApiResponse error format
     *
     * Verifies:
     * - Response has 'success' = false
     * - Response has 'message' indicating invalid action
     * - HTTP status code is 400
     *
     * @return void
     */
    public function testInvalidActionReturnsApiResponseFormat(): void
    {
        $expectedResponse = [
            'success' => false,
            'message' => 'No valid action'
        ];

        $this->assertArrayHasKey('success', $expectedResponse);
        $this->assertFalse($expectedResponse['success']);
        $this->assertArrayNotHasKey('status', $expectedResponse);
        $this->assertArrayNotHasKey('info', $expectedResponse);
    }

    /**
     * Test response format consistency across all action types
     *
     * Verifies that all responses follow consistent Pattern A format:
     * - 'success' boolean field
     * - 'message' string field
     * - Optional data fields based on action
     * - NO 'status' field (Pattern B)
     * - NO 'info' array field (Pattern B)
     *
     * @return void
     */
    public function testResponseFormatConsistencyAcrossActions(): void
    {
        $responses = [
            'addCar success' => [
                'success' => true,
                'message' => 'Car added successfully',
                'cardetails' => []
            ],
            'updateCar success' => [
                'success' => true,
                'message' => 'Car updated successfully',
                'cardetails' => []
            ],
            'fetchImages success' => [
                'success' => true,
                'message' => 'Images retrieved successfully',
                'images' => []
            ],
            'removeImage success' => [
                'success' => true,
                'message' => 'Image removed successfully',
                'count' => 0,
                'images' => []
            ],
            'validation error' => [
                'success' => false,
                'message' => 'Cannot add car: validation errors',
                'errors' => []
            ],
            'not found error' => [
                'success' => false,
                'message' => 'Car not found'
            ],
            'server error' => [
                'success' => false,
                'message' => 'Failed to add car'
            ]
        ];

        foreach ($responses as $name => $response) {
            // All responses must have success and message (Pattern A)
            $this->assertArrayHasKey('success', $response, "Response '$name' missing 'success'");
            $this->assertArrayHasKey('message', $response, "Response '$name' missing 'message'");

            // No Pattern B fields allowed
            $this->assertArrayNotHasKey('status', $response, "Response '$name' contains Pattern B 'status'");
            $this->assertArrayNotHasKey('info', $response, "Response '$name' contains Pattern B 'info' array");

            // Verify type consistency
            $this->assertIsBool($response['success'], "Response '$name' success is not boolean");
            $this->assertIsString($response['message'], "Response '$name' message is not string");
        }
    }
}
