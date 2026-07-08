<?php
declare(strict_types=1);

use ElanRegistry\Input;
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

$carId = (int) Input::raw('car_id');
if ($carId <= 0) {
    ApiResponse::error('Invalid car ID', 400)
        ->send();
}

try {
    $car = new Car($carId);

    if (!$car->exists()) {
        ApiResponse::notFound('Car not found')
            ->withLogging(
                $user->data()->id,
                LogCategories::LOG_CATEGORY_CAR_ERRORS,
                "Car details lookup failed: Car ID {$carId} not found"
            )
            ->send();
    }

    $data = $car->data();

    $carData = [
        'id' => $data->id,
        'year' => $data->year,
        'type' => $data->type,
        'chassis' => $data->chassis,
        'color' => $data->color,
        'series' => $data->series,
        'fname' => $data->fname,
        'lname' => $data->lname,
        'email' => $data->email,
        'city' => $data->city,
        'state' => $data->state,
        'country' => $data->country,
        'ctime' => $data->ctime,
        'mtime' => $data->mtime
    ];

    ApiResponse::success('Car details retrieved successfully')
        ->withData('car', $carData)
        ->send();

} catch (\Throwable $e) {
    ApiResponse::serverError('An unexpected error occurred. Please try again.')
        ->withLogging(
            $user->data()->id,
            LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            "Car details lookup failed (" . get_class($e) . "): " . $e->getMessage()
        )
        ->send();
}
?>
