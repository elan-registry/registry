<?php
if (count(get_included_files()) == 1) {
    die(); //Direct Access Not Permitted Leave this line in place
}

global $user;

// Get some interesting user information to display later

$user_id = $user->data()->id;

// USER ID is in $user_id .  Use the USER ID to get the user information from users table
$userQ = $db->query("SELECT * FROM users WHERE id = ?", array($user_id));
if ($userQ->count() > 0) {
    $thatUser = $userQ->results();
}

$cars = Car::findByOwner($user_id);

?>


<div class="card registry-card">
    <div class="card-header">
        <h4 class="mb-0"><i class="fas fa-car"></i> <strong>Your Car Information</strong></h4>
    </div>
    <div class="card-body">
        <?php

        // If there is car information then display it

        if (empty($cars)) {
            //     If the user does not have a car then display the add car form
        ?>
            <div class="text-center py-5">
                <i class="fas fa-car fa-3x text-muted mb-3"></i>
                <p class="text-muted mb-3">You haven't registered any cars yet. Add your first Lotus Elan to get started!</p>
                <a class="btn btn-success btn-lg" href="<?= $us_url_root ?>app/cars/edit.php" role="button">
                    <i class="fas fa-plus me-2"></i>Add Your First Car
                </a>
            </div>
            <?php
        } else {
            // Display cars with enhanced styling similar to details page
            foreach ($cars as $car) {
            ?>
                <!-- Car Hero Section -->
                <div class="card registry-card bg-info text-white mb-4">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h3 class="mb-0">
                                    <i class="fas fa-car me-2"></i>
                                    <?= $car->data()->year ?> Lotus Elan <?= $car->data()->series ?>
                                    <?= !empty($car->data()->variant) ? ' (' . $car->data()->variant . ')' : '' ?>
                                </h3>
                                <div class="row mt-3">
                                    <div class="col-sm-6 col-lg-3 mb-2">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-hashtag me-3 fa-lg"></i>
                                            <div>
                                                <div class="text-white-75 fw-medium mb-1">Registry ID</div>
                                                <div class="fw-bold fs-5"><?= $car->data()->id ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-6 col-lg-3 mb-2">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-barcode me-3 fa-lg"></i>
                                            <div>
                                                <div class="text-white-75 fw-medium mb-1">Chassis</div>
                                                <div class="fw-bold fs-5"><?= $car->data()->chassis ?: 'Not specified' ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-6 col-lg-3 mb-2">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-palette me-3 fa-lg"></i>
                                            <div>
                                                <div class="text-white-75 fw-medium mb-1">Color</div>
                                                <div class="fw-bold fs-5"><?= $car->data()->color ?: 'Not specified' ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-6 col-lg-3 mb-2">
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-cog me-3 fa-lg"></i>
                                            <div>
                                                <div class="text-white-75 fw-medium mb-1">Engine</div>
                                                <div class="fw-bold fs-5"><?= $car->data()->engine ?: 'Not specified' ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <form method='POST' action='<?= $us_url_root ?>app/cars/edit.php' class="d-inline me-2">
                                    <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                                    <input type="hidden" name="action" value="updateCar" />
                                    <input type="hidden" name="car_id" value="<?= $car->data()->id ?>" />
                                    <button class="btn btn-light btn-lg" type="submit">
                                        <i class="fas fa-edit"></i> Update Car
                                    </button>
                                </form>
                                <a class="btn btn-outline-light btn-lg" role="button" href="<?= $us_url_root ?>app/cars/details.php?car_id=<?= $car->data()->id ?>">
                                    <i class="fas fa-eye"></i> View Details
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Car Information Details -->
                <div class="row mb-4">
                    <div class="col-lg-6 mb-4">
                        <div class="card registry-card">
                            <div class="card-header">
                                <h4 class="mb-0"><i class="fas fa-info-circle text-primary"></i> Vehicle Information</h4>
                            </div>
                            <div class="card-body">
                                <dl class="row mb-4">
                                    <dt class="col-sm-4 text-muted">
                                        <i class="fas fa-calendar text-primary"></i> Model Year
                                    </dt>
                                    <dd class="col-sm-8"><?= $car->data()->year ?: '<em class="text-muted">Not specified</em>' ?></dd>
                                    
                                    <dt class="col-sm-4 text-muted">
                                        <i class="fas fa-list text-primary"></i> Series
                                    </dt>
                                    <dd class="col-sm-8"><?= $car->data()->series ?: '<em class="text-muted">Not specified</em>' ?></dd>
                                    
                                    <dt class="col-sm-4 text-muted">
                                        <i class="fas fa-tag text-primary"></i> Type
                                    </dt>
                                    <dd class="col-sm-8"><?= $car->data()->type ?: '<em class="text-muted">Not specified</em>' ?></dd>
                                    
                                    <dt class="col-sm-4 text-muted">
                                        <i class="fas fa-barcode text-primary"></i> Chassis
                                    </dt>
                                    <dd class="col-sm-8">
                                        <strong><?= $car->data()->chassis ?: '<em class="text-muted">Not specified</em>' ?></strong>
                                    </dd>
                                </dl>

                                <hr>

                                <h6 class="text-muted mb-3">
                                    <i class="fas fa-palette text-secondary"></i> Appearance & Technical
                                </h6>
                                <dl class="row mb-4">
                                    <dt class="col-sm-4 text-muted">Color</dt>
                                    <dd class="col-sm-8"><?= $car->data()->color ?: '<em class="text-muted">Not specified</em>' ?></dd>
                                    
                                    <dt class="col-sm-4 text-muted">Engine</dt>
                                    <dd class="col-sm-8"><?= $car->data()->engine ?: '<em class="text-muted">Not specified</em>' ?></dd>
                                </dl>

                                <?php if (!empty($car->data()->purchasedate) || !empty($car->data()->solddate) || !empty($car->data()->website)) { ?>
                                <hr>
                                <h6 class="text-muted mb-3">
                                    <i class="fas fa-history text-secondary"></i> Ownership & History
                                </h6>
                                <dl class="row mb-4">
                                    <?php if (!empty($car->data()->purchasedate)) { ?>
                                    <dt class="col-sm-4 text-muted">Purchase Date</dt>
                                    <dd class="col-sm-8">
                                        <?php 
                                        $purchaseDate = new DateTime($car->data()->purchasedate);
                                        echo $purchaseDate->format('F j, Y');
                                        ?>
                                    </dd>
                                    <?php } ?>
                                    
                                    <?php if (!empty($car->data()->solddate)) { ?>
                                    <dt class="col-sm-4 text-muted">Sold Date</dt>
                                    <dd class="col-sm-8">
                                        <?php 
                                        $soldDate = new DateTime($car->data()->solddate);
                                        echo $soldDate->format('F j, Y');
                                        ?>
                                    </dd>
                                    <?php } ?>
                                    
                                    <?php if (!empty($car->data()->website)) { ?>
                                    <dt class="col-sm-4 text-muted">Website</dt>
                                    <dd class="col-sm-8">
                                        <a href="<?= $car->data()->website ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
                                            <i class="fas fa-external-link-alt"></i> Visit Website
                                        </a>
                                    </dd>
                                    <?php } ?>
                                </dl>
                                <?php } ?>

                                <?php if (!empty($car->data()->comments)) { ?>
                                <hr>
                                <h6 class="text-muted mb-3">
                                    <i class="fas fa-comment text-secondary"></i> Your Comments
                                </h6>
                                <div class="bg-light p-3 rounded">
                                    <?= nl2br(htmlspecialchars($car->data()->comments)) ?>
                                </div>
                                <?php } ?>

                                <!-- Registry Information -->
                                <hr>
                                <div class="row text-center">
                                    <div class="col-6">
                                        <i class="fas fa-plus-circle text-success d-block mb-1"></i>
                                        <small class="text-muted d-block">Added to Registry</small>
                                        <strong>
                                            <?php 
                                            $createdDate = new DateTime($car->data()->ctime);
                                            echo $createdDate->format('M j, Y');
                                            ?>
                                        </strong>
                                    </div>
                                    <div class="col-6">
                                        <i class="fas fa-edit text-info d-block mb-1"></i>
                                        <small class="text-muted d-block">Last Updated</small>
                                        <strong>
                                            <?php 
                                            $modifiedDate = new DateTime($car->data()->mtime);
                                            echo $modifiedDate->format('M j, Y');
                                            ?>
                                        </strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 mb-4">
                        <!-- Car Images -->
                        <div class="card registry-card mb-4">
                            <div class="card-header">
                                <h4 class="mb-0"><i class="fas fa-images text-primary"></i> Photos</h4>
                            </div>
                            <div class="card-body">
                                <?php echo CarView::displayCarousel($car); ?>
                            </div>
                        </div>
                        <!-- Factory Data Card -->
                        <?php if (!is_null($car->factory())) { ?>
                        <div class="card registry-card">
                            <div class="card-header">
                                <h4 class="mb-0">
                                    <i class="fas fa-industry text-primary"></i> Factory Data
                                    <span class="badge bg-warning ms-2">Unverified</span>
                                </h4>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info mb-3">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <strong>Note:</strong> This information has not been verified against the official Lotus archives.
                                </div>
                                
                                <dl class="row">
                                    <dt class="col-sm-5 text-muted">Production Year</dt>
                                    <dd class="col-sm-7"><?= $car->factory()->year ?: '<em class="text-muted">Unknown</em>' ?></dd>
                                    
                                    <dt class="col-sm-5 text-muted">Production Month</dt>
                                    <dd class="col-sm-7"><?= $car->factory()->month ?: '<em class="text-muted">Unknown</em>' ?></dd>
                                    
                                    <dt class="col-sm-5 text-muted">Production Batch</dt>
                                    <dd class="col-sm-7"><?= $car->factory()->batch ?: '<em class="text-muted">Unknown</em>' ?></dd>
                                    
                                    <dt class="col-sm-5 text-muted">Factory Type</dt>
                                    <dd class="col-sm-7"><?= $car->factory()->type ?: '<em class="text-muted">Unknown</em>' ?></dd>
                                    
                                    <dt class="col-sm-5 text-muted">Factory Chassis</dt>
                                    <dd class="col-sm-7">
                                        <strong><?= $car->factory()->serial ?: '<em class="text-muted">Unknown</em>' ?></strong>
                                    </dd>
                                    
                                    <dt class="col-sm-5 text-muted">Chassis Suffix</dt>
                                    <dd class="col-sm-7"><?= $car->factory()->suffix ?: '<em class="text-muted">Unknown</em>' ?></dd>
                                    
                                    <dt class="col-sm-5 text-muted">Factory Engine</dt>
                                    <dd class="col-sm-7">
                                        <?= $car->factory()->engineletter ?><?= $car->factory()->enginenumber ?>
                                    </dd>
                                    
                                    <dt class="col-sm-5 text-muted">Gearbox</dt>
                                    <dd class="col-sm-7"><?= $car->factory()->gearbox ?: '<em class="text-muted">Unknown</em>' ?></dd>
                                    
                                    <dt class="col-sm-5 text-muted">Factory Color</dt>
                                    <dd class="col-sm-7"><?= $car->factory()->color ?: '<em class="text-muted">Unknown</em>' ?></dd>
                                    
                                    <dt class="col-sm-5 text-muted">Build Date</dt>
                                    <dd class="col-sm-7">
                                        <?php 
                                        if ($car->factory()->builddate) {
                                            $buildDate = new DateTime($car->factory()->builddate);
                                            echo $buildDate->format('F j, Y');
                                        } else {
                                            echo '<em class="text-muted">Unknown</em>';
                                        }
                                        ?>
                                    </dd>
                                    
                                    <?php if (!empty($car->factory()->note)) { ?>
                                    <dt class="col-sm-5 text-muted">Notes</dt>
                                    <dd class="col-sm-7"><?= htmlspecialchars($car->factory()->note) ?></dd>
                                    <?php } ?>
                                </dl>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                </div>

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