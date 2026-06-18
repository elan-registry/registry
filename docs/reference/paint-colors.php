<?php

declare(strict_types=1);

/**
 * Lotus Elan & Plus 2 Paint Colors Guide
 *
 * Comprehensive reference to factory paint colors used on the Lotus Elan
 * and Plus 2. Data is currently static but structured to support future
 * database-driven content.
 *
 * @package ElanRegistry
 * @version 2.14.0
 * @author Jim Boone
 */

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

// Security check - ensure page access is authorized
if (!securePage($php_self)) {
    die();
}

// Paint chip image base path (relative to web root)
$chipPath = $us_url_root . 'docs/reference/images/paint/';

/**
 * Early colors (no Lotus code) - borrowed from other manufacturers
 * TODO: Move to database table
 */
$earlyColors = [
    ['chip' => 'medici-blue.png',       'color' => 'Medici Blue',      'dates' => 'Oct 1962 onwards',  'origin' => 'Standard Triumph (ref 378TR)', 'nexa' => '3192'],
    ['chip' => 'carmen-red.jpeg',        'color' => 'Carmen Red',       'dates' => 'Oct 1962 onwards',  'origin' => 'Jaguar',                       'nexa' => '3097'],
    ['chip' => 'fiesta-yellow.jpeg',     'color' => 'Fiesta Yellow',    'dates' => 'Oct 1962 onwards',  'origin' => 'Austin',                       'nexa' => 'YL11'],
    ['chip' => 'sunburst-yellow.jpeg',   'color' => 'Sunburst Yellow',  'dates' => 'Oct 1962 onwards',  'origin' => 'Ford; also used on the Elite', 'nexa' => ''],
    ['chip' => '',                       'color' => 'Monaco Red',       'dates' => '1965&ndash;1966',   'origin' => 'Ford; used on late S2/early S3 Elans and the Alan Mann racers', 'nexa' => ''],
];

/**
 * Official Lotus coded colors (L01-L26)
 * TODO: Move to database table
 */
