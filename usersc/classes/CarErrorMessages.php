<?php

declare(strict_types=1);

/**
 * CarErrorMessages
 *
 * Centralized error message management for the Car class providing
 * user-friendly error messages while preserving technical details for debugging.
 *
 * Categorizes messages into:
 * - User-facing: Clear, actionable messages for end users
 * - Admin: More detailed messages for administrative operations
 * - Technical: Full details for logging/debugging
 *
 * @package ElanRegistry
 * @since   2.9.0
 * @see     https://github.com/unibrain1/elanregistry/issues/261
 */
class CarErrorMessages
{
    /**
     * Get user-friendly error message with optional technical details
     *
     * @param string $errorKey Error message key
     * @param string $context Additional context (user|admin|technical)
     * @param array<string, string> $params Parameters for message interpolation
     * @return string User-friendly error message
     */
    public static function getMessage(string $errorKey, string $context = 'user', array $params = []): string
    {
        $messages = self::getMessages();
        
        if (!isset($messages[$errorKey])) {
            return "An unexpected error occurred. Please try again or contact support.";
        }
        
        $messageData = $messages[$errorKey];
        $message = $messageData[$context] ?? $messageData['user'];
        
        // Replace parameters in message
        foreach ($params as $key => $value) {
            $message = str_replace('{' . $key . '}', $value, $message);
        }
        
        return $message;
    }
    
    /**
     * Get technical error message for logging
     *
     * @param string $errorKey Error message key
     * @param array<string, string> $params Parameters for message interpolation
     * @return string Technical error message for logging
     */
    public static function getTechnicalMessage(string $errorKey, array $params = []): string
    {
        return self::getMessage($errorKey, 'technical', $params);
    }
    
    /**
     * Get admin error message with more context
     *
     * @param string $errorKey Error message key
     * @param array<string, string> $params Parameters for message interpolation
     * @return string Admin error message
     */
    public static function getAdminMessage(string $errorKey, array $params = []): string
    {
        return self::getMessage($errorKey, 'admin', $params);
    }
    
