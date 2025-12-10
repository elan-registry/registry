<?php
declare(strict_types=1);
/**
 * identification.php
 * Provides a guide for identifying different Lotus Elan models and variants.
 *
 * Includes links to documentation and uses the site template for layout and security.
 *
 * @author Elan Registry Admin
 * @copyright 2025
 */
require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';
?>



<?php if (!securePage($_SERVER['PHP_SELF'])) {
	die();
}

?>


<div class="page-wrapper">
	<div class="container-fluid">
		<div class="page-container">
			<div class="row justify-content-center">
				<div class="col-lg-10">
					<div class="card registry-card">
					<div class="card-header">
						<h2 class="mb-0">Identification Guide</h2>
					</div>
					<div class="card-body">
						<p>This is not a comprehensive list of differences between the cars, rather a quick guide to identifying a car. Like all manufacturers, there were running changes throughout the life of the Elan. In the case of Lotus, many of these changes were poorly documented. If in doubt, post a question to the <a href="http://www.lotuselan.net/forums/">LotusElan.net forum</a> and ask.</p>
						<!-- Skip Navigation -->
						<a class="visually-hidden-focusable btn btn-primary" href="#roadster">Skip to identification guide</a>
						
						<!-- Navigation Pills -->
						<ul class="nav nav-pills mb-3 justify-content-center" role="tablist">
							<li class="nav-item" role="presentation"><a class="nav-link" href="#roadster" role="tab">Roadster</a></li>
							<li class="nav-item" role="presentation"><a class="nav-link" href="#coupe" role="tab">Coupe</a></li>
							<li class="nav-item" role="presentation"><a class="nav-link" href="#race" role="tab">Race</a></li>
							<li class="nav-item" role="presentation"><a class="nav-link" href="#plus2" role="tab">Plus 2</a></li>
							<li class="nav-item" role="presentation"><a class="nav-link" href='<?= $us_url_root ?>docs/embed.php?doc=2019_Jan_The_Elan_Super_Safety.pdf' target="_blank" rel="noopener"><i class="fas fa-external-link-alt me-1"></i>Super Safety</a></li>
						</ul>
						<!-- Roadster Section -->
						<section id="roadster" aria-labelledby="roadster-heading">
							<h3 id="roadster-heading" class="mt-4 mb-3">
								<i class="fas fa-car text-primary me-2"></i>Roadster/Drophead
								<a href="#roadster" class="text-muted ms-2 text-decoration-none" title="Link to this section">#</a>
							</h3>
							<div class="table-responsive">
								<table class="table table-striped table-bordered table-sm" aria-describedby="roadster-heading">
									<caption class="visually-hidden">Lotus Elan Roadster and Drophead model identification guide with descriptions and images</caption>
										<thead>
											<tr>
												<th scope="col" class="w-20">Model</th>
												<th scope="col" class="w-50">Description</th>
												<th scope="col" class="w-30">Image</th>
											</tr>
										</thead>
										<tbody>
											<tr>
												<td><strong>Elan 1500</strong><br><em> Type 26 Elan 1500 Roadster </em></td>
												<td>
													<ul>
														<li>1500 badge on boot</li>
														<li>All were recalled from the factory and updated to Elan 1600 specification</li>
														<li>Boot lid does not extend all the way to the trailing edge of the car</li>
														<li>Lift up windows</li>
													</ul>
												</td>
												<td></td>
											</tr>
											<tr>
												<td><strong>Elan 1600 </strong><br><em> Type 26 S1 Roadster </em>
												</td>
												<td>
													<ul>
														<li>1600 badge on boot</li>
														<li>Round tail lights</li>
														<li>No rollup windows</li>
														<li>Boot lid does not extend all the way to the trailing edge of the car</li>
														<li>Lift up windows</li>
													</ul>
												</td>
												<td><img class="img-fluid rounded shadow-sm" src="../images/examples/thumbs/elan1600.jpg" style="max-width: 150px; height: auto;" title="Type 26 Elan 1600 (S1)" alt="Type 26 Elan 1600 (S1)" loading="lazy"></td>
											</tr>

											<tr>
												<td><strong>Roadster S2 </strong><br><em> Type 26 S2 Roadster </em>
												</td>
												<td>
													<ul>
														<li>No rollup windows</li>
														<li>Oval tail lights. Early cars had round lights</li>
														<li>Boot lid does not extend all the way to the trailing edge of the car</li>
														<li>Lift up windows</li>
													</ul>
												</td>
												<td><img class="polaroid" src="../images/examples/thumbs/s2a.jpg" height="112" width="150" title="Type 26 Elan S2" alt="Type 26 Elan S2">
													<img class="polaroid" src="../images/examples/thumbs/s2b.jpg" height="112" width="150" title="Type 26 Elan S2" alt="Type 26 Elan S2">
												</td>
											</tr>

											<tr>
												<td><strong>Drophead S3 </strong><br><em> Type 45 S3 DHC </em>
												</td>
												<td>
													<ul>
														<li>Electric rollup windows with fixed frames</li>
														<li>Oval tail lights</li>
													</ul>
												</td>
												<td></td>
											</tr>

											<tr>
												<td><strong>Drophead S3 S/E </strong><br><em> Type 45 S3 S/E DHC </em>
												</td>
												<td>
													<ul>
														<li>Electric rollup windows with fixed frames</li>
														<li>Oval tail lights</li>
														<li>S/E badged</li>
														<li>Stainless steel side trims & wing mounted flasher repeaters on most cars</li>
													</ul>
												</td>
												<td><img class="polaroid" src="../images/examples/thumbs/s3_se_dhc.jpg" height="90" width="150" title="Type 45 S3 S/E DHC" alt="Type 45 S3 S/E DHC"></td>
											</tr>

											<tr>
												<td><strong>Drophead S4 </strong><br><em> Type 45 S4 DHC </em>
												</td>
												<td>
													<ul>
														<li>Square tail lights</li>
														<li>Square profile wheelarches</li>
													</ul>
												</td>
												<td><img class="polaroid" src="../images/examples/thumbs/s4_dhc.jpg" height="89" width="150" title="Type 45 S4 DHC" alt="Type 45 S4 DHC"></td>
											</tr>

											<tr>
												<td><strong>Drophead S4 S/E </strong><br><em> Type 45 S4 S/E DHC </em>
												</td>
												<td>
													<ul>
														<li>Square tail lights</li>
														<li>Square profile wheelarches</li>
														<li>S/E badged</li>
														<li>Stainless steel side trims & wing mounted flasher repeaters on most cars</li>
													</ul>
												</td>
												<td><img class="polaroid" src="../images/examples/thumbs/s4_se_dhc_b.jpg" height="112" width="150" title="Type 45 S4 S/E DHC" alt="Type 45 S4 S/E DHC">
													<img class="polaroid" src="../images/examples/thumbs/s4_se_dhc_a.jpg" height="112" width="150" title="Type 45 S4 S/E DHC" alt="Type 45 S4 S/E DHC">
												</td>
											</tr>

											<tr>
												<td><strong>Drophead Sprint </strong><br><em> Type 45 Sprint DHC </em>
												</td>
												<td>
													<ul>
														<li><a href="http://www.lotuselansprint.com">Complete Details</a></li>
														<li>Square tail lights</li>
														<li>Badged as Sprint</li>
														<li>Unique Two tone paint. Could be deleted as an option</li>
														<li>Square profile wheelarches</li>
													</ul>
												</td>
												<td><img class="polaroid" src="../images/examples/thumbs/sprint_dhc.jpg" height="84" width="150" title="Type 45 Sprint DHC" alt="Type 45 Sprint DHC"></td>
											</tr>
									</table>
							</div>
						</section>

						<!-- Coupe Section -->
						<section id="coupe" aria-labelledby="coupe-heading">
							<h3 id="coupe-heading" class="mt-4 mb-3">
								<i class="fas fa-car text-primary me-2"></i>Coupe
								<a href="#coupe" class="text-muted ms-2 text-decoration-none" title="Link to this section">#</a>
							</h3>
							<div class="table-responsive">
								<table class="table table-striped table-bordered table-sm" aria-describedby="coupe-heading">
									<caption class="visually-hidden">Lotus Elan Coupe model identification guide with descriptions and images</caption>
											<thead>
												<tr>
													<th scope="col" class="w-20">Model</th>
													<th scope="col" class="w-50">Description</th>
													<th scope="col" class="w-30">Image</th>
												</tr>
											</thead>
											<tbody>
												<tr>
													<td><strong>Coupe S3 Pre-Airflow </strong><br><em> Type 36 FHC-preairflow </em></td>
													<td>
														<ul>
															<li>Oval tail lights</li>
															<li>No extractor grill on the B pillar</li>
														</ul>
													</td>
													<td><img class="polaroid" src="../images/examples/thumbs/s3_fhc_pre.jpg" height="99" width="150" title="Type 36 FHC preairflow" alt="Type 36 FHC preairflow"></td>
												</tr>
												<tr>
													<td><strong>Coupe S3 Airflow </strong><br><em> Type 36 S3 FHC </em>
													</td>
													<td>
														<ul>
															<li>Oval tail lights</li>
															<li>Extractor grill on the rear quarter panel</li>
														</ul>
													</td>
													<td><img class="polaroid" src="../images/examples/thumbs/s3_fhc_air.jpg" height="100" width="150" title="Type 36 FHC" alt="Type 36 FHC"></td>
												</tr>

												<tr>
													<td><strong>Coupe S3 S/E Airflow </strong><br><em> Type 36 S3 S/E FHC </em>
													</td>
													<td>
														<ul>
															<li>Oval tail lights</li>
															<li>Extractor grill on the rear quarter panel</li>
															<li>S/E badged</li>
															<li>Stainless steel side trims & wing mounted flasher repeaters on most cars</li>
														</ul>
													</td>
													<td></td>
												</tr>

												<tr>
													<td><strong>Coupe S4 </strong><br><em> Type 36 S4 FHC </em>
													</td>
													<td>
														<ul>
															<li>Square tail lights</li>
															<li>Square profile wheelarches</li>
														</ul>
													</td>
													<td><img class="polaroid" src="../images/examples/thumbs/s4_fhc.jpg" height="79" width="150" title="Type 36 S4 FHC" alt="Type 36 S4 FHC"></td>
												</tr>

												<tr>
													<td><strong>Coupe S4 S/E </strong><br><em> Type 36 S4 S/E FHC </em>
													</td>
													<td>
														<ul>
															<li>Square tail lights</li>
															<li>Square profile wheelarches</li>
															<li>S/E badged</li>
															<li>Stainless steel side trims & wing mounted flasher repeaters on most cars</li>
														</ul>
													</td>
													<td></td>
												</tr>

												<tr>
													<td><strong>Coupe Sprint </strong><br><em> Type 36 Sprint FHC </em>
													</td>
													<td>
														<ul>
															<li><a href="http://www.lotuselansprint.com">Complete Details</a></li>
															<li>Square tail lights</li>
															<li>Badged as Sprint</li>
															<li>Unique Two tone paint. Could be deleted as an option</li>
															<li>Square profile wheelarches</li>
														</ul>
													</td>
													<td><img class="polaroid" src="../images/examples/thumbs/sprint_fhc.jpg" height="56" width="150" title="Type 45 Sprint DHC" alt="Type 45 Sprint DHC">
													</td>
												</tr>
										</table>
									</div>
							</fieldset>
						</section>

						<!-- Race Section -->
						<section id="race" aria-labelledby="race-heading">
							<h3 id="race-heading" class="mt-4 mb-3">
								<i class="fas fa-trophy text-primary me-2"></i>Racing Version
								<a href="#race" class="text-muted ms-2 text-decoration-none" title="Link to this section">#</a>
							</h3>
							<div class="table-responsive">
								<table class="table table-striped table-bordered table-sm" aria-describedby="race-heading">
									<caption class="visually-hidden">Lotus Elan Racing version identification guide with descriptions and images</caption>
												<thead>
													<tr>
														<th scope="col" class="w-20">Model</th>
														<th scope="col" class="w-50">Description</th>
														<th scope="col" class="w-30">Image</th>
													</tr>
												</thead>
												<tbody>
													<tr>
														<td><strong>26R </strong><br><em> Type 26 26R Race </em></td>
														<td>
															<ul>
																<li>Fixed headlights, Most not all</li>
																<li>Magnesium Lotus designed peg drive wheels</li>
																<li>S2 versions had lightweight bodies and flared fenders</li>
															</ul>
														</td>
														<td><img class="polaroid" src="../images/examples/thumbs/26r.jpg" height="83" width="150" title="Type 26R" alt="Type 26R"></td>
													</tr>
												</tbody>
											</table>
										</div>
							</fieldset>
						</section>

						<!-- Plus 2 Section -->
						<section id="plus2" aria-labelledby="plus2-heading">
							<h3 id="plus2-heading" class="mt-4 mb-3">
								<i class="fas fa-users text-primary me-2"></i>Plus 2
								<a href="#plus2" class="text-muted ms-2 text-decoration-none" title="Link to this section">#</a>
							</h3>
							<div class="table-responsive">
								<table class="table table-striped table-bordered table-sm" aria-describedby="plus2-heading">
									<caption class="visually-hidden">Lotus Elan Plus 2 model identification guide with descriptions and images</caption>
												<thead>
													<tr>
														<th scope="col" class="w-20">Model</th>
														<th scope="col" class="w-50">Description</th>
														<th scope="col" class="w-30">Image</th>
													</tr>
												</thead>
												<tbody>
													<tr>
														<td><strong>Plus 2</strong><br><em>Type 50 +2</em></td>
														<td>
															<ul>
																<li>50/0001 on </li>
																<li>2 large gauges - Speedometer, Tachometer</li>
																<li>4 small gauges - Water Temp, Oil Pressure, Ammeter, Fuel</li>
															</ul>
														</td>
														<td><img class="polaroid" src="../images/examples/thumbs/plus2int.jpg" height="112" width="150" title="Type 50 Plus 2 Dash" alt="Type 50 Plus 2 Dash"></td>
													</tr>
													<tr>
														<td><strong>Plus 2 Federal</strong><br><em>Type 50 +2 Federal</em>
														</td>
														<td>
															<ul>
																<li>50/0857 on for US, 50/0929 all markets </li>
																<li>2 large gauges - Speedometer, Tachometer</li>
																<li>4 small gauges - Water Temp, Oil Pressure, Ammeter, Fuel</li>
																<li>Remote boot release, flush interior door handles, modified exhaust</li>
															</ul>
														</td>
														<td></td>
													</tr>

													<tr>
														<td><strong>Plus 2S </strong><br><em>Type 50 +2 2S</em>
														</td>
														<td>
															<ul>
																<li>50/1593 on </li>
																<li>2 large gauges - Speedometer, Tachometer</li>
																<li>6 small gauges -Oil, Water temp, Battery Condition, Temp, Fuel, Clock</li>
																<li>4 warning lights in center of dash (Hazard, Parking Brake, Brake Fail, Rear Screen)</li>
																<li>New luxury interior including revised seats and centre console</li>
																<li>Fog/Driving lamps below the bumper</li>
															</ul>
														</td>
														<td><img class="polaroid" src="../images/examples/thumbs/plus2sa.jpg" height="99" width="150" title="Type 50 Plus 2S" alt="Type 50 Plus 2S">
															<img class="polaroid" src="../images/examples/thumbs/plus2sb.jpg" height="99" width="150" title="Type 50 Plus 2S" alt="Type 50 Plus 2S">
														</td>
													</tr>

													<tr>
														<td><strong>Plus 2S Federal </strong><br><em>Type 50 +2 2S Federal</em>
														</td>
														<td>
															<ul>
																<li>50/2447 on </li>
																<li>2 large gauges - Speedometer, Tachometer</li>
																<li>6 small gauges -Oil, Water temp, Battery Condition, Temp, Fuel, Clock</li>
																<li>4 warning lights in center of dash (Hazard, Parking Brake, Brake Fail, Rear Screen)</li>
																<li>Luxury interior including revised seats and centre console</li>
																<li>Fog/Driving lamps below the bumper</li>
															</ul>
														</td>
														<td></td>
													</tr>

													<tr>
														<td><strong>Plus 2S 130</strong><br><em>Type 50 +2 130</em>
														</td>
														<td>
															<ul>
																<li>71.01...0001 on </li>
																<li>2 large gauges - Speedometer, Tachometer</li>
																<li>6 small gauges - Oil, Water temp, Battery Condition, Temp, Fuel, Clock</li>
																<li>3 warning lights in center of dash (Hazard, Park/Brake Fail, Rear Screen) </li>
																<li>Luxury interior including revised seats and centre console</li>
																<li>Big Valve engine</li>
																<li>Fog/Driving lamps below the bumper</li>
															</ul>
														</td>
														<td><img class="polaroid" src="../images/examples/thumbs/plus2s130a.jpg" height="112" width="150" title="Type 50 Plus2S 130" alt="Type 50 Plus2S 130">
															<img class="polaroid" src="../images/examples/thumbs/plus2s130b.jpg" height="112" width="150" alt="Type 50 Plus2S 130" title="Type 50 Plus2S 130">
															<img class="polaroid" src="../images/examples/thumbs/plus2s130int.jpg" height="112" width="150" alt="Type 50 Plus2S 130" title="Type 50 Plus2S 130">
															<img class="polaroid" src="../images/examples/thumbs/plus2s130d.jpg" height="112" width="150" alt="Type 50 Plus2S 130" title="Type 50 Plus2S 130">
														</td>
													</tr>

													<tr>
														<td><strong>Plus 2S 130/5</strong><br><em>Type 50 +2 130/5</em>
														</td>
														<td>
															<ul>
																<li>72.10... on </li>
																<li>Same as Plus 2S 130 with 5-speed gearbox</li>
															</ul>
														</td>
														<td></td>
													</tr>
											</table>
										</div>
							</fieldset>
						</section>





									<!-- Back to Top Button -->
						<div class="text-center mt-4">
							<button type="button" class="btn btn-outline-primary" id="backToTop" title="Back to Top">
								<i class="fas fa-chevron-up"></i> Back to Top
							</button>
						</div>

						<!-- End of main content section -->
					</div> <!-- card-body -->
				</div> <!-- card -->
				</div>
			</div>
		</div>
	</div>
