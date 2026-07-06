<?php

declare(strict_types=1);

/**
 * ApiResponse - Standardized JSON response class for AJAX endpoints
 *
 * Provides a consistent, immutable response format for all API endpoints
 * with factory methods for common response types and integrated logging.
 *
 * Pattern: success/message format only (no backward compatibility for Pattern B)
 *
 * Usage examples:
 * ```php
 * // Success with data
 * ApiResponse::success('Profile updated!')
 *     ->withData('quality_score', 85)
 *     ->withLogging($userId, LogCategories::LOG_CATEGORY_OWNER_ACTIONS, 'Profile updated')
 *     ->send();
 *
 * // Validation error with field-keyed errors
 * ApiResponse::validationError([
 *     'email' => 'Invalid email format',
 *     'name' => 'Required field'
 * ])->send();
 *
 * // Permission denied with logging
 * ApiResponse::forbidden('Admin access required')
 *     ->withLogging(0, LogCategories::LOG_CATEGORY_SECURITY, 'Unauthorized access attempt')
 *     ->send();
 * ```
 *
 * @author Jim Boone
 * @since Issue #437
 */
class ApiResponse
{
    /**
     * HTTP Status code constants
     */
    private const HTTP_OK = 200;
    private const HTTP_BAD_REQUEST = 400;
    private const HTTP_UNAUTHORIZED = 401;
    private const HTTP_FORBIDDEN = 403;
    private const HTTP_NOT_FOUND = 404;
    private const HTTP_UNPROCESSABLE_ENTITY = 422;
    private const HTTP_INTERNAL_SERVER_ERROR = 500;

    /**
     * @var bool Success status of the response
     */
    private bool $success;

    /**
     * @var string Response message
     */
    private string $message;

    /**
     * @var int HTTP status code
     */
    private int $statusCode;

    /**
     * @var array<string, mixed> Additional data to include in response
     */
    private array $data = [];

    /**
     * @var array<string, mixed>|null Pending log entry
     */
    private ?array $pendingLog = null;

    /**
     * Private constructor - use factory methods instead
     *
     * @param bool   $success    Whether the operation was successful
     * @param string $message    Human-readable message
     * @param int    $statusCode HTTP status code
     */
    private function __construct(bool $success, string $message, int $statusCode)
    {
        $this->success = $success;
        $this->message = $message;
        $this->statusCode = $statusCode;
    }

    /**
     * Create a success response
     *
     * @param string $message Success message (default: 'Operation successful')
     *
     * @return self New ApiResponse instance
     */
    public static function success(string $message = 'Operation successful'): self
    {
        return new self(true, $message, self::HTTP_OK);
    }

    /**
     * Create an error response
     *
     * @param string $message    Error message (default: 'An error occurred')
     * @param int    $statusCode HTTP status code (default: 400)
     *
     * @return self New ApiResponse instance
     */
    public static function error(string $message = 'An error occurred', int $statusCode = self::HTTP_BAD_REQUEST): self
    {
        return new self(false, $message, $statusCode);
    }

    /**
     * Create a validation error response with field-keyed errors
     *
     * @param array<string, string> $errors Field-keyed validation errors
     * @param string                $message Overall error message
     *
     * @return self New ApiResponse instance with errors in data
     */
    public static function validationError(array $errors, string $message = 'Validation failed'): self
    {
        $response = new self(false, $message, self::HTTP_UNPROCESSABLE_ENTITY);
        $response->data['errors'] = $errors;
        return $response;
    }

    /**
     * Create an unauthorized (401) response
     *
     * @param string $message Error message (default: 'Authentication required')
     *
     * @return self New ApiResponse instance
     */
    public static function unauthorized(string $message = 'Authentication required'): self
    {
        return new self(false, $message, self::HTTP_UNAUTHORIZED);
    }

    /**
     * Create a forbidden (403) response
     *
     * @param string $message Error message (default: 'Access denied')
     *
     * @return self New ApiResponse instance
     */
    public static function forbidden(string $message = 'Access denied'): self
    {
        return new self(false, $message, self::HTTP_FORBIDDEN);
    }