$officialColors = [
    ['chip' => 'british-racing-green.jpeg', 'code' => 'L01', 'color' => 'British Racing Green',  'dates' => 'Oct 1962 &ndash; Oct 1970',  'models' => 'Elan S1&ndash;S4',            'notes' => 'Originally a Jaguar color; Jaguar used three BRG variants in the 1960s'],
    ['chip' => 'french-blue.jpeg',          'code' => 'L02', 'color' => 'French Blue',            'dates' => 'Mid 1967 &ndash; Oct 1970',  'models' => 'Elan S3&ndash;S4',            'notes' => ''],
    ['chip' => 'wedgewood-blue.jpeg',       'code' => 'L03', 'color' => 'Wedgewood Blue',         'dates' => 'Mid 1967 &ndash; Oct 1968',  'models' => 'Elan S3',                     'notes' => ''],
    ['chip' => 'cirrus-white.jpeg',         'code' => 'L04', 'color' => 'Cirrus White',           'dates' => 'Oct 1962 &ndash; Oct 1973',  'models' => 'Elan S1&ndash;Sprint, Plus 2','notes' => 'Unique to Lotus; also an Elite color. Used in Gold Leaf scheme'],
    ['chip' => 'carnival-red.jpeg',         'code' => 'L05', 'color' => 'Carnival Red',           'dates' => 'Mid 1967 &ndash; Jul 1973',  'models' => 'Elan S3&ndash;Sprint, Plus 2','notes' => 'Gold Leaf scheme color. Most popular Sprint color (23%)'],
    ['chip' => 'burnt-sand.jpeg',           'code' => 'L06', 'color' => 'Burnt Sand',             'dates' => 'Mid 1967 &ndash; Oct 1970',  'models' => 'Elan S3&ndash;S4',            'notes' => ''],
    ['chip' => 'lotus-yellow.jpeg',         'code' => 'L07', 'color' => 'Lotus Yellow',           'dates' => 'Mid 1967 &ndash; 1979',      'models' => 'Elan S3&ndash;Sprint, Plus 2','notes' => 'One of the longest-lived colors. Second most popular Sprint (18%)'],
    ['chip' => '',                          'code' => 'L08', 'color' => 'Matt Black',             'dates' => 'Jan 1967 onwards',            'models' => 'All (non-body)',              'notes' => 'Engine bay, boot, sill, air boxes. Bourne-bodied S1s were grey'],
    ['chip' => 'royal-blue.jpeg',           'code' => 'L09', 'color' => 'Royal Blue',             'dates' => 'Oct 1968 &ndash; Oct 1970',  'models' => 'Elan S4',                     'notes' => ''],
    ['chip' => 'bahama-yellow.jpeg',        'code' => 'L10', 'color' => 'Bahama Yellow',          'dates' => 'Oct 1968 &ndash; Oct 1970',  'models' => 'Elan S4',                     'notes' => ''],
    ['chip' => 'regency-red.jpeg',          'code' => 'L11', 'color' => 'Regency Red',            'dates' => 'Oct 1970 onwards',            'models' => 'Elan Sprint, Plus 2',         'notes' => 'Listed as &ldquo;Maroon&rdquo; in factory brochure'],
    ['chip' => 'lagoon-blue.jpeg',          'code' => 'L12', 'color' => 'Lagoon Blue (Metallic)', 'dates' => 'Oct 1970 &ndash; 1974',      'models' => 'Elan Sprint, Plus 2',         'notes' => 'Listed as &ldquo;Metallic Blue&rdquo; in factory brochure'],
    ['chip' => 'pistachio-lime-green.jpeg', 'code' => 'L13', 'color' => 'Pistachio Lime Green',   'dates' => 'Oct 1970 &ndash; Jul 1973',  'models' => 'Elan Sprint, Plus 2',         'notes' => 'Listed as &ldquo;Lime Green&rdquo; in factory brochure'],
    ['chip' => 'colorado-orange.jpeg',      'code' => 'L14', 'color' => 'Colorado Orange',        'dates' => 'Oct 1970 &ndash; Oct 1972',  'models' => 'Elan Sprint',                 'notes' => 'Listed as &ldquo;Light Orange&rdquo; in factory brochure'],
    ['chip' => '',                          'code' => 'L15', 'color' => 'Black Gloss',            'dates' => 'Sep 1972 onwards',            'models' => 'Elan (special order), Plus 2 JPS', 'notes' => 'Not an official Sprint color; used for wheels'],
    ['chip' => 'tawny-brown.jpeg',          'code' => 'L16', 'color' => 'Tawny Brown (Metallic)', 'dates' => 'Oct 1972 &ndash; Jan 1974',  'models' => 'Elan Sprint, Plus 2',         'notes' => 'Originally a Ford color'],
    ['chip' => '',                          'code' => 'L17', 'color' => 'Mid Green (Metallic)',    'dates' => 'Jul 1973 &ndash; Oct 1973',  'models' => 'Plus 2',                      'notes' => 'Originally a Renault color'],
    ['chip' => 'glacier-blue.jpeg',         'code' => 'L18', 'color' => 'Glacier Blue (Metallic)', 'dates' => 'Jul 1973 &ndash; Oct 1973', 'models' => 'Plus 2',                      'notes' => ''],
    ['chip' => 'sable.jpeg',               'code' => 'L19', 'color' => 'Sable',                   'dates' => 'Oct 1972 &ndash; 1976',      'models' => 'Plus 2',                      'notes' => 'Sometimes called &ldquo;Siberian&rdquo;'],
    ['chip' => '',                          'code' => 'L20', 'color' => 'Indigo Blue',             'dates' => 'Oct 1972 &ndash; Jul 1973',  'models' => 'Plus 2',                      'notes' => ''],
    ['chip' => 'purple.jpeg',              'code' => 'L21', 'color' => 'Purple (Metallic)',        'dates' => 'Oct 1973 &ndash; 1976',      'models' => 'Plus 2',                      'notes' => 'Sometimes called &ldquo;Roman Purple&rdquo;'],
    ['chip' => 'bitter-green.jpeg',        'code' => 'L22', 'color' => 'Bitter Green',             'dates' => 'Oct 1973 onwards',           'models' => 'Plus 2',                      'notes' => ''],
    ['chip' => '',                          'code' => 'L23', 'color' => 'Sepia Brown',             'dates' => 'Oct 1973 &ndash; Mar 1974',  'models' => 'Plus 2',                      'notes' => ''],
    ['chip' => 'firecracker.jpeg',         'code' => 'L24', 'color' => 'Firecracker (Red)',        'dates' => 'Oct 1973 &ndash; Jan 1974',  'models' => 'Plus 2',                      'notes' => ''],
    ['chip' => '',                          'code' => 'L25', 'color' => 'Monaco White',            'dates' => 'Oct 1973 &ndash; 1982',      'models' => 'Plus 2',                      'notes' => 'One of the longest-lived colors'],
    ['chip' => 'olympic-blue.jpeg',        'code' => 'L26', 'color' => 'Olympic Blue',             'dates' => 'Oct 1973 onwards',           'models' => 'Plus 2',                      'notes' => ''],
    ['chip' => 'laurel-green.jpeg',        'code' => '&mdash;', 'color' => 'Laurel Green',         'dates' => '1974',                       'models' => 'Plus 2',                      'notes' => 'Late Plus 2 color, rare, no Lotus code. Possibly metallic'],
];

