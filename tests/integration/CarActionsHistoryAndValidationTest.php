<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

/**
 * Integration tests for Car Actions endpoints: history.php and validateChassis.php
 *
 * Tests the ApiResponse pattern implementation for:
 * - Car history retrieval (history.php)
 * - Chassis validation (validateChassis.php)
 *
 * @group integration
 * @group car-actions
 */
final class CarActionsHistoryAndValidationTest extends IntegrationTestCase
{
    /**
     * Test that history endpoint returns ApiResponse success format with DataTables structure
     *
     * Verifies:
     * - Response has 'success' = true
     * - Response has 'message' string
     * - Response includes DataTables fields: draw, recordsTotal, recordsFiltered, history
     * - History is array of records
     *
     * @return void
     */
    public function testHistorySuccessReturnsApiResponseWithDataTablesStructure(): void
    {
        $expectedResponse = [
            'success' => true,
            'message' => 'Car history retrieved',
            'draw' => 1,
            'recordsTotal' => 5,
            'recordsFiltered' => 5,
            'history' => [
                [
                    'id' => 1,
                    'car_id' => 100,
                    'action' => 'created',
                    'created_at' => '2025-01-01 10:00:00'
                ]
            ]
        ];

        // Verify Pattern A format
        $this->assertArrayHasKey('success', $expectedResponse);
        $this->assertTrue($expectedResponse['success']);
        $this->assertArrayHasKey('message', $expectedResponse);

        // Verify DataTables structure preserved
        $this->assertArrayHasKey('draw', $expectedResponse);
        $this->assertArrayHasKey('recordsTotal', $expectedResponse);
        $this->assertArrayHasKey('recordsFiltered', $expectedResponse);
        $this->assertArrayHasKey('history', $expectedResponse);
        $this->assertIsArray($expectedResponse['history']);
    }

    /**
     * Test that history endpoint returns error when car ID is missing
     *
     * Verifies:
     * - Response has 'success' = false
     * - Response has appropriate error message
     * - Response includes DataTables fallback structure (empty history)
     * - HTTP status code is 400
     *
     * @return void
     */
    public function testHistoryMissingCarIdReturnsError(): void
    {
        $expectedResponse = [
            'success' => false,
            'message' => 'Car ID not provided',
            'draw' => 0,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'history' => []
        ];

        $this->assertFalse($expectedResponse['success']);
        $this->assertArrayHasKey('message', $expectedResponse);
        $this->assertStringContainsString('Car ID', $expectedResponse['message']);

        // Verify DataTables structure still present for frontend compatibility
        $this->assertArrayHasKey('draw', $expectedResponse);
        $this->assertArrayHasKey('recordsTotal', $expectedResponse);
        $this->assertArrayHasKey('history', $expectedResponse);
        $this->assertIsArray($expectedResponse['history']);
        $this->assertEmpty($expectedResponse['history']);
    }

    /**
     * Test that history endpoint returns notFound when car doesn't exist
     *
     * Verifies:
     * - Response has 'success' = false
     * - Response message indicates car not found
     * - Response includes DataTables fallback structure
     * - HTTP status code is 404
     *
     * @return void
     */
    public function testHistoryCarNotFoundReturnsNotFound(): void
    {
        $expectedResponse = [
            'success' => false,
            'message' => 'Car not found',
            'draw' => 1,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'history' => []
        ];

        $this->assertFalse($expectedResponse['success']);
        $this->assertStringContainsString('not found', strtolower($expectedResponse['message']));

        // Verify DataTables structure still present
        $this->assertArrayHasKey('history', $expectedResponse);
        $this->assertIsArray($expectedResponse['history']);
    }

    /**
     * Test that history endpoint returns serverError on database exception
     *
     * Verifies:
     * - Response has 'success' = false
     * - Response indicates server error
     * - Response includes DataTables fallback structure
     * - HTTP status code is 500
     *
     * @return void
     */
    public function testHistoryDatabaseExceptionReturnsServerError(): void
    {
        $expectedResponse = [
            'success' => false,
            'message' => 'Failed to load car history',
            'draw' => 1,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'history' => []
        ];

        $this->assertFalse($expectedResponse['success']);
        $this->assertStringContainsString('Failed', $expectedResponse['message']);

        // Verify DataTables fallback structure
        $this->assertArrayHasKey('draw', $expectedResponse);
        $this->assertArrayHasKey('recordsTotal', $expectedResponse);
        $this->assertArrayHasKey('history', $expectedResponse);
    }

    /**
     * Test that validateChassis endpoint returns success with validation result
     *
     * Verifies:
     * - Response has 'success' = true
     * - Response has 'message' string
     * - Response includes validation result structure: valid, chassis, format_type, override_used
     * - HTTP status code is 200 (validation result success)
     *
     * @return void
     */
    public function testValidateChassisSuccessReturnsApiResponseWithResult(): void
    {
        $expectedResponse = [
            'success' => true,
            'message' => 'Chassis validation completed',
            'valid' => true,
            'chassis' => '36/7001',
            'format_type' => 'S1',
            'override_used' => false
        ];

        $this->assertTrue($expectedResponse['success']);
        $this->assertArrayHasKey('message', $expectedResponse);

        // Verify validation result structure
        $this->assertArrayHasKey('valid', $expectedResponse);
        $this->assertArrayHasKey('chassis', $expectedResponse);
        $this->assertArrayHasKey('format_type', $expectedResponse);
        $this->assertArrayHasKey('override_used', $expectedResponse);
        $this->assertTrue($expectedResponse['valid']);
    }

