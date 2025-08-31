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

                    // Get the new user details
                    $userQ                    = $db->findById($user_id, "usersview");
                    $userData                 = $userQ->results();

                    $fields['user_id']   = $userData[0]->id;
                    $fields['email']     = $userData[0]->email;
                    $fields['username']  = $userData[0]->username;
                    $fields['fname']     = $userData[0]->fname;
                    $fields['lname']     = $userData[0]->lname;
                    $fields['join_date'] = $userData[0]->join_date;
                    $fields['city']      = $userData[0]->city;
                    $fields['state']     = $userData[0]->state;
                    $fields['country']   = $userData[0]->country;
                    $fields['lat']       = $userData[0]->lat;
                    $fields['lon']       = $userData[0]->lon;
                    $fields['website']   = $userData[0]->website;

                    // Update the car details with the new owner
                    $db->update('cars', $car_id, $fields);

                    // Update the cross reference table
                    $db->query("UPDATE car_user SET userid = ? WHERE carid = ?", [$user_id, $car_id]);

                    // Add a record to the history with some information on the assignment
                    $fields['comments'] = "Car was reassigned to new owner $user_id.";
                    $fields['operation'] = "NEWOWNER";

                    $fields['ctime'] = date('Y-m-d G:i:s'); // Set date of this record
                    $fields['mtime'] = $fields['ctime'];

                    $fields['car_id'] = $car_id;
                    $db->insert("cars_hist", $fields);

                    $successes[] = 'Admin ' . ($user->data()->id) . ' ' . $fields['comments'];
                    logger($user->data()->id, "ElanRegistry", $fields['comments']);

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
            <div class="row">
                <!-- Messages Section -->
                <div class="col-lg-4 col-md-12 mb-4">
                    <div class="card registry-card">
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
                                    <i class="fas fa-info-circle"></i> Messages will appear here after merge operations.
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>

                <!-- Car Reassignment Section -->
                <div class="col-lg-8 col-md-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h3 class="mb-0"><i class="fas fa-exchange-alt"></i> Reassign Car</h3>
                        </div>
                        <div class="card-body">
                            <form name="assignCar" action="manage.php" method="POST" class="needs-validation" novalidate>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="car_id" class="form-label">Car ID</label>
                                            <input type="number" class="form-control" id="car_id" name="car_id" required>
                                            <div class="invalid-feedback">Please provide a valid car ID.</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="user_id" class="form-label">User ID</label>
                                            <input type="number" class="form-control" id="user_id" name="user_id" required>
                                            <div class="invalid-feedback">Please provide a valid user ID.</div>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                                <input type="hidden" name="command" value="reassign" />
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-user-friends"></i> Reassign Car
                                </button>
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
                                                        
                                                        <!-- Merge Controls -->
                                                        <div class="mb-3">
                                                            <div class="row">
                                                                <div class="col-md-8">
                                                                    <strong>Merge Reason:</strong>
                                                                    <div class="form-check form-check-inline">
                                                                        <input class="form-check-input" type="radio" name="reason[]" id="duplicate<?= $groupIndex ?>" value="duplicate">
                                                                        <label class="form-check-label" for="duplicate<?= $groupIndex ?>">
                                                                            <i class="fas fa-clone text-danger"></i> Duplicate Car
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
                                                                    <button type="submit" class="btn btn-danger btn-sm merge-btn" disabled>
                                                                        <i class="fas fa-compress-arrows-alt"></i> Merge Selected
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        
                                                        <!-- Car Comparison Cards -->
                                                        <div class="row">
                                                            <?php 
                                                            // Sort cars by creation date - newest on the right
                                                            usort($cars, function($a, $b) {
                                                                return strtotime($a->ctime) - strtotime($b->ctime);
                                                            });
                                                            
                                                            // Create comparison data for highlighting differences
                                                            $vehicleFields = ['year', 'type', 'chassis', 'series', 'color'];
                                                            $ownerFields = ['fname', 'lname', 'username', 'email', 'city', 'state', 'country'];
                                                            
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
                                                            
                                                            foreach ($cars as $index => $car) { 
                                                                $isNewer = $index === count($cars) - 1 && count($cars) > 1;
                                                                $cardClass = $isNewer ? 'car-comparison-card newer-car' : 'car-comparison-card';
                                                            ?>
                                                                <div class="col-lg-6 mb-3">
                                                                    <div class="card <?= $cardClass ?>">
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
                                                                                    <p class="mb-1 <?= $ownerMatches['username'] ? 'field-match' : 'field-differ' ?>">
                                                                                        <strong>Username:</strong> 
                                                                                        <span class="field-value"><?= $car->username ?></span>
                                                                                        <?= !$ownerMatches['username'] ? '<i class="fas fa-exclamation-triangle text-warning ml-1" title="Different values"></i>' : '<i class="fas fa-check text-success ml-1" title="Values match"></i>' ?>
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
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
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
    height: 100%;
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
    
    // Auto-focus first input in reassignment form
    $('#car_id').focus();
});
</script>
