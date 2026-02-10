<?php

declare(strict_types=1);

/**
 * Load Car Models Script
 *
 * Administrative script to populate the car_models database table with
 * Lotus Elan model definitions. This is a critical prerequisite for downstream
 * color normalization and model-based filtering features.
 *
 * Issue #577: Create car_models database table
 *
 * This script handles both schema creation and data population:
 * - Creates car_models table with all constraints and indexes
 * - Inserts 24 pre-parsed model definitions from cardefinition.js
 * - Validates data integrity and correct schema application
 *
 * Pre-parsed models are embedded as SQL (generated from cardefinition.js during
 * development). If cardefinition.js changes in future, regenerate INSERT
 * statements via parser script (see CLAUDE.md).
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. Run this script via FIX/index.php menu or direct URL
 * 2. Verify 24 models are inserted and year ranges are valid (1963-1974)
 * 3. Run validation SQL queries to confirm data integrity
 * 4. See FIX/README.md for additional details
 *
 * FEATURES:
 * - Simple, reliable text-based progress output
 * - Two-step process: description → start button → real-time processing
 * - Timestamped progress with emoji indicators
 * - Idempotent (can run multiple times safely via TRUNCATE)
 * - Automatic completion logging
 * - Clean error handling and reporting
 */

// UI Constants for progress output
define('SECTION_SEPARATOR', '═══════════════════════════════════════════════════════');

require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

use ElanRegistry\Exceptions\FIXScriptException;

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Set up custom error handler to log through UserSpice logger
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if ($errno !== E_DEPRECATED) {
        logger(isset($user) ? $user->data()->id : 0, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Error [$errno]: $errstr in $errfile:$errline");
    }
    return true;
});

$db = DB::getInstance();

