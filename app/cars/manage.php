<?php

/**
 * manage.php
 * Enhanced admin interface for managing cars in the registry.
 *
 * Provides modern card-based interface for duplicate detection and resolution.
 * Duplicates are defined as cars with identical type AND chassis number.
 * Includes car reassignment tools and comprehensive merge functionality.
 *
 * @author Elan Registry Admin
 * @copyright 2025
 * @updated Issue #233 - Enhanced duplicate detection UI and logic
 */
require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

// TODO - Reimagine managing cars for admin - Tracked in GitHub Issue #213

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Duplicate detection query - finds cars with same type AND chassis number
$duplicates = "SELECT  a.* FROM cars a JOIN(  SELECT  type,chassis,  COUNT(*)  FROM  users_carsview  WHERE chassis <> '' GROUP BY type,chassis HAVING COUNT(*) > 1) b ON a.chassis = b.chassis AND a.type = b.type ORDER BY a.chassis, a.type";

// Get list of suspected duplicates
$duplicatesQ = $db->query($duplicates);
$duplicateCars = $duplicatesQ->results();

$errors                     = [];
$successes                  = [];

//Form is posted now process it
if (Input::exists('post')) {
    $token = Input::get('csrf');
    if (!Token::check($token)) {
        include $abs_us_root . $us_url_root . 'usersc/scripts/token_error.php';
    } else {
        // Do something!
        $command = Input::get('command');

        if ($command) {
            switch ($command) {
                // Assign car to a new owner
                case "reassign":
                    $user_id = (int) Input::get('user_id');
                    $car_id  = (int) Input::get('car_id');

                    // Validate inputs
                    if (!$user_id || !$car_id) {
                        $errors[] = 'Please provide valid car ID and user ID';
                        break;
                    }

                    // Get the new user details
                    $userQ = $db->findById($user_id, "usersview");
                    $userData = $userQ->results();

                    // Check if user was found
                    if (empty($userData)) {
                        $errors[] = "User ID $user_id not found";
                        break;
                    }

                    // Check if car exists
                    $carQ = $db->query("SELECT id FROM cars WHERE id = ?", [$car_id]);
                    if ($carQ->count() === 0) {
                        $errors[] = "Car ID $car_id not found";
                        break;
                    }

                    // Build fields array safely
                    $targetUser = $userData[0];
                    $fields['user_id']   = $targetUser->id;
                    $fields['email']     = $targetUser->email ?? '';
                    $fields['username']  = $targetUser->username ?? '';
                    $fields['fname']     = $targetUser->fname ?? '';
                    $fields['lname']     = $targetUser->lname ?? '';
                    $fields['join_date'] = $targetUser->join_date ?? date('Y-m-d G:i:s');
                    $fields['city']      = $targetUser->city ?? '';
                    $fields['state']     = $targetUser->state ?? '';
                    $fields['country']   = $targetUser->country ?? '';
                    $fields['lat']       = $targetUser->lat ?? null;
                    $fields['lon']       = $targetUser->lon ?? null;
                    $fields['website']   = $targetUser->website ?? '';

                    // Update the car details with the new owner
                    $updateResult = $db->update('cars', $car_id, $fields);
                    if ($db->error()) {
                        $errors[] = "Failed to update car: " . $db->errorString();
                        break;
                    }

                    // Update the cross reference table
                    $db->query("UPDATE car_user SET userid = ? WHERE carid = ?", [$user_id, $car_id]);
                    if ($db->error()) {
                        $errors[] = "Failed to update car-user relationship: " . $db->errorString();
                        break;
                    }

                    // Add a record to the history with some information on the assignment
                    $ownerName = $targetUser->fname && $targetUser->lname ? "{$targetUser->fname} {$targetUser->lname}" : "User ID $user_id";
                    $fields['comments'] = "Car was reassigned to $ownerName (User ID: $user_id) by admin " . $user->data()->id;
                    $fields['operation'] = "NEWOWNER";

                    $fields['ctime'] = date('Y-m-d G:i:s'); // Set date of this record
                    $fields['mtime'] = $fields['ctime'];

                    $fields['car_id'] = $car_id;
                    $historyResult = $db->insert("cars_hist", $fields);
                    
                    if ($db->error()) {
                        $errors[] = "Warning: Failed to log history: " . $db->errorString();
                    }

                    $successes[] = "Car ID $car_id successfully reassigned to $ownerName (User ID: $user_id)";
                    logger($user->data()->id, "ElanRegistry", "Car ID $car_id reassigned to User ID $user_id");

                    break;

                // Merge two cars because a car is a) a duplicate or b)the car was sold to a new owner and the new owner created a record.
                case "merge":
                    // Validate input
                    $cars = Input::get('cars');
                    $reason = Input::get('reason');
                    if (!$cars || !$reason) {
                        $errors[] = 'Select 2 cars to merge and a reason';
                        break;
                    }

                    if (count($cars) <> 2) {
                        $errors[] = 'Select 2 cars to merge';
                        break;
                    }
                    if (count($reason) <> 1) {
                        $errors[] = 'Select 1 reason code';
                        break;
                    } else {
                        // Build the reason string
                        switch ($reason[0]) {
                            case "duplicate":
                                // Determine the newest car
                                if ($cars[0] > $cars[1]) {
                                    $new_car_id = $cars[0];
                                    $old_car_id = $cars[1];
                                } else {
                                    $new_car_id = $cars[1];
                                    $old_car_id = $cars[0];
                                }
                                $fields['comments'] = "Car $old_car_id is a duplicate of $new_car_id.  The history of $old_car_id has been merged with $new_car_id and $old_car_id deleted.";
                                $fields['operation'] = "DUPLICATE";
                                break;

                            case "newownerNewToOld":
                                // Determine the newest car
                                if ($cars[0] > $cars[1]) {
                                    $new_car_id = $cars[0];
                                    $old_car_id = $cars[1];
                                } else {
                                    $new_car_id = $cars[1];
                                    $old_car_id = $cars[0];
                                }
                                $fields['comments'] = "Car $old_car_id was sold to a new owner and the new owner created a record for the same car as $new_car_id. The history of $old_car_id has been merged with $new_car_id and $old_car_id deleted.";
                                $fields['operation'] = "NEWOWNER";
                                break;

                            case "newownerOldToNew":
                                if ($cars[0] > $cars[1]) {
                                    $new_car_id = $cars[1];
                                    $old_car_id = $cars[0];
                                } else {
                                    $new_car_id = $cars[0];
                                    $old_car_id = $cars[1];
                                }
                                $fields['comments'] = "Car $old_car_id was sold to a new owner and the new owner created a record for the same car as $new_car_id. The history of $old_car_id has been merged with $new_car_id and $old_car_id deleted.";
                                $fields['operation'] = "NEWOWNER";
                                break;

                            default:

                                // This should never happen (Yeah right)
                                $fields['comments'] = "Car $old_car_id was merged with $new_car_id.  Car $old_car_id has been deleted.";
                                $fields['operation'] = "DEFAULT";
                                break;
                        }
                    }

                    // Merge the history
                    $db->query("UPDATE cars_hist SET car_id = ? WHERE car_id = ?", [$new_car_id, $old_car_id]);
                    if ($db->error()) {
                        $errors[] = $db->errorString();
                        logger($user->data()->id, "ElanRegistry", "FAILED: Merged CAR $old_car_id to CAR $new_car_id.");
                    } else {
                        // Unassign from the previous owner
                        $db->query("DELETE FROM car_user WHERE carid = ?", [$old_car_id]);

                        // Remove old car
                        $db->query("DELETE FROM cars WHERE id = ?", [$old_car_id]);

                        // Add a record to the history with some information on the assignment
                        $fields['car_id'] = $new_car_id;


                        $fields['ctime'] = date('Y-m-d G:i:s'); // Set date of this record
                        $fields['mtime'] = $fields['ctime'];


                        $db->insert("cars_hist", $fields);

                        $successes[] = $fields['comments'];
                        logger($user->data()->id, "ElanRegistry", $fields['comments']);
                    }
                    // Now update suspected duplicates
                    $duplicatesQ = $db->query($duplicates);
                    $duplicateCars = $duplicatesQ->results();

                    break;

                // Delete car permanently
                case "delete":
                    $car_id = (int) Input::get('car_id');
                    $confirmation = Input::get('confirmation');
                    
                    if (!$car_id) {
                        $errors[] = 'Please provide a valid car ID';
                        break;
                    }
                    
                    if ($confirmation !== 'DELETE') {
                        $errors[] = 'Please type DELETE in the confirmation field to proceed';
                        break;
                    }
                    
                    // Get car details before deletion for logging
                    $carQ = $db->query("SELECT * FROM cars WHERE id = ?", [$car_id]);
                    if ($carQ->count() === 0) {
                        $errors[] = "Car ID $car_id not found";
                        break;
                    }
                    
                    $carData = $carQ->first();
                    
                    // Add deletion record to history before removing the car
                    $fields = [];
                    $fields['car_id'] = $car_id;
                    $fields['comments'] = "Car ID $car_id ({$carData->chassis}) permanently deleted by admin " . $user->data()->id . ". Reason: Administrative deletion via car management.";
                    $fields['operation'] = "DELETE";
                    $fields['ctime'] = date('Y-m-d G:i:s');
                    $fields['mtime'] = $fields['ctime'];
                    
                    $db->insert("cars_hist", $fields);
                    
                    // Remove from car_user relationship table
                    $db->query("DELETE FROM car_user WHERE carid = ?", [$car_id]);
                    
                    // Remove the car record
                    $result = $db->query("DELETE FROM cars WHERE id = ?", [$car_id]);
                    
                    if ($db->error()) {
                        $errors[] = "Failed to delete car: " . $db->errorString();
                        logger($user->data()->id, "ElanRegistry", "FAILED: Delete car ID $car_id - " . $db->errorString());
                    } else {
                        $successes[] = "Car ID $car_id ({$carData->chassis}) has been permanently deleted";
                        logger($user->data()->id, "ElanRegistry", "SUCCESS: Deleted car ID $car_id ({$carData->chassis})");
                    }
                    
                    break;

                // Get car details for confirmation (AJAX endpoint)
                case "getCarDetails":
                    // Clean output buffer and set JSON headers
                    if (ob_get_level()) {
                        ob_clean();
                    }
                    header('Content-Type: application/json');
                    
                    $car_id = (int) Input::get('car_id');
                    
                    if (!$car_id) {
                        echo json_encode(['success' => false, 'error' => 'Invalid car ID']);
                        exit;
                    }
                    
                    try {
                        $carQ = $db->query("SELECT * FROM cars WHERE id = ?", [$car_id]);
                        if ($carQ->count() === 0) {
                            echo json_encode(['success' => false, 'error' => 'Car not found']);
                            exit;
                        }
                        
                        $car = $carQ->first();
                        echo json_encode([
                            'success' => true,
                            'car' => [
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
                            ]
                        ]);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
                    }
                    exit;

                // Get user details for reassignment (AJAX endpoint)
                case "getUserDetails":
                    // Clean output buffer and set JSON headers
                    if (ob_get_level()) {
                        ob_clean();
                    }
                    header('Content-Type: application/json');
                    
                    $user_id = (int) Input::get('user_id');
                    
                    if (!$user_id) {
                        echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
                        exit;
                    }
                    
                    try {
                        $user = null;
                        
                        // Try multiple approaches to find the user
                        // 1. First try usersview (same as reassignment logic)
                        $userQ = $db->findById($user_id, "usersview");
                        $userData = $userQ->results();
                        
                        if (!empty($userData)) {
                            $user = $userData[0];
                        } else {
                            // 2. Try direct users table query (for admin accounts that might not be in view)
                            $userQ = $db->query("SELECT u.*, p.city, p.state, p.country FROM users u LEFT JOIN profiles p ON u.id = p.user_id WHERE u.id = ?", [$user_id]);
                            if ($userQ->count() > 0) {
                                $user = $userQ->first();
                            }
                        }
                        
                        // 3. Last resort: try just the users table without profile join
                        if (!$user) {
                            $userQ = $db->query("SELECT * FROM users WHERE id = ?", [$user_id]);
                            if ($userQ->count() > 0) {
                                $user = $userQ->first();
                                // Set default values for missing profile fields
                                $user->city = $user->city ?? '';
                                $user->state = $user->state ?? '';
                                $user->country = $user->country ?? '';
                            }
                        }
                        
                        if (!$user) {
                            echo json_encode(['success' => false, 'error' => 'User not found']);
                            exit;
                        }
                        echo json_encode([
                            'success' => true,
                            'user' => [
                                'id' => $user->id,
                                'username' => $user->username,
                                'fname' => $user->fname,
                                'lname' => $user->lname,
                                'email' => $user->email,
                                'city' => $user->city,
                                'state' => $user->state,
                                'country' => $user->country,
                                'join_date' => $user->join_date
                            ]
                        ]);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
                    }
                    exit;

                // This will never happen (Yeah right)
                default:
                    echo "The cake is a lie";
                    break;
            }
        }
    }
}

