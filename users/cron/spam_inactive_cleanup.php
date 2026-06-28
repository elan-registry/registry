<?php
declare(strict_types=1);

/**
 * Automated SPAM and Inactive User Cleanup System
 * 
 * Issue #232: Automated SPAM and Inactive User Cleanup System
 * 
 * This cron script automatically identifies and removes:
 * - SPAM user accounts (immediate removal)
 * - Inactive users with no cars after 30+ days (with grace period)
 * 
 * Features:
 * - Uses UserSpice APIs for safe user management
 * - Comprehensive audit logging
 * - Email notifications for grace period
 * - Multiple safety mechanisms
 * - Dry-run mode for testing
 */

require_once '../init.php';

use ElanRegistry\Exceptions\AdminOperationException;
$filename = currentPage();
$db = DB::getInstance();
$ip = ipCheck();
logger("", "CronRequest", "SPAM/Inactive cleanup cron request from $ip.");

// Security check - only allow from configured cron IP
if($settings->cron_ip != ''){
    if($ip != $settings->cron_ip && $ip != '127.0.0.1'){
        logger("", "CronRequest", "SPAM/Inactive cleanup cron request DENIED from $ip.");
        die;
    }
}

$errors = $successes = [];
$user_id = 1; // System user for cron operations

/**
 * Generate grace period email content (HTML with plain text fallback)
 */
