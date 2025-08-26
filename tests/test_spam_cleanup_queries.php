<?php
/**
 * Test script for SPAM and Inactive User Cleanup queries
 * 
 * This script validates the SQL queries used in the cleanup cron job
 * without requiring the full UserSpice environment.
 */

// Database connection (MAMP MySQL)
$host = '127.0.0.1';
$port = 8889;
$username = 'claude';
$password = 'claude';
$database = 'elanregi_spice';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$database", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Database connection successful\n\n";
} catch (PDOException $e) {
    die("❌ Database connection failed: " . $e->getMessage() . "\n");
}

// Test configuration
$INACTIVE_DAYS = 30;
$GRACE_PERIOD_DAYS = 7;
$MAX_DELETIONS_PER_RUN = 50;

echo "=== SPAM AND INACTIVE USER CLEANUP TEST ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Configuration:\n";
echo "- Inactive days threshold: $INACTIVE_DAYS\n";
echo "- Grace period: $GRACE_PERIOD_DAYS days\n";
echo "- Max deletions per run: $MAX_DELETIONS_PER_RUN\n\n";

// ===========================================
// TEST 1: SPAM Detection Queries
// ===========================================

echo "=== PHASE 1: SPAM USER DETECTION ===\n";

// Query 1: Legacy data anomalies (1969 dates)
echo "1. Testing legacy data anomaly detection...\n";
$sql = "
    SELECT u.id, u.username, u.email, u.join_date, u.last_login, u.email_verified,
           (SELECT COUNT(*) FROM cars c WHERE c.user_id = u.id) as car_count
    FROM users u
    WHERE u.join_date < '1980-01-01' 
    AND u.last_login = '0000-00-00 00:00:00'
    AND u.email_verified = 0
    AND u.username NOT IN ('admin', 'noowner')
    AND (SELECT COUNT(*) FROM cars c WHERE c.user_id = u.id) = 0
    ORDER BY u.join_date
    LIMIT $MAX_DELETIONS_PER_RUN
";

$stmt = $pdo->query($sql);
$legacySpamUsers = $stmt->fetchAll(PDO::FETCH_OBJ);
echo "   Found " . count($legacySpamUsers) . " legacy spam candidates\n";

if (count($legacySpamUsers) > 0) {
    echo "   Sample users:\n";
    for ($i = 0; $i < min(3, count($legacySpamUsers)); $i++) {
        $user = $legacySpamUsers[$i];
        echo "   - ID: {$user->id}, Username: {$user->username}, Join: {$user->join_date}, Cars: {$user->car_count}\n";
    }
}
echo "\n";

// Query 2: Suspicious recent users
echo "2. Testing suspicious user pattern detection...\n";
$sql = "
    SELECT u.id, u.username, u.email, u.join_date, u.last_login, u.email_verified,
           (SELECT COUNT(*) FROM cars c WHERE c.user_id = u.id) as car_count,
           (SELECT COUNT(*) FROM profiles p WHERE p.user_id = u.id) as profile_count
    FROM users u
    WHERE u.join_date > '2020-01-01'
    AND u.last_login = '0000-00-00 00:00:00'
    AND u.email_verified = 0
    AND u.username NOT IN ('admin', 'noowner')
    AND (SELECT COUNT(*) FROM cars c WHERE c.user_id = u.id) = 0
    AND (SELECT COUNT(*) FROM profiles p WHERE p.user_id = u.id) = 0
    AND DATEDIFF(NOW(), u.join_date) > 7
    ORDER BY u.join_date
    LIMIT $MAX_DELETIONS_PER_RUN
";

$stmt = $pdo->query($sql);
$suspiciousUsers = $stmt->fetchAll(PDO::FETCH_OBJ);
echo "   Found " . count($suspiciousUsers) . " suspicious user candidates\n";

if (count($suspiciousUsers) > 0) {
    echo "   Sample users:\n";
    for ($i = 0; $i < min(3, count($suspiciousUsers)); $i++) {
        $user = $suspiciousUsers[$i];
        echo "   - ID: {$user->id}, Username: {$user->username}, Join: {$user->join_date}, Profile: {$user->profile_count}\n";
    }
}
echo "\n";

$totalSpamCandidates = count($legacySpamUsers) + count($suspiciousUsers);
echo "Total SPAM candidates: $totalSpamCandidates\n\n";

// ===========================================
// TEST 2: Inactive User Detection
// ===========================================

echo "=== PHASE 2: INACTIVE USER DETECTION ===\n";

