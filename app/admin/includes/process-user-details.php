<?php
declare(strict_types=1);

use ElanRegistry\ApiResponse;
use ElanRegistry\Owner;

/**
 * process-user-details.php
 * AJAX endpoint for retrieving user/owner details (reassignment confirmation)
 *
 * This endpoint provides owner information for admin operations including:
 * - Car reassignment confirmation
 * - Owner information verification
 *
 * Returns user data via ApiResponse JSON format with profile information
 */

require_once '../../../users/init.php';

requireAdminAjax('user details', false);

// Validate user ID
$userId = (int)($_POST['user_id'] ?? 0);
if ($userId <= 0) {
    ApiResponse::error('Invalid user ID', 400)
        ->send();
}

try {
    // Get user with profile data
    $userWithProfile = (new Owner($userId))->data();

    if (!$userWithProfile) {
        ApiResponse::error('User not found', 200)
            ->withLogging(
                $user->data()->id,
                'OwnerErrors',
                "User details lookup failed: User ID {$userId} not found"
            )
            ->send();
    }

    // Build user data response
    $userData = [
        'id' => $userWithProfile->id,
        'fname' => $userWithProfile->fname,
        'lname' => $userWithProfile->lname,
        'email' => $userWithProfile->email,
        'city' => $userWithProfile->city,
        'state' => $userWithProfile->state,
        'country' => $userWithProfile->country,
        'join_date' => $userWithProfile->join_date
    ];

    ApiResponse::success('User details retrieved successfully')
        ->withData('user', $userData)
        ->send();

} catch (Exception $e) {
    ApiResponse::serverError('Database error occurred')
        ->withLogging(
            $user->data()->id,
            'DatabaseError',
            "User details query failed: " . $e->getMessage()
        )
        ->send();
}
?>