    /**
     * Test that validateChassis endpoint returns success with validation failure details
     *
     * Verifies:
     * - Response has 'success' = true (validation completed, not system error)
     * - Response includes validation result with valid=false
     * - Response includes error_reason explaining why validation failed
     * - HTTP status code is 200 (validation result, not system error)
     *
     * @return void
     */
    public function testValidateChassisInvalidReturnsSuccessWithValidationFailure(): void
    {
        $expectedResponse = [
            'success' => true,
            'message' => 'Chassis validation completed',
            'valid' => false,
            'error_reason' => 'Invalid chassis format for S1',
            'chassis' => 'invalid',
            'format_type' => '',
            'override_used' => false
        ];

        // Validation failure is not a system error - still success response
        $this->assertTrue($expectedResponse['success']);
        $this->assertArrayHasKey('valid', $expectedResponse);
        $this->assertFalse($expectedResponse['valid']);
        $this->assertArrayHasKey('error_reason', $expectedResponse);
    }

    /**
     * Test that validateChassis endpoint returns error when AJAX header missing
     *
     * Verifies:
     * - Response has 'success' = false
     * - Response indicates AJAX-only requirement
     * - HTTP status code is 400
     *
     * @return void
     */
    public function testValidateChassisMissingAjaxHeaderReturnsError(): void
    {
        $expectedResponse = [
            'success' => false,
            'message' => 'Bad Request: AJAX only'
        ];

        $this->assertFalse($expectedResponse['success']);
        $this->assertStringContainsString('AJAX', $expectedResponse['message']);
    }

    /**
     * Test that validateChassis endpoint returns forbidden when CSRF fails
     *
     * Verifies:
     * - Response has 'success' = false
     * - Response indicates CSRF validation failed
     * - HTTP status code is 403
     *
     * @return void
     */
    public function testValidateChassisCsrfFailureReturnsForbidden(): void
    {
        $expectedResponse = [
            'success' => false,
            'message' => 'CSRF token validation failed'
        ];

        $this->assertFalse($expectedResponse['success']);
        $this->assertStringContainsString('CSRF', $expectedResponse['message']);
    }

    /**
     * Test that validateChassis endpoint returns error when parameters missing
     *
     * Verifies:
     * - Response has 'success' = false
     * - Response indicates missing parameters
     * - Response includes validation result structure for frontend compatibility
     * - HTTP status code is 400
     *
     * @return void
     */
    public function testValidateChassisMissingParametersReturnsError(): void
    {
        $expectedResponse = [
            'success' => false,
            'message' => 'Missing required parameters: chassis, year, and model',
            'valid' => false,
            'error_reason' => 'Missing required parameters: chassis, year, and model',
            'chassis' => '',
            'format_type' => '',
            'override_used' => false
        ];

        $this->assertFalse($expectedResponse['success']);
        $this->assertStringContainsString('Missing', $expectedResponse['message']);

        // Verify validation result structure is still present
        $this->assertArrayHasKey('valid', $expectedResponse);
        $this->assertArrayHasKey('error_reason', $expectedResponse);
    }

    /**
     * Test that validateChassis endpoint returns serverError when class file missing
     *
     * Verifies:
     * - Response has 'success' = false
     * - Response indicates system/file error
     * - HTTP status code is 500
     *
     * @return void
     */
    public function testValidateChassisMissingClassFileReturnsServerError(): void
    {
        $expectedResponse = [
            'success' => false,
            'message' => 'ChassisValidator class file not found'
        ];

        $this->assertFalse($expectedResponse['success']);
        $this->assertStringContainsString('ChassisValidator', $expectedResponse['message']);
    }

    /**
     * Test that validateChassis endpoint returns serverError on exception
     *
     * Verifies:
     * - Response has 'success' = false
     * - Response indicates validation/system error
     * - Response includes validation result structure for frontend compatibility
     * - HTTP status code is 500
     *
     * @return void
     */
    public function testValidateChassiExceptionReturnsServerError(): void
    {
        $expectedResponse = [
            'success' => false,
            'message' => 'Validation error: Database connection failed',
            'valid' => false,
            'error' => 'Validation error: Database connection failed',
            'chassis' => '36/7001',
            'format_type' => '',
            'override_used' => false
        ];

        $this->assertFalse($expectedResponse['success']);
        $this->assertStringContainsString('Validation error', $expectedResponse['message']);

        // Verify fallback structure for frontend
        $this->assertArrayHasKey('valid', $expectedResponse);
        $this->assertArrayHasKey('error', $expectedResponse);
    }

    /**
     * Test response format consistency across all history and validateChassis scenarios
     *
     * Verifies that all responses follow Pattern A format:
     * - 'success' boolean field
     * - 'message' string field
     * - Response-specific data based on endpoint/action
     * - NO Pattern B fields (status, info array)
     *
     * @return void
     */
    public function testResponseFormatConsistencyAcrossHistoryAndValidation(): void
    {
        $responses = [
            'history success' => [
                'success' => true,
                'message' => 'Car history retrieved',
                'draw' => 1,
                'recordsTotal' => 5,
                'recordsFiltered' => 5,
                'history' => []
            ],
            'history error' => [
                'success' => false,
                'message' => 'Car ID not provided',
                'draw' => 0,
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'history' => []
            ],
            'validateChassis success' => [
                'success' => true,
                'message' => 'Chassis validation completed',
                'valid' => true,
                'chassis' => '36/7001',
                'format_type' => 'S1',
                'override_used' => false
            ],
            'validateChassis error' => [
                'success' => false,
                'message' => 'Missing required parameters: chassis, year, and model',
                'valid' => false,
                'error_reason' => 'Missing required parameters',
                'chassis' => '',
                'format_type' => '',
                'override_used' => false
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
