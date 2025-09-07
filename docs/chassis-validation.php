<?php

/**
 * chassis-validation.php
 * Documentation page for Lotus Elan chassis validation rules
 *
 * Provides comprehensive documentation of all chassis numbering formats
 * supported by the registry validation system.
 *
 * @author Elan Registry Team
 * @copyright 2025
 */

require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';
require_once '../usersc/classes/ChassisValidator.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Get validation rules from the centralized validator
$validationRules = ChassisValidator::getValidationRules();

?>

<div class="page-wrapper">
    <div class="container-fluid">
        <div class="page-container">
            
            <!-- Page Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="text-center">
                        <h1 class="h3 mb-2 text-gray-800">
                            <i class="fas fa-barcode text-primary"></i> Chassis Validation Rules
                        </h1>
                        <p class="text-muted mb-0">
                            Complete guide to Lotus Elan chassis numbering formats and validation requirements
                        </p>
                    </div>
                </div>
            </div>

            <!-- Overview Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card registry-card">
                        <div class="card-header bg-primary text-white">
                            <h4 class="mb-0">
                                <i class="fas fa-info-circle"></i> Overview
                            </h4>
                        </div>
                        <div class="card-body">
                            <p>
                                The Lotus Elan Registry uses chassis validation to ensure data accuracy
                                and maintain historical authenticity. The validation system recognizes three distinct
                                periods in Lotus chassis numbering evolution:
                            </p>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="text-center p-3 border rounded bg-light">
                                        <h5 class="text-primary">Pre-1970</h5>
                                        <p class="small mb-0">Simple 4-digit format<br>All models use same system</p>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-center p-3 border rounded bg-light">
                                        <h5 class="text-warning">1970 Transition</h5>
                                        <p class="small mb-0">Both legacy and new formats<br>5 or 11 character options</p>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-center p-3 border rounded bg-light">
                                        <h5 class="text-success">Post-1970</h5>
                                        <p class="small mb-0">Standardized 11-character<br>YYMMBBXXXXC format</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Production Cars Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card registry-card">
                        <div class="card-header bg-success text-white">
                            <h4 class="mb-0">
                                <i class="fas fa-car"></i> Production Car Chassis Formats
                            </h4>
                        </div>
                        <div class="card-body">
                            
                            <!-- Pre-1970 -->
                            <div class="mb-4">
                                <h5 class="text-primary">
                                    <i class="fas fa-calendar-alt"></i> Pre-1970 Format (1963-1969)
                                </h5>
                                <div class="alert alert-info">
                                    <strong>Rule:</strong> <?= $validationRules['production_cars']['pre_1970'] ?>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>✅ Valid Examples:</h6>
                                        <ul class="list-unstyled">
                                            <li><code class="text-success">1234</code> - Standard 4-digit format</li>
                                            <li><code class="text-success">0001</code> - Early production number</li>
                                            <li><code class="text-success">6490</code> - Later production number</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>❌ Invalid Examples:</h6>
                                        <ul class="list-unstyled">
                                            <li><code class="text-danger">123</code> - Too short</li>
                                            <li><code class="text-danger">12345</code> - Too long</li>
                                            <li><code class="text-danger">123A</code> - Contains letter</li>
                                            <li><code class="text-danger">36/1234</code> - Includes type prefix</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- 1970 Transition -->
                            <div class="mb-4">
                                <h5 class="text-warning">
                                    <i class="fas fa-exchange-alt"></i> 1970 Transition Year
                                </h5>
                                <div class="alert alert-warning">
                                    <strong>Rule:</strong> <?= $validationRules['production_cars']['1970'] ?>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Legacy 5-Character Format:</h6>
                                        <ul class="list-unstyled">
                                            <li><code class="text-success">1234A</code> - Elan model (A-K)</li>
                                            <li><code class="text-success">5678L</code> - +2 model (L, M, N)</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>New 11-Character Format:</h6>
                                        <ul class="list-unstyled">
                                            <li><code class="text-success">7001019999B</code> - Full YYMMBBXXXXC</li>
                                            <li><code class="text-success">7012345678M</code> - +2 with M suffix</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- Post-1970 -->
                            <div class="mb-4">
                                <h5 class="text-success">
                                    <i class="fas fa-calendar-plus"></i> Post-1970 Format (1971-1974)
                                </h5>
                                <div class="alert alert-success">
                                    <strong>Rule:</strong> <?= $validationRules['production_cars']['post_1970'] ?>
                                </div>
                                
                                <div class="card border-left-primary">
                                    <div class="card-body">
                                        <h6>YYMMBBXXXXC Format Breakdown:</h6>
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Position</th>
                                                    <th>Code</th>
                                                    <th>Meaning</th>
                                                    <th>Example</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td>1-2</td>
                                                    <td>YY</td>
                                                    <td>Year (last 2 digits)</td>
                                                    <td>73 = 1973</td>
                                                </tr>
                                                <tr>
                                                    <td>3-4</td>
                                                    <td>MM</td>
                                                    <td>Month of production</td>
                                                    <td>01 = January</td>
                                                </tr>
                                                <tr>
                                                    <td>5-6</td>
                                                    <td>BB</td>
                                                    <td>Production batch</td>
                                                    <td>01 = First batch</td>
                                                </tr>
                                                <tr>
                                                    <td>7-10</td>
                                                    <td>XXXX</td>
                                                    <td>Sequential number</td>
                                                    <td>9999 = Unit number</td>
                                                </tr>
                                                <tr>
                                                    <td>11</td>
                                                    <td>C</td>
                                                    <td>Model type letter</td>
                                                    <td>B = Elan, M = +2</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <h6>✅ Valid Examples:</h6>
                                        <ul class="list-unstyled">
                                            <li><code class="text-success">7301019999B</code> - 1973 Elan</li>
                                            <li><code class="text-success">7412345678M</code> - 1974 +2 model</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>❌ Invalid Examples:</h6>
                                        <ul class="list-unstyled">
                                            <li><code class="text-danger">73010199B</code> - Too short (10 chars)</li>
                                            <li><code class="text-danger">730101999AB</code> - Too long (12 chars)</li>
                                            <li><code class="text-danger">7301019999I</code> - Invalid letter (I not used)</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Letter Codes Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card registry-card">
                        <div class="card-header bg-info text-white">
                            <h4 class="mb-0">
                                <i class="fas fa-font"></i> Model-Specific Letter Codes
                            </h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card border-left-primary">
                                        <div class="card-body">
                                            <h5 class="text-primary">
                                                <i class="fas fa-car-side"></i> Elan Models
                                            </h5>
                                            <p><strong>Allowed Codes:</strong> <?= $validationRules['letter_codes']['elan'] ?></p>
                                            <div class="row">
                                                <div class="col-6">
                                                    <strong>Valid:</strong>
                                                    <div class="d-flex flex-wrap">
                                                        <?php
                                                        $elanCodes = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'K'];
                                                        foreach($elanCodes as $code):
                                                        ?>
                                                            <span class="badge badge-success mr-1 mb-1"><?= $code ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <strong>Invalid:</strong>
                                                    <div class="d-flex flex-wrap">
                                                        <span class="badge badge-danger mr-1 mb-1">I</span>
                                                        <span class="badge badge-danger mr-1 mb-1">L</span>
                                                        <span class="badge badge-danger mr-1 mb-1">M</span>
                                                        <span class="badge badge-danger mr-1 mb-1">N</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card border-left-success">
                                        <div class="card-body">
                                            <h5 class="text-success">
                                                <i class="fas fa-plus"></i> +2 Models
                                            </h5>
                                            <p><strong>Allowed Codes:</strong> <?= $validationRules['letter_codes']['plus2'] ?></p>
                                            <div class="row">
                                                <div class="col-6">
                                                    <strong>Valid:</strong>
                                                    <div class="d-flex flex-wrap">
                                                        <span class="badge badge-success mr-1 mb-1">L</span>
                                                        <span class="badge badge-success mr-1 mb-1">M</span>
                                                        <span class="badge badge-success mr-1 mb-1">N</span>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <strong>Invalid:</strong>
                                                    <div class="d-flex flex-wrap">
                                                        <?php
                                                        $invalidCodes = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'];
                                                        foreach(array_slice($invalidCodes, 0, 4) as $code):
                                                        ?>
                                                            <span class="badge badge-danger mr-1 mb-1"><?= $code ?></span>
                                                        <?php endforeach; ?>
                                                        <span class="text-muted">...</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="alert alert-warning mt-3">
                                <strong>Important:</strong> The letter codes are mutually exclusive. Elan models cannot use +2 codes (L, M, N)
                                and +2 models cannot use Elan codes (A-K). The letter "I" is never used to avoid confusion with the number "1".
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Race Cars Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card registry-card">
                        <div class="card-header bg-danger text-white">
                            <h4 class="mb-0">
                                <i class="fas fa-flag-checkered"></i> Race Car Chassis Formats
                            </h4>
                        </div>
                        <div class="card-body">
                            <p>
                                Race cars use special chassis numbering that differs from production models.
                                The format varies by year and racing series designation.
                            </p>
                            
                            <div class="row">
                                <?php foreach($validationRules['race_cars'] as $year => $format): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="card border-left-danger">
                                            <div class="card-body">
                                                <h6 class="text-danger">
                                                    <i class="fas fa-calendar"></i> <?= ucfirst(str_replace('_', ' ', $year)) ?>
                                                </h6>
                                                <p class="mb-2"><strong>Format:</strong> <?= $format ?></p>
                                                
                                                <?php if($year === '1963'): ?>
                                                    <div class="small">
                                                        <strong>Examples:</strong>
                                                        <ul class="list-unstyled ml-3">
                                                            <li><code class="text-success">26-R-01</code></li>
                                                            <li><code class="text-success">26-R-15</code></li>
                                                        </ul>
                                                    </div>
                                                <?php elseif($year === '1964'): ?>
                                                    <div class="small">
                                                        <strong>Examples:</strong>
                                                        <ul class="list-unstyled ml-3">
                                                            <li><code class="text-success">26-R-01</code> (R series)</li>
                                                            <li><code class="text-success">26-S2-05</code> (S2 series)</li>
                                                        </ul>
                                                    </div>
                                                <?php elseif($year === '1965-1966'): ?>
                                                    <div class="small">
                                                        <strong>Examples:</strong>
                                                        <ul class="list-unstyled ml-3">
                                                            <li><code class="text-success">26-S2-01</code></li>
                                                            <li><code class="text-success">26-S2-12</code></li>
                                                        </ul>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="small">
                                                        <strong>Examples:</strong>
                                                        <ul class="list-unstyled ml-3">
                                                            <li><code class="text-success">26-R-01</code></li>
                                                            <li><code class="text-success">26-R-08</code></li>
                                                        </ul>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Validation Override Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card registry-card border-warning">
                        <div class="card-header bg-warning text-dark">
                            <h4 class="mb-0">
                                <i class="fas fa-exclamation-triangle"></i> Validation Override
                            </h4>
                        </div>
                        <div class="card-body">
                            <p>
                                In rare cases where historical records don't conform to standard numbering schemes,
                                the registry provides a validation override option:
                            </p>
                            
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-shield-alt"></i> When to Use Override:</h6>
                                <ul>
                                    <li>Historical documentation shows non-standard chassis numbers</li>
                                    <li>Factory records indicate special numbering for specific cars</li>
                                    <li>Competition modifications resulted in altered chassis plates</li>
                                    <li>Restoration discovered original numbering that doesn't match standards</li>
                                </ul>
                            </div>

                            <div class="alert alert-danger">
                                <h6><i class="fas fa-exclamation-circle"></i> Warning:</h6>
                                <p class="mb-0">
                                    Use the override function with caution. Incorrect chassis numbers can affect:
                                </p>
                                <ul class="mb-0">
                                    <li>Registry data integrity and historical accuracy</li>
                                    <li>Insurance and valuation documentation</li>
                                    <li>Parts identification and authenticity verification</li>
                                    <li>Future ownership transfers and registry searches</li>
                                </ul>
                            </div>

                            <div class="card border-left-info">
                                <div class="card-body">
                                    <h6 class="text-info">
                                        <i class="fas fa-info-circle"></i> Best Practice
                                    </h6>
                                    <p class="mb-0">
                                        When using the validation override, always include detailed comments explaining
                                        the reason for the non-standard chassis number and any supporting documentation
                                        or historical context.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Technical Information Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card registry-card">
                        <div class="card-header bg-secondary text-white">
                            <h4 class="mb-0">
                                <i class="fas fa-cog"></i> Technical Implementation
                            </h4>
                        </div>
                        <div class="card-body">
                            <p>
                                The chassis validation system uses centralized logic to ensure consistency
                                between real-time frontend validation and backend form processing.
                            </p>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="text-center p-3 border rounded">
                                        <i class="fas fa-code fa-2x text-primary mb-2"></i>
                                        <h6>Centralized Validation</h6>
                                        <small class="text-muted">Single ChassisValidator class handles all validation logic</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-center p-3 border rounded">
                                        <i class="fas fa-bolt fa-2x text-warning mb-2"></i>
                                        <h6>Real-time Feedback</h6>
                                        <small class="text-muted">AJAX validation provides immediate user feedback</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-center p-3 border rounded">
                                        <i class="fas fa-shield-alt fa-2x text-success mb-2"></i>
                                        <h6>Data Integrity</h6>
                                        <small class="text-muted">Backend validation ensures data quality and security</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reference Source Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card registry-card border-info">
                        <div class="card-header bg-info text-white">
                            <h4 class="mb-0">
                                <i class="fas fa-book"></i> Reference Source
                            </h4>
                        </div>
                        <div class="card-body">
                            <p>
                                The chassis validation rules implemented in this registry are based on the 
                                documentation found in:
                            </p>
                            
                            <div class="card border-left-primary">
                                <div class="card-body">
                                    <h6 class="text-primary">
                                        <i class="fas fa-book-open"></i> Source Reference
                                    </h6>
                                    <p class="mb-2">
                                        <strong>"Authentic Lotus Elan &amp; Plus 2 1962 - 1974"</strong><br>
                                        <em>by Robinshaw and Ross</em>
                                    </p>
                                    <p class="mb-2">
                                        <strong>Relevant Pages:</strong> Page 13 and Page 135
                                    </p>
                                    <p class="mb-0">
                                        <a href="https://www.amazon.com/Authentic-Lotus-1962-1974-Marques-Models/dp/0947981950"
                                           target="_blank" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-external-link-alt"></i> View on Amazon
                                        </a>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