function generateGracePeriodEmailHTML(string $username, int $gracePeriodDays): string {
    $registryUrl = 'https://elanregistry.org';
    $loginUrl = $registryUrl . '/users/login.php';
    $addCarUrl = $registryUrl . '/app/cars/edit.php';
    $logoUrl = $registryUrl . '/usersc/templates/ElanRegistry/assets/images/logo-72x72.png';
    
    $htmlContent = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lotus Elan Registry - Account Inactive Notice</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
        .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .header { background-color: #029acf; color: white; padding: 20px; text-align: center; }
        .logo { width: 48px; height: 48px; margin-bottom: 10px; }
        .content { padding: 30px; }
        .warning-box { background-color: #fef2f2; border: 2px solid #dc2626; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .warning-text { color: #dc2626; font-weight: bold; font-size: 18px; text-align: center; }
        .action-buttons { text-align: center; margin: 30px 0; }
        .btn { display: inline-block; padding: 12px 24px; margin: 10px; background-color: #469408; color: white; text-decoration: none; border-radius: 5px; font-weight: bold; }
        .btn:hover { background-color: #3a7006; }
        .footer { background-color: #f8f9fa; padding: 20px; text-align: center; border-top: 1px solid #dee2e6; color: #6b7280; font-size: 14px; }
        .lotus-green { color: #469408; }
        @media only screen and (max-width: 600px) {
            .content { padding: 20px; }
            .btn { display: block; margin: 10px 0; }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <img src="' . $logoUrl . '" alt="Lotus Logo" class="logo">
            <h1>Lotus Elan Registry</h1>
            <p>Account Inactive Notice</p>
        </div>
        
        <div class="content">
            <h2>Dear ' . htmlspecialchars($username) . ',</h2>
            
            <p>This is an important notice regarding your <strong class="lotus-green">Lotus Elan Registry</strong> account.</p>
            
            <p>Your account was registered over 30 days ago, but you haven\'t yet registered any Lotus Elan vehicles in our database. To keep our registry data clean and current, we automatically remove inactive accounts that don\'t have any registered cars.</p>
            
            <div class="warning-box">
                <div class="warning-text">
                    YOUR ACCOUNT WILL BE DELETED IN ' . $gracePeriodDays . ' DAYS
                </div>
                <p style="text-align: center; margin-top: 10px;">unless you take action below</p>
            </div>
            
            <h3>To keep your account active:</h3>
            <ol>
                <li>Log in to your account</li>
                <li>Add at least one Lotus Elan to the registry</li>
            </ol>
            
            <div class="action-buttons">
                <a href="' . $loginUrl . '" class="btn">Log In Now</a>
                <a href="' . $addCarUrl . '" class="btn">Add Your Lotus Elan</a>
            </div>
            
            <p>The <strong class="lotus-green">Lotus Elan Registry</strong> is a valuable resource for enthusiasts worldwide, and we want to ensure our database contains accurate, up-to-date information about Lotus Elans and their owners.</p>
            
            <p><em>If you no longer wish to maintain an account with us, no action is needed and your account will be automatically removed.</em></p>
            
            <p><small>If you believe this notice was sent in error, please contact us immediately.</small></p>
        </div>
        
        <div class="footer">
            <p><strong>The Lotus Elan Registry Team</strong></p>
            <p><a href="' . $registryUrl . '">' . $registryUrl . '</a></p>
            <p>Preserving the legacy of Colin Chapman\'s masterpiece since 2003</p>
            <hr style="border: none; border-top: 1px solid #dee2e6; margin: 15px 0;">
            <p><small>This is an automated message. Please do not reply to this email.</small></p>
        </div>
    </div>
</body>
</html>';
    
    return $htmlContent;
}

/**
 * Generate grace period email content (Plain text fallback)
 */
function generateGracePeriodEmailText(string $username, int $gracePeriodDays): string {
    $registryUrl = 'https://elanregistry.org';
    $loginUrl = $registryUrl . '/users/login.php';
    $addCarUrl = $registryUrl . '/app/cars/edit.php';
    
    return "LOTUS ELAN REGISTRY - Account Inactive Notice

Dear " . htmlspecialchars($username) . ",

This is an important notice regarding your Lotus Elan Registry account.

Your account was registered over 30 days ago, but you haven't yet registered any Lotus Elan vehicles in our database. To keep our registry data clean and current, we automatically remove inactive accounts that don't have any registered cars.

*** YOUR ACCOUNT WILL BE DELETED IN $gracePeriodDays DAYS ***
*** unless you take action below ***

To keep your account active:
1. Log in to your account: $loginUrl
2. Add at least one Lotus Elan to the registry: $addCarUrl

The Lotus Elan Registry is a valuable resource for enthusiasts worldwide, and we want to ensure our database contains accurate, up-to-date information about Lotus Elans and their owners.

If you no longer wish to maintain an account with us, no action is needed and your account will be automatically removed.

If you believe this notice was sent in error, please contact us immediately.

Thank you for your understanding.

Best regards,
The Lotus Elan Registry Team
$registryUrl
Preserving the legacy of Colin Chapman's masterpiece since 2003

---
This is an automated message. Please do not reply to this email.
";
}

// Load configuration from database settings
$DRY_RUN = $settings->elan_spam_cleanup_dry_run == 1;
$CLEANUP_ENABLED = $settings->elan_spam_cleanup_enabled == 1;
$INACTIVE_DAYS = $settings->elan_spam_inactive_days;
$GRACE_PERIOD_DAYS = $settings->elan_spam_grace_period_days;
$MAX_DELETIONS_PER_RUN = $settings->elan_spam_max_deletions;
$MAX_PERCENTAGE_CLEANUP = floatval($settings->elan_spam_max_percentage);
$ENABLE_EMAIL_NOTIFICATIONS = $settings->elan_spam_email_notifications == 1;

// Check if cleanup is enabled
if (!$CLEANUP_ENABLED) {
    logger($user_id, 'SpamCleanup', 'SPAM cleanup is disabled in settings - skipping execution');
    // Still log to cron system for tracking
    if($from != NULL && $currentPage == $filename) {
        $query = $db->query("SELECT id,name FROM crons WHERE file = ?", array($filename));
        if($query->count() > 0) {
            $results = $query->first();
            $cronfields = array(
                'cron_id' => $results->id,
                'datetime' => date("Y-m-d H:i:s"),
                'user_id' => $user_id
            );
            $db->insert('crons_logs', $cronfields);
        }
        Redirect::to('/' . $from);
    }
    die('SPAM cleanup disabled');
}

// Get total user count for safety calculations
$totalUsersQuery = $db->query("SELECT COUNT(*) as total FROM users");
$totalUsers = $totalUsersQuery->first()->total;

// Start cleanup process
logger($user_id, 'SpamCleanup', 'Starting automated SPAM and inactive user cleanup process');

try {
    // ========================================
    // PHASE 1: SPAM User Detection & Removal
    // ========================================
    
    $spamUsers = [];
    $spamCriteria = [];
    
    // Criteria 1: Legacy data anomalies (1969 dates with no activity)
    $query = $db->query("
        SELECT u.id, u.username, u.email, u.join_date, u.last_login, u.email_verified,
               (SELECT COUNT(*) FROM cars c WHERE c.user_id = u.id) as car_count
        FROM users u
        WHERE u.join_date < '1980-01-01' 
        AND u.last_login = '0000-00-00 00:00:00'
        AND u.email_verified = 0
        AND u.username NOT IN ('admin', 'noowner')
        AND (SELECT COUNT(*) FROM cars c WHERE c.user_id = u.id) = 0
        ORDER BY u.join_date
        LIMIT " . $MAX_DELETIONS_PER_RUN
    );
    
    if($query->count() > 0) {
        $legacySpamUsers = $query->results();
        foreach($legacySpamUsers as $user) {
            $spamUsers[] = $user;
            $spamCriteria[$user->id] = 'Legacy data anomaly (1969 date, never active, no cars)';
        }
    }
    
    // Criteria 2: Never logged in + unverified + no profile completion
    $query = $db->query("
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
        LIMIT " . ($MAX_DELETIONS_PER_RUN - count($spamUsers))
    );
    
    if($query->count() > 0) {
        $suspiciousUsers = $query->results();
        foreach($suspiciousUsers as $user) {
            $spamUsers[] = $user;
            $spamCriteria[$user->id] = 'Suspicious pattern (never active, unverified, no profile)';
        }
    }
    
    // Process SPAM users
    $spamCount = count($spamUsers);
    if($spamCount > 0) {
        logger($user_id, 'SpamCleanup', "Identified $spamCount potential SPAM accounts");
        
        if($DRY_RUN) {
            logger($user_id, 'SpamCleanup', 'DRY RUN: Would delete the following SPAM users:');
            foreach($spamUsers as $user) {
                logger($user_id, 'SpamCleanup', "DRY RUN: User ID {$user->id} ({$user->username}) - {$spamCriteria[$user->id]}");
            }
        } else {
            // Extract user IDs for deletion
            $spamUserIds = array_column($spamUsers, 'id');
            
            // Use UserSpice's built-in deleteUsers function
            $deletedCount = deleteUsers($spamUserIds);
            
            logger($user_id, 'SpamCleanup', "Successfully deleted $deletedCount SPAM accounts");
            
            // Log details of deleted users
            foreach($spamUsers as $user) {
                logger($user->id, 'SpamDeletion', "SPAM account deleted: {$spamCriteria[$user->id]}");
            }
        }
    } else {
        logger($user_id, 'SpamCleanup', 'No SPAM accounts identified for removal');
    }
    
    // Log SPAM detection summary for debugging
    logger($user_id, 'SpamCleanup', "SPAM detection completed - Found " . count($spamUsers) . " SPAM accounts");
    
    // ========================================
    // PHASE 2: Inactive User Detection
    // ========================================
    
    $inactiveUsers = [];
    $graceUsers = [];
    
    // Build exclusion list for users already processed as SPAM
    $spamUserIds = array_column($spamUsers, 'id');
    $spamExclusion = '';
    if(count($spamUserIds) > 0) {
        $spamExclusion = " AND u.id NOT IN (" . implode(',', $spamUserIds) . ")";
    }
    
    // Find users inactive for 30+ days with no cars (excluding SPAM users)
    $query = $db->query("
        SELECT u.id, u.username, u.email, u.join_date, u.last_login, u.email_verified,
               (SELECT COUNT(*) FROM cars c WHERE c.user_id = u.id) as car_count,
               DATEDIFF(NOW(), u.join_date) as days_since_join,
               DATEDIFF(NOW(), IF(u.last_login = '0000-00-00 00:00:00', u.join_date, u.last_login)) as days_since_activity
        FROM users u
        WHERE DATEDIFF(NOW(), u.join_date) > " . $INACTIVE_DAYS . "
        AND u.username NOT IN ('admin', 'noowner')
        AND u.protected = 0
        AND (SELECT COUNT(*) FROM cars c WHERE c.user_id = u.id) = 0
        AND (
            u.last_login = '0000-00-00 00:00:00'
            OR DATEDIFF(NOW(), u.last_login) > 90
            OR (u.email_verified = 0 AND DATEDIFF(NOW(), u.join_date) > " . ($INACTIVE_DAYS + $GRACE_PERIOD_DAYS) . ")
        )" . $spamExclusion . "
        ORDER BY u.join_date
        LIMIT " . $MAX_DELETIONS_PER_RUN
    );
    
    if($query->count() > 0) {
        $candidateUsers = $query->results();
        
        foreach($candidateUsers as $user) {
            // Check if user is in grace period (has been notified)
            $graceQuery = $db->query("
                SELECT * FROM logs 
                WHERE user = ? 
                AND category = 'InactiveUserNotification' 
                AND logdate > DATE_SUB(NOW(), INTERVAL ? DAY)
                ORDER BY logdate DESC 
                LIMIT 1
            ", [$user->id, $GRACE_PERIOD_DAYS]);
            
            if($graceQuery->count() > 0) {
                // User is in grace period, check if grace period has expired
                $notificationDate = $graceQuery->first()->logdate;
                $daysSinceNotification = (time() - strtotime($notificationDate)) / (60 * 60 * 24);
                
                if($daysSinceNotification >= $GRACE_PERIOD_DAYS) {
                    $inactiveUsers[] = $user;
                }
            } else {
                // User needs to be notified for grace period
                $graceUsers[] = $user;
            }
        }
    }
    
    // Process grace period notifications
    $graceCount = count($graceUsers);
    if($graceCount > 0) {
        logger($user_id, 'InactiveCleanup', "Identified $graceCount users for grace period notification");
        
        if($DRY_RUN) {
            $emailStatus = $ENABLE_EMAIL_NOTIFICATIONS ? 'would send emails' : 'would log only (emails disabled)';
            logger($user_id, 'InactiveCleanup', "DRY RUN: Would process grace period notifications ($emailStatus):");
            foreach($graceUsers as $user) {
                if($ENABLE_EMAIL_NOTIFICATIONS) {
                    logger($user_id, 'InactiveCleanup', "DRY RUN: Would email User ID {$user->id} ({$user->username}, {$user->email})");
                } else {
                    logger($user_id, 'InactiveCleanup', "DRY RUN: Would log User ID {$user->id} ({$user->username}, {$user->email}) - no email (disabled)");
                }
            }
        } else {
            // Send grace period emails using UserSpice email system
            foreach($graceUsers as $user) {
                if ($ENABLE_EMAIL_NOTIFICATIONS) {
                    $email_subject = "Lotus Elan Registry - Account Inactive Notice";
                    $email_html = generateGracePeriodEmailHTML($user->username, $GRACE_PERIOD_DAYS);
                    $email_text = generateGracePeriodEmailText($user->username, $GRACE_PERIOD_DAYS);
                    
                    // Use UserSpice email function with HTML support
                    if (function_exists('email')) {
                        // Try to send HTML email first, fallback to plain text
                        $emailSent = false;
                        
                        // Check if UserSpice supports HTML emails (newer versions)
                        if (function_exists('emailHTML')) {
                            // Use HTML email function if available
                            $emailSent = emailHTML($user->email, $email_subject, $email_html, $email_text);
                        } else {
                            // Use standard email function with HTML content
                            $emailSent = email($user->email, $email_subject, $email_html);
                            
                            // If HTML fails, try plain text fallback
                            if (!$emailSent) {
                                $emailSent = email($user->email, $email_subject, $email_text);
                            }
                        }
                        
                        if ($emailSent) {
                            logger($user->id, 'InactiveUserNotification', "Grace period HTML email sent to {$user->email} - account will be deleted in $GRACE_PERIOD_DAYS days if no cars are registered");
                        } else {
                            logger($user->id, 'InactiveUserNotificationError', "Failed to send grace period email to {$user->email}");
                        }
                    } else {
                        logger($user->id, 'InactiveUserNotification', "Grace period notification logged (email function unavailable) - account will be deleted in $GRACE_PERIOD_DAYS days if no cars are registered");
                    }
                } else {
                    logger($user->id, 'InactiveUserNotification', "Grace period notification logged (email disabled) - account will be deleted in $GRACE_PERIOD_DAYS days if no cars are registered");
                }
            }
            logger($user_id, 'InactiveCleanup', "Processed grace period notifications for $graceCount inactive users");
        }
    }
    
    // Process inactive users (grace period expired)
    $inactiveCount = count($inactiveUsers);
    if($inactiveCount > 0) {
        logger($user_id, 'InactiveCleanup', "Identified $inactiveCount inactive users for deletion (grace period expired)");
        
        if($DRY_RUN) {
            logger($user_id, 'InactiveCleanup', 'DRY RUN: Would delete the following inactive users:');
            foreach($inactiveUsers as $user) {
                logger($user_id, 'InactiveCleanup', "DRY RUN: User ID {$user->id} ({$user->username}) - {$user->days_since_join} days old, {$user->days_since_activity} days inactive");
            }
        } else {
            // Extract user IDs for deletion
            $inactiveUserIds = array_column($inactiveUsers, 'id');
            
            // Use UserSpice's built-in deleteUsers function
            $deletedCount = deleteUsers($inactiveUserIds);
            
            logger($user_id, 'InactiveCleanup', "Successfully deleted $deletedCount inactive accounts");
            
            // Log details of deleted users
            foreach($inactiveUsers as $user) {
                logger($user->id, 'InactiveDeletion', "Inactive account deleted after grace period - {$user->days_since_join} days old, no cars registered");
            }
        }
    } else {
        logger($user_id, 'InactiveCleanup', 'No inactive accounts ready for deletion');
    }
    
    // ========================================
    // SAFETY CHECK & CLEANUP SUMMARY
    // ========================================
    
    $totalProcessed = $spamCount + $graceCount + $inactiveCount;
    $totalDeletions = $spamCount + $inactiveCount;
    $cleanupPercentage = ($totalDeletions / $totalUsers) * 100;
    $mode = $DRY_RUN ? 'DRY RUN' : 'LIVE';
    
    // Final safety check
    if (!$DRY_RUN && $cleanupPercentage > $MAX_PERCENTAGE_CLEANUP) {
        $warningMsg = "SAFETY ABORT: Cleanup would affect {$cleanupPercentage}% of users (limit: {$MAX_PERCENTAGE_CLEANUP}%). Aborting for safety.";
        logger($user_id, 'SpamCleanupError', $warningMsg);
        throw new AdminOperationException($warningMsg);
    }

    logger($user_id, 'SpamCleanup', "Cleanup complete ($mode) - SPAM: $spamCount, Grace notifications: $graceCount, Inactive deletions: $inactiveCount, Total processed: $totalProcessed, Impact: " . round($cleanupPercentage, 2) . "% of users");

} catch (AdminOperationException $e) {
    $errorMsg = "Error during cleanup process: " . $e->getMessage();
    logger($user_id, 'SpamCleanupError', $errorMsg);
    $errors[] = $errorMsg;
} catch (\Throwable $e) {
    $errorMsg = "Unexpected error during cleanup: " . $e->getMessage();
    logger($user_id, 'SpamCleanupError', $errorMsg);
    $errors[] = $errorMsg;
}

// UserSpice cron system logging
$from = Input::get('from');
if($from != NULL && $currentPage == $filename) {
    $query = $db->query("SELECT id,name FROM crons WHERE file = ?", array($filename));
    $results = $query->first();
    $cronfields = array(
        'cron_id' => $results->id,
        'datetime' => date("Y-m-d H:i:s"),
        'user_id' => $user_id
    );
    $db->insert('crons_logs', $cronfields);
    Redirect::to('/' . $from);
}
?>