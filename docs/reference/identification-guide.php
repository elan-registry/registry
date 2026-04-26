<?php

declare(strict_types=1);

/**
 * Lotus Elan Identification Guide
 *
 * Quick reference for identifying Elan and Plus 2 variants by chassis number,
 * body details, and distinguishing features.
 *
 * @package ElanRegistry
 * @version 2.16.0
 * @author Jim Boone
 */

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($php_self)) {
    die();
}

$imgPath = $us_url_root . 'docs/reference/images/identify/';

?>
<div class="page-wrapper">
    <div class="container">

        <!-- Page Header -->
        <div class="row">
            <div class="col-12">
                <div class="card registry-card">
                    <div class="card-header">
                        <h1 class="mb-0"><i class="fas fa-search"></i> Lotus Elan Identification Guide</h1>
                        <p class="text-muted mb-0">A quick guide to identifying each Elan and Plus 2 variant</p>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle"></i>
                            <strong>Note:</strong> This is not a comprehensive list of differences between the cars — it is a quick identification guide. Lotus made many undocumented running changes throughout production. If in doubt, post a question to the <a href="http://www.lotuselan.net/forums/" target="_blank" rel="noopener">LotusElan.net forum</a> and ask.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Navigation -->
        <div class="row mt-3">
            <div class="col-12">
                <div class="card registry-card">
                    <div class="card-body py-2">
                        <strong>Jump to:</strong>
                        <a href="#roadster" class="btn btn-outline-secondary btn-sm mx-1">Roadster / Drophead</a>
                        <a href="#coupe" class="btn btn-outline-secondary btn-sm mx-1">Coup&eacute;</a>
                        <a href="#racing" class="btn btn-outline-secondary btn-sm mx-1">Racing Version</a>
                        <a href="#plus2" class="btn btn-outline-secondary btn-sm mx-1">Plus 2</a>
                        <a href="<?= htmlspecialchars($us_url_root, ENT_QUOTES, 'UTF-8') ?>docs/pdf-viewer.php?subdir=reference&doc=<?= rawurlencode('2019_Jan_The_Elan_Super_Safety.pdf') ?>"
                           target="_blank" class="btn btn-outline-info btn-sm mx-1">
                            <i class="fas fa-file-pdf"></i> Super Safety Documentation
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Roadster / Drophead -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card registry-card">
                    <div class="card-header">
                        <h2 class="mb-0" id="roadster">Roadster / Drophead</h2>
                    </div>
                    <div class="card-body">

                        <h4>Elan 1500 <small class="text-muted">Type 26 Elan 1500 Roadster</small></h4>
                        <ul>
                            <li>VIN/Chassis number starts with <strong>26/</strong></li>
                            <li>1500 badge on boot</li>
                            <li>All were recalled from the factory and updated to Elan 1600 specification</li>
                            <li>Round tail lights</li>
                            <li>Boot lid does not extend all the way to the trailing edge of the car</li>
                            <li>Lift-up windows, manually operated</li>
                        </ul>

                        <hr>

                        <h4>Elan 1600 <small class="text-muted">Type 26 S1 Roadster</small></h4>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <img src="<?= $imgPath ?>elan1600.jpg" alt="Type 26 Elan 1600 (S1) showing round tail lights and lift-up windows" class="img-fluid identify-photo">
                            </div>
                            <div class="col-md-8">
                                <ul>
                                    <li>VIN/Chassis number starts with <strong>26/</strong></li>
                                    <li>1600 badge on boot</li>
                                    <li>Round tail lights</li>
                                    <li>Boot lid does not extend all the way to the trailing edge of the car</li>
                                    <li>Lift-up windows, manually operated</li>
                                </ul>
                            </div>
                        </div>

                        <hr>

                        <h4>Roadster S2 <small class="text-muted">Type 26 S2 Roadster</small></h4>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <img src="<?= $imgPath ?>s2a.jpg" alt="Type 26 Elan S2 with oval tail lights" class="img-fluid identify-photo mb-2">
                                <img src="<?= $imgPath ?>s2b.jpg" alt="Type 26 Elan S2 alternate view" class="img-fluid identify-photo">
                            </div>
                            <div class="col-md-8">
                                <ul>
                                    <li>VIN/Chassis number starts with <strong>26/</strong></li>
                                    <li>Oval tail lights (early cars had round lights)</li>
                                    <li>Boot lid does not extend all the way to the trailing edge of the car</li>
                                    <li>Lift-up windows, manually operated</li>
                                </ul>
                            </div>
                        </div>

                        <hr>

                        <h4>Drophead S3 <small class="text-muted">Type 45 S3 DHC</small></h4>
                        <ul>
                            <li>VIN/Chassis number starts with <strong>45/</strong></li>
                            <li>Electric roll-up windows</li>
                            <li>Doors with fixed window frames</li>
                            <li>Oval tail lights</li>
                        </ul>

                        <hr>

                        <h4>Drophead S3 S/E <small class="text-muted">Type 45 S3 S/E DHC</small></h4>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <img src="<?= $imgPath ?>s3_se_dhc.jpg" alt="Type 45 S3 S/E DHC with electric roll-up windows" class="img-fluid identify-photo">
                            </div>
                            <div class="col-md-8">
                                <ul>
                                    <li>VIN/Chassis number starts with <strong>45/</strong></li>
                                    <li>Electric roll-up windows</li>
                                    <li>Doors with fixed window frames</li>
                                    <li>Oval tail lights</li>
                                    <li>S/E badged</li>
                                    <li>Stainless steel side trims &amp; wing-mounted flasher repeaters on most cars</li>
                                </ul>
                            </div>
                        </div>

                        <hr>

                        <h4>Drophead S4 <small class="text-muted">Type 45 S4 DHC</small></h4>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <img src="<?= $imgPath ?>s4_dhc.jpg" alt="Type 45 S4 DHC with square tail lights" class="img-fluid identify-photo">
                            </div>
                            <div class="col-md-8">
                                <ul>
                                    <li>VIN/Chassis number starts with <strong>45/</strong>; from 1970 on: <code>70nnnnnnnnL</code>, <code>71nnnnnnnnL</code>, <code>72nnnnnnnnL</code>, or <code>73nnnnnnnnL</code></li>
                                    <li>Electric roll-up windows</li>
                                    <li>Doors with fixed window frames</li>
                                    <li>Square tail lights</li>
                                    <li>Square-profile wheelarches</li>
                                </ul>
                            </div>
                        </div>

                        <hr>

                        <h4>Drophead S4 S/E <small class="text-muted">Type 45 S4 S/E DHC</small></h4>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <img src="<?= $imgPath ?>s4_se_dhc_b.jpg" alt="Type 45 S4 S/E DHC showing stainless steel trim" class="img-fluid identify-photo mb-2">
                                <img src="<?= $imgPath ?>s4_se_dhc_a.jpg" alt="Type 45 S4 S/E DHC alternate view" class="img-fluid identify-photo">
                            </div>
                            <div class="col-md-8">
                                <ul>
                                    <li>VIN/Chassis number starts with <strong>45/</strong>; from 1970 on: <code>70nnnnnnnnL</code>, <code>71nnnnnnnnL</code>, <code>72nnnnnnnnL</code>, or <code>73nnnnnnnnL</code></li>
                                    <li>Electric roll-up windows</li>
                                    <li>Doors with fixed window frames</li>
                                    <li>Square tail lights</li>
                                    <li>Square-profile wheelarches</li>
                                    <li>S/E badged</li>
                                    <li>Stainless steel side trims &amp; wing-mounted flasher repeaters on most cars</li>
                                </ul>
                            </div>
                        </div>

                        <hr>

                        <h4>Drophead Sprint <small class="text-muted">Type 45 Sprint DHC</small></h4>
                        <div class="row">
                            <div class="col-md-4">
                                <img src="<?= $imgPath ?>sprint_dhc.jpg" alt="Type 45 Sprint DHC with unique two-tone paint" class="img-fluid identify-photo">
                            </div>
                            <div class="col-md-8">
                                <ul>
                                    <li>VIN/Chassis number from 1971 on: <code>71nnnnnnnnL</code>, <code>72nnnnnnnnL</code>, or <code>73nnnnnnnnL</code></li>
                                    <li>Electric roll-up windows</li>
                                    <li>Doors with fixed window frames</li>
                                    <li>Square tail lights</li>
                                    <li>Badged as Sprint</li>
                                    <li>Unique two-tone paint (could be deleted as option)</li>
                                    <li>Square-profile wheelarches</li>
                                    <li><a href="http://www.lotuselansprint.com" target="_blank" rel="noopener">Complete Sprint Details</a></li>
                                </ul>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- Coupe -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card registry-card">
                    <div class="card-header">
                        <h2 class="mb-0" id="coupe">Coup&eacute;</h2>
                    </div>
                    <div class="card-body">

                        <h4>Coup&eacute; S3 Pre-Airflow <small class="text-muted">Type 36 FHC Pre-Airflow</small></h4>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <img src="<?= $imgPath ?>s3_fhc_pre.jpg" alt="Type 36 FHC pre-airflow showing no extractor grill" class="img-fluid identify-photo">
                            </div>
                            <div class="col-md-8">
                                <ul>
                                    <li>VIN/Chassis number starts with <strong>36/</strong></li>
                                    <li>Electric roll-up windows</li>
                                    <li>Doors with fixed window frames</li>
                                    <li>Oval tail lights</li>
                                    <li>No extractor grill on the B pillar</li>
                                </ul>
                            </div>
                        </div>

                        <hr>

                        <h4>Coup&eacute; S3 Airflow <small class="text-muted">Type 36 S3 FHC</small></h4>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <img src="<?= $imgPath ?>s3_fhc_air.jpg" alt="Type 36 FHC with extractor grill on rear quarter panel" class="img-fluid identify-photo">
                            </div>
                            <div class="col-md-8">
                                <ul>
                                    <li>VIN/Chassis number starts with <strong>36/</strong></li>
                                    <li>Electric roll-up windows</li>
                                    <li>Doors with fixed window frames</li>
                                    <li>Oval tail lights</li>
                                    <li>Extractor grill on the rear quarter panel</li>
                                </ul>
                            </div>
                        </div>

                        <hr>

                        <h4>Coup&eacute; S3 S/E Airflow <small class="text-muted">Type 36 S3 S/E FHC</small></h4>
                        <ul>
                            <li>VIN/Chassis number starts with <strong>36/</strong></li>
                            <li>Electric roll-up windows</li>
                            <li>Doors with fixed window frames</li>
                            <li>Oval tail lights</li>
                            <li>Extractor grill on the rear quarter panel</li>
                            <li>S/E badged</li>
                            <li>Stainless steel side trims &amp; wing-mounted flasher repeaters on most cars</li>
                        </ul>

                        <hr>

                        <h4>Coup&eacute; S4 <small class="text-muted">Type 36 S4 FHC</small></h4>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <img src="<?= $imgPath ?>s4_fhc.jpg" alt="Type 36 S4 FHC with square tail lights" class="img-fluid identify-photo">
                            </div>
                            <div class="col-md-8">
                                <ul>
                                    <li>VIN/Chassis number starts with <strong>36/</strong>; from 1970 on: <code>70nnnnnnnnL</code>, <code>71nnnnnnnnL</code>, <code>72nnnnnnnnL</code>, or <code>73nnnnnnnnL</code></li>
                                    <li>Electric roll-up windows</li>
                                    <li>Doors with fixed window frames</li>
                                    <li>Square tail lights</li>
                                    <li>Square-profile wheelarches</li>
                                </ul>
                            </div>
                        </div>

                        <hr>

                        <h4>Coup&eacute; S4 S/E <small class="text-muted">Type 36 S4 S/E FHC</small></h4>
                        <ul>
                            <li>VIN/Chassis number starts with <strong>36/</strong>; from 1970 on: <code>70nnnnnnnnL</code>, <code>71nnnnnnnnL</code>, <code>72nnnnnnnnL</code>, or <code>73nnnnnnnnL</code></li>
                            <li>Electric roll-up windows</li>
                            <li>Doors with fixed window frames</li>
                            <li>Square tail lights</li>
                            <li>Square-profile wheelarches</li>
                            <li>S/E badged</li>
                            <li>Stainless steel side trims &amp; wing-mounted flasher repeaters on most cars</li>
                        </ul>

                        <hr>

                        <h4>Coup&eacute; Sprint <small class="text-muted">Type 36 Sprint FHC</small></h4>
                        <div class="row">
                            <div class="col-md-4">
                                <img src="<?= $imgPath ?>sprint_fhc.jpg" alt="Type 36 Sprint FHC with unique two-tone paint" class="img-fluid identify-photo">
                            </div>
                            <div class="col-md-8">
                                <ul>
                                    <li>VIN/Chassis number from 1971 on: <code>71nnnnnnnnL</code>, <code>72nnnnnnnnL</code>, or <code>73nnnnnnnnL</code></li>
                                    <li>Electric roll-up windows</li>
                                    <li>Doors with fixed window frames</li>
                                    <li>Square tail lights</li>
                                    <li>Badged as Sprint</li>
                                    <li>Unique two-tone paint (could be deleted as option)</li>
                                    <li>Square-profile wheelarches</li>
                                    <li><a href="http://www.lotuselansprint.com" target="_blank" rel="noopener">Complete Sprint Details</a></li>
                                </ul>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- Racing Version -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card registry-card">
                    <div class="card-header">
                        <h2 class="mb-0" id="racing">Racing Version</h2>
                    </div>
                    <div class="card-body">

                        <h4>26R <small class="text-muted">Type 26 26R Race</small></h4>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <img src="<?= $imgPath ?>26r.jpg" alt="Type 26R race car with fixed headlights" class="img-fluid identify-photo">
                            </div>
                            <div class="col-md-8">
                                <ul>
                                    <li>VIN/Chassis number starts with <strong>26-</strong></li>
                                    <li>Fixed headlights (but not all)</li>
                                    <li>Magnesium Lotus-designed peg drive wheels</li>
                                </ul>
                            </div>
                        </div>

                        <hr>

                        <h4>26R S2 <small class="text-muted">Type 26 26R Race S2</small></h4>
                        <div class="row">
                            <div class="col-md-4">
                                <img src="<?= $imgPath ?>26r.jpg" alt="Type 26R S2 race car with fixed headlights" class="img-fluid identify-photo">
                            </div>
                            <div class="col-md-8">
                                <ul>
                                    <li>VIN/Chassis number starts with <strong>26-S2</strong></li>
                                    <li>Fixed headlights (but not all)</li>
                                    <li>Magnesium Lotus-designed peg drive wheels</li>
                                    <li>Lightweight body</li>
                                    <li>Flared fenders</li>
                                </ul>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- Plus 2 -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card registry-card">
                    <div class="card-header">
                        <h2 class="mb-0" id="plus2">Plus 2</h2>
                    </div>
                    <div class="card-body">

                        <h4>Plus 2 <small class="text-muted">Type 50 +2</small></h4>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <img src="<?= $imgPath ?>plus2int.jpg" alt="Type 50 Plus 2 dashboard showing 2 large and 4 small gauges" class="img-fluid identify-photo">
                            </div>
                            <div class="col-md-8">
                                <ul>
                                    <li>VIN/Chassis number starts with <strong>50/0001</strong></li>
                                    <li>2 large gauges &mdash; Speedometer, Tachometer</li>
                                    <li>4 small gauges &mdash; Water Temp, Oil Pressure, Ammeter, Fuel</li>
                                </ul>
                            </div>
                        </div>

                        <hr>

                        <h4>Plus 2 Federal <small class="text-muted">Type 50 +2 Federal</small></h4>
                        <ul>
                            <li>VIN/Chassis number starts with <strong>50/0857</strong> (US); <strong>50/0929</strong> (all markets)</li>
                            <li>2 large gauges &mdash; Speedometer, Tachometer</li>
                            <li>4 small gauges &mdash; Water Temp, Oil Pressure, Ammeter, Fuel</li>
                            <li>Remote boot release, flush interior door handles, modified exhaust</li>
                        </ul>

                        <hr>

                        <h4>Plus 2S <small class="text-muted">Type 50 +2 2S</small></h4>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <img src="<?= $imgPath ?>plus2sa.jpg" alt="Type 50 Plus 2S exterior view" class="img-fluid identify-photo mb-2">
                                <img src="<?= $imgPath ?>plus2sb.jpg" alt="Type 50 Plus 2S alternate view" class="img-fluid identify-photo">
                            </div>
                            <div class="col-md-8">
                                <ul>
                                    <li>VIN/Chassis number starts with <strong>50/1593</strong></li>
                                    <li>2 large gauges &mdash; Speedometer, Tachometer</li>
                                    <li>6 small gauges &mdash; Oil, Water Temp, Battery Condition, Temp, Fuel, Clock</li>
                                    <li>4 warning lights in centre of dash (Hazard, Parking Brake, Brake Fail, Rear Screen)</li>
                                    <li>New luxury interior including revised seats and centre console</li>
                                    <li>Fog/driving lamps below the bumper</li>
                                </ul>
                            </div>
                        </div>

                        <hr>

                        <h4>Plus 2S Federal <small class="text-muted">Type 50 +2 2S Federal</small></h4>
                        <ul>
                            <li>VIN/Chassis number starts with <strong>50/2447</strong></li>
                            <li>2 large gauges &mdash; Speedometer, Tachometer</li>
                            <li>6 small gauges &mdash; Oil, Water Temp, Battery Condition, Temp, Fuel, Clock</li>
                            <li>4 warning lights in centre of dash (Hazard, Parking Brake, Brake Fail, Rear Screen)</li>
                            <li>Luxury interior including revised seats and centre console</li>
                            <li>Fog/driving lamps below the bumper</li>
                        </ul>

                        <hr>

                        <h4>Plus 2S 130 <small class="text-muted">Type 50 +2 130</small></h4>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <img src="<?= $imgPath ?>plus2s130a.jpg" alt="Type 50 Plus 2S 130 exterior front" class="img-fluid identify-photo mb-2">
                                <img src="<?= $imgPath ?>plus2s130b.jpg" alt="Type 50 Plus 2S 130 exterior rear" class="img-fluid identify-photo mb-2">
                                <img src="<?= $imgPath ?>plus2s130int.jpg" alt="Type 50 Plus 2S 130 dashboard showing 6 small gauges and 3 warning lights" class="img-fluid identify-photo mb-2">
                                <img src="<?= $imgPath ?>plus2s130d.jpg" alt="Type 50 Plus 2S 130 detail view" class="img-fluid identify-photo">
                            </div>
                            <div class="col-md-6">
                                <ul>
                                    <li>VIN/Chassis number starts with <strong>71.01&hellip;0001</strong></li>
                                    <li>2 large gauges &mdash; Speedometer, Tachometer</li>
                                    <li>6 small gauges &mdash; Oil, Water Temp, Battery Condition, Temp, Fuel, Clock</li>
                                    <li>3 warning lights in centre of dash (Hazard, Park/Brake Fail, Rear Screen)</li>
                                    <li>Luxury interior including revised seats and centre console</li>
                                    <li>Big Valve engine</li>
                                    <li>Fog/driving lamps below the bumper</li>
                                </ul>
                            </div>
                        </div>

                        <hr>

                        <h4>Plus 2S 130/5 <small class="text-muted">Type 50 +2 130/5</small></h4>
                        <ul>
                            <li>VIN/Chassis number starts with <strong>72.10&hellip;</strong></li>
                            <li>Same as Plus 2S 130 with 5-speed gearbox</li>
                        </ul>

                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.container -->
</div><!-- .page-wrapper -->

<style>
    .identify-photo {
        border: 1px solid #dee2e6;
        border-radius: 4px;
        max-width: 100%;
    }
</style>

<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php';
?>
