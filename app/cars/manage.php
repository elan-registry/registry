<?php

/**
 * manage_cars.php
 * Admin interface for managing cars and users in the registry.
 *
 * Provides tools for finding duplicates, orphaned records, and reassigning cars.
 * Uses the site template for layout and security checks for access.
 *
 * @author Elan Registry Admin
 * @copyright 2025
 */
require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

// TODO - Reimagine managing cars for admin - Tracked in GitHub Issue #213

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Some useful queries

$badusers = "SELECT users.id FROM users  LEFT JOIN car_user ON (users.id = car_user.userid) WHERE ( users.email_verified = 0 AND users.last_login = 0 AND car_user.carid IS NULL AND vericode_expiry < CURRENT_DATE ) GROUP BY users.id ";
$unusedprofiles = " SELECT t1.user_id FROM profiles t1 LEFT JOIN users t2 ON t1.user_id = t2.id WHERE t2.id IS NULL ";
$orphanedcars = "SELECT t1.userid FROM car_user t1 LEFT JOIN users t2 ON t1.userid = t2.id  WHERE t2.id IS NULL ";
$orphanedcaruser = "SELECT cu.id, cu.userid, cu.carid FROM car_user cu WHERE cu.carid NOT IN (SELECT id FROM cars) ORDER BY cu.carid ASC"; // Issue #35: car_user records with deleted cars
$duplicates = "SELECT  a.* FROM cars a JOIN(  SELECT  type,chassis,  COUNT(*)  FROM  users_carsview  WHERE chassis <> '' GROUP BY type,chassis HAVING COUNT(*) > 1) b ON a.chassis = b.chassis ORDER BY a.chassis, a.type";

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
<div id="page-wrapper">
    <div class="container-fluid">
        <div class="well">
            <div class="row">

                <div class="col-4">
                    <div class="card card-default">
                        <div class="card-header">
                            <h2><strong>Messages</strong></h2>
                        </div>
                        <div class="card-body">
                            <?php if (!$errors == '') {
                            ?>
                                <div class="alert alert-danger"><?= display_errors($errors); ?></div><?php
                                                                                                    } ?>
                            <?php if (!$successes == '') {
                            ?>
                                <div class="alert alert-success"><?= display_successes($successes); ?></div><?php
                                                                                                        } ?>
                        </div> <!-- card-body -->
                    </div> <!-- card -->
                </div> <!-- col -->


                <div class="col-4">
                    <div class="card card-default">
                        <div class="card-header">
                            <h2><strong>DB Cleanup</strong></h2>
                        </div>
                        <div class="card-body">
                            <button type="button" class="btn btn-success btn-lg btn-block" data-toggle="modal" data-target="#badUser">Remove <?= $db->query($badusers)->count() ?> Bad Users</button>
                            <button type="button" class="btn btn-success btn-lg btn-block" data-toggle="modal" data-target="#cleanProfile">Clean <?= $db->query($unusedprofiles)->count() ?> Unused Profiles</button>
                            <button type="button" class="btn btn-success btn-lg btn-block" data-toggle="modal" data-target="#orphanCars">Assign <?= $db->query($orphanedcars)->count() ?> Orphan Cars</button>
                        </div> <!-- card-body -->
                    </div> <!-- card -->
                </div> <!-- col -->

                <div class="col-4">
                    <div class="card card-default">
                        <div class="card-header">
                            <h2><strong>Reassign Car</strong></h2>
                        </div>
                        <div class="card-body">
                            <form name="assignCar" action="manage_cars.php" method="POST" enctype="multipart/form-data">
                                <label for="car_id">Car ID:</label><br>
                                <input type="text" id="car_id" name="car_id"><br>
                                <label for="user_id">User ID:</label><br>
                                <input type="text" id="user_id" name="user_id">
                                <br><br>
                                <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                                <input type="hidden" name="command" value="reassign" />
                                <input class="btn btn-success btn-lg btn-block" type='submit' value='Assign' class='submit' />
                            </form>
                        </div> <!-- card-body -->
                    </div> <!-- card -->
                </div> <!-- col -->

            </div> <!-- row -->
            <div class="row">
                <div class="col">
                    <div class="card card-default">
                        <div class="card-header">
                            <h2><strong>Duplicates</strong></h2>
                        </div>
                        <div class="card-body">
                            <form action="manage_cars.php" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="command" value="merge" />
                                <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                                <input type="submit" class="btn btn-success " name="formSubmit" value="Merge" />
                                <div class="form-group">
                                    ?>

                                    <input type="checkbox" class="custom-control-input" id="customCheck-duplicate" name="reason[]" value="duplicate" />
                                    <label class="custom-control-label" for="customCheck-duplicate">Duplicate car</label>
                                </div>
                        </div>
                        <div class="form-group">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="customCheck-newownerNewToOld" name="reason[]" value="newownerNewToOld" />
                                <label class="custom-control-label" for="customCheck-newownerNewToOld">New car is new owner</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="customCheck-newownerOldToNew" name="reason[]" value="newownerOldToNew" />
                                <label class="custom-control-label" for="customCheck-newownerOldToNew">Old car is new owner</label>
                            </div>
                        </div>

                        <table id="duptable" class="table table-striped table-bordered table-sm" aria-describedby="card-header">
                            <thead>
                                <tr>
                                    <th scope=column>Merge</th>
                                    <th scope=column>CarID</th>
                                    <th scope=column>Username</th>
                                    <th scope=column>Create</th>
                                    <th scope=column>Modified</th>
                                    <th scope=column>Year</th>
                                    <th scope=column>Type</th>
                                    <th scope=column>Chassis</th>
                                    <th scope=column>Series</th>
                                    <th scope=column>Variant</th>
                                    <th scope=column>Color</th>
                                    <th scope=column>Engine</th>
                                    <th scope=column>Purchase Date</th>
                                    <th scope=column>Sold Date</th>
                                    <th scope=column>Comments</th>
                                    <th scope=column>Image</th>
                                    <th scope=column>Fname</th>
                                    <th scope=column>Lname</th>
                                    <th scope=column>email</th>
                                    <th scope=column>City</th>
                                    <th scope=column>State</th>
                                    <th scope=column>Country</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                //Cycle through users
                                foreach ($duplicateCars as $car) {
                                ?>
                                    <tr>
                                        <td class="center">
                                            <div class="form-group">
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="custom-control-input" id="customCheck-<?= $car->id ?>" name="cars[]" value="<?= $car->id ?>" />
                                                    <label class="custom-control-label" for="customCheck-<?= $car->id ?>"></label>
                                                </div>
                                            </div>
                                        </td>
                                        <td><a class="btn btn-success btn-sm" target="_blank" href='<?= $us_url_root ?>app/cars/details.php?car_id=<?= $car->id ?>'><?= $car->id ?></a></td>
                                        <td><?= $car->username ?></td>
                                        <td><?= $car->ctime ?></td>
                                        <td><?= $car->mtime ?></td>
                                        <td><?= $car->year ?></td>
                                        <td><?= $car->type ?></td>
                                        <td><?= $car->chassis ?></td>
                                        <td><?= $car->series ?></td>
                                        <td><?= $car->variant ?></td>
                                        <td><?= $car->color ?></td>
                                        <td><?= $car->engine ?></td>
                                        <td><?= $car->purchasedate ?></td>
                                        <td><?= $car->solddate ?></td>
                                        <td><?= $car->comments ?></td>
                                        <!-- <td> <?php include $abs_us_root . $us_url_root . 'app/views/_display_image.php';
                                                    // TODO This needs to change for Car Class - Tracked in GitHub Issue #214
                                                    ?>  -->
                                        </td>
                                        <td><?= $car->fname ?></td>
                                        <td><?= $car->lname ?></td>
                                        <td><?= $car->email ?></td>
                                        <td><?= $car->city ?></td>
                                        <td><?= $car->state ?></td>
                                        <td><?= $car->country ?></td>
                                    </tr>
                                <?php
                                } ?>
                            </tbody>
                        </table>
                        </form>
                    </div> <!-- card-body -->
                </div> <!-- card -->
            </div> <!-- col -->
        </div> <!-- row -->
    </div> <!-- well -->
