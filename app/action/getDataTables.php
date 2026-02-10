<?php

declare(strict_types=1);

/**
 * Secure DataTables server-side processing endpoint
 *
 * Replaces the vulnerable getList.php with secure implementation using Car class.
 * Uses prepared statements and input validation to prevent SQL injection.
 *
 * @author Elan Registry Security Team
 * @copyright 2025
 */

require_once '../../users/init.php';

// Security: Only process POST requests
if ($method !== 'POST') {
    ApiResponse::error('Method not allowed', 405)->send();
}

// Security: Verify CSRF token
if (Input::exists('post')) {
    $token = Input::get('csrf');
    if (!Token::check($token)) {
        ApiResponse::forbidden('Invalid CSRF token')
            ->withDataArray([
                'draw' => 0,
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => []
            ])
            ->withLogging($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SECURITY, 'CSRF token validation failed in DataTables endpoint')
            ->send();
    }
    
    try {
        // Handle complex DataTables parameters more carefully
        $draw = (int) Input::get('draw');
        $start = (int) Input::get('start');
        $length = (int) Input::get('length');
        $table = Input::get('table');
        
        // Get search value from nested array
        $searchValue = '';
        $searchData = Input::get('search');
        if (is_array($searchData) && isset($searchData['value'])) {
            $searchValue = htmlspecialchars(strip_tags($searchData['value']), ENT_QUOTES, 'UTF-8');
        }
        
        $request = [
            'draw' => $draw,
            'start' => $start,
            'length' => $length,
            'search' => [
                'value' => $searchValue
            ],
            'order' => Input::get('order') ?? [],
            'columns' => Input::get('columns') ?? []
        ];
        
        // Handle special endpoints
        if ($table === 'findCarByChassis') {
            $chassis = Input::get('chassis');
            if (empty($chassis)) {
                ApiResponse::error('Chassis number required')
                    ->send();
            }

            $carQuery = $db->query("SELECT id FROM cars WHERE chassis = ? LIMIT 1", [$chassis]);
            if ($carQuery->count() > 0) {
                $car = $carQuery->first();
                ApiResponse::success('Car found')
                    ->withData('car_id', $car->id)
                    ->send();
            } else {
                ApiResponse::success('No car found for this chassis number')
                    ->withData('car_id', null)
                    ->send();
            }
        }
        
        // Validate table parameter
        if (!in_array($table, ['cars', 'factory'], true)) {
            throw new InvalidArgumentException('Invalid table parameter: ' . $table);
        }
        
        // Use Car class for secure data retrieval
        $car = new Car();
        $response = $car->getDataTablesData($request, $table);
        
        // Return JSON response
        header('Content-Type: application/json');
        echo json_encode($response);
        
    } catch (Exception $e) {
        // Log detailed error information for debugging
        logger(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, "DataTables error: " . $e->getMessage());
        logger(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, "DataTables error trace: " . $e->getTraceAsString());

        // Return standardized error response with DataTables metadata
        ApiResponse::serverError('Server error occurred')
            ->withDataArray([
                'draw' => (int) Input::get('draw'),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => []
            ])
            ->send();
    }
} else {
    ApiResponse::error('No data received', 400)
        ->withDataArray([
            'draw' => 0,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => []
        ])
        ->send();
}