    /**
     * Create a not found (404) response
     *
     * @param string $message Error message (default: 'Resource not found')
     *
     * @return self New ApiResponse instance
     */
    public static function notFound(string $message = 'Resource not found'): self
    {
        return new self(false, $message, self::HTTP_NOT_FOUND);
    }

    /**
     * Create a server error (500) response
     *
     * @param string $message Error message (default: 'Internal server error')
     *
     * @return self New ApiResponse instance
     */
    public static function serverError(string $message = 'Internal server error'): self
    {
        return new self(false, $message, self::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Add data to the response (immutable - returns new instance)
     *
     * @param string $key   Data key
     * @param mixed  $value Data value
     *
     * @return self New ApiResponse instance with added data
     */
    public function withData(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->data[$key] = $value;
        return $clone;
    }

    /**
     * Add multiple data items at once (immutable - returns new instance)
     *
     * @param array<string, mixed> $data Key-value pairs to add
     *
     * @return self New ApiResponse instance with added data
     */
    public function withDataArray(array $data): self
    {
        $clone = clone $this;
        $clone->data = array_merge($clone->data, $data);
        return $clone;
    }

    /**
     * Set a pending log entry to be executed when send() is called
     *
     * @param int|string $userId User ID for logging (auto-cast to int)
     * @param string $category Must be a LogCategories::LOG_CATEGORY_* constant (e.g., LogCategories::LOG_CATEGORY_OWNER_ACTIONS)
     * @param string $message  Log message
     *
     * @return self New ApiResponse instance with pending log
     */
    public function withLogging(int|string $userId, string $category, string $message): self
    {
        $clone = clone $this;
        $clone->pendingLog = [
            'userId' => (int) $userId,
            'category' => $category,
            'message' => $message,
        ];
        return $clone;
    }

    /**
     * Get the response as an array (useful for testing)
     *
     * @return array<string, mixed> Response array with success, message, and any additional data
     */
    public function toArray(): array
    {
        $response = [
            'success' => $this->success,
            'message' => $this->message,
        ];

        // Merge additional data into response
        foreach ($this->data as $key => $value) {
            $response[$key] = $value;
        }

        return $response;
    }

    /**
     * Get the HTTP status code
     *
     * @return int HTTP status code
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get the pending log entry (useful for testing)
     *
     * @return array<string, mixed>|null Pending log entry or null
     */
    public function getPendingLog(): ?array
    {
        return $this->pendingLog;
    }

    /**
     * Get the success status
     *
     * @return bool Success status
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Get the message
     *
     * @return string Response message
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Get additional data
     *
     * @return array<string, mixed> Additional data
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Send the response as JSON and exit
     *
     * Handles:
     * - Setting Content-Type header
     * - Setting HTTP status code
     * - Output buffering cleanup
     * - Executing pending log entry
     * - JSON encoding and output
     * - Script termination
     *
     * Gracefully handles cases where headers are already sent by
     * logging a warning but still outputting the JSON response.
     *
     * @return never This method terminates script execution
     */
    public function send(): never
    {
        // Execute pending log entry before sending response
        if ($this->pendingLog !== null && function_exists('logger')) {
            logger(
                $this->pendingLog['userId'],
                $this->pendingLog['category'],
                $this->pendingLog['message']
            );
        }

        // Check if headers have already been sent
        if (headers_sent($file, $line)) {
            // Log warning but continue with JSON output
            if (function_exists('logger')) {
                logger(
                    0,
                    LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
                    "ApiResponse: Headers already sent in {$file}:{$line}, cannot set response headers"
                );
            }
        } else {
            // Clean any existing output buffers
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Set response headers
            http_response_code($this->statusCode);
            header('Content-Type: application/json; charset=utf-8');
        }

        // Output JSON and exit
        echo json_encode($this->toArray(), JSON_THROW_ON_ERROR);
        exit;
    }

}