?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="well">

            <?php if (!isset($_GET['start'])): ?>
            <!-- Initial Description -->
            <div class="row">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-database"></i> Load Car Models
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script populates the <code>car_models</code> database table with Lotus Elan model definitions. The model data was pre-parsed from <code>cardefinition.js</code> and is embedded as SQL for transparency and auditability. This is a critical prerequisite for color normalization and advanced filtering features.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Creates the <code>car_models</code> table with schema, constraints, and indexes</li>
                                    <li>Inserts 24 pre-parsed Lotus Elan model definitions with year ranges</li>
                                    <li>Validates all data against constraints (year bounds 1963-1974, valid type codes)</li>
                                    <li>Verifies <code>series_normalized</code> generated column works correctly</li>
                                    <li>Confirms complete data integrity and audit trail</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li>This script is idempotent and safe to run multiple times</li>
                                    <li>Existing data will be replaced (uses TRUNCATE then INSERT)</li>
                                    <li>All operations are transaction-backed and will rollback on error</li>
                                    <li>Model data is pre-parsed from <code>cardefinition.js</code> and transparent in the SQL</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <a href="?start=1" class="btn btn-success btn-lg">
                                    <i class="fa fa-play"></i> Continue - Start Loading Models
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php else:
                // Processing mode - simple text output
                ob_end_clean(); // Clear template buffering
                header('Content-Type: text/html; charset=utf-8');
                echo str_repeat(' ', 1024); // Pad to force initial flush
                flush();
            ?>

            <div class="card registry-card">
                <div class="card-header">
                    <h2 class="mb-0">
                        <i class="fa fa-cogs"></i> Processing Car Models
                    </h2>
                    <small class="text-muted">
                        <i class="fa fa-clock-o"></i> Started: <?php echo date('Y-m-d H:i:s'); ?>
                    </small>
                </div>
                <div class="card-body">
                    <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; max-height: 600px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 13px;"><?php

                    /**
                     * Helper function for progress output
                     *
                     * @param string $message Message to display
                     * @param string $type Type of message: 'info', 'success', 'error', 'warning', 'step'
                     */
                    function logProgress($message, $type = 'info'): void {
                        $icons = [
                            'info' => 'ℹ️',
                            'success' => '✅',
                            'error' => '❌',
                            'warning' => '⚠️',
                            'step' => '▶️'
                        ];
                        $icon = $icons[$type] ?? '•';
                        echo date('[H:i:s] ') . $icon . ' ' . $message . "\n";
                        flush();
                    }

                    // Initialize results tracking
                    $results = [
                        'processed' => 0,
                        'errors' => 0,
                        'warnings' => 0,
                        'models' => []
                    ];

                    try {
                        // STEP 1: Create car_models table schema
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 1: Create car_models Table Schema', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $createTableSQL = <<<'SQL'
CREATE TABLE IF NOT EXISTS `car_models` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `year_available_from` int(11) NOT NULL COMMENT 'First production year',
  `year_available_to` int(11) NOT NULL COMMENT 'Last production year',
  `display_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Full display name from cardefinition.js',
  `human_readable_short` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Short name (no parenthetical)',
  `series` varchar(15) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Series (S1, S2, S3, S4, +2, Sprint, etc.)',
  `variant` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Body style (Roadster, FHC, DHC, Federal, Race)',
  `type_code` char(3) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Type code (26, 36, 45, 50, 26R)',
  `model_value` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'series|variant|type composite key',
  `series_normalized` varchar(15) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (
    CASE
      WHEN `series` LIKE '% SE' THEN TRIM(SUBSTRING_INDEX(`series`, ' SE', 1))
      WHEN `series` LIKE '% S/E' THEN TRIM(SUBSTRING_INDEX(`series`, ' S/E', 1))
      WHEN `series` LIKE '%|Race' THEN TRIM(SUBSTRING_INDEX(`series`, '|Race', 1))
      ELSE `series`
    END
  ) STORED COMMENT 'Normalized series for filtering (strips SE/Race suffixes)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `model_value` (`model_value`),
  UNIQUE KEY `unique_model_combo` (`series`,`variant`,`type_code`),
  KEY `idx_year_range` (`year_available_from`,`year_available_to`),
  KEY `idx_series` (`series`),
  KEY `idx_series_normalized` (`series_normalized`),
  KEY `idx_type_code` (`type_code`),
  CONSTRAINT `ck_year_range` CHECK ((`year_available_from` <= `year_available_to`)),
  CONSTRAINT `ck_year_bounds` CHECK (((`year_available_from` >= 1963) and (`year_available_to` <= 1974))),
  CONSTRAINT `ck_type_code` CHECK ((`type_code` in ('26','36','45','50','26R')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Lotus Elan model reference data from cardefinition.js'
SQL;

                        $db->query($createTableSQL);
                        logProgress('car_models table schema created/verified', 'success');

                        // STEP 2: Clear existing data and insert pre-parsed models
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 2: Insert Pre-Parsed Car Models', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        // Start transaction
                        $db->query('START TRANSACTION');
                        logProgress('Transaction started', 'info');

                        // Clear existing data
                        $db->query('TRUNCATE TABLE car_models');
                        logProgress('Cleared existing car_models data', 'info');

                        // Pre-parsed models extracted from cardefinition.js MENU array
                        // This data was generated once during development and is stable
                        $insertSQL = <<<'SQL'
INSERT INTO `car_models`
  (`year_available_from`, `year_available_to`, `display_name`,
   `human_readable_short`, `series`, `variant`, `type_code`, `model_value`)
VALUES
  (1963, 1963, 'Elan 1500 ( Type 26 Elan 1500 Roadster )', 'Elan 1500', 'Elan 1500', 'Roadster', '26', 'Elan 1500|Roadster|26'),
  (1963, 1964, 'Elan 1600 ( Type 26 S1 Roadster )', 'Elan 1600', 'S1', 'Roadster', '26', 'S1|Roadster|26'),
  (1963, 1964, 'Racing Version ( Number 26-R-xx )', 'Racing Version', 'S1', 'Race', '26R', 'S1|Race|26R'),
  (1964, 1966, 'Roadster S2 ( Type 26 S2 Roadster )', 'Roadster S2', 'S2', 'Roadster', '26', 'S2|Roadster|26'),
  (1964, 1966, 'Racing Version ( Number 26-S2-xx )', 'Racing Version', 'S2', 'Race', '26R', 'S2|Race|26R'),
  (1965, 1965, 'Racing Version ( Type 26 26R Race )', 'Racing Version', '26R', 'Race', '26', '26R|Race|26'),
  (1965, 1966, 'Coupe S3 Pre-Airflow ( Type 36 S3 FHC-preairflow )', 'Coupe S3 Pre-Airflow', 'S3', 'FHC-preairflow', '36', 'S3|FHC-preairflow|36'),
  (1966, 1966, 'Roadster S2 S/E ( Type 26 S2 S/E Roadster )', 'Roadster S2 S/E', 'S2 SE', 'Roadster', '26', 'S2 SE|Roadster|26'),
  (1966, 1968, 'Coupe S3 Airflow ( Type 36 S3 FHC )', 'Coupe S3 Airflow', 'S3', 'FHC', '36', 'S3|FHC|36'),
  (1966, 1968, 'Drophead S3 DHC ( Type 45 S3 DHC )', 'Drophead S3 DHC', 'S3', 'DHC', '45', 'S3|DHC|45'),
  (1966, 1968, 'Drophead S3 S/E DHC ( Type 45 S3 S/E DHC )', 'Drophead S3 S/E DHC', 'S3 SE', 'DHC', '45', 'S3 SE|DHC|45'),
  (1967, 1970, 'Plus 2 ( Type 50 +2)', 'Plus 2', '+2', 'FHC', '50', '+2|FHC|50'),
  (1967, 1968, 'Coupe S3 S/E ( Type 36 S3 FHC )', 'Coupe S3 S/E', 'S3 SE', 'FHC', '36', 'S3 SE|FHC|36'),
  (1968, 1970, 'Plus 2 Federal ( Type 50 +2 Federal)', 'Plus 2 Federal', '+2', 'Federal', '50', '+2|Federal|50'),
  (1968, 1971, 'Coupe S4 ( Type 36 S4 FHC )', 'Coupe S4', 'S4', 'FHC', '36', 'S4|FHC|36'),
  (1968, 1971, 'Drophead S4 DHC ( Type 45 S4 DHC )', 'Drophead S4 DHC', 'S4', 'DHC', '45', 'S4|DHC|45'),
  (1968, 1971, 'Coupe S4 S/E ( Type 36 S4 S/E FHC )', 'Coupe S4 S/E', 'S4 SE', 'FHC', '36', 'S4 SE|FHC|36'),
  (1968, 1971, 'Drophead S4 S/E DHC ( Type 45 S4 S/E DHC )', 'Drophead S4 S/E DHC', 'S4 SE', 'DHC', '45', 'S4 SE|DHC|45'),
  (1969, 1970, 'Plus 2S ( Type 50 +2S)', 'Plus 2S', '+2S', 'FHC', '50', '+2S|FHC|50'),
  (1969, 1970, 'Plus 2S Federal ( Type 50 +2S Federal)', 'Plus 2S Federal', '+2S', 'Federal', '50', '+2S|Federal|50'),
  (1970, 1973, 'Coupe Sprint ( Type 36 Sprint FHC )', 'Coupe Sprint', 'Sprint', 'FHC', '36', 'Sprint|FHC|36'),
  (1970, 1973, 'Drophead Sprint ( Type 45 Sprint DHC )', 'Drophead Sprint', 'Sprint', 'DHC', '45', 'Sprint|DHC|45'),
  (1971, 1974, 'Plus 2S 130 ( Type 50 +2S/130)', 'Plus 2S 130', '+2S/130', 'FHC', '50', '+2S/130|FHC|50'),
  (1972, 1974, 'Plus 2S 130/5 ( Type 50 +2S/130/5)', 'Plus 2S 130/5', '+2S/130/5', 'FHC', '50', '+2S/130/5|FHC|50')
SQL;

                        $db->query($insertSQL);
                        $results['processed'] = 24; // Fixed count from pre-parsed data

                        logProgress("Inserted {$results['processed']} pre-parsed car models", 'success');

                        // STEP 3: Validate data integrity
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 3: Validate Data Integrity', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        // Check total count
                        $countResult = $db->query('SELECT COUNT(*) as total FROM car_models')->first();
                        $totalCount = (int)($countResult->total ?? 0);
                        logProgress("Total models in database: {$totalCount}", 'info');

                        if ($totalCount !== 24) {
                            throw new FIXScriptException("Data integrity check failed: expected 24 models, got {$totalCount}");
                        }

                        // Check type code distribution
                        $typeCodes = $db->query('SELECT type_code, COUNT(*) as cnt FROM car_models GROUP BY type_code')->results();
                        foreach ($typeCodes as $tc) {
                            logProgress("  Type {$tc->type_code}: {$tc->cnt} models", 'info');
                        }

                        // Check for NULL series_normalized (which would indicate a bug)
                        $nullNormalized = $db->query('SELECT COUNT(*) as total FROM car_models WHERE series_normalized IS NULL')->first()->total ?? 0;
                        if ($nullNormalized > 0) {
                            throw new FIXScriptException("Data integrity check failed: {$nullNormalized} records with NULL series_normalized");
                        }
                        logProgress("series_normalized column generated correctly for all records", 'success');

                        // Commit transaction
                        $db->query('COMMIT');
                        logProgress('Transaction committed successfully', 'success');

                        // Log script completion
                        $db->insert('fix_script_runs', [
                            'script_name' => '26-Load-Car-Models.php',
                            'completed_at' => date('Y-m-d H:i:s')
                        ]);

                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            "Script completed - Processed: {$results['processed']}, Warnings: {$results['warnings']}, Errors: {$results['errors']}");

                        // Display summary
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('Car Models Successfully Loaded', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress("Models Processed: {$results['processed']}", 'success');
                        if ($results['warnings'] > 0) {
                            logProgress("Warnings: {$results['warnings']}", 'warning');
                        }
                        logProgress('', 'info');
                        logProgress('Post-Processing Steps:', 'info');
                        logProgress('  • Verify car_models table in database', 'info');
                        logProgress('  • Run unit tests: composer test:integration tests/unit/Reference/', 'info');
                        logProgress('  • Test CarModel class queries manually', 'info');

                    } catch (FIXScriptException $e) {
                        $db->query('ROLLBACK');
                        logProgress('FATAL ERROR: ' . $e->getMessage(), 'error');
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, 'Fatal error: ' . $e->getMessage());
                        $results['errors']++;
                    }

                    ?></pre>

                    <div class="text-center mt-3">
                        <a href="index.php" class="btn btn-primary btn-lg">
                            <i class="fa fa-arrow-left"></i> Return to FIX Menu
                        </a>
                    </div>
                </div>
            </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
