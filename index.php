  <?php
/**
 * Lotus Elan Registry - Homepage
 *
 * This is the main landing page for the Lotus Elan Registry website.
 * It displays registry statistics, a random featured car, and important resources.
 *
 * @package ElanRegistry
 * @version 2.8.0
 * @author Jim Boone
 */

require_once 'users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';
require_once $abs_us_root . $us_url_root . 'usersc/classes/CarView.php';

// Security check - ensure page access is authorized
if (!securePage($_SERVER['PHP_SELF'])) {
	die();
}

/**
 * Fetch a random car with valid images to display on homepage
 * 
 * Filters for cars that have:
 * - Non-empty image field
 * - Valid JSON format (not empty array '[]')
 * - Properly formatted JSON (JSON_VALID = 1)
 * - At least one image in the JSON array (JSON_LENGTH > 0)
 *
 * @var int $randomCarId The ID of a randomly selected car with valid images
 * @var Car $car Car object instance for the selected random car
 */
$randomCarResults = $db->query("SELECT id FROM cars
    WHERE image <> ''
    AND image <> '[]'
    AND JSON_VALID(image) = 1
    AND JSON_LENGTH(image) > 0
    ORDER BY RAND() LIMIT 1")->results();

if (!empty($randomCarResults)) {
    $randomCarId = (int) $randomCarResults[0]->id;
    $car = new Car($randomCarId);
} else {
    // Fallback: if no cars with valid images found, try any car with non-empty image field
    $fallbackResults = $db->query("SELECT id FROM cars WHERE image <> '' ORDER BY RAND() LIMIT 1")->results();
    if (!empty($fallbackResults)) {
        $randomCarId = (int) $fallbackResults[0]->id;
        $car = new Car($randomCarId);
    } else {
        // No cars with images at all - set to null to handle in template
        $car = null;
    }
}

/**
 * Get count of cars by series using efficient single query
 * Groups cars into series categories and counts registrations
 *
 * @var array $seriesResults Database results containing series counts
 */
$seriesResults = $db->query("SELECT
    CASE
        WHEN series LIKE 's1%' THEN 's1'
        WHEN series LIKE 's2%' THEN 's2'
        WHEN series LIKE 's3%' THEN 's3'
        WHEN series LIKE 's4%' THEN 's4'
        WHEN series LIKE 'sprint%' THEN 'sprint'
        WHEN series LIKE '+2%' THEN '+2'
    END as series_group,
    COUNT(*) as count
FROM cars
WHERE series LIKE 's1%' OR series LIKE 's2%' OR series LIKE 's3%' OR series LIKE 's4%' OR series LIKE 'sprint%' OR series LIKE '+2%'
GROUP BY series_group")->results();

/**
 * Initialize count array with zeros for all series
 *
 * @var array<string, int> $count Array of series counts indexed by series name
 */
$count = ['s1' => 0, 's2' => 0, 's3' => 0, 's4' => 0, 'sprint' => 0, '+2' => 0];

/**
 * Populate count array from database query results
 */
foreach ($seriesResults as $result) {
    if ($result->series_group) {
        $count[$result->series_group] = (int) $result->count;
    }
}

/**
 * Number of cars produced per series (from reference material)
 * Data source: "Authentic Lotus Elan & Plus 2 1962-1974" by Robinshaw and Ross
 *
 * @var array<string, string> $notes Production numbers for each series
 */
$notes = [];
$notes['s1']     = "900";
$notes['s2']     = "1250";
$notes['s3']     = "2650";
$notes['s4']     = "2976";
$notes['sprint'] = "900";
$notes['+2']     = "4526";

?>
<?php
/**
 * HTML Template Start - Homepage Layout
 *
 * The following section contains the main homepage template with:
 * - Site header and navigation
 * - Registry statistics table
 * - Featured random car display
 * - Resource links and acknowledgments
 */
?>
<div class="page-wrapper">
	<!-- Page Content -->
	<div class='container'>
		<!-- Heading Row -->
		<div class='row'>
			<div class='col-lg-5'>
				<div class='card registry-card'>
					<div class='card-header'>
						<h1 class='mb-0'><i class='fas fa-car'></i> <?php echo htmlspecialchars($settings->site_name ?? 'Lotus Elan Registry', ENT_QUOTES, 'UTF-8'); ?></h1>
						<p class='text-muted'>A place to document Lotus Elan and Lotus Elan Plus 2</p>
					</div>
					<div class='card-body'>

						<?php
						/**
						 * Display user authentication buttons
						 * Shows account link for logged-in users, login/signup for guests
						 */
						if ($user->isLoggedIn()) {
							/** @var int $uid Current user ID */
							$uid = (int) $user->data()->id; ?>
							<a class='btn btn-outline-secondary btn-sm' href='users/account.php' role='button'><i class='fas fa-user'></i> User Account &raquo;</a>
						<?php
						} else { ?>
							<a class='btn btn-warning btn-sm' href='users/login.php' role='button'><i class='fas fa-sign-in-alt'></i> Log In &raquo;</a>
							<a class='btn btn-info btn-sm' href='users/join.php' role='button'><i class='fas fa-user-plus'></i> Sign Up &raquo;</a>
						<?php } ?>
						<br><br>
						<p>This is the Registry for the 1963 thru 1973 Lotus
							Elan and the 1967 thru 1974 Lotus Elan Plus 2. The purpose of the registry is to keep a
							history of the cars, trace the evolution of the
							Lotus Elan and to facilitate owner communication.
						</p>
						<p>The Lotus Elan Registry started in January 2003. A thread on LotusElan.net asked the question,
							<a href='http://www.lotuselan.net/forums/elan-f14/lotus-elan-register-t349.html'>
								Does anybody know if there is a Lotus Elan register?</a>
							I bashed together a registry and a few years later
							we have over 300 cars accounted for with more added every month.
						</p>
					</div> <!-- card-body -->
				</div> <!-- card -->

				<div class='card registry-card'>
					<div class="card-header">
						<h2 class='mb-0'><i class='fas fa-chart-bar'></i> How are we doing?</h2>
					</div>
					<div class="card-body">
						<table id="seriestable" class="table table-striped table-bordered table-hover table-sm" aria-describedby="card-header">
							<thead class="thead-light">
								<tr>
									<th scope="column">Series</th>
									<th scope="column">Registered</th>
									<th scope="column">Number produced *</th>
									<th scope="column">Percent registered</th>
								</tr>
							</thead>
							<tbody>
								<?php
								/**
								 * Generate statistics table rows showing registry data by series
								 * Calculates totals and percentages for registered vs produced cars
								 *
								 * @var int $total Total registered cars across all series
								 * @var int $totalN Total cars produced across all series
								 */
								$total = 0;
								$totalN = 0;
								foreach ($count as $key => $value) {
									echo "<tr><td>" . ucfirst((string) $key) . "</td><td>" . (int) $value . "</td>";
									echo "<td>" . htmlspecialchars((string) $notes[$key], ENT_QUOTES, 'UTF-8') . "</td>";
									echo "<td>" . round(((int) $value * 100) / (int) $notes[$key], 0) . " %</td></tr>";

									$total += (int) $value;
									$totalN += (int) $notes[$key];
								}
								echo "<tr><td><strong>Total</strong></td><td><strong>" . $total . "</strong></td><td>" .
									$totalN . "</td><td>" . round(($total * 100) / $totalN) . " %</td></tr>";
								?>
							</tbody>
						</table>
						<p><small>* - Number produced is from
								<a href="https://www.amazon.com/Authentic-Lotus-1962-1974-Marques-Models/dp/0947981950">
									Authentic Lotus Elan & Plus 2 1962 - 1974 by Robinshaw and Ross</a>, page 22 and page 138.
								In cases where there is a range of values, I took the lower.</small></p>
					</div> <!-- body -->
				</div><!-- card -->

			</div>
			<!-- /.col-lg-8 -->
			<div class='col-lg-7'>
				<div class='card registry-card'>
					<div class='card-header'>
						<h2 class='mb-0'><i class='fas fa-star'></i> One of the Cars</h2>
					</div>
					<div class='card-body'>
						<?php
						/**
						 * Display featured random car
						 * Shows carousel images and key details for a randomly selected car
						 */
						if ($car !== null) {
							echo CarView::displayCarousel($car); ?>
							<table id='cartable' class='table table-striped table-bordered table-hover table-sm' aria-describedby='Car ID <?= (int) $car->data()->id ?>'>
								<tr>
									<th scope='col'><strong>Year :</strong></th>
									<th scope='col'><?= htmlspecialchars((string) $car->data()->year, ENT_QUOTES, 'UTF-8') ?></th>
								</tr>
								<tr>
									<td><strong>Series :</strong></td>
									<td><?= htmlspecialchars((string) $car->data()->series, ENT_QUOTES, 'UTF-8') ?></td>
								</tr>
								<tr>
									<td><strong>Variant:</strong></td>
									<td><?= htmlspecialchars((string) $car->data()->variant, ENT_QUOTES, 'UTF-8') ?></td>
								</tr>
								<tr>
									<td><strong>Type:</strong></td>
									<td><?= htmlspecialchars((string) $car->data()->type, ENT_QUOTES, 'UTF-8') ?></td>
								</tr>
								<tr>
									<td colspan='2'><a class='btn btn-success btn-sm' href='<?= htmlspecialchars($us_url_root, ENT_QUOTES, 'UTF-8') ?>app/cars/details.php?car_id=<?= (int) $car->data()->id ?>'><i class='fas fa-eye'></i> Details</a></td>
								</tr>
							</table>
						<?php
						} else {
							// No cars with images found - show message
							echo '<div class="text-center py-4">';
							echo '<i class="fas fa-car text-muted" style="font-size: 3rem;"></i>';
							echo '<h5 class="text-muted mt-3 mb-2">No Featured Cars Available</h5>';
							echo '<p class="text-muted">Cars are being added to the registry regularly. Check back soon!</p>';
							echo '</div>';
						}
						?>
					</div> <!-- card-body -->
				</div> <!-- card -->
			</div> <!-- /.col-md-4 -->
		</div> <!-- /.row -->

		<!-- Content Row -->
		<div class='row'>
			<div class='col-md-5'>
				<div class='card registry-card'>
					<div class='card-header'>
						<h2 class='mb-0'><i class='fas fa-heart'></i> Thanks</h2>
					</div>
					<div class='card-body'>
						<p class='card-text'>Thank you to the many people on the Elan mailing list and the
							Elan forums who have helped with the registry.
							The group has helped with testing, providing pictures, provided feedback on what
							should be included, and kept me motivated to improve the site.
							This is their work. I am just the one who assembled the pieces.</p>

						<p class='card-text'>Special thanks to Ross, Tim, Gary, Ed, Terry, Peter, Jeff, Nicholas,
							Alan, Christian, Michael, Stan,
							Jason and everyone else who has contributed and will continue to make the registry
							what it is, a place
							for us to obsess over little British cars.</p>
					</div><!-- /.card-body -->
				</div><!-- /.card -->
			</div><!-- /.col -->
			<!-- /.col-md-4 -->
			<div class='col-md-7'>
				<div class='card registry-card'>
					<div class='card-header'>
						<h2 class='mb-0'><i class='fas fa-link'></i> Important Resources</h2>
					</div>
					<div class='card-body'>
						<div class='list-group'>
							<a href='http://www.lotuselansprint.com/' class='list-group-item list-group-item-action flex-column align-items-start'>
								<div class='d-flex w-100 justify-content-between'>
									<h5 class='mb-1'>The Lotus Elan Sprint</h5>
								</div>
								<p class='mb-1 pl-3'><small>This site is dedicated to the Lotus Elan Sprint, the final iteration of the Lotus Elan</small></p>
							</a>
							<a href='http://www.lotuselan.net/' class='list-group-item list-group-item-action flex-column align-items-start'>
								<div class='d-flex w-100 justify-content-between'>
									<h5 class='mb-1'>LotusElan.Net</h5>
								</div>
								<p class='mb-1 pl-3'><small>A great online community for the Lotus Elan.</small></p>
							</a>
							<a href='<?= $us_url_root ?>docs/stories/type26register.php' class='list-group-item list-group-item-action flex-column align-items-start'>
								<div class='d-flex w-100 justify-content-between'>
									<h5 class='mb-1'>Type 26 Registry</h5>
								</div>
								<p class='mb-1 pl-3'><small>The 26 registry is no longer online. I've copied what I can and saved it here</small></p>
							</a>
							<a href='https://github.com/unibrain1/elanregistry' class='list-group-item list-group-item-action flex-column align-items-start'>
								<div class='d-flex w-100 justify-content-between'>
									<h5 class='mb-1'>Elan Registry project on GitHub</h5>
								</div>
								<p class='mb-1 pl-3'><small>If you want to help out with the coding or just want to see how the sausage is made. I'm not a professional coder, I just play one in the garage.</small></p>
							</a>
						</div><!-- /.list-group -->
					</div><!-- /.card-body -->
				</div> <!-- /.card -->
			</div> <!-- /.col-md-4 -->
		</div> <!-- /.row -->
	</div> <!-- /.container -->
</div><!-- .page-wrapper -->
<!-- footers -->
<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer
?>