</div><!-- Container -->
</div><!-- page -->


<!-- The Modal for Bad Users-->
<div class="modal fade" id="badUser">
    <div class="modal-dialog">
        <div class="modal-content">
            <!-- Modal Header -->
            <div class="modal-header">
                <h4 class="modal-title">Remove Bad Users</h4>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>

            <!-- Modal body -->
            <div class="modal-body">
                <?php
                $usersQ = $db->query($badusers);
                echo "Delete " . $usersQ->count() . " SPAM users<br>";
                $users = $usersQ->results();
                foreach ($users as $u) {
                    echo "- user_id " . $u->id . "<br>";
                    deleteUsers(array($u->id));
                    $db->query("DELETE FROM profiles WHERE user_id = ?", array($u->id));
                }
                ?>
            </div>

            <!-- Modal footer -->
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- The Modal for Clean Profiles-->
<div class="modal fade" id="cleanProfile">
    <div class="modal-dialog">
        <div class="modal-content">
            <!-- Modal Header -->
            <div class="modal-header">
                <h4 class="modal-title">Clean Unused Profiles</h4>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>

            <!-- Modal body -->
            <div class="modal-body">
                <?php
                $profileQ = $db->query($unusedprofiles);
                echo "Delete " . $profileQ->count() . " profiles<br>";
                $profile = $profileQ->results();
                foreach ($profile as $p) {
                    echo "- user_id " . $p->user_id . "<br>";
                    $db->query("DELETE FROM profiles WHERE user_id = ?", array($p->user_id));
                }
                ?>
            </div>

            <!-- Modal footer -->
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- The Modal for Orphan Cars-->
<div class="modal fade" id="orphanCars" role="dialog">
    <div class="modal-dialog">
        <div class="modal-content">

            <!-- Modal Header -->
            <div class="modal-header">
                <h4 class="modal-title">Assign Orphan Cars</h4>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>

            <!-- Modal body -->
            <div class="modal-body">
                <?php
                $qResult = $db->query($orphanedcars);
                echo "There are " . $qResult->count() . " car_user rows without corresponding owner<br>";

                $profile = $qResult->results();
                foreach ($profile as $p) {
                    echo "- userid " . $p->userid . "<br>";
                    $db->query("DELETE FROM car_user WHERE userid = ?", array($p->userid));
                }

                $q = "

    SELECT t1.id
    FROM cars t1
    LEFT JOIN car_user t2 ON t1.id = t2.carid
    WHERE t2.carid IS NULL";

                $qResult = $db->query($q);

                echo "There are " . $qResult->count() . " cars  without corresponding car_owner entry<br>";

                $car = $qResult->results();
                foreach ($car as $c) {
                    echo "- carid " . $c->id . "<br>";
                    $db->query("INSERT INTO car_user (userid, carid ) VALUES (83, ?)", array($c->id));
                }

                // Issue #35: Clean up car_user records where the car no longer exists
                echo "<br><strong>Orphaned car_user Records (Issue #35):</strong><br>";
                $orphanedCarUserResult = $db->query($orphanedcaruser);
                echo "There are " . $orphanedCarUserResult->count() . " car_user rows referencing deleted cars<br>";

                $orphanedRecords = $orphanedCarUserResult->results();
                foreach ($orphanedRecords as $record) {
                    echo "- car_user.id " . $record->id . " references missing carid " . $record->carid . "<br>";
                    $db->query("DELETE FROM car_user WHERE id = ?", array($record->id));
                }

                if (count($orphanedRecords) > 0) {
                    logger($user->data()->id, 'DatabaseCleanup', "Cleaned up " . count($orphanedRecords) . " orphaned car_user records (Issue #35)");
                    echo "<span style='color: green;'>✓ Cleaned up " . count($orphanedRecords) . " orphaned car_user records</span><br>";
                } else {
                    echo "<span style='color: green;'>✓ No orphaned car_user records found</span><br>";
                }
                ?>
            </div>

            <!-- Modal footer -->
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer

// Table Sorting and Such
echo html_entity_decode($settings->elan_datatables_js_cdn);
echo html_entity_decode($settings->elan_datatables_css_cdn);
?>
<script>
    $(document).ready(function() {
        $('#duptable').DataTable({
            fixedHeader: true,
            responsive: true,
            rowGroup: {
                dataSrc: [6, 7],
                startClassName: 'table-info',
            },
            "ordering": false,
            "scrollX": true,
            "pageLength": 50
        });
    });
</script>
