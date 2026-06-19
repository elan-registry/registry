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

// Import exception classes for typed exception handling
use ElanRegistry\Exceptions\ValidationException;
use ElanRegistry\Exceptions\CarException;
use ElanRegistry\Exceptions\ElanRegistryException;

/**
 * Handle DataTables server-side processing requests
 *
 * Processes DataTables AJAX requests for the cars and factory tables.
 * Implements secure parameter validation, CSRF protection, and error handling
 * with standardized ApiResponse Pattern A format.
 *
 * POST Parameters:
 * - draw: DataTables draw counter for request matching
 * - start: Pagination start index
 * - length: Number of records to return
 * - search: Search filter object with 'value' property
 * - order: Column ordering array
 * - columns: Column definitions array
 * - table: Target table ('cars' or 'factory')
 * - csrf: CSRF token for security validation
 * - chassis: (Optional) Chassis number for findCarByChassis special endpoint
 *
 * Sends JSON responses with DataTables metadata (draw, recordsTotal, recordsFiltered, data)
 * in all cases (success and error).
 *
 * @return void Sends JSON response via ApiResponse::send() and exits
 *
 * @throws ValidationException If table parameter is invalid
 * @throws CarException If Car class data retrieval fails
 * @throws ElanRegistryException Caught and logged with 500 response
 */

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
        
        $searchValue = isset($_POST['search']['value']) ? trim((string) $_POST['search']['value']) : '';

        $request = [
            'draw' => $draw,
            'start' => $start,
            'length' => $length,
            'search' => [
                'value' => $searchValue
            ],
            'order'   => $_POST['order'] ?? [],
            'columns' => $_POST['columns'] ?? []
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
            throw new ValidationException('Invalid table parameter: ' . $table);
        }
        
        // Use Car class for secure data retrieval
        $car = new Car();
        $response = $car->getDataTablesData($request, $table);
        
        // Return JSON response
        header('Content-Type: application/json');
        echo json_encode($response);
        
    } catch (ValidationException | CarException $e) {
        // Handle domain-specific validation and car operation errors
        logger(
            $user->data()->id ?? 0,
            $e->getLogCategory(),
            "DataTables error: " . $e->getMessage()
        );

        // Return error response with DataTables metadata
        ApiResponse::error($e->getUserMessage(), $e->getHttpStatusCode())
            ->withDataArray([
                'draw' => (int) Input::get('draw'),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => []
            ])
            ->send();
    } catch (ElanRegistryException $e) {
        // Fallback for any other application exceptions
        logger(
            $user->data()->id ?? 0,
            LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            "DataTables error: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString()
        );

        // Return error response with DataTables metadata
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
    // No POST data received - return bad request error via factory method
    // error() defaults to 400 status code for bad requests
    ApiResponse::error('No data received')
        ->withDataArray([
            'draw' => 0,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => []
        ])
        ->send();
}