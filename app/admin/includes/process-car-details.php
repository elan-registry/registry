<?php
declare(strict_types=1);
/**
 * process-car-details.php
 * AJAX endpoint for retrieving car details (reassign/delete confirmation)
 *
 * This endpoint provides car information for admin operations including:
 * - Car reassignment confirmation
 * - Car deletion confirmation
 *
 * Returns car data via ApiResponse JSON format
 */

require_once '../../../users/init.php';

requireAdminAjax('car details', false);

// Validate car ID
$carId = (int)($_POST['car_id'] ?? 0);
if ($carId <= 0) {
    ApiResponse::error('Invalid car ID', 400)
        ->send();
}

try {
    // Query car details
    $carQ = $db->query("SELECT * FROM cars WHERE id = ?", [$carId]);

    if ($carQ->count() === 0) {
        ApiResponse::notFound('Car not found')
            ->withLogging(
                $user->data()->id,
                LogCategories::LOG_CATEGORY_CAR_ERRORS,
                "Car details lookup failed: Car ID {$carId} not found"
            )
            ->send();
    }

    $car = $carQ->first();

    // Build car data response
    $carData = [
        'id' => $car->id,
        'year' => $car->year,
        'type' => $car->type,
        'chassis' => $car->chassis,
        'color' => $car->color,
        'series' => $car->series,
        'fname' => $car->fname,
        'lname' => $car->lname,
        'email' => $car->email,
        'city' => $car->city,
        'state' => $car->state,
        'country' => $car->country,
        'ctime' => $car->ctime,
        'mtime' => $car->mtime
    ];

    ApiResponse::success('Car details retrieved successfully')
        ->withData('car', $carData)
        ->send();

} catch (Exception $e) {
    ApiResponse::serverError('Database error occurred')
        ->withLogging(
            $user->data()->id,
            LogCategories::LOG_CATEGORY_DATABASE_ERROR,
            "Car details query failed: " . $e->getMessage()
        )
        ->send();
}
?>
