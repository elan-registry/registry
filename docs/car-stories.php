<?php

/**
 * Car Stories Page
 *
 * Individual car histories, owner stories, and registry articles about specific vehicles.
 * Features stories from the community and historical documentation.
 */
require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

$stories = $us_url_root . 'docs/stories/';

?>
<div class="page-wrapper">
    <div class="container">
        <div class="row">
            <div class="col-sm-12">
                <div class="card registry-card">
                        <div class="card-header">
                            <h2><i class="fas fa-book-open"></i> <strong>Car Stories</strong></h2>
                            <p class="text-muted">Individual car histories, owner stories, and community articles</p>
                        </div>
                        <div class="card-body">
                            
                            <div class="alert alert-info mb-4">
                                <i class="fas fa-car"></i>
                                <strong>Registry Stories:</strong> These are stories of individual cars in our registry, 
                                shared by their owners and the community. Each tells a unique tale of restoration, 
                                discovery, racing history, or personal connection to these remarkable vehicles.
                            </div>

                            <table class="table table-striped table-bordered table-hover table-sm" aria-describedby="Car stories and histories table">
                                <thead class="thead-dark">
                                    <tr>
                                        <th scope="column" style="width: 60%;">
                                            <i class="fas fa-book-open"></i> Story Title
                                        </th>
                                        <th scope="column" style="width: 40%;">
                                            <i class="fas fa-comment"></i> Details & Comments
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>
                                            <a href="<?= $stories ?>SGO_2F/index.php" class="btn btn-outline-primary btn-sm mb-2">
                                                <i class="fas fa-external-link-alt"></i> The Story of SGO 2F
                                            </a>
                                            <br>
                                            <small class="text-muted">Registry ID: 50/0164</small>
                                        </td>
                                        <td>
                                            <em>A detailed history of this remarkable Elan</em>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td>
                                            <a href="<?= $stories ?>brian_walton/index.php" class="btn btn-outline-primary btn-sm mb-2">
                                                <i class="fas fa-external-link-alt"></i> Elan Experimental Rally Car
                                            </a>
                                            <br>
                                            <small class="text-muted">Registry ID: 36/6086</small>
                                        </td>
                                        <td>
                                            <em>The fascinating story of Brian Walton's rally-prepared Elan</em>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td>
                                            <a href='<?= $us_url_root ?>docs/embed.php?doc=Mag%20_issue_50_p12-15_Barry-Shapecraft.pdf' 
                                               target='_blank' class="btn btn-outline-info btn-sm mb-2">
                                                <i class="fas fa-file-pdf"></i> Shapecraft Elan Story
                                            </a>
                                            <br>
                                            <small class="text-muted">Registry ID: 26/4992</small>
                                        </td>
                                        <td>
                                            Featured in <a href="http://www.historiclotusclub.uk/the-magazine/no-50-spring-2022" 
                                                          target="_blank" class="text-decoration-none">
                                                <em>Historic Lotus Racing Magazine</em>, No. 50, Spring 2022
                                            </a>
                                            <br>
                                            <small class="text-muted">Professional magazine article about this unique vehicle</small>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <!-- Historical Archives Section -->
                            <div class="mt-5">
                                <h5 class="mb-3">
                                    <i class="fas fa-archive"></i> Historical Archives
                                </h5>
                                
                                <div class="card">
                                    <div class="card-body">
                                        <h6 class="card-title">
                                            <a href="<?= $stories ?>type26register.php" class="text-decoration-none">
                                                <i class="fas fa-history"></i> Type26Register.com Archive
                                            </a>
                                        </h6>
                                        <p class="card-text">
                                            An incomplete archive of type26register.com retrieved from the 
                                            <a href='https://web.archive.org/web/20230000000000*/type26register.com' 
                                               target="_blank" class="text-decoration-none">Wayback Machine</a>.
                                        </p>
                                        <p class="card-text">
                                            <small class="text-muted">
                                                This represents the site as best as can be recreated from July 2010, 
                                                preserving valuable historical information about Type 26 Elans.
                                            </small>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Call to Action -->
                            <div class="alert alert-success mt-4">
                                <i class="fas fa-pen-alt"></i>
                                <strong>Share Your Story:</strong> 
                                Have a story about your Elan? We'd love to feature it here! 
                                <a href="<?= $us_url_root ?>app/contact/" class="alert-link">Contact us</a> 
                                to share your car's unique history.
                            </div>

                        </div> <!-- card-body -->
                    </div> <!-- card -->
                </div> <!-- col -->
            </div> <!-- row -->
    </div><!-- Container -->
</div><!-- page -->

<?php require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/footer.php'; ?>