?>
<div class="page-wrapper">
    <div class="container-fluid">
        <div class="page-container">
            
            <!-- Page Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="d-sm-flex align-items-center justify-content-between">
                        <div>
                            <h1 class="h3 mb-2 text-gray-800">
                                <i class="fas fa-cogs"></i> Car Management
                            </h1>
                            <p class="text-muted mb-0">Admin tools for managing duplicate cars, reassignments, and data quality</p>
                        </div>
                        <div>
                            <a href="../reports/data-quality.php" class="btn btn-outline-primary">
                                <i class="fas fa-chart-line"></i> Data Quality Reports
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <!-- Messages Section -->
                <div class="col-lg-4 col-md-4 mb-4">
                    <div class="card registry-card h-100">
                        <div class="card-header">
                            <h3 class="mb-0"><i class="fas fa-bell"></i> Messages</h3>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($errors)) { ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <strong><i class="fas fa-exclamation-triangle"></i> Error!</strong>
                                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                    <ul class="mb-0 mt-2">
                                        <?php foreach ($errors as $error) { ?>
                                            <li><?= htmlspecialchars($error) ?></li>
                                        <?php } ?>
                                    </ul>
                                </div>
                            <?php } ?>
                            <?php if (!empty($successes)) { ?>
                                <div class="alert alert-success alert-dismissible fade show" role="alert">
                                    <strong><i class="fas fa-check-circle"></i> Success!</strong>
                                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                        <span aria-hidden="true">&times;</span>
                                    </button>
                                    <ul class="mb-0 mt-2">
                                        <?php foreach ($successes as $success) { ?>
                                            <li><?= htmlspecialchars($success) ?></li>
                                        <?php } ?>
                                    </ul>
                                </div>
                            <?php } ?>
                            <?php if (empty($errors) && empty($successes)) { ?>
                                <div class="text-muted text-center py-3">
                                    <i class="fas fa-info-circle"></i> Messages will appear here after operations.
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>

                <!-- Car Reassignment Section -->
                <div class="col-lg-4 col-md-4 mb-4">
                    <div class="card registry-card h-100">
                        <div class="card-header">
                            <h3 class="mb-0"><i class="fas fa-exchange-alt"></i> Reassign Car</h3>
                        </div>
                        <div class="card-body">
                            <form name="assignCar" action="manage.php" method="POST" class="reassign-form needs-validation" novalidate>
                                <!-- Car Selection -->
                                <div class="form-group">
                                    <label for="reassign_car_id" class="form-label">Select Car</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="reassign_car_id" name="car_id" placeholder="Enter Car ID" required>
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-info" type="button" id="lookupCarBtn">
                                                <i class="fas fa-search"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="invalid-feedback">Please provide a valid car ID.</div>
                                    
                                    <!-- Car Details Display -->
                                    <div id="carDetails" class="mt-2" style="display: none;">
                                        <div class="alert alert-info alert-sm">
                                            <h6 class="alert-heading mb-1"><i class="fas fa-car"></i> Car Details</h6>
                                            <div id="carInfo"></div>
                                            <div class="mt-2">
                                                <strong>Current Owner:</strong> <span id="currentOwner"></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- User Selection -->
                                <div class="form-group">
                                    <label for="reassign_user_id" class="form-label">Select New Owner</label>
                                    
                                    <!-- No Owner Checkbox -->
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="noOwnerCheckbox" value="83">
                                        <label class="form-check-label" for="noOwnerCheckbox">
                                            <i class="fas fa-user-slash text-muted"></i> Assign to "No Owner" (ID: 83)
                                        </label>
                                    </div>
                                    
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="reassign_user_id" name="user_id" placeholder="Enter User ID" required>
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-info" type="button" id="lookupUserBtn">
                                                <i class="fas fa-search"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="invalid-feedback">Please provide a valid user ID.</div>
                                    
                                    <!-- User Details Display -->
                                    <div id="userDetails" class="mt-2" style="display: none;">
                                        <div class="alert alert-success alert-sm">
                                            <h6 class="alert-heading mb-1"><i class="fas fa-user"></i> New Owner</h6>
                                            <div id="userInfo"></div>
                                        </div>
                                    </div>
                                    
                                    <!-- No Owner Display -->
                                    <div id="noOwnerDetails" class="mt-2" style="display: none;">
                                        <div class="alert alert-warning alert-sm">
                                            <h6 class="alert-heading mb-1"><i class="fas fa-user-slash"></i> No Owner</h6>
                                            <div>Car will be assigned to <strong>"No Owner"</strong> registry account.<br>
                                            <small class="text-muted">Used for cars without current owner information.</small></div>
                                        </div>
                                    </div>
                                </div>

                                <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                                <input type="hidden" name="command" value="reassign" />
                                <button type="submit" class="btn btn-primary btn-lg w-100" id="reassignBtn" disabled>
                                    <i class="fas fa-user-friends"></i> Reassign Car
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Delete Car Section -->
                <div class="col-lg-4 col-md-4 mb-4">
                    <div class="card registry-card border-danger h-100">
                        <div class="card-header bg-danger text-white">
                            <h3 class="mb-0"><i class="fas fa-trash-alt"></i> Delete Car</h3>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-danger alert-sm">
                                <p class="mb-1"><strong><i class="fas fa-exclamation-triangle"></i> Warning</strong></p>
                                <p class="mb-0 small">Permanently deletes car record and all associated data. Cannot be undone.</p>
                            </div>
                            
                            <form name="deleteCar" action="manage.php" method="POST" class="delete-form needs-validation" novalidate>
                                <div class="form-group">
                                    <label for="delete_car_id" class="form-label">Car ID to Delete</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="delete_car_id" name="car_id" placeholder="Enter Car ID" required>
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-info" type="button" id="lookupDeleteCarBtn">
                                                <i class="fas fa-search"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="invalid-feedback">Please provide a valid car ID.</div>
                                    
                                    <!-- Car Details Display -->
                                    <div id="deleteCarDetails" class="mt-2" style="display: none;">
                                        <div class="alert alert-warning alert-sm">
                                            <h6 class="alert-heading mb-1"><i class="fas fa-car"></i> Car to Delete</h6>
                                            <div id="deleteCarInfo"></div>
                                            <div class="mt-2">
                                                <strong>Current Owner:</strong> <span id="deleteCurrentOwner"></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="delete_confirmation" class="form-label">Type "DELETE" to confirm</label>
                                    <input type="text" class="form-control" id="delete_confirmation" name="confirmation" placeholder="Type DELETE to confirm" required>
                                    <div class="invalid-feedback">You must type DELETE to confirm deletion.</div>
                                </div>
                                <button type="submit" class="btn btn-danger btn-lg w-100" id="deleteBtn" disabled>
                                    <i class="fas fa-trash-alt"></i> Delete Car
                                </button>
                                <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                                <input type="hidden" name="command" value="delete" />
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Duplicate Detection Section -->
            <div class="row">
                <div class="col-12">
                    <div class="card registry-card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3 class="mb-0"><i class="fas fa-search"></i> Duplicate Detection & Management</h3>
                            <span class="badge badge-info badge-lg"><?= count($duplicateCars) ?> potential duplicates</span>
                        </div>
                        <div class="card-body">
                            <!-- Merge Reason Explanations -->
                            <div class="alert alert-info mb-4">
                                <h5 class="alert-heading"><i class="fas fa-info-circle"></i> Merge Reason Guide</h5>
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <h6 class="text-danger"><i class="fas fa-clone"></i> Duplicate Car</h6>
                                        <p class="mb-0 small">Two identical records of the same physical car. One record will be removed and all history preserved in the remaining record.</p>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <h6 class="text-primary"><i class="fas fa-arrow-right"></i> Keep Newer Record</h6>
                                        <p class="mb-0 small">Car sold to new owner who registered it again. <strong>Keep the newer record</strong> with current owner's information, merge history from older record.</p>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <h6 class="text-primary"><i class="fas fa-arrow-left"></i> Keep Older Record</h6>
                                        <p class="mb-0 small">Car was sold but <strong>keep the original record</strong> as the primary entry. Transfer ownership information and merge newer record's history.</p>
                                    </div>
                                </div>
                                <hr class="mt-3 mb-2">
                                <div class="row">
                                    <div class="col-md-8">
                                        <p class="mb-0 small text-muted"><strong>Note:</strong> All merge operations preserve complete history and create audit trail entries. The operation cannot be undone once completed.</p>
                                    </div>
                                    <div class="col-md-4 text-right">
                                        <small class="text-muted">
                                            <span class="badge badge-success badge-sm mr-1"><i class="fas fa-check"></i></span>Matching Fields
                                            <span class="badge badge-danger badge-sm ml-2"><i class="fas fa-exclamation-triangle"></i></span>Different Fields
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <?php if (empty($duplicateCars)) { ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-check-circle text-success" style="font-size: 3rem;"></i>
                                    <h4 class="mt-3 text-success">No Duplicates Found</h4>
                                    <p class="text-muted">All cars in the registry appear to be unique.</p>
                                </div>
                            <?php } else { ?>
                                <!-- Duplicate Groups Container -->
                                <div id="duplicateGroups">
                                    <?php 
                                    // Group duplicates by type and chassis number (exact matches)
                                    $groupedDuplicates = [];
                                    foreach ($duplicateCars as $car) {
                                        $key = $car->chassis . '_' . $car->type;
                                        if (!isset($groupedDuplicates[$key])) {
                                            $groupedDuplicates[$key] = [];
                                        }
                                        $groupedDuplicates[$key][] = $car;
                                    }
                                    
                                    $groupIndex = 0;
                                    foreach ($groupedDuplicates as $chassis => $cars) {
                                        $groupIndex++;
                                    ?>
                                        <div class="duplicate-group card mb-4 border-warning">
                                            <div class="card-header bg-warning bg-opacity-10 d-flex justify-content-between align-items-center">
                                                <h5 class="mb-0">
                                                    <button class="btn btn-link text-decoration-none p-0" type="button" data-toggle="collapse" data-target="#group<?= $groupIndex ?>" aria-expanded="true">
                                                        <i class="fas fa-chevron-down"></i>
                                                        Group <?= $groupIndex ?>: <?= explode('_', $chassis)[1] ?>-<?= explode('_', $chassis)[0] ?>
                                                    </button>
                                                </h5>
                                                <div>
                                                    <span class="badge badge-warning"><?= count($cars) ?> matches</span>
                                                    <span class="badge badge-secondary">Confidence: High</span>
                                                </div>
                                            </div>
                                                <div class="collapse show" id="group<?= $groupIndex ?>">
                                                    <div class="card-body">
                                                        <form action="manage.php" method="POST" class="merge-form">
                                                            <input type="hidden" name="command" value="merge" />
                                                            <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                                                            
                                                            <!-- Car Comparison Cards -->
                                                            <div class="row">
                                                                <?php 
                                                                // Sort cars by creation date - newest on the right
                                                                usort($cars, function($a, $b) {
                                                                    return strtotime($a->ctime) - strtotime($b->ctime);
                                                                });
                                                                
                                                                // Create comparison data for highlighting differences
                                                                $vehicleFields = ['year', 'type', 'chassis', 'series', 'color'];
                                                                $ownerFields = ['fname', 'lname', 'email', 'city', 'state', 'country'];
                                                                
                                                                $comparison = [];
                                                                foreach ($cars as $c) {
                                                                    foreach ($vehicleFields as $field) {
                                                                        $comparison['vehicle'][$field][] = $c->$field ?? '';
                                                                    }
                                                                    foreach ($ownerFields as $field) {
                                                                        $comparison['owner'][$field][] = $c->$field ?? '';
                                                                    }
                                                                }
                                                                
                                                                // Determine if fields match or differ
                                                                $vehicleMatches = [];
                                                                $ownerMatches = [];
                                                                foreach ($vehicleFields as $field) {
                                                                    $values = array_unique($comparison['vehicle'][$field]);
                                                                    $vehicleMatches[$field] = count($values) === 1;
                                                                }
                                                                foreach ($ownerFields as $field) {
                                                                    $values = array_unique($comparison['owner'][$field]);
                                                                    $ownerMatches[$field] = count($values) === 1;
                                                                }
                                                                
                                                                // Check if this is a perfect match (all fields identical)
                                                                $allVehicleMatch = array_reduce($vehicleMatches, function($carry, $match) { return $carry && $match; }, true);
                                                                $allOwnerMatch = array_reduce($ownerMatches, function($carry, $match) { return $carry && $match; }, true);
                                                                $isPerfectMatch = $allVehicleMatch && $allOwnerMatch;
                                                                ?>

                                                            <!-- Perfect Match Recommendation -->
                                                            <?php if ($isPerfectMatch) { ?>
                                                                <div class="alert alert-success mb-3">
                                                                    <h6 class="alert-heading mb-2">
                                                                        <i class="fas fa-bullseye text-success"></i> Perfect Match Detected
                                                                    </h6>
                                                                    <p class="mb-2">
                                                                        <strong>Recommendation:</strong> These cars have identical vehicle and owner information.
                                                                        This appears to be a duplicate entry of the same car.
                                                                    </p>
                                                                    <div class="d-flex align-items-center">
                                                                        <i class="fas fa-arrow-right text-success mr-2"></i>
                                                                        <strong>Suggested Action:</strong> 
                                                                        <span class="badge badge-success ml-2">Merge as Duplicate</span>
                                                                    </div>
                                                                </div>
                                                            <?php } ?>

                                                            <!-- Merge Controls -->
                                                            <div class="mb-3">
                                                                <div class="row">
                                                                    <div class="col-md-8">
                                                                        <strong>Merge Reason:</strong>
                                                                        <div class="form-check form-check-inline">
                                                                            <input class="form-check-input" type="radio" name="reason[]" id="duplicate<?= $groupIndex ?>" value="duplicate" <?= $isPerfectMatch ? 'checked' : '' ?>>
                                                                            <label class="form-check-label" for="duplicate<?= $groupIndex ?>">
                                                                                <i class="fas fa-clone text-danger"></i> Duplicate Car
                                                                                <?php if ($isPerfectMatch) { ?>
                                                                                    <span class="badge badge-success badge-sm ml-1">Recommended</span>
                                                                                <?php } ?>
                                                                            </label>
                                                                        </div>
                                                                        <div class="form-check form-check-inline">
                                                                            <input class="form-check-input" type="radio" name="reason[]" id="newowner1_<?= $groupIndex ?>" value="newownerNewToOld">
                                                                            <label class="form-check-label" for="newowner1_<?= $groupIndex ?>">
                                                                                <i class="fas fa-arrow-right text-primary"></i> Keep Newer Record
                                                                            </label>
                                                                        </div>
                                                                        <div class="form-check form-check-inline">
                                                                            <input class="form-check-input" type="radio" name="reason[]" id="newowner2_<?= $groupIndex ?>" value="newownerOldToNew">
                                                                            <label class="form-check-label" for="newowner2_<?= $groupIndex ?>">
                                                                                <i class="fas fa-arrow-left text-primary"></i> Keep Older Record
                                                                            </label>
                                                                        </div>
                                                                    </div>
                                                                    <div class="col-md-4 text-right">
                                                                        <button type="submit" class="btn btn-danger btn-sm merge-btn" <?= $isPerfectMatch ? '' : 'disabled' ?>>
                                                                            <i class="fas fa-compress-arrows-alt"></i> Merge Selected
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <!-- Car Comparison Cards -->
                                                            <div class="car-comparison-cards-row">
                                                                <?php 
                                                                
                                                                foreach ($cars as $index => $car) { 
                                                                    $isNewer = $index === count($cars) - 1 && count($cars) > 1;
                                                                    $cardClass = $isNewer ? 'car-comparison-card newer-car' : 'car-comparison-card';
                                                                ?>
                                                                    <div class="col-lg-6 col-md-6 mb-3 d-flex">
                                                                        <div class="card <?= $cardClass ?> w-100">
                                                                            <div class="card-header d-flex justify-content-between align-items-center">
                                                                                <div class="form-check">
                                                                                    <input class="form-check-input car-select" type="checkbox" name="cars[]" value="<?= $car->id ?>" id="car<?= $car->id ?>">
                                                                                    <label class="form-check-label" for="car<?= $car->id ?>">
                                                                                        <strong>Car #<?= $car->id ?></strong>
                                                                                        <?php if ($isNewer) { ?>
                                                                                            <span class="badge badge-success badge-sm ml-1">NEWER</span>
                                                                                        <?php } ?>
                                                                                    </label>
                                                                                </div>
                                                                                <a class="btn btn-outline-primary btn-sm" target="_blank" href='<?= $us_url_root ?>app/cars/details.php?car_id=<?= $car->id ?>'>
                                                                                    <i class="fas fa-external-link-alt"></i> View Details
                                                                                </a>
                                                                            </div>
                                                                            
                                                                            <!-- Prominent Date Section -->
                                                                            <div class="card-header bg-light border-top-0 pt-2 pb-2">
                                                                                <div class="row text-center">
                                                                                    <div class="col-6">
                                                                                        <div class="timestamp-info">
                                                                                            <i class="fas fa-calendar-plus text-primary"></i>
                                                                                            <div class="timestamp-label">Created</div>
                                                                                            <div class="timestamp-value"><?= date('M j, Y', strtotime($car->ctime)) ?></div>
                                                                                            <div class="timestamp-time"><?= date('g:i A', strtotime($car->ctime)) ?></div>
                                                                                        </div>
                                                                                    </div>
                                                                                    <div class="col-6 border-left">
                                                                                        <div class="timestamp-info">
                                                                                            <i class="fas fa-edit text-warning"></i>
                                                                                            <div class="timestamp-label">Modified</div>
                                                                                            <div class="timestamp-value"><?= date('M j, Y', strtotime($car->mtime)) ?></div>
                                                                                            <div class="timestamp-time"><?= date('g:i A', strtotime($car->mtime)) ?></div>
                                                                                        </div>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                            
                                                                            <div class="card-body">
                                                                                <div class="row">
                                                                                    <div class="col-sm-6">
                                                                                        <h6 class="text-primary">Vehicle Info</h6>
                                                                                        <p class="mb-1 <?= $vehicleMatches['year'] ? 'field-match' : 'field-differ' ?>">
                                                                                            <strong>Year:</strong> 
                                                                                            <span class="field-value"><?= $car->year ?></span>
                                                                                            <?= !$vehicleMatches['year'] ? '<i class="fas fa-exclamation-triangle text-warning ml-1" title="Different values"></i>' : '<i class="fas fa-check text-success ml-1" title="Values match"></i>' ?>
                                                                                        </p>
                                                                                        <p class="mb-1">
                                                                                            <strong>Type:</strong> <?= $car->type ?>
                                                                                        </p>
                                                                                        <p class="mb-1">
                                                                                            <strong>Chassis:</strong> <?= $car->chassis ?>
                                                                                        </p>
                                                                                        <p class="mb-1 <?= $vehicleMatches['series'] ? 'field-match' : 'field-differ' ?>">
                                                                                            <strong>Series:</strong> 
                                                                                            <span class="field-value"><?= $car->series ?></span>
                                                                                            <?= !$vehicleMatches['series'] ? '<i class="fas fa-exclamation-triangle text-warning ml-1" title="Different values"></i>' : '<i class="fas fa-check text-success ml-1" title="Values match"></i>' ?>
                                                                                        </p>
                                                                                        <p class="mb-1 <?= $vehicleMatches['color'] ? 'field-match' : 'field-differ' ?>">
                                                                                            <strong>Color:</strong> 
                                                                                            <span class="field-value"><?= $car->color ?></span>
                                                                                            <?= !$vehicleMatches['color'] ? '<i class="fas fa-exclamation-triangle text-warning ml-1" title="Different values"></i>' : '<i class="fas fa-check text-success ml-1" title="Values match"></i>' ?>
                                                                                        </p>
                                                                                    </div>
                                                                                    <div class="col-sm-6">
                                                                                        <h6 class="text-success">Owner Info</h6>
                                                                                        <p class="mb-1 <?= $ownerMatches['fname'] && $ownerMatches['lname'] ? 'field-match' : 'field-differ' ?>">
                                                                                            <strong>Owner:</strong> 
                                                                                            <span class="field-value"><?= $car->fname ?> <?= $car->lname ?></span>
                                                                                            <?= !($ownerMatches['fname'] && $ownerMatches['lname']) ? '<i class="fas fa-exclamation-triangle text-warning ml-1" title="Different values"></i>' : '<i class="fas fa-check text-success ml-1" title="Values match"></i>' ?>
                                                                                        </p>
                                                                                        <p class="mb-1 <?= $ownerMatches['email'] ? 'field-match' : 'field-differ' ?>">
                                                                                            <strong>Email:</strong> 
                                                                                            <span class="field-value"><?= $car->email ?></span>
                                                                                            <?= !$ownerMatches['email'] ? '<i class="fas fa-exclamation-triangle text-warning ml-1" title="Different values"></i>' : '<i class="fas fa-check text-success ml-1" title="Values match"></i>' ?>
                                                                                        </p>
                                                                                        <p class="mb-1 <?= $ownerMatches['city'] && $ownerMatches['state'] && $ownerMatches['country'] ? 'field-match' : 'field-differ' ?>">
                                                                                            <strong>Location:</strong> 
                                                                                            <span class="field-value"><?= $car->city ?>, <?= $car->state ?> <?= $car->country ?></span>
                                                                                            <?= !($ownerMatches['city'] && $ownerMatches['state'] && $ownerMatches['country']) ? '<i class="fas fa-exclamation-triangle text-warning ml-1" title="Different values"></i>' : '<i class="fas fa-check text-success ml-1" title="Values match"></i>' ?>
                                                                                        </p>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                <?php } ?>
                                                            </div> <!-- Close car-comparison-cards-row -->
                                                        </form>
                                                    </div> <!-- Close card-body -->
                                                </div> <!-- Close collapse -->
                                            </div>
                                        </div> <!-- Close duplicate-group card -->
                                    <?php } ?>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>



<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer
?>

<!-- Custom styles for duplicate detection interface -->
<style>
.car-comparison-card {
    transition: all 0.3s ease;
    display: flex;
    flex-direction: column;
}

.car-comparison-card .card-body {
    flex: 1;
}

/* Fix cascading height issue - ensure all car cards have same minimum height */
.car-comparison-cards-row {
    display: flex;
    flex-wrap: wrap;
    margin: 0 -15px;
}

.car-comparison-cards-row > .col-lg-6 {
    display: flex;
    padding: 0 15px;
    margin-bottom: 1rem;
}

.car-comparison-cards-row .car-comparison-card {
    width: 100%;
    min-height: 420px; /* Set consistent minimum height */
}

.car-comparison-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.car-comparison-card.selected {
    border: 2px solid #007bff;
    background-color: #f8f9ff;
}

.car-comparison-card.newer-car {
    border-left: 4px solid #28a745;
}

.car-comparison-card.newer-car .card-header {
    background-color: rgba(40, 167, 69, 0.05);
}

.duplicate-group {
    transition: all 0.3s ease;
}

/* Prominent timestamp styles */
.timestamp-info {
    padding: 8px 4px;
}

.timestamp-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: #6c757d;
    text-transform: uppercase;
    margin-bottom: 2px;
}

.timestamp-value {
    font-size: 0.9rem;
    font-weight: 600;
    color: #495057;
    margin-bottom: 1px;
}

.timestamp-time {
    font-size: 0.7rem;
    color: #868e96;
}

/* Field comparison highlighting */
.field-match {
    background-color: rgba(40, 167, 69, 0.1);
    border-left: 3px solid #28a745;
    padding: 4px 8px;
    margin: 2px 0;
    border-radius: 4px;
}

.field-differ {
    background-color: rgba(220, 53, 69, 0.1);
    border-left: 3px solid #dc3545;
    padding: 4px 8px;
    margin: 2px 0;
    border-radius: 4px;
}

.field-match .field-value {
    font-weight: 500;
    color: #155724;
}

.field-differ .field-value {
    font-weight: 500;
    color: #721c24;
}

.field-match i.fa-check {
    font-size: 0.8rem;
}

.field-differ i.fa-exclamation-triangle {
    font-size: 0.8rem;
}

.duplicate-group:hover {
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
}

.form-validation input.is-invalid {
    border-color: #dc3545;
}

.form-validation input.is-valid {
    border-color: #28a745;
}

.badge-lg {
    font-size: 0.9rem;
    padding: 0.5rem 0.75rem;
}

.bg-opacity-10 {
    background-color: rgba(255, 193, 7, 0.1) !important;
}

.merge-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* Delete car section styles */
.delete-form .btn-danger:disabled {
    background-color: #6c757d;
    border-color: #6c757d;
    opacity: 0.7;
    cursor: not-allowed;
}

.delete-form .btn-danger:disabled:hover {
    background-color: #6c757d;
    border-color: #6c757d;
    transform: none;
}

.delete-form .form-control:focus {
    border-color: #dc3545;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
}

.delete-form input[name="confirmation"] {
    font-family: 'Courier New', Courier, monospace;
    font-weight: bold;
    text-transform: uppercase;
}

/* Reassignment form improvements */
.alert-sm {
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
}

.alert-sm .alert-heading {
    font-size: 0.9rem;
    margin-bottom: 0.25rem;
}

.reassign-form .btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.input-group .btn {
    border-left: 0;
}

.input-group .form-control:focus + .input-group-append .btn {
    border-color: #80bdff;
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}

@media (max-width: 768px) {
    .car-comparison-card .col-sm-6 {
        margin-bottom: 1rem;
    }
    
    .form-check-inline {
        display: block !important;
        margin-bottom: 0.5rem;
    }
    
    .timestamp-info {
        padding: 6px 2px;
    }
    
    .timestamp-label {
        font-size: 0.7rem;
    }
    
    .timestamp-value {
        font-size: 0.8rem;
    }
    
    .timestamp-time {
        font-size: 0.65rem;
    }
    
    .badge-sm {
        font-size: 0.7rem;
        padding: 0.2rem 0.4rem;
    }
    
    .field-match, .field-differ {
        padding: 3px 6px;
        margin: 1px 0;
    }
    
    .field-match i.fa-check, 
    .field-differ i.fa-exclamation-triangle {
        font-size: 0.7rem;
    }
}
</style>

<!-- Enhanced JavaScript for duplicate detection interface -->
<script>
$(document).ready(function() {
    // Form validation for car reassignment
    $('.needs-validation').on('submit', function(e) {
        const form = this;
        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
        }
        $(form).addClass('form-validation');
    });

    // Enhanced merge functionality
    $('.merge-form').each(function() {
        const $form = $(this);
        const $carCheckboxes = $form.find('.car-select');
        const $reasonRadios = $form.find('input[name="reason[]"]');
        const $mergeBtn = $form.find('.merge-btn');

        // Enable/disable merge button based on selections
        function updateMergeButton() {
            const selectedCars = $carCheckboxes.filter(':checked').length;
            const selectedReason = $reasonRadios.filter(':checked').length;
            
            const canMerge = selectedCars === 2 && selectedReason === 1;
            $mergeBtn.prop('disabled', !canMerge);
            
            if (selectedCars > 2) {
                $mergeBtn.text('Select exactly 2 cars to merge');
                $mergeBtn.removeClass('btn-danger').addClass('btn-warning');
            } else if (selectedCars === 2 && selectedReason === 1) {
                $mergeBtn.html('<i class="fas fa-compress-arrows-alt"></i> Merge Selected');
                $mergeBtn.removeClass('btn-warning').addClass('btn-danger');
            } else {
                $mergeBtn.html('<i class="fas fa-compress-arrows-alt"></i> Merge Selected');
                $mergeBtn.removeClass('btn-warning').addClass('btn-danger');
            }
        }

        // Visual feedback for selected cars
        $carCheckboxes.on('change', function() {
            const $card = $(this).closest('.car-comparison-card');
            if ($(this).is(':checked')) {
                $card.addClass('selected');
            } else {
                $card.removeClass('selected');
            }
            updateMergeButton();
        });

        $reasonRadios.on('change', updateMergeButton);

        // Confirmation dialog for merge operations
        $form.on('submit', function(e) {
            const selectedCars = $carCheckboxes.filter(':checked');
            const selectedReason = $reasonRadios.filter(':checked');
            
            if (selectedCars.length !== 2 || selectedReason.length !== 1) {
                e.preventDefault();
                alert('Please select exactly 2 cars and 1 merge reason.');
                return false;
            }

            const car1Id = $(selectedCars[0]).val();
            const car2Id = $(selectedCars[1]).val();
            const reason = selectedReason.val();
            
            let reasonText = '';
            switch(reason) {
                case 'duplicate':
                    reasonText = 'These are duplicate entries of the same car';
                    break;
                case 'newownerNewToOld':
                    reasonText = 'Keep the newer record (current owner information)';
                    break;
                case 'newownerOldToNew':
                    reasonText = 'Keep the older record (original registration)';
                    break;
            }

            const confirmed = confirm(
                `Are you sure you want to merge cars #${car1Id} and #${car2Id}?\n\n` +
                `Reason: ${reasonText}\n\n` +
                `This action cannot be undone. The history will be preserved, but one car record will be permanently deleted.`
            );

            if (!confirmed) {
                e.preventDefault();
                return false;
            }
        });

        // Initialize button state
        updateMergeButton();
    });

    // Collapsible group management
    $('.duplicate-group [data-toggle="collapse"]').on('click', function() {
        const $icon = $(this).find('i');
        const isExpanded = $(this).attr('aria-expanded') === 'true';
        
        setTimeout(function() {
            if (isExpanded) {
                $icon.removeClass('fa-chevron-down').addClass('fa-chevron-right');
            } else {
                $icon.removeClass('fa-chevron-right').addClass('fa-chevron-down');
            }
        }, 100);
    });

    // Enhanced tooltips and popovers (if Bootstrap supports them)
    if (typeof $().tooltip === 'function') {
        $('[data-toggle="tooltip"]').tooltip();
    }
    
    // Delete car lookup functionality
    let selectedDeleteCar = null;
    
    $('#lookupDeleteCarBtn').on('click', function() {
        const carId = $('#delete_car_id').val();
        if (!carId) {
            alert('Please enter a Car ID first');
            return;
        }
        
        const $btn = $(this);
        const originalHtml = $btn.html();
        $btn.prop('disabled', true);
        $btn.html('<i class="fas fa-spinner fa-spin"></i>');
        
        $.ajax({
            url: 'manage.php',
            type: 'POST',
            data: {
                command: 'getCarDetails',
                car_id: carId,
                csrf: $('.delete-form input[name="csrf"]').val()
            },
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false);
                $btn.html(originalHtml);
                
                if (!response.success) {
                    alert('Error: ' + response.error);
                    $('#deleteCarDetails').hide();
                    selectedDeleteCar = null;
                    updateDeleteButton();
                    return;
                }
                
                selectedDeleteCar = response.car;
                const car = response.car;
                const ownerName = car.fname && car.lname ? `${car.fname} ${car.lname}` : 'Unknown Owner';
                
                $('#deleteCarInfo').html(
                    `<strong>${car.year || 'Unknown'} ${car.type || 'Unknown'}</strong><br>` +
                    `Chassis: ${car.chassis || 'Unknown'} | Color: ${car.color || 'Unknown'} | Series: ${car.series || 'Unknown'}`
                );
                $('#deleteCurrentOwner').text(`${ownerName} (${car.email || 'No email'})`);
                $('#deleteCarDetails').show();
                updateDeleteButton();
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false);
                $btn.html(originalHtml);
                alert('Error fetching car details: ' + error);
                $('#deleteCarDetails').hide();
                selectedDeleteCar = null;
                updateDeleteButton();
            }
        });
    });
    
    // Auto-lookup on enter key for delete car
    $('#delete_car_id').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#lookupDeleteCarBtn').click();
        }
    });
    
    // Clear delete car selection when input changes
    $('#delete_car_id').on('input', function() {
        const value = $(this).val();
        
        // Clear previous car details when typing
        if (!value || (selectedDeleteCar && value != selectedDeleteCar.id)) {
            $('#deleteCarDetails').hide();
            selectedDeleteCar = null;
        }
        updateDeleteButton();
    });

    // Delete car functionality - updated to work with lookup
    function updateDeleteButton() {
        const $form = $('.delete-form');
        const carId = $form.find('#delete_car_id').val();
        const confirmation = $form.find('#delete_confirmation').val();
        const $deleteBtn = $form.find('#deleteBtn');
        
        // Enable button only when car is looked up, confirmation matches, and IDs match
        const carLookedUp = selectedDeleteCar && selectedDeleteCar.id == carId;
        const confirmationValid = confirmation === 'DELETE';
        const canDelete = carLookedUp && confirmationValid;
        
        $deleteBtn.prop('disabled', !canDelete);
        
        if (canDelete) {
            $deleteBtn.removeClass('btn-secondary').addClass('btn-danger');
        } else {
            $deleteBtn.removeClass('btn-danger').addClass('btn-secondary');
        }
    }
    
    // Monitor confirmation field changes
    $('#delete_confirmation').on('input', updateDeleteButton);
    
    // Delete form submission with confirmation using already-loaded car data
    $('.delete-form').on('submit', function(e) {
        e.preventDefault();
        
        const $form = $(this);
        const carId = $('#delete_car_id').val();
        const confirmation = $('#delete_confirmation').val();
        
        if (!selectedDeleteCar || !carId || confirmation !== 'DELETE') {
            alert('Please lookup the car details first and type DELETE to confirm.');
            return false;
        }
        
        if (selectedDeleteCar.id != carId) {
            alert('Please lookup the current car ID before proceeding.');
            return false;
        }
        
        const car = selectedDeleteCar;
        const ownerName = car.fname && car.lname ? `${car.fname} ${car.lname}` : 'Unknown Owner';
        const location = car.city && car.state ? `${car.city}, ${car.state} ${car.country}` : 'Unknown Location';
        const createdDate = new Date(car.ctime).toLocaleDateString();
        const modifiedDate = new Date(car.mtime).toLocaleDateString();
        
        // First confirmation dialog with car details
        const carDetails = 
            `⚠️ PERMANENT DELETION WARNING ⚠️\n\n` +
            `You are about to permanently delete:\n\n` +
            `Car ID: ${car.id}\n` +
            `Year: ${car.year || 'Unknown'}\n` +
            `Type: ${car.type || 'Unknown'}\n` +
            `Chassis: ${car.chassis || 'Unknown'}\n` +
            `Color: ${car.color || 'Unknown'}\n` +
            `Series: ${car.series || 'Unknown'}\n` +
            `Owner: ${ownerName}\n` +
            `Email: ${car.email || 'Unknown'}\n` +
            `Location: ${location}\n` +
            `Created: ${createdDate}\n` +
            `Modified: ${modifiedDate}\n\n` +
            `This action will:\n` +
            `• Delete the car record permanently\n` +
            `• Remove all user-car relationships\n` +
            `• Delete all uploaded images\n` +
            `• Cannot be undone\n\n` +
            `Are you absolutely sure you want to continue?`;
        
        const firstConfirm = confirm(carDetails);
        
        if (!firstConfirm) {
            return false;
        }
        
        // Second confirmation dialog
        const secondConfirm = confirm(
            `FINAL CONFIRMATION\n\n` +
            `This is your last chance to cancel.\n\n` +
            `${car.year || 'Unknown'} ${car.type || 'Unknown'} (${car.chassis || 'Unknown'})\n` +
            `Owner: ${ownerName}\n\n` +
            `This car will be PERMANENTLY DELETED.\n\n` +
            `Click OK to proceed with deletion or Cancel to abort.`
        );
        
        if (secondConfirm) {
            // Show final loading state
            const $btn = $('#deleteBtn');
            $btn.prop('disabled', true);
            $btn.html('<i class="fas fa-spinner fa-spin"></i> Deleting...');
            
            // Submit the form
            $form[0].submit();
        }
        
        return false;
    });

    // Reassignment form improvements
    let selectedCar = null;
    let selectedUser = null;
    
    // Car lookup functionality
    $('#lookupCarBtn').on('click', function() {
        const carId = $('#reassign_car_id').val();
        if (!carId) {
            alert('Please enter a Car ID first');
            return;
        }
        
        const $btn = $(this);
        const originalHtml = $btn.html();
        $btn.prop('disabled', true);
        $btn.html('<i class="fas fa-spinner fa-spin"></i>');
        
        $.ajax({
            url: 'manage.php',
            type: 'POST',
            data: {
                command: 'getCarDetails',
                car_id: carId,
                csrf: $('.reassign-form input[name="csrf"]').val()
            },
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false);
                $btn.html(originalHtml);
                
                if (!response.success) {
                    alert('Error: ' + response.error);
                    $('#carDetails').hide();
                    selectedCar = null;
                    updateReassignButton();
                    return;
                }
                
                selectedCar = response.car;
                const car = response.car;
                const ownerName = car.fname && car.lname ? `${car.fname} ${car.lname}` : 'Unknown Owner';
                
                $('#carInfo').html(
                    `<strong>${car.year || 'Unknown'} ${car.type || 'Unknown'}</strong><br>` +
                    `Chassis: ${car.chassis || 'Unknown'} | Color: ${car.color || 'Unknown'}`
                );
                $('#currentOwner').text(`${ownerName} (${car.email || 'No email'})`);
                $('#carDetails').show();
                updateReassignButton();
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false);
                $btn.html(originalHtml);
                alert('Error fetching car details: ' + error);
                $('#carDetails').hide();
                selectedCar = null;
                updateReassignButton();
            }
        });
    });
    
    // User lookup functionality
    $('#lookupUserBtn').on('click', function() {
        const userId = $('#reassign_user_id').val();
        if (!userId) {
            alert('Please enter a User ID first');
            return;
        }
        
        const $btn = $(this);
        const originalHtml = $btn.html();
        $btn.prop('disabled', true);
        $btn.html('<i class="fas fa-spinner fa-spin"></i>');
        
        $.ajax({
            url: 'manage.php',
            type: 'POST',
            data: {
                command: 'getUserDetails',
                user_id: userId,
                csrf: $('.reassign-form input[name="csrf"]').val()
            },
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false);
                $btn.html(originalHtml);
                
                if (!response.success) {
                    alert('Error: ' + response.error);
                    $('#userDetails').hide();
                    selectedUser = null;
                    updateReassignButton();
                    return;
                }
                
                selectedUser = response.user;
                const user = response.user;
                const userName = user.fname && user.lname ? `${user.fname} ${user.lname}` : 'Unknown Name';
                const location = user.city && user.state ? `${user.city}, ${user.state} ${user.country}` : 'Unknown Location';
                const joinDate = new Date(user.join_date).toLocaleDateString();
                
                $('#userInfo').html(
                    `<strong>${userName}</strong> (${user.username})<br>` +
                    `Email: ${user.email}<br>` +
                    `Location: ${location}<br>` +
                    `Member since: ${joinDate}`
                );
                $('#userDetails').show();
                updateReassignButton();
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false);
                $btn.html(originalHtml);
                alert('Error fetching user details: ' + error);
                $('#userDetails').hide();
                selectedUser = null;
                updateReassignButton();
            }
        });
    });
    
    // Auto-lookup on enter key
    $('#reassign_car_id').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#lookupCarBtn').click();
        }
    });
    
    $('#reassign_user_id').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#lookupUserBtn').click();
        }
    });
    
    // No Owner checkbox functionality
    $('#noOwnerCheckbox').on('change', function() {
        const isChecked = $(this).is(':checked');
        const $userIdField = $('#reassign_user_id');
        const $lookupBtn = $('#lookupUserBtn');
        
        if (isChecked) {
            // Set No Owner data
            selectedUser = {
                id: 83,
                fname: 'No',
                lname: 'Owner',
                username: 'noowner',
                email: 'noowner@example.com',
                city: null,
                state: null,
                country: null,
                join_date: '2023-01-01'
            };
            
            // Update UI
            $userIdField.val('83').prop('disabled', true);
            $lookupBtn.prop('disabled', true);
            $('#userDetails').hide();
            $('#noOwnerDetails').show();
            
        } else {
            // Clear No Owner data
            selectedUser = null;
            $userIdField.val('').prop('disabled', false).focus();
            $lookupBtn.prop('disabled', false);
            $('#userDetails').hide();
            $('#noOwnerDetails').hide();
        }
        
        updateReassignButton();
    });
    
    // Clear user selection when input changes
    $('#reassign_user_id').on('input', function() {
        const value = $(this).val();
        
        // If field is cleared or different from 83, uncheck "No Owner"
        if (!value || value !== '83') {
            $('#noOwnerCheckbox').prop('checked', false);
            $('#noOwnerDetails').hide();
            selectedUser = null;
        }
        
        // Clear previous user details when typing
        $('#userDetails').hide();
        updateReassignButton();
    });

    // Update button state
    function updateReassignButton() {
        const $btn = $('#reassignBtn');
        const canReassign = selectedCar && selectedUser;
        $btn.prop('disabled', !canReassign);
        
        if (canReassign) {
            $btn.removeClass('btn-secondary').addClass('btn-primary');
        } else {
            $btn.removeClass('btn-primary').addClass('btn-secondary');
        }
    }
    
    // Enhanced reassignment confirmation
    $('.reassign-form').on('submit', function(e) {
        e.preventDefault();
        
        if (!selectedCar || !selectedUser) {
            alert('Please lookup both car and user details before reassigning.');
            return false;
        }
        
        const carName = `${selectedCar.year || 'Unknown'} ${selectedCar.type || 'Unknown'} (${selectedCar.chassis || 'Unknown'})`;
        const currentOwner = selectedCar.fname && selectedCar.lname ? `${selectedCar.fname} ${selectedCar.lname}` : 'Unknown Owner';
        const newOwner = selectedUser.fname && selectedUser.lname ? `${selectedUser.fname} ${selectedUser.lname}` : 'Unknown Name';
        const isNoOwner = selectedUser.id === 83;
        
        let confirmMessage = `Confirm Car Reassignment\n\n` +
            `Car: ${carName}\n` +
            `Current Owner: ${currentOwner} (${selectedCar.email || 'No email'})\n`;
        
        if (isNoOwner) {
            confirmMessage += `New Owner: No Owner (Registry placeholder)\n\n` +
                `This will assign the car to the "No Owner" registry account.\n` +
                `Use this for cars without current owner information.\n` +
                `The change will be logged in the car's history.\n\n` +
                `Proceed with reassignment?`;
        } else {
            confirmMessage += `New Owner: ${newOwner} (${selectedUser.email})\n\n` +
                `This will transfer ownership of the car to the new owner.\n` +
                `The change will be logged in the car's history.\n\n` +
                `Proceed with reassignment?`;
        }
        
        if (confirm(confirmMessage)) {
            // Show loading state
            const $btn = $('#reassignBtn');
            $btn.prop('disabled', true);
            $btn.html('<i class="fas fa-spinner fa-spin"></i> Reassigning...');
            
            // Submit the form
            this.submit();
        }
        
        return false;
    });

    // Initialize form state on page load
    function initializeReassignForm() {
        // Ensure user ID field is enabled by default
        $('#reassign_user_id').prop('disabled', false);
        $('#lookupUserBtn').prop('disabled', false);
        $('#noOwnerCheckbox').prop('checked', false);
        
        // Reset all selections
        selectedCar = null;
        selectedUser = null;
        updateReassignButton();
        
        // Hide all detail boxes
        $('#carDetails, #userDetails, #noOwnerDetails').hide();
    }
    
    // Initialize delete form state
    function initializeDeleteForm() {
        // Reset delete car selection
        selectedDeleteCar = null;
        updateDeleteButton();
        
        // Hide delete car details
        $('#deleteCarDetails').hide();
    }
    
    // Call initialization
    initializeReassignForm();
    initializeDeleteForm();
    
    // Debug: Add click handler to ensure field is responsive
    $('#reassign_user_id').on('click focus', function() {
        console.log('User ID field clicked/focused, disabled:', $(this).prop('disabled'));
        if ($(this).prop('disabled')) {
            console.log('Field is disabled - this might be the issue');
            console.log('Checkbox checked:', $('#noOwnerCheckbox').is(':checked'));
        }
    });

    // Auto-focus first input in reassignment form
    $('#reassign_car_id').focus();
});
</script>
