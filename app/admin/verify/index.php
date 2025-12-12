<?php
/**
 * index.php
 * Default verification page - redirects or provides verification interface.
 *
 * Part of the car verification system that allows owners to verify their car information
 * via email links. Handles security and authentication.
 *
 * @author Elan Registry Admin
 * @copyright 2025
 */

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($_SERVER['PHP_SELF'])) {
	die();
}

// Get the counts of cars that have a vericode and count of cars with a verified date

$resultsRequest = $db->query("SELECT  COUNT(*) as count FROM cars  WHERE vericode <> '' ")->results()[0];
$resultsSold = $db->query("SELECT COUNT(*) as count FROM `cars_hist` WHERE operation = 'VERIFIED SOLD'")->results()[0];
$resultsVerified = $db->query("SELECT COUNT(*) as count FROM `cars_hist` WHERE operation = 'VERIFIED'")->results()[0];
$resultsUpdate = $db->query("SELECT count(*) as count  FROM `cars_hist` WHERE operation = 'UPDATE' and timestamp > '2020-06-15 15:44:00'")->results()[0];

?>

<div id="page-wrapper">
	<div class="container-fluid">
		<div class="well">
			<div class="row">
				<div class="col-8">
					<div class="card card-default">
						<div class="card-header">
							<h2><strong>Report of Verification Status</strong></h2>
						</div>
						<div class="card-body">
							Sold <?= $resultsSold->count ?></br>
							Verified <?= $resultsVerified->count ?></br>
							Updated <?= $resultsUpdate->count ?></br>
							Requested <?= $resultsRequest->count ?></br>
						</div> <!-- card-body -->
					</div> <!-- card -->
				</div> <!-- col -->
				<div class="col-4">
					<div class="card card-default">
						<div class="card-header">
							<h2><strong>Verify Cars</strong></h2>
						</div>
						<div class="card-body">
							<button class="btn btn-primary btn-lg btn-block" onclick=" window.open('send_email.php','_blank')">Send Verify Request Email</button>
						</div> <!-- card-body -->
					</div> <!-- card -->
				</div> <!-- col -->

			</div> <!-- row -->

		</div> <!-- well -->
	</div><!-- Container -->
</div><!-- page -->


<!-- Javascript -->



<!-- footers -->
<?php require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/footer.php'; //custom template footer
?>