echo "3. Testing inactive user detection...\n";
$sql = "
    SELECT u.id, u.username, u.email, u.join_date, u.last_login, u.email_verified,
           (SELECT COUNT(*) FROM cars c WHERE c.user_id = u.id) as car_count,
           DATEDIFF(NOW(), u.join_date) as days_since_join,
           DATEDIFF(NOW(), IF(u.last_login = '0000-00-00 00:00:00', u.join_date, u.last_login)) as days_since_activity
    FROM users u
    WHERE DATEDIFF(NOW(), u.join_date) > $INACTIVE_DAYS
    AND u.username NOT IN ('admin', 'noowner')
    AND u.protected = 0
    AND (SELECT COUNT(*) FROM cars c WHERE c.user_id = u.id) = 0
    AND (
        u.last_login = '0000-00-00 00:00:00'
        OR DATEDIFF(NOW(), u.last_login) > 90
        OR (u.email_verified = 0 AND DATEDIFF(NOW(), u.join_date) > " . ($INACTIVE_DAYS + $GRACE_PERIOD_DAYS) . ")
    )
    ORDER BY u.join_date
    LIMIT $MAX_DELETIONS_PER_RUN
";

$stmt = $pdo->query($sql);
$inactiveUsers = $stmt->fetchAll(PDO::FETCH_OBJ);
echo "   Found " . count($inactiveUsers) . " inactive user candidates\n";

if (count($inactiveUsers) > 0) {
    echo "   Sample users:\n";
    for ($i = 0; $i < min(5, count($inactiveUsers)); $i++) {
        $user = $inactiveUsers[$i];
        echo "   - ID: {$user->id}, Username: {$user->username}, Days old: {$user->days_since_join}, Days inactive: {$user->days_since_activity}\n";
    }
}
echo "\n";

// ===========================================
// TEST 3: Database Statistics
// ===========================================

echo "=== DATABASE STATISTICS ===\n";

// Total user counts
$stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
$totalUsers = $stmt->fetch(PDO::FETCH_OBJ)->total;

$stmt = $pdo->query("SELECT COUNT(*) as active FROM users WHERE active = 1");
$activeUsers = $stmt->fetch(PDO::FETCH_OBJ)->active;

$stmt = $pdo->query("SELECT COUNT(*) as with_cars FROM users WHERE (SELECT COUNT(*) FROM cars WHERE cars.user_id = users.id) > 0");
$usersWithCars = $stmt->fetch(PDO::FETCH_OBJ)->with_cars;

$stmt = $pdo->query("SELECT COUNT(*) as without_cars FROM users WHERE (SELECT COUNT(*) FROM cars WHERE cars.user_id = users.id) = 0");
$usersWithoutCars = $stmt->fetch(PDO::FETCH_OBJ)->without_cars;

echo "Total users: $totalUsers\n";
echo "Active users: $activeUsers\n";
echo "Users with cars: $usersWithCars\n";
echo "Users without cars: $usersWithoutCars\n\n";

// Age distribution
echo "User age distribution:\n";
$stmt = $pdo->query("
    SELECT 
        CASE 
            WHEN DATEDIFF(NOW(), join_date) < 30 THEN '0-30 days'
            WHEN DATEDIFF(NOW(), join_date) < 90 THEN '30-90 days'
            WHEN DATEDIFF(NOW(), join_date) < 365 THEN '90-365 days'
            WHEN DATEDIFF(NOW(), join_date) < 1825 THEN '1-5 years'
            ELSE '5+ years'
        END as age_group,
        COUNT(*) as user_count
    FROM users 
    GROUP BY age_group
    ORDER BY MIN(DATEDIFF(NOW(), join_date))
");

while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
    echo "- {$row->age_group}: {$row->user_count} users\n";
}
echo "\n";

// ===========================================
// SAFETY CHECK SUMMARY
// ===========================================

echo "=== SAFETY ASSESSMENT ===\n";

$totalCleanupCandidates = $totalSpamCandidates + count($inactiveUsers);
$cleanupPercentage = round(($totalCleanupCandidates / $totalUsers) * 100, 2);

echo "Total cleanup candidates: $totalCleanupCandidates\n";
echo "Percentage of total users: $cleanupPercentage%\n";

if ($cleanupPercentage > 10) {
    echo "⚠️  WARNING: Cleanup would affect > 10% of users - consider manual review\n";
} else if ($cleanupPercentage > 5) {
    echo "⚠️  CAUTION: Cleanup would affect > 5% of users - proceed carefully\n";
} else {
    echo "✅ SAFE: Cleanup affects < 5% of users\n";
}

echo "\nRecommendation: ";
if ($totalCleanupCandidates == 0) {
    echo "No users need cleanup at this time.\n";
} else if ($cleanupPercentage <= 2) {
    echo "Safe to proceed with automated cleanup.\n";
} else {
    echo "Consider running in dry-run mode first or implementing batch processing.\n";
}

echo "\n=== TEST COMPLETE ===\n";
?>