/**
 * Special finishes (non-body)
 * TODO: Move to database table
 */
$specialFinishes = [
    ['finish' => 'Gold Lacquer',        'dates' => 'Oct 1970 &ndash; Dec 1974', 'usage' => 'Elan Sprint bumpers and sidewinder decals. Talbot Aztec Gold (code 143) is an alternative'],
    ['finish' => 'Silver Lacquer',      'dates' => 'Oct 1962 &ndash; Jan 1971', 'usage' => 'S1, S2, S3 &amp; S4 bumpers and wheels'],
    ['finish' => 'Silver',              'dates' => 'Mar 1969 onwards',           'usage' => 'Plus 2 roof only'],
    ['finish' => 'Metallic Jewels',     'dates' => '&mdash;',                    'usage' => 'Plus 2S roof only'],
    ['finish' => 'Black Crackle Finish','dates' => '&mdash;',                    'usage' => 'Big Valve cam cover'],
    ['finish' => 'Red Crackle Finish',  'dates' => '&mdash;',                    'usage' => 'Federal Big Valve cam cover, some European spec engines, and a small number of later domestic engines'],
];

/**
 * Paint code cross-reference table
 * TODO: Move to database table
 */
$paintCodes = [
    ['code' => '&mdash;', 'color' => 'Medici Blue',              'nexa' => '3192',  'ppg' => '',       'glasurit' => ''],
    ['code' => '&mdash;', 'color' => 'Carmen Red',               'nexa' => '3097',  'ppg' => '',       'glasurit' => ''],
    ['code' => '&mdash;', 'color' => 'Fiesta Yellow',            'nexa' => 'YL11',  'ppg' => '',       'glasurit' => ''],
    ['code' => '&mdash;', 'color' => 'Sunburst Yellow',          'nexa' => '',       'ppg' => '80774',  'glasurit' => ''],
    ['code' => '&mdash;', 'color' => 'Monaco Red',               'nexa' => '',       'ppg' => '71245',  'glasurit' => ''],
    ['code' => '01',       'color' => 'British Racing Green',    'nexa' => '2854',  'ppg' => 'LOT0050','glasurit' => 'L01'],
    ['code' => '02',       'color' => 'French Blue',             'nexa' => '7210',  'ppg' => 'LOT0046','glasurit' => ''],
    ['code' => '03',       'color' => 'Wedgewood Blue',          'nexa' => '5814',  'ppg' => 'LOT0038','glasurit' => ''],
    ['code' => '04',       'color' => 'Cirrus White',            'nexa' => '9049',  'ppg' => 'LOT0053','glasurit' => 'L04'],
    ['code' => '05',       'color' => 'Carnival Red',            'nexa' => '8385',  'ppg' => 'LOT0388','glasurit' => 'L05'],
    ['code' => '06',       'color' => 'Burnt Sand',              'nexa' => '6853',  'ppg' => 'LOT0049','glasurit' => 'L06'],
    ['code' => '07',       'color' => 'Lotus Yellow',            'nexa' => '9547',  'ppg' => '83526',  'glasurit' => 'X3940'],
    ['code' => '08',       'color' => 'Matt Black',              'nexa' => '',       'ppg' => '',       'glasurit' => ''],
    ['code' => '09',       'color' => 'Royal Blue',              'nexa' => '7557',  'ppg' => 'LOT0047','glasurit' => ''],
    ['code' => '10',       'color' => 'Bahama Yellow',           'nexa' => '6854',  'ppg' => '2325',   'glasurit' => ''],
    ['code' => '11',       'color' => 'Regency Red',             'nexa' => '9053',  'ppg' => '71791',  'glasurit' => ''],
    ['code' => '12',       'color' => 'Lagoon Blue (Metallic)',  'nexa' => '4339',  'ppg' => 'LOT0225','glasurit' => ''],
    ['code' => '13',       'color' => 'Pistachio Lime Green',    'nexa' => '9052',  'ppg' => 'LOT0041','glasurit' => 'L13'],
    ['code' => '14',       'color' => 'Colorado Orange',         'nexa' => '9055',  'ppg' => 'LOT0043','glasurit' => ''],
    ['code' => '15',       'color' => 'Black Gloss',             'nexa' => '',       'ppg' => '95039',  'glasurit' => 'L15'],
    ['code' => '16',       'color' => 'Tawny Brown (Metallic)',  'nexa' => '3626',  'ppg' => '23555',  'glasurit' => ''],
    ['code' => '17',       'color' => 'Mid Green (Metallic)',    'nexa' => '',       'ppg' => 'LOT0221','glasurit' => ''],
    ['code' => '18',       'color' => 'Glacier Blue (Metallic)', 'nexa' => '14170', 'ppg' => '',       'glasurit' => ''],
    ['code' => '19',       'color' => 'Sable',                   'nexa' => '8830',  'ppg' => 'LOT0301','glasurit' => ''],
    ['code' => '20',       'color' => 'Indigo Blue',             'nexa' => '12119', 'ppg' => '',       'glasurit' => ''],
    ['code' => '21',       'color' => 'Purple (Metallic)',       'nexa' => '',       'ppg' => 'LOT0222','glasurit' => 'L21'],
    ['code' => '22',       'color' => 'Bitter Green',            'nexa' => '',       'ppg' => '',       'glasurit' => ''],
    ['code' => '23',       'color' => 'Sepia Brown',             'nexa' => '',       'ppg' => 'LOT0470','glasurit' => ''],
    ['code' => '24',       'color' => 'Firecracker',             'nexa' => '',       'ppg' => '',       'glasurit' => ''],
    ['code' => '25',       'color' => 'Monaco White',            'nexa' => '',       'ppg' => 'LOT0526','glasurit' => ''],
    ['code' => '26',       'color' => 'Olympic Blue',            'nexa' => '',       'ppg' => '',       'glasurit' => 'L26'],
];

