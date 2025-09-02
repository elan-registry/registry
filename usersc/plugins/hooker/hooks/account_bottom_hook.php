<?php
if (count(get_included_files()) == 1) {
    die(); //Direct Access Not Permitted Leave this line in place
}

global $user;

// Get some interesting user information to display later

$user_id = $user->data()->id;

// USER ID is in $user_id .  Use the USER ID to get the users Profile information
$userQ = $db->query("SELECT * FROM usersview WHERE id = ?", array($user_id));
if ($userQ->count() > 0) {
    $thatUser = $userQ->results();
}

$cars = findByOwner($user_id);

?>


<div class="card registry-card">
    <div class="card-header">
        <h4 class="mb-0"><i class="fas fa-car"></i> <strong>Your Car Information</strong></h4>
    </div>
    <div class="card-body">
        <?php

        // If there is car information then display it

        if (empty($cars)) {
            //     If the user does not have a car then display the add car form</li>
        ?>
            <a class="btn btn-success" href=<?= $us_url_root . "app/cars/edit.php" ?> role="button">Add Car</a>
            <?php
        } else {
            // Else there is car information then display it
            foreach ($cars as $car) {
                // output data of each row.  View has both cars and users
            ?>
                <table style='padding: 0;' id="cartable-<?= $car->data()->id ?>" class="table table-striped table-bordered table-sm" aria-describedby="card-header">
                    <tr class="table-success">
                        <th scope=column><strong>Car ID :</strong></th>
                        <th scope=column><?= $car->data()->id ?></th>
                    </tr>
                    <tr>
                        <td><strong>Model :</strong></td>
                        <td><?= $car->data()->model ?></td>
                    </tr>
                    <tr>
                        <td><strong>Series :</strong></td>
                        <td><?= $car->data()->series ?></td>
                    </tr>
                    <tr>
                        <td><strong>Variant:</strong></td>
                        <td><?= $car->data()->variant ?></td>
                    </tr>
                    <tr>
                        <td><strong>Year :</strong></td>
                        <td><?= $car->data()->year ?></td>
                    </tr>
                    <tr>
                        <td><strong>Type:</strong></td>
                        <td><?= $car->data()->type ?></td>
                    </tr>
                    <tr>
                        <td><strong>Chassis :</strong></td>
                        <td><?= $car->data()->chassis ?></td>
                    </tr>
                    <tr>
                        <td><strong>Color:</strong></td>
                        <td><?= $car->data()->color ?></td>
                    </tr>
                    <tr>
                        <td><strong>Engine :</strong></td>
                        <td><?= $car->data()->engine ?></td>
                    </tr>
                    <tr>
                        <td><strong>Purchase Date:</strong></td>
                        <td><?= $car->data()->purchasedate ?></td>
                    </tr>
                    <tr>
                        <td><strong>Sold Date :</strong></td>
                        <td><?= $car->data()->solddate ?></td>
                    </tr>
                    <tr>
                        <td><strong>Website : </strong></td>
                        <?php
                        if (!empty($car->data()->website)) {
                            echo '<td> <a target="_blank"  href=' . $car->data()->website . '>' . $car->data()->website . '</a></td>';
                        } else {
                            echo "<td></td></tr>";
                        }
                        ?>
                    <tr>
                        <td><strong>Comments:</strong></td>
                        <td><?= $car->data()->comments ?></td>
                    </tr>
                    <tr>
                        <td><strong>Created:</strong></td>
                        <td><?= $car->data()->ctime ?></td>
                    </tr>
                    <tr>
                        <td><strong>Last Modified:</strong></td>
                        <td><?= $car->data()->mtime ?></td>
                    </tr>
                    <tr>
                        <td><strong>Images:</strong></td>
                        <td>
                            <?php echo displayCarousel($car); ?>
                        </td>
                    </tr>
                    <?php
                    if (!is_null($car->factory())) {
                    ?>
                        <tr class="table-info">
                            <td colspan=2><strong>Factory Data - <small>I' ve lost track of where this data originated and it may be incomplete, inaccurate, false, or just plain made up.</small></strong></td>
                        </tr>
                        <tr>
                            <td><strong>Year:</strong></td>
                            <td><?= $car->factory()->year ?></td>
                        </tr>
                        <tr>
                            <td><strong>Month:</strong></td>
                            <td><?= $car->factory()->month ?></td>
                        </tr>
                        <tr>
                            <td><strong>Production Batch:</strong></td>
                            <td><?= $car->factory()->batch ?></td>
                        </tr>
                        <tr>
                            <td><strong>Type:</strong></td>
                            <td><?= $car->factory()->type ?></td>
                        </tr>
                        <tr>
                            <td><strong>Chassis:</strong></td>
                            <td><?= $car->factory()->serial ?></td>
                        </tr>
                        <tr>
                            <td><strong>Suffix:</strong></td>
                            <td><?= $car->factory()->suffix ?></td>
                        </tr>
                        <tr>
                            <td><strong>Engine:</strong></td>
                            <td><?= $car->factory()->engineletter ?><?= $car->factory()->enginenumber ?></td>
                        </tr>
                        <tr>
                            <td><strong>Gearbox:</strong></td>
                            <td><?= $car->factory()->gearbox ?></td>
                        </tr>
                        <tr>
                            <td><strong>Color:</strong></td>
                            <td><?= $car->factory()->color ?></td>
                        </tr>
                        <tr>
                            <td><strong>Build Date:</strong></td>
                            <td><?= $car->factory()->builddate ?></td>
                        </tr>
                        <tr>
                            <td><strong>Notes:</strong></td>
                            <td><?= $car->factory()->note ?></td>
                        </tr>
                    <?php }    ?>
                </table>
                <div class="col">
                    <div class="form-group row">
                        <form method='POST' action=<?= $us_url_root . 'app/cars/edit.php' ?>>
                            <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                            <input type="hidden" name="action" value="updateCar" />
                            <input type="hidden" name="carid" value="<?= $car->data()->id ?>" />
                            <button class="btn btn-success" type="submit">Update Car</button>
                        </form>
                        <a class="btn btn-info" role="button" href="<?= $us_url_root ?>app/cars/details.php?car_id=<?= $car->data()->id ?>">Details</a>
                    </div>
                </div>
                <br>
        <?php
            }
        } ?>

    </div> <!-- card-body -->
</div> <!-- card -->

<script>
    // Remove/ things in the master that I don't want
    $('#username').remove();
    $('#fname').remove();
    $('#slash ').remove();
    $('#lname').remove();
    $('.col-sm-12.col-md-9 p').remove(); // Edit Button

    // Ensure proper column alignment with the left card
    $('.col-sm-12.col-md-9').removeClass('col-sm-12').addClass('col-12');
</script>