</div>

<!-- JavaScript for Enhanced Navigation -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Smooth scrolling for navigation links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
                
                // Update nav pills active state
                document.querySelectorAll('.nav-link').forEach(link => {
                    link.classList.remove('active');
                });
                this.classList.add('active');
            }
        });
    });
    
    // Back to top functionality
    const backToTopBtn = document.getElementById('backToTop');
    if (backToTopBtn) {
        backToTopBtn.addEventListener('click', function() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
        
        // Show/hide back to top button based on scroll position
        window.addEventListener('scroll', function() {
            if (window.pageYOffset > 300) {
                backToTopBtn.style.opacity = '1';
            } else {
                backToTopBtn.style.opacity = '0.5';
            }
        });
    }
    
    // Set initial active state based on URL hash
    if (window.location.hash) {
        const activeLink = document.querySelector(`a[href="${window.location.hash}"]`);
        if (activeLink) {
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });
            activeLink.classList.add('active');
        }
    }
});
</script>

<!-- Custom CSS for Enhanced Styling -->
<style>
.polaroid {
    /* Legacy class maintained for existing images */
    max-width: 150px;
    height: auto;
}

#backToTop {
    transition: opacity 0.3s ease;
    opacity: 0.5;
}

/* Mobile responsive improvements */
@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .nav-pills {
        flex-wrap: wrap;
    }
    
    .nav-pills .nav-item {
        margin-bottom: 0.25rem;
    }
    
    .w-20, .w-30, .w-50 {
        width: auto !important;
    }
    
    .img-fluid {
        max-width: 100px;
    }
}

/* Print styles */
@media print {
    .nav-pills, #backToTop, .visually-hidden-focusable {
        display: none !important;
    }
    
    .table {
        font-size: 12px;
    }
    
    h3 {
        page-break-after: avoid;
    }
}
</style>

<!-- footers -->
<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer
?>