/**
 * Render a paint chip image or empty cell
 *
 * @param string $chipFile The chip filename (empty string for no chip)
 * @param string $altText  Alt text for the image
 * @param string $basePath URL base path for chip images
 * @return string HTML for the table cell content
 */
function renderChip(string $chipFile, string $altText, string $basePath): string
{
    if ($chipFile === '') {
        return '';
    }
    $src = htmlspecialchars($basePath . $chipFile, ENT_QUOTES, 'UTF-8');
    $alt = htmlspecialchars($altText, ENT_QUOTES, 'UTF-8');
    return '<img src="' . $src . '" alt="' . $alt . '" class="paint-chip">';
}

?>
<div class="page-wrapper">
    <div class="container">
        <!-- Page Header -->
        <div class="row">
            <div class="col-12">
                <div class="card registry-card">
                    <div class="card-header card-header-er-primary">
                        <h1 class="mb-0 card-header-er-primary-text"><i class="fas fa-palette"></i> Lotus Elan &amp; Plus 2 Paint Colors Guide</h1>
                        <p class="card-header-er-primary-text">A comprehensive reference to factory paint colors from 1962 onwards</p>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-primary mb-0">
                            <i class="fas fa-info-circle"></i>
                            <strong>Note:</strong> Paint chips are for illustrative purposes only. Original color charts are over 40 years old and actual shades may vary. Always obtain a physical color sample or use a professional paint matching service for restoration work.
                        </div>
                    </div>
                </div>
            </div>
        </div>

                            <!-- Paint Codes PDF Download -->
                            <div class="row mt-4">
                                <div class="col-12">
                                    <div class="card registry-card">
                                        <div class="card-header card-header-er-primary">
                                            <h5 class="mb-0 card-header-er-primary-text"><i class="fas fa-file-pdf"></i> Paint Codes PDF — Official Factory Reference</h5>
                                        </div>
                                        <div class="card-body d-flex flex-column">
                                            <p class="card-text flex-grow-1">Official factory paint codes for all Elan and Plus 2 models — downloadable PDF for offline reference.</p>
                                            <div class="mt-auto">
                                                <a href="<?= $us_url_root ?>docs/pdf-viewer.php?subdir=reference&doc=<?= rawurlencode('All Elan and Elan Plus 2 Paint Codes.pdf') ?>"
                                                   target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm me-2">
                                                    <i class="fas fa-eye"></i> Read Online
                                                </a>
                                                <a href="<?= $us_url_root ?>docs/reference/assets/<?= rawurlencode('All Elan and Elan Plus 2 Paint Codes.pdf') ?>"
                                                   download class="btn btn-secondary btn-sm">
                                                    <i class="fas fa-download"></i> Download PDF
                                                </a>
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
                        <a href="#early-colors" class="btn btn-outline-primary btn-sm mx-1">Early Colors</a>
                        <a href="#official-colors" class="btn btn-outline-primary btn-sm mx-1">Official Colors (L01&ndash;L26)</a>
                        <a href="#special-finishes" class="btn btn-outline-primary btn-sm mx-1">Special Finishes</a>
                        <a href="#paint-suppliers" class="btn btn-outline-primary btn-sm mx-1">Paint Suppliers</a>
                        <a href="#paint-codes" class="btn btn-outline-primary btn-sm mx-1">Paint Code Reference</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Early Colors -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card registry-card">
                    <div class="card-header card-header-er-primary">
                        <h2 class="mb-0 card-header-er-primary-text" id="early-colors">Early Colors (No Lotus Code)</h2>
                    </div>
                    <div class="card-body">
                        <p>These colors were available on early S1 Elans from October 1962 but were never assigned a Lotus color code. They were borrowed from other British manufacturers.</p>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead class="thead-dark">
                                    <tr>
                                        <th style="width: 100px;">Chip</th>
                                        <th>Color</th>
                                        <th>Dates</th>
                                        <th>Origin</th>
                                        <th>Nexa Code</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($earlyColors as $row): ?>
                                    <tr>
                                        <td class="text-center align-middle"><?= renderChip($row['chip'], $row['color'], $chipPath) ?></td>
                                        <td class="align-middle"><?= $row['color'] ?></td>
                                        <td class="align-middle"><?= $row['dates'] ?></td>
                                        <td class="align-middle"><?= $row['origin'] ?></td>
                                        <td class="align-middle"><?= $row['nexa'] !== '' ? $row['nexa'] : '&mdash;' ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Official Lotus Colors -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card registry-card">
                    <div class="card-header card-header-er-primary">
                        <h2 class="mb-0 card-header-er-primary-text" id="official-colors">Official Lotus Colors (L01&ndash;L26)</h2>
                    </div>
                    <div class="card-body">
                        <p>All officially coded Lotus paint colors used across the Elan and Plus 2 range.</p>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead class="thead-dark">
                                    <tr>
                                        <th style="width: 100px;">Chip</th>
                                        <th>Code</th>
                                        <th>Color</th>
                                        <th>Dates</th>
                                        <th>Models</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($officialColors as $row): ?>
                                    <tr>
                                        <td class="text-center align-middle"><?= renderChip($row['chip'], $row['color'], $chipPath) ?></td>
                                        <td class="align-middle"><strong><?= $row['code'] ?></strong></td>
                                        <td class="align-middle"><?= $row['color'] ?></td>
                                        <td class="align-middle"><?= $row['dates'] ?></td>
                                        <td class="align-middle"><?= $row['models'] ?></td>
                                        <td class="align-middle"><?= $row['notes'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Special Finishes -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card registry-card">
                    <div class="card-header card-header-er-primary">
                        <h2 class="mb-0 card-header-er-primary-text" id="special-finishes">Special Finishes</h2>
                    </div>
                    <div class="card-body">
                        <p>Non-body finishes used on specific components.</p>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead class="thead-dark">
                                    <tr>
                                        <th>Finish</th>
                                        <th>Dates</th>
                                        <th>Usage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($specialFinishes as $row): ?>
                                    <tr>
                                        <td><?= $row['finish'] ?></td>
                                        <td><?= $row['dates'] ?></td>
                                        <td><?= $row['usage'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Paint Suppliers & Matching -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card registry-card">
                    <div class="card-header card-header-er-primary">
                        <h2 class="mb-0 card-header-er-primary-text" id="paint-suppliers">Paint Suppliers &amp; Matching</h2>
                    </div>
                    <div class="card-body">
                        <p>The Lotus &ldquo;L&rdquo; prefix codes indicate nitro-cellulose paint. Two suppliers originally provided paint to the Lotus factory:</p>
                        <ul>
                            <li><strong>Pinchin, Johnson</strong> &mdash; Later taken over by Courtaulds and merged into International Paint.</li>
                            <li><strong>ICI</strong> &mdash; ICI Paints was taken over by Nexa Autocolor, which is now a brand of PPG.</li>
                        </ul>
                        <h4>Modern Paint Matching Services</h4>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead class="thead-dark">
                                    <tr>
                                        <th>Supplier</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>Nexa Autocolor / PPG</strong></td>
                                        <td>World leader in automotive finishes. Nexa codes listed below can be used to order matching paint. PPG established UK presence in 1985.</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Glasurit (BASF)</strong></td>
                                        <td>Over 100 years in coatings. Offers Glasurit Classic Car Colors with a library of 200,000+ colors for classic car refinishing.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Paint Code Reference Table -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card registry-card">
                    <div class="card-header card-header-er-primary">
                        <h2 class="mb-0 card-header-er-primary-text" id="paint-codes">Paint Code Reference Table</h2>
                    </div>
                    <div class="card-body">
                        <p>Full cross-reference of Lotus codes to supplier paint codes, useful when ordering paint for restoration.</p>
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped">
                                <thead class="thead-dark">
                                    <tr>
                                        <th>Lotus Code</th>
                                        <th>Color</th>
                                        <th>Nexa Autocolor</th>
                                        <th>PPG</th>
                                        <th>Glasurit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($paintCodes as $row): ?>
                                    <tr>
                                        <td><?= $row['code'] ?></td>
                                        <td><?= $row['color'] ?></td>
                                        <td><?= $row['nexa'] !== '' ? $row['nexa'] : '&mdash;' ?></td>
                                        <td><?= $row['ppg'] !== '' ? $row['ppg'] : '&mdash;' ?></td>
                                        <td><?= $row['glasurit'] !== '' ? $row['glasurit'] : '&mdash;' ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.container -->
</div><!-- .page-wrapper -->

<style>
    .paint-chip {
        max-width: 80px;
        max-height: 80px;
        border: 1px solid #dee2e6;
        border-radius: 4px;
    }
</style>

<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php';
?>
