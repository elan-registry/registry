<?php

declare(strict_types=1);

/**
 * Test Database Setup Script
 *
 * Loads reference data from database/2-reference-data.sql into the test database.
 * This script ensures car_models and other reference tables are populated for integration tests.
 *
 * Usage:
 *   php tests/setup-test-database.php
 */

require_once __DIR__ . '/bootstrap-integration.php';

echo "================================================================================\n";
echo "ELAN REGISTRY TEST DATABASE SETUP\n";
echo "================================================================================\n\n";

// Verify database connection
try {
    $db = DB::getInstance();
    $result = $db->query("SELECT 1");
    echo "✅ Database connection verified\n";
} catch (Throwable $e) {
    fwrite(STDERR, "❌ ERROR: Database connection failed: {$e->getMessage()}\n");
    exit(1);
}

// Check car_models table existence
try {
    $count = $db->query("SELECT COUNT(*) as cnt FROM car_models")->first();
    $currentCount = (int)$count->cnt;
    echo "📊 Current car_models records: {$currentCount}\n";

    if ($currentCount > 0) {
        echo "⚠️  car_models table already contains {$currentCount} records\n";
        echo "   To reload, manually truncate the table first.\n\n";
        echo "✅ Test database setup complete (already populated).\n";
        exit(0);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "❌ ERROR: Failed to check car_models table: {$e->getMessage()}\n");
    exit(1);
}

// Load reference data SQL file
$refDataPath = dirname(__DIR__) . '/database/2-reference-data.sql';
if (!file_exists($refDataPath)) {
    fwrite(STDERR, "❌ ERROR: Reference data file not found: {$refDataPath}\n");
    exit(1);
}

echo "📂 Loading reference data from: {$refDataPath}\n";
$refDataSql = file_get_contents($refDataPath);

if ($refDataSql === false) {
    fwrite(STDERR, "❌ ERROR: Failed to read reference data file\n");
    exit(1);
}

// Extract just the car_models INSERT statement
// Find the car_models section (starts at line ~10058)
$carModelsPattern = '/INSERT IGNORE INTO `car_models`.*?VALUES\s*(.*?);/s';
if (!preg_match($carModelsPattern, $refDataSql, $matches)) {
    fwrite(STDERR, "❌ ERROR: Could not find car_models INSERT statement in SQL file\n");
    exit(1);
}

$carModelsInsert = "INSERT IGNORE INTO `car_models`
  (`year_available_from`, `year_available_to`, `display_name`,
   `human_readable_short`, `series`, `variant`, `type_code`, `model_value`)
VALUES " . $matches[1] . ";";

echo "🔄 Loading car_models reference data...\n";

try {
    // Execute the INSERT statement
    $db->query($carModelsInsert);

    // Verify the data was loaded
    $count = $db->query("SELECT COUNT(*) as cnt FROM car_models")->first();
    $loadedCount = (int)$count->cnt;

    if ($loadedCount === 0) {
        fwrite(STDERR, "❌ ERROR: No records were inserted into car_models\n");
        exit(1);
    }

    echo "✅ Loaded {$loadedCount} car_models records successfully\n";

    // Show sample data
    $samples = $db->query("SELECT model_value, human_readable_short FROM car_models LIMIT 5")->results();
    echo "\n📋 Sample records:\n";
    foreach ($samples as $sample) {
        echo "   - {$sample->model_value} ({$sample->human_readable_short})\n";
    }

} catch (Throwable $e) {
    fwrite(STDERR, "❌ ERROR: Failed to load car_models data: {$e->getMessage()}\n");
    exit(1);
}

echo "\n";
echo "================================================================================\n";
echo "✅ Test database setup complete.\n";
echo "================================================================================\n";
echo "\nYou can now run integration tests:\n";
echo "  composer test:integration\n";
echo "  composer test:medium\n";
echo "  composer test:full\n";
echo "\n";

exit(0);
