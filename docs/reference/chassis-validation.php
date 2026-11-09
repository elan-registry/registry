<?php

declare(strict_types=1);

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

$pageTitle = 'Lotus Elan Chassis Number Formats';
$pageDescription = 'Reference guide to the chassis numbering formats the Lotus Elan Registry recognizes and validates during car registration.';

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

use ElanRegistry\Documentation\DocumentPortalTemplate;

if (!securePage($php_self)) {
    die();
}

$validationRules = [
    'race_cars' => [
        '1963'        => '26-R-xx format only',
        '1964'        => '26-R-xx or 26-S2-xx formats',
        '1965-1966'   => '26-S2-xx format only',
        'other_years' => '26-R-xx format',
    ],
    'production_cars' => [
        'pre_1970'  => '4 digits numeric only',
        '1970'      => '5 characters (legacy) or 11 characters (YYMMBBXXXXC)',
        '1971'      => '11 characters, YYMMBBXXXXC format',
        '1972_1974' => '9 characters (YYMMXXXXC), or 11 characters (YYMMBBXXXXC) if the car was built in 1970 or 1971',
    ],
    'letter_codes' => [
        'elan'  => 'A, B, C, D, E, F, G, H, J, K (excluding I)',
        'plus2' => 'L, M, N only',
    ],
];

?>