    /**
     * Error message definitions
     *
     * @return array<string, array{user: string, admin: string, technical: string}> Error messages keyed by error code
     */
    private static function getMessages(): array
    {
        return [
            // Car not found errors
            'car_not_found' => [
                'user' => 'The requested car could not be found or may have already been removed.',
                'admin' => 'Car record not found in database - may have been deleted or ID is invalid.',
                'technical' => 'Car not found - database query returned no results for ID: {id}'
            ],
            
            'car_not_found_delete' => [
                'user' => 'The car could not be found or may have already been removed.',
                'admin' => 'Cannot delete car - record not found in database.',
                'technical' => 'Car not found - cannot delete car ID: {id}'
            ],
            
            'car_not_found_transfer' => [
                'user' => 'The car could not be found for ownership transfer.',
                'admin' => 'Cannot transfer ownership - car record not found.',
                'technical' => 'Car not found - cannot transfer car ID: {id}'
            ],
            
            'car_not_found_merge' => [
                'user' => 'The car could not be found for merging.',
                'admin' => 'Cannot merge cars - target car record not found.',
                'technical' => 'Target car not found - cannot merge car ID: {id}'
            ],
            
            'car_not_found_verification' => [
                'user' => 'The car could not be found for verification.',
                'admin' => 'Cannot set verification code - car record not found.',
                'technical' => 'Car not found - cannot set verification code for ID: {id}'
            ],
            
            'car_not_found_verify' => [
                'user' => 'The car could not be found for verification.',
                'admin' => 'Cannot mark car as verified - record not found.',
                'technical' => 'Car not found - cannot mark as verified for ID: {id}'
            ],
            
            'car_not_found_sold' => [
                'user' => 'The car could not be found to mark as sold.',
                'admin' => 'Cannot mark car as sold - record not found.',
                'technical' => 'Car not found - cannot mark as sold for ID: {id}'
            ],
            
            // User/Authentication errors
            'user_not_found' => [
                'user' => 'Unable to transfer ownership: the target user account is not valid.',
                'admin' => 'Transfer failed - target user account not found or inactive.',
                'technical' => 'Target user not found - cannot transfer ownership to user ID: {user_id}'
            ],
            
            'user_auth_required' => [
                'user' => 'You must be logged in to perform this action.',
                'admin' => 'User authentication required for this administrative operation.',
                'technical' => 'User authentication required for {operation}'
            ],
            
            // Database operation errors
            'database_update_failed' => [
                'user' => 'Unable to save changes. Please try again.',
                'admin' => 'Database update failed - check system logs for details.',
                'technical' => 'Database update failed: {error}'
            ],
            
            'verification_code_failed' => [
                'user' => 'Verification code could not be updated. Please try again or contact support.',
                'admin' => 'Failed to set verification code - database update error.',
                'technical' => 'Failed to set verification code: {error}'
            ],
            
            'verification_mark_failed' => [
                'user' => 'Unable to mark car as verified. Please try again or contact support.',
                'admin' => 'Failed to update verification status - database error.',
                'technical' => 'Failed to mark car as verified: {error}'
            ],
            
            'sold_mark_failed' => [
                'user' => 'Unable to mark car as sold. Please try again or contact support.',
                'admin' => 'Failed to update sold status - database error.',
                'technical' => 'Failed to mark car as sold: {error}'
            ],
            
            // Image processing errors
            'image_remove_failed' => [
                'user' => 'Unable to remove image. Please try again or contact support.',
                'admin' => 'Image removal failed - database update error.',
                'technical' => 'Failed to remove image from database: {error}'
            ],
            
            'image_filename_empty' => [
                'user' => 'No image was specified for removal.',
                'admin' => 'Image removal failed - no filename provided.',
                'technical' => 'Image filename cannot be empty'
            ],
            
            'image_encoding_failed' => [
                'user' => 'Unable to process car images. Please try again or contact support.',
                'admin' => 'Image processing failed - JSON encoding error.',
                'technical' => 'Failed to encode images as JSON'
            ],
            
            // Validation errors
            'invalid_verification_code' => [
                'user' => 'The verification code format is not valid.',
                'admin' => 'Verification code must be at least 8 characters long.',
                'technical' => 'Invalid verification code format - minimum 8 characters required'
            ],
            
            'invalid_sold_date' => [
                'user' => 'The sold date format is not valid. Please use YYYY-MM-DD format.',
                'admin' => 'Invalid sold date format - must be YYYY-MM-DD.',
                'technical' => 'Invalid sold date format (expected: Y-m-d): {date}'
            ],
            
            // CSRF and security errors
            'csrf_token_invalid' => [
                'user' => 'Security validation failed. Please refresh the page and try again.',
                'admin' => 'CSRF token validation failed - possible security issue.',
                'technical' => 'Invalid CSRF token for {operation}'
            ],
            
            // Car operation specific errors
            'car_merge_self' => [
                'user' => 'Unable to merge a car with itself.',
                'admin' => 'Cannot merge car with itself - operation not allowed.',
                'technical' => 'Cannot merge a car with itself - car ID: {id}'
            ],
            
            'merge_source_not_found' => [
                'user' => 'The source car for merging could not be found.',
                'admin' => 'Merge failed - source car record not found.',
                'technical' => 'Source car not found - cannot merge car ID: {id}'
            ],
            
            'audit_trail_failed' => [
                'user' => 'Unable to complete the operation. Please contact support.',
                'admin' => 'Operation failed - could not create audit trail entry.',
                'technical' => 'Failed to create audit trail entry for {operation}'
            ],
            
            'car_relationship_failed' => [
                'user' => 'Unable to complete ownership update. Please try again or contact support.',
                'admin' => 'Failed to update car-user relationship in database.',
                'technical' => 'Failed to update car-user relationship: {error}'
            ],
            
            'car_history_transfer_failed' => [
                'user' => 'Unable to complete car merge. Please try again or contact support.',
                'admin' => 'Car merge failed - could not transfer history records.',
                'technical' => 'Failed to transfer car history: {error}'
            ],
            
            // General errors
            'operation_failed' => [
                'user' => 'The operation could not be completed. Please try again or contact support.',
                'admin' => 'Operation failed - check system logs for technical details.',
                'technical' => '{operation} failed: {error}'
            ],
            
            'unexpected_error' => [
                'user' => 'An unexpected error occurred. Please try again or contact support.',
                'admin' => 'Unexpected error occurred during operation.',
                'technical' => 'Unexpected error: {error}'
            ]
        ];
    }
}
