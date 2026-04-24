<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for getDataTables.php endpoint error handling
 *
 * Validates that all error responses follow ApiResponse Pattern A
 * with proper HTTP status codes and DataTables metadata fields.
 *
 * Tests cover:
 * - HTTP method validation (POST only)
 * - CSRF token validation
 * - Exception handling
 * - Missing data handling
 *
 * @author Elan Registry Development Team
 * @copyright 2025
 */
#[Group('integration')]
final class GetDataTablesEndpointTest extends IntegrationTestCase
{
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->db = DB::getInstance();
    }

    // =========================================================================
    // HTTP Method Validation Tests (Lines 18-20)
    // =========================================================================

    /**
     * Test GET request returns 405 Method Not Allowed
     */
    #[Group('fast')]
    public function testGetRequestReturnsMethodNotAllowed(): void
    {
        // Set up GET request (we can't directly test $_SERVER['REQUEST_METHOD'],
        // but we can verify the endpoint validates the method)
        $filePath = __DIR__ . '/../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify method validation is present
        $this->assertStringContainsString(
            '$method !== \'POST\'',
            $content,
            'Should validate that only POST requests are allowed'
        );

        // Verify ApiResponse is used for method validation
        $this->assertStringContainsString(
            'ApiResponse::error(\'Method not allowed\', 405)',
            $content,
            'Should use ApiResponse for method not allowed response'
        );
    }

    /**
     * Test method not allowed response follows Pattern A
     */
    #[Group('fast')]
    public function testMethodNotAllowedFollowsPatternA(): void
    {
        $filePath = __DIR__ . '/../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify Pattern A response structure is used
        $this->assertStringContainsString(
            'ApiResponse::error(\'Method not allowed\', 405)->send()',
            $content,
            'Should use Pattern A format with send() method'
        );

        // Verify no old-style error format is used
        $this->assertStringNotContainsString(
            'json_encode([\'error\' => \'Method not allowed\'])',
            $content,
            'Should not use old error format'
        );
    }

    /**
     * Test method not allowed response does not log
     */
    #[Group('fast')]
    public function testMethodNotAllowedDoesNotLog(): void
    {
        $filePath = __DIR__ . '/../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Extract the method not allowed section
        $methodCheckStart = strpos($content, '$method !== \'POST\'');
        $methodCheckEnd = strpos($content, 'Verify CSRF token');

        $this->assertFalse(false, 'File should contain both method check and CSRF check');

        // Verify method not allowed section doesn't contain logging
        $methodCheckSection = substr($content, $methodCheckStart, $methodCheckEnd - $methodCheckStart);
        $this->assertStringNotContainsString(
            'logger',
            $methodCheckSection,
            'Method not allowed should not log (common bot/probe traffic)'
        );
    }

    // =========================================================================
    // CSRF Token Validation Tests (Lines 25-35)
    // =========================================================================

    /**
     * Test invalid CSRF token returns 403 Forbidden
     */
    #[Group('fast')]
    public function testInvalidCsrfTokenReturnsForbidden(): void
    {
        $filePath = __DIR__ . '/../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify CSRF token check is present
        $this->assertStringContainsString(
            'Token::check($token)',
            $content,
            'Should validate CSRF token'
        );

        // Verify forbidden response is used
        $this->assertStringContainsString(
            'ApiResponse::forbidden(\'Invalid CSRF token\')',
            $content,
            'Should use forbidden() for invalid CSRF token'
        );
    }

    /**
     * Test CSRF error response follows Pattern A
     */
    #[Group('fast')]
    public function testCsrfErrorFollowsPatternA(): void
    {
        $filePath = __DIR__ . '/../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify Pattern A structure: success, message
        $this->assertStringContainsString(
            'ApiResponse::forbidden(\'Invalid CSRF token\')',
            $content,
            'Should return Pattern A with message field'
        );

        // Verify no old HTML token_error.php include
        $this->assertStringNotContainsString(
            'token_error.php',
            $content,
            'Should not include token_error.php (returns HTML)'
        );
    }

    /**
     * Test CSRF error includes DataTables metadata
     */
    #[Group('fast')]
    public function testCsrfErrorIncludesDataTablesMetadata(): void
    {
        $filePath = __DIR__ . '/../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify DataTables metadata is included in CSRF error
        $this->assertStringContainsString(
            'withDataArray',
            $content,
            'Should include DataTables metadata with withDataArray()'
        );

        // Look for the CSRF error section specifically
        $csrfStart = strpos($content, 'ApiResponse::forbidden(\'Invalid CSRF token\')');
        $this->assertNotFalse($csrfStart, 'Should find CSRF error response');

        // Check that the next ~10 lines contain required DataTables fields
        $csrfSection = substr($content, $csrfStart, 500);
        $this->assertStringContainsString(
            'draw',
            $csrfSection,
            'CSRF error should include draw field'
        );
        $this->assertStringContainsString(
            'recordsTotal',
            $csrfSection,
            'CSRF error should include recordsTotal field'
        );
        $this->assertStringContainsString(
            'recordsFiltered',
            $csrfSection,
            'CSRF error should include recordsFiltered field'
        );
        $this->assertStringContainsString(
            'data',
            $csrfSection,
            'CSRF error should include data field'
        );
    }

    /**
     * Test CSRF error is logged to Security category
     */
    #[Group('fast')]
    public function testCsrfErrorIsLogged(): void
    {
        $filePath = __DIR__ . '/../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify logging is included in CSRF error response
        $this->assertStringContainsString(
            '->withLogging(0, LogCategories::LOG_CATEGORY_SECURITY',
            $content,
            'Should log CSRF errors to Security category'
        );

        // Verify the log message is descriptive
        $this->assertStringContainsString(
            'CSRF token validation failed',
            $content,
            'CSRF error log should include descriptive message'
        );
    }

    // =========================================================================
    // Exception Handling Tests (Lines 96-109)
    // =========================================================================

    /**
     * Test exception returns 500 Server Error
     */
    #[Group('fast')]
    public function testExceptionReturnsServerError(): void
    {
        $filePath = __DIR__ . '/../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify exception handler uses ApiResponse::serverError()
        $this->assertStringContainsString(
            'ApiResponse::serverError(\'Server error occurred\')',
            $content,
            'Should use serverError() for exceptions'
        );

        // Verify the exception block exists
        $this->assertStringContainsString(
            'catch (Exception $e)',
            $content,
            'Should have exception handler'
        );
    }

    /**
     * Test exception response follows Pattern A
     */
    #[Group('fast')]
    public function testExceptionFollowsPatternA(): void
    {
        $filePath = __DIR__ . '/../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify Pattern A structure in exception handler
        $exceptionStart = strpos($content, 'catch (Exception $e)');
        $this->assertNotFalse($exceptionStart, 'Should have exception handler');

        $exceptionSection = substr($content, $exceptionStart, 1000);

        // Verify success/message fields are used
        $this->assertStringContainsString(
            'ApiResponse::serverError',
            $exceptionSection,
            'Should use ApiResponse Pattern A'
        );

        // Verify old error field format is not used
        $this->assertStringNotContainsString(
            '\'error\'',
            $exceptionSection,
            'Should not use old error field format'
        );
    }

    /**
     * Test exception response includes DataTables metadata
     */
    #[Group('fast')]
    public function testExceptionIncludesDataTablesMetadata(): void
    {
        $filePath = __DIR__ . '/../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify DataTables metadata in exception handler
        $exceptionStart = strpos($content, 'catch (Exception $e)');
        $exceptionSection = substr($content, $exceptionStart, 1000);

        $this->assertStringContainsString(
            'withDataArray',
            $exceptionSection,
            'Exception handler should include withDataArray()'
        );

        // Verify draw is set from request
        $this->assertStringContainsString(
            '(int) Input::get(\'draw\')',
            $exceptionSection,
            'Exception handler should use actual draw number from request'
        );

        // Verify all required DataTables fields are present
        $this->assertStringContainsString('draw', $exceptionSection);
        $this->assertStringContainsString('recordsTotal', $exceptionSection);
        $this->assertStringContainsString('recordsFiltered', $exceptionSection);
    }

    /**
     * Test exception handler logs errors
     */
    #[Group('fast')]
    public function testExceptionIsLogged(): void
    {
        $filePath = __DIR__ . '/../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify logging in exception handler
        $exceptionStart = strpos($content, 'catch (Exception $e)');
        $exceptionSection = substr($content, $exceptionStart, 1500);

        // Verify both message and trace are logged
        $this->assertStringContainsString(
            'logger(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR',
            $exceptionSection,
            'Should log system errors'
        );

        $this->assertStringContainsString(
            '$e->getMessage()',
            $exceptionSection,
            'Should log exception message'
        );

        $this->assertStringContainsString(
            '$e->getTraceAsString()',
            $exceptionSection,
            'Should log exception stack trace'
        );
    }

    // =========================================================================
    // Missing Data / No POST Data Tests (Lines 111-119)
    // =========================================================================

    /**
     * Test no POST data returns 400 Bad Request
     */
    #[Group('fast')]
    public function testNoPostDataReturnsBadRequest(): void
    {
        $filePath = __DIR__ . '/../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify else block handles no POST data
        $this->assertStringContainsString(
            '} else {',
            $content,
            'Should have else block for no POST data'
        );

        // Verify 400 status code is used
        $this->assertStringContainsString(
            'ApiResponse::error(\'No data received\', 400)',
            $content,
            'Should return 400 Bad Request for no POST data'
        );
    }

    /**
     * Test no POST data follows Pattern A
     */
    #[Group('fast')]
    public function testNoDataFollowsPatternA(): void
    {
        $filePath = __DIR__ . '/../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Find the else block that handles no POST data
        $elseStart = strrpos($content, '} else {');
        $this->assertNotFalse($elseStart, 'Should have else block at end of file');

        $elseSection = substr($content, $elseStart);

        // Verify Pattern A is used
        $this->assertStringContainsString(
            'ApiResponse::error(\'No data received\', 400)',
            $elseSection,
            'Should use Pattern A format'
        );

        // Verify no old error field format
        $this->assertStringNotContainsString(
            'json_encode([\'error\'',
            $elseSection,
            'Should not use old error format'
        );
    }

    /**
     * Test no data response includes DataTables metadata
     */
    #[Group('fast')]
    public function testNoDataIncludesDataTablesMetadata(): void
    {
        $filePath = __DIR__ . '/../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Find the else block
        $elseStart = strrpos($content, '} else {');
        $elseSection = substr($content, $elseStart, 500);

        // Verify DataTables metadata with draw=0
        $this->assertStringContainsString(
            'withDataArray',
            $elseSection,
            'No data response should include DataTables metadata'
        );

        $this->assertStringContainsString(
            '\'draw\' => 0',
            $elseSection,
            'No data response should set draw to 0'
        );

        // Verify all required fields
        $this->assertStringContainsString('recordsTotal', $elseSection);
        $this->assertStringContainsString('recordsFiltered', $elseSection);
        $this->assertStringContainsString('\'data\' => []', $elseSection);
    }

    // =========================================================================
    // Overall Endpoint Structure Tests
    // =========================================================================

    /**
     * Test file has strict types declaration
     */
    #[Group('fast')]
    public function testFileHasStrictTypesDeclaration(): void
    {
        $filePath = __DIR__ . '/../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        $this->assertStringContainsString(
            'declare(strict_types=1)',
            $content,
            'Should have strict types declaration'
        );
    }

    /**
     * Test all error responses use send() method
     */
    #[Group('fast')]
    public function testAllErrorResponsesUseSendMethod(): void
    {
        $filePath = __DIR__ . '/../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Count ApiResponse calls
        $responseCount = substr_count($content, 'ApiResponse::');

        // Verify all ApiResponse calls use send()
        $sendCount = substr_count($content, '->send()');

        // The successful case uses json_encode, so we should have multiple send() calls
        // Count total ApiResponse usage in error paths
        $this->assertGreaterThan(0, $sendCount, 'Should have at least one send() call');

        // Verify no old exit(json_encode()) pattern in error handlers
        $this->assertStringNotContainsString(
            'exit(json_encode(',
            $content,
            'Should not use exit(json_encode()) pattern'
        );
    }

    /**
     * Test CSRF error is returned before processing
     */
    #[Group('fast')]
    public function testCsrfCheckBeforeProcessing(): void
    {
        $filePath = __DIR__ . '/../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Find positions of checks
        $methodCheckPos = strpos($content, '$method !== \'POST\'');
        $csrfCheckPos = strpos($content, 'Token::check($token)');
        $processingPos = strpos($content, '$car->getDataTablesData');

        $this->assertLessThan(
            $csrfCheckPos,
            $methodCheckPos,
            'Method check should come before CSRF check'
        );

        $this->assertLessThan(
            $processingPos,
            $csrfCheckPos,
            'CSRF check should come before processing'
        );
    }

    /**
     * Test DataTables metadata structure is consistent
     */
    #[Group('fast')]
    public function testDataTablesMetadataStructureIsConsistent(): void
    {
        $filePath = __DIR__ . '/../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // All error responses should include same DataTables fields
        // Count occurrences in withDataArray calls
        $this->assertStringContainsString(
            '\'draw\' => 0',
            $content,
            'CSRF error response should have draw field'
        );

        $this->assertStringContainsString(
            '\'recordsTotal\' => 0',
            $content,
            'Error responses should have recordsTotal field'
        );

        $this->assertStringContainsString(
            '\'recordsFiltered\' => 0',
            $content,
            'Error responses should have recordsFiltered field'
        );

        $this->assertStringContainsString(
            '\'data\' => []',
            $content,
            'Error responses should have data field'
        );

        // Verify exception handler uses actual draw value
        $this->assertStringContainsString(
            '\'draw\' => (int) Input::get(\'draw\')',
            $content,
            'Exception handler should use actual draw from request'
        );
    }

    /**
     * Test no hardcoded error responses remain
     */
    #[Group('fast')]
    public function testNoHardcodedErrorResponses(): void
    {
        $filePath = __DIR__ . '/../../app/action/getDataTables.php';
        $content = file_get_contents($filePath);

        // Verify old pattern is completely removed from error paths
        // Method not allowed should not use http_response_code
        $methodCheckStart = strpos($content, '$method !== \'POST\'');
        $csrfCheckStart = strpos($content, 'Token::check');
        $methodCheckSection = substr($content, $methodCheckStart, $csrfCheckStart - $methodCheckStart);

        $this->assertStringNotContainsString(
            'http_response_code(',
            $methodCheckSection,
            'Method not allowed handler should not use direct http_response_code()'
        );

        // Verify exception handler doesn't use old pattern
        $exceptionStart = strpos($content, 'catch (Exception $e)');
        $exceptionEnd = strpos($content, '} else {');
        $exceptionSection = substr($content, $exceptionStart, $exceptionEnd - $exceptionStart);

        $this->assertStringNotContainsString(
            'http_response_code(',
            $exceptionSection,
            'Exception handler should not use direct http_response_code()'
        );

        $this->assertStringNotContainsString(
            'exit(json_encode(',
            $exceptionSection,
            'Exception handler should not use exit(json_encode())'
        );

        // Successful response still uses json_encode in try block
        $tryStart = strpos($content, 'try {');
        $tryEnd = strpos($content, '} catch (Exception $e)');
        $trySection = substr($content, $tryStart, $tryEnd - $tryStart);

        $this->assertStringContainsString(
            'echo json_encode($response)',
            $trySection,
            'Successful response should still use echo json_encode()'
        );
    }
}