<div class="page-wrapper">
    <div class="container">
        <?= DocumentPortalTemplate::renderBreadcrumb('reference', $us_url_root, 'Chassis Validation', 'fa-barcode') ?>
        <?= DocumentPortalTemplate::renderPortalHeader([
            'title'       => 'Chassis Validation Rules',
            'titleIcon'   => 'fa-barcode',
            'description' => 'Complete guide to Lotus Elan chassis numbering formats and validation requirements',
        ]) ?>

            <!-- Overview Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card registry-card">
                        <div class="card-header card-header-er-primary">
                            <h4 class="mb-0 card-header-er-primary-text">
                                <i class="fas fa-info-circle"></i> Overview
                            </h4>
                        </div>
                        <div class="card-body">
                            <p>
                                The Lotus Elan Registry uses chassis validation to ensure data accuracy
                                and maintain historical authenticity. The validation system recognizes four
                                periods in Lotus chassis numbering:
                            </p>
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="text-center p-3 border rounded bg-light">
                                        <h5 class="text-primary">Pre-1970</h5>
                                        <p class="small mb-0">Simple 4-digit format<br>All models use same system</p>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center p-3 border rounded bg-light">
                                        <h5 class="text-warning">1970 Transition</h5>
                                        <p class="small mb-0">Both legacy and new formats<br>5 or 11 characters</p>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center p-3 border rounded bg-light">
                                        <h5 class="text-success">1971</h5>
                                        <p class="small mb-0">11 characters<br>YYMMBBXXXXC format</p>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="text-center p-3 border rounded bg-light">
                                        <h5 class="text-info">1972-1974</h5>
                                        <p class="small mb-0">9 characters<br>YYMMXXXXC format<br>(11 if built in 1970 or 1971)</p>
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
                        <div class="card-header card-header-er-primary">
                            <h4 class="mb-0 card-header-er-primary-text">
                                <i class="fas fa-car"></i> Production Car Chassis Formats
                            </h4>
                        </div>
                        <div class="card-body">
                            
                            <!-- Pre-1970 -->
                            <div class="mb-4">
                                <h5 class="text-primary">
                                    <i class="fas fa-calendar-alt"></i> Pre-1970 Format (1963-1969)
                                </h5>
                                <div class="alert alert-primary">
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

                            <!-- 1970-1971 -->
                            <div class="mb-4">
                                <h5 class="text-success">
                                    <i class="fas fa-calendar-plus"></i> 11-Character Format (1970-1971)
                                </h5>
                                <div class="alert alert-success">
                                    <strong>Rule:</strong> <?= $validationRules['production_cars']['1971'] ?>.
                                    A 1970 car can also use the 5-character format above.
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
                                                    <td>Year built (last 2 digits)</td>
                                                    <td>71 = 1971</td>
                                                </tr>
                                                <tr>
                                                    <td>3-4</td>
                                                    <td>MM</td>
                                                    <td>Month built</td>
                                                    <td>12 = December</td>
                                                </tr>
                                                <tr>
                                                    <td>5-6</td>
                                                    <td>BB</td>
                                                    <td>Production batch</td>
                                                    <td>29</td>
                                                </tr>
                                                <tr>
                                                    <td>7-10</td>
                                                    <td>XXXX</td>
                                                    <td>Unit number</td>
                                                    <td>0609</td>
                                                </tr>
                                                <tr>
                                                    <td>11</td>
                                                    <td>C</td>
                                                    <td>Model type letter</td>
                                                    <td>G = Elan</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <h6>✅ Valid Examples:</h6>
                                        <ul class="list-unstyled">
                                            <li><code class="text-success">7112290609G</code> - Elan built in December 1971</li>
                                            <li><code class="text-success">7100000468L</code> - +2 built in 1971</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>❌ Invalid Examples:</h6>
                                        <ul class="list-unstyled">
                                            <li><code class="text-danger">711229069G</code> - Too short (10 characters)</li>
                                            <li><code class="text-danger">71122906099G</code> - Too long (12 characters)</li>
                                            <li><code class="text-danger">7112290609I</code> - Invalid letter (I not used)</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- 1972-1974 -->
                            <div class="mb-4">
                                <h5 class="text-info">
                                    <i class="fas fa-calendar-check"></i> 9-Character Format (1972-1974)
                                </h5>
                                <div class="alert alert-info">
                                    <strong>Rule:</strong> <?= $validationRules['production_cars']['1972_1974'] ?>.
                                </div>
                                <p>
                                    From 1 January 1972, Lotus dropped the batch number (BB) from the chassis number.
                                    The format follows the year the car was built (YY), not the model year.
                                    Some cars built in 1970 or 1971 were sold as 1972-1974 models.
                                    These cars keep the 11-character YYMMBBXXXXC format.
                                </p>
                                <ul>
                                    <li>9 characters: positions 1-8 are digits, and YY is 72 or more.</li>
                                    <li>11 characters: YY must be 70 or 71.</li>
                                    <li>The last letter must be valid for the model (see the letter codes below).</li>
                                    <li>Spaces inside the number are not allowed.</li>
                                </ul>

                                <div class="card border-left-primary">
                                    <div class="card-body">
                                        <h6>YYMMXXXXC Format Breakdown:</h6>
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
                                                    <td>Year built (last 2 digits)</td>
                                                    <td>73 = 1973</td>
                                                </tr>
                                                <tr>
                                                    <td>3-4</td>
                                                    <td>MM</td>
                                                    <td>Month built</td>
                                                    <td>08 = August</td>
                                                </tr>
                                                <tr>
                                                    <td>5-8</td>
                                                    <td>XXXX</td>
                                                    <td>Unit number</td>
                                                    <td>1602</td>
                                                </tr>
                                                <tr>
                                                    <td>9</td>
                                                    <td>C</td>
                                                    <td>Model type letter</td>
                                                    <td>L = +2</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="row mt-3">
                                    <div class="col-md-6">
                                        <h6>✅ Valid Examples:</h6>
                                        <ul class="list-unstyled">
                                            <li><code class="text-success">72110581E</code> - Elan built in November 1972</li>
                                            <li><code class="text-success">73081602L</code> - +2 built in August 1973</li>
                                            <li><code class="text-success">7100000468L</code> - 1972 +2 built in 1971</li>
                                            <li><code class="text-success">7112290609G</code> - 1972 Elan built in December 1971</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>❌ Invalid Examples:</h6>
                                        <ul class="list-unstyled">
                                            <li><code class="text-danger">71110581E</code> - 9 characters, but built in 1971</li>
                                            <li><code class="text-danger">7308011602L</code> - 11 characters, but built in 1973</li>
                                            <li><code class="text-danger">72110581I</code> - Invalid letter (I not used)</li>
                                            <li><code class="text-danger">7211 0581E</code> - Contains a space</li>
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
                        <div class="card-header card-header-er-primary">
                            <h4 class="mb-0 card-header-er-primary-text">
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

                            <div class="alert alert-info mb-0">
                                <strong>Sources:</strong> The Elan letters A-K come from the suffix table on the
                                lotuselansprint.com "Number Made" page. Registry cars use E, G, H and K, and the
                                registry accepts A-K except I. The +2 letters L, M and N come from the Robinshaw and Ross book.
                                The lotuselansprint.com pages do not cover the +2.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Race Cars Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card registry-card">
                        <div class="card-header card-header-er-primary">
                            <h4 class="mb-0 card-header-er-primary-text">
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
                                    <?php $yearStr = (string)$year; ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="card border-left-dark">
                                            <div class="card-body">
                                                <h6 class="text-dark">
                                                    <i class="fas fa-calendar"></i> <?= ucfirst(str_replace('_', ' ', $yearStr)) ?>
                                                </h6>
                                                <p class="mb-2"><strong>Format:</strong> <?= $format ?></p>

                                                <?php if($yearStr === '1963'): ?>
                                                    <div class="small">
                                                        <strong>Examples:</strong>
                                                        <ul class="list-unstyled ml-3">
                                                            <li><code class="text-success">26-R-01</code></li>
                                                            <li><code class="text-success">26-R-15</code></li>
                                                        </ul>
                                                    </div>
                                                <?php elseif($yearStr === '1964'): ?>
                                                    <div class="small">
                                                        <strong>Examples:</strong>
                                                        <ul class="list-unstyled ml-3">
                                                            <li><code class="text-success">26-R-01</code> (R series)</li>
                                                            <li><code class="text-success">26-S2-05</code> (S2 series)</li>
                                                        </ul>
                                                    </div>
                                                <?php elseif($yearStr === '1965-1966'): ?>
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

                            <div class="card border-left-primary">
                                <div class="card-body">
                                    <h6 class="text-primary">
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

            <!-- Reference Source Section -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card registry-card">
                        <div class="card-header card-header-er-primary">
                            <h4 class="mb-0 card-header-er-primary-text">
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
                                           target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-info">
                                            <i class="fas fa-external-link-alt"></i> View on Amazon
                                        </a>
                                    </p>
                                </div>
                            </div>

                            <div class="card border-left-primary mt-3">
                                <div class="card-body">
                                    <h6 class="text-primary">
                                        <i class="fas fa-globe"></i> Lotus Elan Sprint website
                                    </h6>
                                    <ul class="mb-2">
                                        <li>
                                            <a href="https://www.lotuselansprint.com/index.asp?pageid=41826"
                                               target="_blank" rel="noopener noreferrer">Unit Numbers</a>
                                            - the 1 January 1972 change from YYMMBBXXXXC to YYMMXXXXC
                                        </li>
                                        <li>
                                            <a href="https://www.lotuselansprint.com/index.asp?pageid=41847"
                                               target="_blank" rel="noopener noreferrer">Number Made</a>
                                            - the Elan suffix letters
                                        </li>
                                    </ul>
                                    <p class="mb-0 small text-muted">
                                        The book does not give the 1972 change. A registry contact told us about it.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
