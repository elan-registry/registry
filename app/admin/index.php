<?php
declare(strict_types=1);

use ElanRegistry\AppConstants;
use ElanRegistry\Car\Car;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationEmailComposer;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\CarVerificationSendService;
use ElanRegistry\Car\SendResult;
use ElanRegistry\Car\VerificationSettings;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarDeletionException;
use ElanRegistry\Exceptions\CarMergeException;
use ElanRegistry\Exceptions\CarNotFoundException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\Exceptions\ElanRegistryException;
use ElanRegistry\Exceptions\OwnerDatabaseException;
use ElanRegistry\Input as ElanInput;
use ElanRegistry\LogCategories;
use ElanRegistry\Owner;
use ElanRegistry\Transfer\CarTransferRepository;

/**
 * index.php
 * Consolidated Management Interface
 *
 * Unified administrative interface with tabbed structure:
 *
 * TAB 1: Car/Owner Relationships - Car reassignment, deletion, and ownership transfers
 * TAB 2: Manage Cars - Individual car management and bulk operations
 * TAB 3: Manage Owners - User profile management and owner data administration
 *
 * @author Elan Registry Development Team
 * @copyright 2025
 */

// Tab routing - determine which tab to show
$validTabs = [
    'car-mgmt' => 'Car/Owner Relationships',
    'manage-cars' => 'Manage Cars',
    'owner-mgmt' => 'Manage Owners',
    'account-cleanup' => 'Account Cleanup',
    'verification' => 'Verification System',
];

$activeTab = isset($_GET['tab']) && is_string($_GET['tab']) && array_key_exists($_GET['tab'], $validTabs)
    ? $_GET['tab'] : 'car-mgmt';
$pageTitle = 'Registry Management - ' . $validTabs[$activeTab];
$pageDescription = 'Admin tools for managing car and owner relationships in the Lotus Elan Registry.';

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

// Security check
if (!securePage($php_self)) {
    die();
}

// Initialize database connection
$db = DB::getInstance();

// Check for pending Phinx migrations by querying phinxlog directly.
// database/ is removed by .deployignore after deployment, so glob() always
// returns empty on prod. Instead, count applied migrations up to the latest
// known version and compare against the total. Update both constants when
// adding a new migration.
$pendingMigrationCount = 0;
try {
    $latestMigration = 20260915000002;
    $totalMigrations = 32;
    $row = $db->query(
        "SELECT COUNT(*) AS cnt FROM phinxlog WHERE version <= ?",
        [$latestMigration]
    )->first();
    $appliedCount = is_object($row) ? (int)($row->cnt ?? 0) : 0;
    $pendingMigrationCount = $totalMigrations - $appliedCount;
} catch (\Throwable $e) {
    // Non-critical banner — degrade silently but log so infrastructure problems are discoverable.
    logger(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Migration banner check failed: ' . $e->getMessage());
}

// Verification readiness banner. Only surfaces when the feature switch is ON:
// an unconfigured Brevo means verification email has nowhere to go (danger),
// a stalled cron only delays reminders (warning).
$verificationBannerSeverity = null; // null | 'warning' | 'danger'
try {
    $verificationSettings = new VerificationSettings(dbi());
    if ($verificationSettings->isEnabled()) {
        if (!$verificationSettings->brevoReady()) {
            $verificationBannerSeverity = 'danger';
        } elseif (!$verificationSettings->cronReady()) {
            $verificationBannerSeverity = 'warning';
        }
    }
} catch (\Throwable $e) {
    // Non-critical banner — degrade silently but log so infrastructure problems are discoverable.
    logger(0, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING,
        'Verification banner check failed: ' . $e->getMessage());
}

// Abort immediately if no authenticated session exists.
// securePage() above handles access control; this guard ensures the audit trail
// always has a real, authenticated user ID — never a fabricated fallback.
try {
    $currentUserId = currentUserId();
} catch (RuntimeException $e) {
    logger(0, LogCategories::LOG_CATEGORY_SECURITY,
        "Admin page accessed with invalid user session on $php_self");
    Redirect::to($us_url_root . 'users/login.php');
    die();
}

// Generate CSRF token for forms
$csrfToken = Token::generate();

// Get system status for header — defaults guard against DB unavailability
$systemStatus = [
    'total_cars'        => 0,
    'total_users'       => 0,
    'last_updated'      => date('Y-m-d H:i:s'),
    'pending_transfers' => 0,
    'quality_issues'    => 0,
];

try {
    $systemStatus = getAdminSystemStatus(dbi()) + [
        'pending_transfers' => 0,
        'quality_issues'    => 0,
    ];

    $systemStatus['pending_transfers'] = (new CarTransferRepository(dbi()))->countPending();

    // Calculate quality issues separated by type using basic counts
    $carIssues = 0;
    $ownerIssues = 0;

    // Car-specific critical issues only (for tab badge)
    // Count missing chassis numbers (critical)
    $missingChassisStmt = $db->query("SELECT COUNT(*) as count FROM cars WHERE chassis IS NULL OR chassis = ''");
    $missingChassisResult = $missingChassisStmt->first();
    $carIssues += $missingChassisResult ? (int)$missingChassisResult->count : 0;

    // Count invalid model data (critical)
    $invalidModelStmt = $db->query("SELECT COUNT(*) as count FROM cars WHERE model = '||' OR model LIKE '%test%' OR model LIKE '%placeholder%'");
    $invalidModelResult = $invalidModelStmt->first();
    $carIssues += $invalidModelResult ? (int)$invalidModelResult->count : 0;

    // Count cars with multiple critical missing fields (critical)
    $multipleMissingStmt = $db->query("
        SELECT COUNT(*) as count FROM cars
        WHERE (CASE WHEN series IS NULL OR series = '' THEN 1 ELSE 0 END) +
              (CASE WHEN chassis IS NULL OR chassis = '' THEN 1 ELSE 0 END) +
              (CASE WHEN model = '||' THEN 1 ELSE 0 END) >= 2
    ");
    $multipleMissingResult = $multipleMissingStmt->first();
    $carIssues += $multipleMissingResult ? (int)$multipleMissingResult->count : 0;

    // Note: Missing series alone is informational, invalid chassis is warning - not included in critical count

    // Owner-specific issues
    // Count owners missing critical information
    $ownersStmt = $db->query("
        SELECT COUNT(DISTINCT u.id) as count
        FROM users u
        JOIN cars c ON u.id = c.user_id
        LEFT JOIN profiles p ON u.id = p.user_id
        WHERE u.active = 1 AND (
            (u.fname IS NULL OR u.fname = '') OR
            (u.lname IS NULL OR u.lname = '') OR
            (p.city IS NULL OR p.city = '') OR
            (p.lat IS NULL OR p.lon IS NULL)
        )
    ");
    $ownersResult = $ownersStmt->first();
    $ownerIssues += $ownersResult ? (int)$ownersResult->count : 0;

    // Count duplicate emails
    $duplicateEmailsStmt = $db->query("
        SELECT COUNT(*) as count FROM (
            SELECT email FROM users WHERE active = 1 GROUP BY email HAVING COUNT(*) > 1
        ) as duplicates
    ");
    $duplicateEmailsResult = $duplicateEmailsStmt->first();
    $duplicateEmailCount = $duplicateEmailsResult ? (int)$duplicateEmailsResult->count : 0;
    $ownerIssues += $duplicateEmailCount;

    // Store separated counts
    $systemStatus['car_issues'] = $carIssues;
    $systemStatus['owner_issues'] = $ownerIssues;
    $systemStatus['quality_issues'] = $carIssues + $ownerIssues;

} catch (\Throwable $e) {
    // Fail silently for header stats - main functionality should still work
    logger($currentUserId, LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
           "Database or runtime error getting system status: " . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Verification send services. Constructed once here so both the POST-handling
// switch below and the Verification System tab's GET-time eligible-car preview
// (includes/tab-verification.php, included in this same scope) share one set of
// collaborators rather than each building their own.
// ---------------------------------------------------------------------------
$verificationRepo    = new CarRepository(dbi());
$verificationManager = new CarVerificationManager($verificationRepo);
$verificationSendSvc = new CarVerificationSendService(
    $verificationRepo,
    $verificationManager,
    new CarVerificationEmailComposer()
);

if (!function_exists('eligibilitySkipReason')) {
    /**
     * Explain why an already-loaded car row is no longer due a verification email.
     *
     * This encodes the SAME rules as
     * {@see CarRepository::findVerificationEligible()}'s WHERE clause — it is
     * not a second, independent definition of eligibility and must never be
     * allowed to become one. If that SQL changes, this changes with it.
     *
     * It exists only because the preview (GET) and the send (POST) are two
     * separate requests, and a car can leave the eligible set in between: the
     * owner verifies or edits it, a bounce webhook fires, they opt out, or the
     * car is marked sold. Re-running the query would tell us the row is gone
     * from the result set but not WHY — a set-based answer cannot explain one
     * specific row — and the admin report needs a per-car reason. So the check
     * is applied in PHP against the row already loaded by findById(), with no
     * second query.
     *
     * The owner-liveness clauses of the SQL (INNER JOIN users, the `noowner`
     * exclusion) are deliberately NOT duplicated here: they need a join this
     * function has no row for. They stay enforced downstream —
     * {@see CarVerificationSendService::sendOne()} loads the owner and returns
     * a failure result when the users row is gone, which lands in the report's
     * Failed section rather than Skipped.
     *
     * @param object $carData Car row as loaded by CarRepository::findById()
     * @return string|null Short reason the car is no longer eligible, or null if it still is
     * @throws CarValidationException If the row carries a malformed timestamp (via isFresh())
     */
    function eligibilitySkipReason(object $carData): ?string
    {
        // cars.solddate IS NULL
        if (!empty($carData->solddate)) {
            return 'Marked sold';
        }

        // cars.email_bounced = 0
        if (!empty($carData->email_bounced)) {
            return 'Email bounced';
        }

        // cars.email_suppressed = 0
        if (!empty($carData->email_suppressed)) {
            return 'Email suppressed';
        }

        // cars.email IS NOT NULL AND cars.email != ''
        if (trim((string) ($carData->email ?? '')) === '') {
            return 'No email on file';
        }

        // cars.user_id IS NOT NULL
        if ((int) ($carData->user_id ?? 0) <= 0) {
            return 'No owner on file';
        }

        // NOT freshnessSql('cars') — the PHP counterpart of the same rule, so
        // the staleness definition is not re-derived by hand here.
        if (CarRepository::isFresh(
            $carData->last_verified ?? null,
            (string) ($carData->owner_last_updated ?? '')
        )) {
            return 'Recently verified or updated';
        }

        // Mirrors findVerificationEligible()'s attempt-cap clause: 2 sends
        // per rolling 12-month window, then the car waits out the rest of
        // the year. Kept in sync with that SQL and with
        // incrementVerificationAttempts()'s own reset logic.
        //
        // strtotime() failure is NOT treated as "outside the window": PHP's
        // strtotime() returns false on a malformed/unparseable value, and
        // `false > strtotime('-1 year')` evaluates to false — silently
        // treating a corrupt timestamp as "not within the window" would
        // bypass the attempt cap entirely for that car on every batch.
        // Throwing here routes into the caller's EligibilityCheckFailed
        // catch, which skips the car rather than risk over-sending.
        $attemptsSince = $carData->verification_attempts_since ?? null;
        if ($attemptsSince !== null) {
            $sinceTs = strtotime((string) $attemptsSince);
            if ($sinceTs === false) {
                throw new CarValidationException(
                    'eligibilitySkipReason: malformed verification_attempts_since '
                    . var_export($attemptsSince, true) . ' on car ' . (int) ($carData->id ?? 0)
                );
            }
            if ($sinceTs > strtotime('-1 year') && (int) ($carData->verification_attempts ?? 0) >= 2) {
                return 'Attempt cap reached for this year';
            }
        }

        return null;
    }
}

if (!function_exists('verifyHistoryFieldsForAdminAction')) {
    /**
     * Build a cars_hist snapshot row for an admin bounce/suppression action.
     *
     * The admin-side counterpart of verify_car.php's verifyHistoryFields(),
     * kept deliberately identical in shape (full column snapshot plus
     * `operation`) so one query on `operation` can separate these admin
     * actions from ordinary edits and from owner self-verification.
     *
     * NO $soldDate PARAMETER. None of these actions sets a sale date, so there
     * is no new value to record: the caller's snapshot carries the car's
     * current `solddate` through unchanged and the audit row reflects the state
     * as it stood, not a fresh value.
     *
     * @param object $carData   PRE-CHANGE car snapshot returned by the CarVerificationManager
     * @param string $operation 'EMAIL BOUNCED', 'EMAIL BOUNCE CLEARED' or 'EMAIL SUPPRESSION CLEARED'
     * @param string $comments  Free-text audit note
     * @return array<string, mixed> Field map for CarRepository::insertHistory()
     * @throws OwnerDatabaseException If the car's owner cannot be loaded
     */
    function verifyHistoryFieldsForAdminAction(object $carData, string $operation, string $comments): array
    {
        $owner = (new Owner((int) $carData->user_id))->data();

        // Owner::data() is nullable when find() reports "not found" rather than
        // a DB fault. A caller must not silently write a half-populated audit
        // row if the owner vanished between the action and this snapshot. Fail
        // loudly and let the caller's transaction roll the car mutation back
        // too.
        if ($owner === null) {
            throw new OwnerDatabaseException(
                'admin/index.php: owner ' . (int) $carData->user_id
                . " could not be loaded while building the {$operation} history snapshot for car "
                . (int) $carData->id
            );
        }

        return [
            'operation'             => $operation,
            'car_id'                => (int) $carData->id,
            'comments'              => $comments,
            'ctime'                 => $carData->ctime ?? date(AppConstants::DATETIME_FORMAT),
            'mtime'                 => date(AppConstants::DATETIME_FORMAT),
            'model'                 => $carData->model ?? '',
            'series'                => $carData->series ?? '',
            'variant'               => $carData->variant ?? '',
            'year'                  => $carData->year ?? '',
            'type'                  => $carData->type ?? '',
            'chassis'               => $carData->chassis ?? '',
            'color'                 => $carData->color ?? '',
            'engine'                => $carData->engine ?? '',
            'purchasedate'          => $carData->purchasedate ?? null,
            'solddate'              => $carData->solddate ?? null,
            'email_bounced'         => $carData->email_bounced ?? 0,
            'email_bounced_address' => $carData->email_bounced_address ?? null,
            'email_suppressed'      => $carData->email_suppressed ?? 0,
            // Added by migration 20260915000000, which also rebuilt the
            // cars_update trigger to carry these two columns — this
            // explicit snapshot must match, or every admin-action audit row
            // written here reads attempts=0/since=NULL regardless of the
            // car's actual state.
            'verification_attempts'       => $carData->verification_attempts ?? 0,
            'verification_attempts_since' => $carData->verification_attempts_since ?? null,
            'image'                 => $carData->image ?? '',
            'user_id'               => (int) $carData->user_id,
            'email'                 => $carData->email ?? ($owner->email ?? ''),
            'fname'                 => $owner->fname ?? '',
            'lname'                 => $owner->lname ?? '',
            'join_date'             => $owner->join_date ?? null,
            'city'                  => $owner->city ?? '',
            'state'                 => $owner->state ?? '',
            'country'               => $owner->country ?? '',
            'lat'                   => $owner->lat ?? null,
            'lon'                   => $owner->lon ?? null,
            'website'               => $owner->website ?? '',
        ];
    }
}

if (!function_exists('sendEmailOwnerLabel')) {
    /**
     * Human label for an owner, for flash messages.
     *
     * @param object|null $ownerData Owner::data() result
     * @param int $ownerId Fallback identifier when no owner row loaded
     */
    function sendEmailOwnerLabel(?object $ownerData, int $ownerId): string
    {
        if ($ownerData === null) {
            return "owner #{$ownerId}";
        }

        $name = trim(($ownerData->fname ?? '') . ' ' . ($ownerData->lname ?? ''));

        return $name !== '' ? $name : (string) ($ownerData->email ?? "owner #{$ownerId}");
    }
}

// Process form submissions for car management tab
$errors = [];
$successes = [];

// Verification batch-send report. Always defined so tab-verification.php can
// reference them whether or not a send_batch POST just ran. The report's
// four-way sent/unrecorded/skipped/failed shape cannot be expressed as a flat
// error/success list, so it travels in its own variables; $successes carries
// only the one-line summary. "Unrecorded" (SendResult::sentUnrecorded()) is
// its own bucket rather than folded into "sent": the email really was
// delivered, but the bookkeeping that prevents a duplicate send afterward
// failed, and that must stay visible to the admin rather than reading
// identically to a clean send.
$sendBatchJustRan = false;
/** @var array<int, object> */
$sendReportSent = [];
/** @var array<int, array{car: object, reason: string}> */
$sendReportUnrecorded = [];
/** @var array<int, array{car: object, reason: string}> */
$sendReportSkipped = [];
/** @var array<int, array{car: object, reason: string}> */
$sendReportFailed = [];

if (ElanInput::existsPost()) {
    $token = ElanInput::get('csrf');
    if (!Token::check($token)) {
        include $abs_us_root . $us_url_root . 'usersc/scripts/token_error.php';
    } else {
        $command = ElanInput::get('command');

        if ($command) {
            switch ($command) {
                // Car reassignment
                case "reassign":
                    $noOwner = (bool) ElanInput::get('no_owner');
                    $car_id  = (int) ElanInput::get('car_id');

                    if (!$car_id) {
                        $errors[] = 'Please provide a valid car ID';
                        break;
                    }

                    if ($noOwner) {
                        if ((int) ElanInput::get('user_id')) {
                            $errors[] = 'Please choose either a specific new owner or "Assign to No Owner", not both.';
                            break;
                        }

                        $noOwnerUser = new User();
                        $noOwnerFound = $noOwnerUser->find('noowner');
                        if (!$noOwnerFound) {
                            $errors[] = 'Unable to reassign: the "No Owner" system account could not be found. Contact an administrator.';
                            logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_TRANSFER_ERROR, "No Owner reassignment failed for Car ID $car_id — noowner account not found");
                            break;
                        }
                        $user_id = (int) $noOwnerUser->data()->id;
                    } else {
                        $user_id = (int) ElanInput::get('user_id');
                    }

                    if (!$user_id) {
                        $errors[] = 'Please provide a valid user ID, or choose "Assign to No Owner"';
                        break;
                    }

                    try {
                        $car = new Car((int)$car_id);
                        $targetUser = (new Owner($user_id))->data();
                        $targetName = $targetUser && $targetUser->fname && $targetUser->lname
                            ? "{$targetUser->fname} {$targetUser->lname}"
                            : "User ID $user_id";

                        $reason = "Car was reassigned to $targetName (User ID: $user_id) by admin " . $currentUserId;
                        $car->transfer((int) $user_id, $reason, 'NEWOWNER', $currentUserId);

                        $successes[] = "Car ID $car_id successfully reassigned to $targetName";
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_ACTIONS, "Car ID $car_id reassigned to User ID $user_id");
                    } catch (CarNotFoundException $e) {
                        $errors[] = "Car ID $car_id could not be found.";
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_TRANSFER_ERROR, "Car reassignment failed — car not found. " . $e->getMessage());
                    } catch (CarValidationException $e) {
                        $errors[] = $e->getUserMessage();
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_TRANSFER_ERROR, "Car reassignment failed — validation error: " . $e->getMessage());
                    } catch (CarDatabaseException $e) {
                        $errors[] = 'Transfer failed due to a database error. Check the admin log for details.';
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_TRANSFER_ERROR, "Car reassignment failed — DB error: " . $e->getMessage());
                    } catch (\Throwable $e) {
                        $errors[] = 'Transfer failed due to an unexpected error. Check the admin log for details.';
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_TRANSFER_ERROR, "Car reassignment unexpected error [" . get_class($e) . "]: " . $e->getMessage());
                    }
                    break;

                // Car merge
                case "merge":
                    // Validate input
                    $cars = ElanInput::get('cars');
                    $reason = ElanInput::get('reason');
                    if (!$cars || !$reason) {
                        $errors[] = 'Select 2 cars to merge and a reason';
                        break;
                    }

                    if (count($cars) !== 2) {
                        $errors[] = 'Select 2 cars to merge';
                        break;
                    }

                    $car1 = (int) $cars[0];
                    $car2 = (int) $cars[1];
                    if ($car1 <= 0 || $car2 <= 0) {
                        $errors[] = 'Car IDs must be positive integers';
                        break;
                    }
                    if ($car1 === $car2) {
                        $errors[] = 'Cannot merge a car with itself';
                        break;
                    }

                    if (count($reason) !== 1) {
                        $errors[] = 'Select 1 reason code';
                        break;
                    }

                    // Assign old_car_id / new_car_id and build the audit comment based on reason code
                    $mergeComment = '';
                    $old_car_id = 0;
                    $new_car_id = 0;
                    switch ($reason[0]) {
                        case "duplicate":
                            // Newer (higher-ID) car is kept as canonical
                            [$old_car_id, $new_car_id] = $car1 > $car2 ? [$car2, $car1] : [$car1, $car2];
                            $mergeComment = "Car $old_car_id is a duplicate of $new_car_id.  The history of $old_car_id has been merged with $new_car_id and $old_car_id deleted.";
                            break;

                        case "newownerNewToOld":
                            // Newer (higher-ID) car is kept as canonical
                            [$old_car_id, $new_car_id] = $car1 > $car2 ? [$car2, $car1] : [$car1, $car2];
                            $mergeComment = "Car $old_car_id was sold to a new owner and the new owner created a record for the same car as $new_car_id. The history of $old_car_id has been merged with $new_car_id and $old_car_id deleted.";
                            break;

                        case "newownerOldToNew":
                            // Older (lower-ID) car is kept as canonical
                            [$old_car_id, $new_car_id] = $car1 > $car2 ? [$car1, $car2] : [$car2, $car1];
                            $mergeComment = "Car $old_car_id was sold to a new owner and the new owner created a record for the same car as $new_car_id. The history of $old_car_id has been merged with $new_car_id and $old_car_id deleted.";
                            break;

                        default:
                            $errors[] = 'Invalid merge reason code.';
                            break;
                    }

                    if (!empty($errors)) {
                        break;
                    }

                    try {
                        (new Car($new_car_id))->merge($old_car_id, $reason[0], $currentUserId);
                        $successes[] = $mergeComment;
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, $mergeComment);
                    } catch (CarNotFoundException $e) {
                        $errors[] = 'Car merge failed: one or both cars could not be found. Check the admin log for details.';
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_MERGE,
                            "FAILED: Car merge aborted — car not found. " . $e->getMessage());
                    } catch (CarMergeException | CarDatabaseException $e) {
                        $errors[] = 'Car merge failed and was rolled back. Check the admin log for details.';
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_MERGE,
                            "FAILED: Car merge rolled back. " . $e->getMessage());
                    } catch (\Throwable $e) {
                        $errors[] = 'Car merge failed due to an unexpected error and was rolled back. Check the admin log for details.';
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_MERGE,
                            "FAILED: Car merge unexpected error. " . get_class($e) . ': ' . $e->getMessage());
                    }
                    break;

                // Car deletion
                case "delete":
                    $car_id = (int) ElanInput::get('car_id');
                    $confirmation = ElanInput::get('confirmation');
                    $reason = mb_substr(ElanInput::raw('reason') ?: 'Administrative deletion', 0, 500);

                    if (!$car_id) {
                        $errors[] = 'Please provide a valid car ID';
                        break;
                    }

                    if ($confirmation !== 'DELETE') {
                        $errors[] = 'Please type DELETE in the confirmation field to proceed';
                        break;
                    }

                    try {
                        $car = new Car($car_id);
                        if (!$car->exists()) {
                            $errors[] = "Car ID $car_id not found";
                            break;
                        }
                        $chassis = $car->data()->chassis;
                        $car->delete($reason, $currentUserId);
                        $successes[] = "Car ID $car_id ($chassis) has been permanently deleted";
                    } catch (CarNotFoundException $e) {
                        // Race: car deleted between the exists() check and delete().
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_DELETION,
                            "Car ID $car_id not found during deletion attempt");
                        $errors[] = "Car ID $car_id not found";
                    } catch (CarDeletionException | CarDatabaseException $e) {
                        // CarAdministrationService::delete() logs DB failures; CSRF is validated
                        // above (line ~176) before this switch is reached.
                        $errors[] = "Failed to delete car. Check the system log for details.";
                    } catch (\Throwable $e) {
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_DELETION,
                            "Unexpected error deleting car ID $car_id: " . get_class($e) . ': ' . $e->getMessage());
                        $errors[] = "An unexpected error occurred. Check the system log for details.";
                    }
                    break;

                // Verification batch send.
                //
                // NOTHING IS SENT ON A GET. The Verification System tab renders a
                // read-only preview; email leaves the building only when an admin
                // submits this command.
                //
                // RESIDUAL DOUBLE-SUBMISSION RISK, accepted.
                // findVerificationEligible() orders by last_verified ASC and does
                // not exclude a car merely because its vericode_sent_at is very
                // recent, so re-POSTing the same batch within one request window
                // would pass the re-check below again and mail the same owners
                // twice. Accepted under this tool's manual, single-operator,
                // low-frequency trust model — the same acceptance made for
                // 26-Reconcile-Owner-Fields.php's Execute step.
                case "verification_send_batch":
                    // Admin-only, matching tab-verification.php's own
                    // "read-only for editors" docblock and its
                    // $vsCanToggle = hasPerm([2], ...) gate on the Feature
                    // Switch. securePage() alone admits editors to this page;
                    // this check is what keeps the send/bounce actions
                    // themselves admin-only.
                    if (!hasPerm([2], $currentUserId)) {
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
                            "Verification: non-admin attempted command '{$command}'");
                        $errors[] = 'Administrator access is required for this action.';
                        break;
                    }

                    $sendBatchJustRan = true;

                    // \Input::get() sanitizes arrays recursively (see
                    // users/classes/Input.php::sanitize()), the same way the
                    // "merge" case above relies on for its `cars` field. Values
                    // are cast to int below regardless.
                    /** @var array<int, mixed> $submittedIds */
                    $submittedIds = (array) ElanInput::get('car_ids', []);

                    foreach ($submittedIds as $submittedId) {
                        $sendCarId = (int) $submittedId;

                        if ($sendCarId <= 0) {
                            logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
                                'Verification send: non-positive car id in car_ids[]: ' . var_export($submittedId, true));
                            $sendReportSkipped[] = [
                                'car'    => (object) ['id' => 0, 'chassis' => '', 'email' => ''],
                                'reason' => 'Invalid car reference',
                            ];
                            continue;
                        }

                        // findById() throws CarDatabaseException on a query
                        // failure — this must be inside the per-car guard, not
                        // called ahead of it, or a transient DB error mid-batch
                        // aborts the whole send and silently drops the report
                        // for every car already processed (and possibly
                        // already emailed) before it.
                        try {
                            $sendCarData = $verificationRepo->findById($sendCarId);
                        } catch (\Throwable $e) {
                            logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
                                'Verification send: findById threw for car %d [%s]: %s',
                                $sendCarId,
                                get_class($e),
                                $e->getMessage()
                            ));
                            $sendReportFailed[] = [
                                'car'    => (object) ['id' => $sendCarId, 'chassis' => '', 'email' => ''],
                                'reason' => 'The car record could not be read.',
                            ];
                            continue;
                        }

                        if ($sendCarData === null) {
                            logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
                                "Verification send: car {$sendCarId} was in the preview but no longer exists; skipped");
                            $sendReportSkipped[] = [
                                'car'    => (object) ['id' => $sendCarId, 'chassis' => '', 'email' => ''],
                                'reason' => 'Car no longer exists',
                            ];
                            continue;
                        }

                        // Re-check eligibility against the row as it stands NOW.
                        // The preview was rendered by an earlier request and the
                        // car may have left the eligible set since.
                        try {
                            $skipReason = eligibilitySkipReason($sendCarData);
                        } catch (ElanRegistryException $e) {
                            logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
                                "Verification send: eligibility re-check failed for car {$sendCarId}: " . $e->getMessage());
                            // Distinct from every other skip reason below: this one is a
                            // data-integrity fault (e.g. a corrupt verification_attempts_since
                            // value), not a routine ineligibility state like "Marked sold." The
                            // prefix keeps it visually distinguishable in the Skipped table so
                            // it doesn't read as ordinary and get lost among expected skips.
                            $skipReason = 'Data error — Eligibility could not be determined';
                        }

                        if ($skipReason !== null) {
                            $sendReportSkipped[] = ['car' => $sendCarData, 'reason' => $skipReason];
                            continue;
                        }

                        // Guarded per-car: sendOne() covers its own three
                        // internal scopes (vericode rotation, send, bookkeeping),
                        // but Owner::data() and the compose()/email() call sit
                        // between those scopes with no catch of their own. An
                        // uncaught throw here must not abort the whole batch —
                        // it would silently drop every car after it from the
                        // report, and if the throw lands after this car's
                        // vericode was already rotated, that car is left
                        // stranded with no restore attempted.
                        try {
                            $sendResult = $verificationSendSvc->sendOne($sendCarData);
                        } catch (\Throwable $e) {
                            logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
                                'Verification send: sendOne threw for car %d [%s]: %s',
                                $sendCarId,
                                get_class($e),
                                $e->getMessage()
                            ));
                            $sendResult = SendResult::failed($sendCarId, 'An unexpected error occurred during send.');
                        }

                        if ($sendResult->isUnrecorded()) {
                            // sentUnrecorded(): delivered, but the bookkeeping
                            // that prevents a duplicate send failed — keep
                            // this visibly distinct from a clean send.
                            $sendReportUnrecorded[] = [
                                'car'    => $sendCarData,
                                'reason' => $sendResult->reason,
                            ];
                        } elseif ($sendResult->status === SendResult::STATUS_SENT) {
                            $sendReportSent[] = $sendCarData;
                        } else {
                            $sendReportFailed[] = [
                                'car'    => $sendCarData,
                                'reason' => $sendResult->reason ?? 'Unknown failure',
                            ];
                        }
                    }

                    $successes[] = sprintf(
                        'Batch complete: %d sent, %d unrecorded, %d skipped, %d failed.',
                        count($sendReportSent),
                        count($sendReportUnrecorded),
                        count($sendReportSkipped),
                        count($sendReportFailed)
                    );
                    logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
                        'Verification send: batch complete — %d sent, %d unrecorded, %d skipped, %d failed',
                        count($sendReportSent),
                        count($sendReportUnrecorded),
                        count($sendReportSkipped),
                        count($sendReportFailed)
                    ));
                    break;

                // Owner-level deliverability actions.
                //
                // Each is owner-scoped (the flags are owner-level facts fanned out
                // to that owner's cars) and pairs every state change with a
                // cars_hist INSERT inside one transaction. Mark Bounced goes
                // through CarVerificationManager::setBouncedForOwner(), whose write
                // path is structurally free of `user_id` — a bounce records a
                // deliverability fact and never moves a car to another owner.
                case "mark_bounced":
                case "clear_bounced":
                case "clear_suppression":
                    // Admin-only — see the identical check on
                    // verification_send_batch above for rationale.
                    if (!hasPerm([2], $currentUserId)) {
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
                            "Verification: non-admin attempted command '{$command}'");
                        $errors[] = 'Administrator access is required for this action.';
                        break;
                    }

                    $verifyCarId = (int) ElanInput::get('car_id');

                    try {
                        $verifyCarData = $verifyCarId > 0 ? $verificationRepo->findById($verifyCarId) : null;
                    } catch (\Throwable $e) {
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
                            'Verification %s: findById threw for car %d [%s]: %s',
                            $command,
                            $verifyCarId,
                            get_class($e),
                            $e->getMessage()
                        ));
                        $errors[] = 'That car could not be read. Please try again.';
                        break;
                    }

                    if ($verifyCarData === null) {
                        $errors[] = 'That car could not be found. It may have been removed.';
                        break;
                    }

                    $verifyOwnerId = (int) $verifyCarData->user_id;

                    // Keyed on the literal case labels above. $command is
                    // `mixed` as far as static analysis is concerned, so a
                    // match() on it can never be proven exhaustive; a lookup
                    // keyed by the same three strings is equivalent and honest
                    // about the one impossible branch.
                    $verifyActionMap = [
                        'mark_bounced'      => ['EMAIL BOUNCED', 'marked as bounced'],
                        'clear_bounced'     => ['EMAIL BOUNCE CLEARED', 'cleared of the bounce flag'],
                        'clear_suppression' => ['EMAIL SUPPRESSION CLEARED', 'cleared of the suppression flag'],
                    ];
                    [$verifyOperation, $verifySuccessVerb] = $verifyActionMap[(string) $command];

                    $verificationRepo->beginTransaction();

                    try {
                        $verifyOwnerData = (new Owner($verifyOwnerId))->data();

                        if ($command === 'mark_bounced') {
                            // The address recorded is ALWAYS the owner's current
                            // users.email, never the car's denormalized cars.email.
                            // The two can differ (the car column drifts), and the
                            // automatic bounce-clearing path keyed off a later email
                            // confirmation compares against the address the owner
                            // confirms — recording anything else here means that
                            // clear never fires and the owner stays permanently
                            // un-emailable.
                            if ($verifyOwnerData === null || trim((string) ($verifyOwnerData->email ?? '')) === '') {
                                throw new CarValidationException(
                                    "Owner {$verifyOwnerId} has no usable email address to record a bounce against"
                                );
                            }

                            $changedCars = $verificationManager->setBouncedForOwner(
                                $verifyOwnerId,
                                (string) $verifyOwnerData->email
                            );
                        } elseif ($command === 'clear_bounced') {
                            $changedCars = $verificationManager->clearBouncedForOwner($verifyOwnerId);
                        } else {
                            $changedCars = $verificationManager->clearSuppressedForOwner($verifyOwnerId);
                        }

                        // One audit row per car actually changed, from the
                        // PRE-change snapshot the manager returned — matching the
                        // cars_update trigger's OLD.* convention.
                        foreach ($changedCars as $beforeCar) {
                            if (!$verificationRepo->insertHistory(verifyHistoryFieldsForAdminAction(
                                $beforeCar,
                                $verifyOperation,
                                'Admin action via the Verification System tab'
                            ))) {
                                throw new CarDatabaseException(
                                    "Audit trail insert failed for {$verifyOperation} on car "
                                    . (int) $beforeCar->id
                                );
                            }
                        }

                        $verificationRepo->commit();

                        $successes[] = sprintf(
                            '%s: %d car%s %s.',
                            sendEmailOwnerLabel($verifyOwnerData, $verifyOwnerId),
                            count($changedCars),
                            count($changedCars) === 1 ? '' : 's',
                            $verifySuccessVerb
                        );
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
                            'Verification: %s applied for owner %d (%d cars changed)',
                            $verifyOperation,
                            $verifyOwnerId,
                            count($changedCars)
                        ));
                    } catch (CarValidationException $e) {
                        $verificationRepo->rollback();
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
                            "Verification: {$verifyOperation} validation failure for owner {$verifyOwnerId}: " . $e->getMessage());
                        $errors[] = $e->getUserMessage();
                    } catch (ElanRegistryException $e) {
                        $verificationRepo->rollback();
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION,
                            "Verification: {$verifyOperation} failed for owner {$verifyOwnerId}: " . $e->getMessage());
                        $errors[] = 'That action could not be applied. Nothing was changed — check the logs for details.';
                    } catch (\Throwable $e) {
                        // Matches this file's other cases' catch-all clause
                        // (see "reassign"/"merge"/"delete" above): a
                        // narrower catch here would leave the transaction
                        // open for the rest of the request on any fault
                        // that isn't an ElanRegistryException subtype.
                        $verificationRepo->rollback();
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_VERIFICATION, sprintf(
                            'Verification: %s unexpected error [%s] for owner %d: %s',
                            $verifyOperation,
                            get_class($e),
                            $verifyOwnerId,
                            $e->getMessage()
                        ));
                        $errors[] = 'That action could not be applied due to an unexpected error. Check the logs for details.';
                    }
                    break;
            }
        }
    }

    // Convert error/success arrays to UserSpice session messages
    if (!empty($errors)) {
        foreach ($errors as $error) {
            usError($error);
        }
    }
    if (!empty($successes)) {
        foreach ($successes as $success) {
            usSuccess($success);
        }
    }
}

?>

<div class="page-wrapper">
    <!-- Hidden CSRF token for AJAX requests -->
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>" />

    <div class="container-fluid">
        <div class="page-container">

            <!-- Page Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="d-sm-flex align-items-center justify-content-between flex-wrap gap-3">
                        <div>
                            <h1 class="h2 mb-2 text-gray-800">
                                <i class="fas fa-cogs"></i> Registry Management
                            </h1>
                            <p class="text-muted mb-0">Administrative tools for car registry and ownership management</p>
                        </div>
                        <div>
                            <div class="d-flex gap-3 flex-wrap mb-2">
                                <div class="er-stat-tile">
                                    <div class="er-stat-number"><?= number_format($systemStatus['total_cars']) ?></div>
                                    <div class="er-stat-label">Total Cars</div>
                                </div>
                                <div class="er-stat-tile">
                                    <div class="er-stat-number"><?= number_format($systemStatus['total_users']) ?></div>
                                    <div class="er-stat-label">Total Users</div>
                                </div>
                                <?php if ($systemStatus['pending_transfers'] > 0) { ?>
                                    <div class="er-stat-tile">
                                        <div class="er-stat-number text-warning"><?= $systemStatus['pending_transfers'] ?></div>
                                        <div class="er-stat-label">Pending Transfers</div>
                                    </div>
                                <?php } ?>
                            </div>
                            <div class="text-end">
                                <?php if ($verificationBannerSeverity === null): ?>
                                    <span class="badge text-bg-primary badge-lg me-2">
                                        <i class="fas fa-check-circle"></i> System Operational
                                    </span>
                                <?php endif; ?>
                                <small class="text-muted">
                                    <i class="fas fa-clock"></i> <?= date('M j, Y g:i A', strtotime($systemStatus['last_updated'])) ?>
                                    &nbsp;<i class="fas fa-code-branch"></i> <?= htmlspecialchars(ApplicationVersion::get()) ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($pendingMigrationCount > 0): ?>
            <div class="alert alert-warning d-flex align-items-center mb-3" role="alert">
                <i class="fas fa-database me-2"></i>
                <span>
                    <strong><?= $pendingMigrationCount ?> pending migration<?= $pendingMigrationCount !== 1 ? 's' : '' ?>.</strong>
                    Run <code>composer migrate</code> to apply.
                </span>
            </div>
            <?php endif; ?>

            <?php if ($verificationBannerSeverity !== null): ?>
            <div class="alert alert-<?= $verificationBannerSeverity ?> d-flex align-items-center mb-3" role="alert">
                <i class="fas fa-<?= $verificationBannerSeverity === 'danger' ? 'exclamation-circle' : 'exclamation-triangle' ?> me-2"></i>
                <span>
                    <?php if ($verificationBannerSeverity === 'danger'): ?>
                        <strong>Verification is enabled but Brevo is not configured.</strong>
                        Verification emails cannot be sent.
                    <?php else: ?>
                        <strong>Verification is enabled but the cron transport hasn't run recently.</strong>
                        Reminders may be delayed.
                    <?php endif; ?>
                    <a href="?tab=verification">View details</a>
                </span>
            </div>
            <?php endif; ?>

            <!-- Main Interface Card -->
            <div class="row">
                <div class="col-12">
                    <div class="card registry-card">

                        <!-- Navigation Tabs -->
                        <div class="card-header p-0">
                            <ul class="nav nav-tabs card-header-tabs" id="managementTabs" role="tablist">

                                <!-- Car Management Tab -->
                                <li class="nav-item">
                                    <a class="nav-link <?= $activeTab === 'car-mgmt' ? 'active' : '' ?>"
                                       href="?tab=car-mgmt" role="tab">
                                        <i class="fas fa-car"></i> Car/Owner Relationships
                                        <?php if ($systemStatus['pending_transfers'] > 0) { ?>
                                            <span class="badge text-bg-warning badge-sm ms-1"><?= $systemStatus['pending_transfers'] ?></span>
                                        <?php } ?>
                                    </a>
                                </li>

                                <!-- Manage Cars Tab -->
                                <li class="nav-item">
                                    <a class="nav-link <?= $activeTab === 'manage-cars' ? 'active' : '' ?>"
                                       href="?tab=manage-cars" role="tab">
                                        <i class="fas fa-clipboard-check"></i> Manage Cars
                                        <?php if ($systemStatus['car_issues'] > 0) { ?>
                                            <span class="badge text-bg-warning badge-sm ms-1"><?= $systemStatus['car_issues'] ?></span>
                                        <?php } ?>
                                    </a>
                                </li>

                                <!-- Manage Owners Tab -->
                                <li class="nav-item">
                                    <a class="nav-link <?= $activeTab === 'owner-mgmt' ? 'active' : '' ?>"
                                       href="?tab=owner-mgmt" role="tab">
                                        <i class="fas fa-users"></i> Manage Owners
                                        <?php if ($systemStatus['owner_issues'] > 0) { ?>
                                            <span class="badge text-bg-warning badge-sm ms-1"><?= $systemStatus['owner_issues'] ?></span>
                                        <?php } ?>
                                    </a>
                                </li>

                                <!-- Account Cleanup Tab -->
                                <li class="nav-item">
                                    <a class="nav-link <?= $activeTab === 'account-cleanup' ? 'active' : '' ?>"
                                       href="?tab=account-cleanup" role="tab">
                                        <i class="fas fa-user-slash"></i> Account Cleanup
                                    </a>
                                </li>

                                <!-- Verification System Tab -->
                                <li class="nav-item">
                                    <a class="nav-link <?= $activeTab === 'verification' ? 'active' : '' ?>"
                                       href="?tab=verification" role="tab">
                                        <i class="fas fa-clipboard-check"></i> Verification System
                                        <?php if ($verificationBannerSeverity !== null) { ?>
                                            <span class="badge text-bg-<?= $verificationBannerSeverity ?> badge-sm ms-1">
                                                <i class="fas fa-<?= $verificationBannerSeverity === 'danger' ? 'exclamation-circle' : 'exclamation-triangle' ?>"></i>
                                                <span class="visually-hidden">Verification needs attention</span>
                                            </span>
                                        <?php } ?>
                                    </a>
                                </li>

                            </ul>
                        </div>

                        <!-- Tab Content -->
                        <div class="card-body">
                            <div class="tab-content" id="managementTabContent">
                                <?php include 'includes/partials/js-data-island.php'; ?>
                                <?php
                                // Include the appropriate tab content
                                $tabFile = 'includes/tab-' . str_replace('-', '_', $activeTab) . '.php'; // $activeTab already whitelist-validated above
                                $tabPath = __DIR__ . '/' . $tabFile;

                                if (file_exists($tabPath)) {
                                    include $tabPath;
                                } else {
                                    // Fallback placeholder content
                                    include 'includes/tab-placeholder.php';
                                }
                                ?>

                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Bootstrap Modals for Confirmations -->

<!-- Car Reassignment Confirmation Modal -->
<div class="modal fade" id="reassignConfirmModal" tabindex="-1" role="dialog" aria-labelledby="reassignConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header card-header-er-primary">
                <h5 class="modal-title card-header-er-primary-text" id="reassignConfirmModalLabel">
                    <i class="fas fa-user-friends"></i> Confirm Car Reassignment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-primary mb-3">
                    <i class="fas fa-info-circle"></i> <strong>Administrative Transfer:</strong> This will immediately transfer car ownership and log the change in the car's history.
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-primary"><i class="fas fa-car"></i> Car Details</h6>
                        <div id="modal-car-info" class="card border-primary">
                            <div class="card-body p-3">
                                <div id="modal-car-details"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-primary"><i class="fas fa-user"></i> New Owner</h6>
                        <div id="modal-user-info" class="card border-primary">
                            <div class="card-body p-3">
                                <div id="modal-user-details"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <p class="mb-2"><strong>This action will:</strong></p>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-check text-primary"></i> Transfer ownership immediately</li>
                        <li><i class="fas fa-check text-primary"></i> Log the change in car history</li>
                        <li><i class="fas fa-check text-primary"></i> Update all registry records</li>
                        <li><i class="fas fa-exclamation-triangle text-warning"></i> Cannot be undone easily</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="btn btn-primary" id="confirmReassignBtn">
                    <i class="fas fa-user-friends"></i> Confirm Reassignment
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Car Deletion Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" role="dialog" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteConfirmModalLabel">
                    <i class="fas fa-exclamation-triangle"></i> Permanent Car Deletion Warning
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger mb-3">
                    <h6 class="alert-heading"><i class="fas fa-skull-crossbones"></i> EXTREME CAUTION REQUIRED</h6>
                    <p class="mb-0">This action will <strong>permanently delete</strong> the car record and cannot be undone. Use only for spam or test data.</p>
                </div>

                <div class="card border-danger mb-3">
                    <div class="card-header bg-danger text-white">
                        <h6 class="mb-0"><i class="fas fa-car"></i> Car to be Deleted</h6>
                    </div>
                    <div class="card-body">
                        <div id="modal-delete-car-details"></div>
                    </div>
                </div>

                <div class="mb-3">
                    <p class="mb-2"><strong class="text-danger">This action will permanently:</strong></p>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-times text-danger"></i> Delete the car record</li>
                        <li><i class="fas fa-times text-danger"></i> Remove all user-car relationships</li>
                        <li><i class="fas fa-times text-danger"></i> Delete all uploaded images</li>
                        <li><i class="fas fa-times text-danger"></i> Remove from all statistics</li>
                        <li><i class="fas fa-exclamation-triangle text-warning"></i> <strong>Cannot be recovered</strong></li>
                    </ul>
                </div>

                <div class="mb-3">
                    <label for="modal-delete-confirmation" class="fw-bold">
                        Type <code>DELETE PERMANENTLY</code> to confirm:
                    </label>
                    <input type="text" class="form-control" id="modal-delete-confirmation"
                           placeholder="Type: DELETE PERMANENTLY" autocomplete="off">
                    <small class="form-text text-muted">This confirmation is required to prevent accidental deletions.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-shield-alt"></i> Cancel (Safe)
                </button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn" disabled>
                    <i class="fas fa-skull-crossbones"></i> Permanently Delete
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Transfer Decision Confirmation Modal -->
<div class="modal fade" id="transferDecisionModal" tabindex="-1" role="dialog" aria-labelledby="transferDecisionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header card-header-er-primary" id="transferDecisionModalHeader">
                <h5 class="modal-title card-header-er-primary-text" id="transferDecisionModalLabel">
                    <i class="fas fa-exchange-alt"></i> <span id="transferDecisionTitle">Confirm Transfer Decision</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="transferDecisionMessage" class="alert mb-3">
                    <i class="fas fa-info-circle"></i> <span id="transferDecisionMessageText">Please confirm your decision on this transfer request.</span>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-primary"><i class="fas fa-car"></i> Car Details</h6>
                        <div id="modal-transfer-car-info" class="card border-primary">
                            <div class="card-body p-3">
                                <div id="modal-transfer-car-details"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-primary"><i class="fas fa-users"></i> Transfer Parties</h6>
                        <div class="mb-3">
                            <strong class="text-muted">Current Owner:</strong>
                            <div id="modal-current-owner-info" class="card border-secondary mt-1">
                                <div class="card-body p-2">
                                    <div id="modal-current-owner-details"></div>
                                </div>
                            </div>
                        </div>
                        <div>
                            <strong class="text-primary">Requesting User:</strong>
                            <div id="modal-requester-info" class="card border-primary mt-1">
                                <div class="card-body p-2">
                                    <div id="modal-requester-details"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <h6><i class="fas fa-calendar-alt"></i> Request Information</h6>
                    <div id="modal-transfer-request-info" class="card border-primary">
                        <div class="card-body p-3">
                            <div id="modal-transfer-request-details"></div>
                        </div>
                    </div>
                </div>

                <div class="mt-3" id="modal-transfer-comments-section" style="display: none;">
                    <h6><i class="fas fa-comment-alt"></i> Requester's Comments</h6>
                    <div class="card border-primary">
                        <div class="card-body p-3">
                            <div id="modal-transfer-comments" class="text-dark" style="white-space: pre-wrap;"></div>
                        </div>
                    </div>
                </div>

                <div class="mt-3" id="transferDecisionConsequences">
                    <p class="mb-2"><strong>This action will:</strong></p>
                    <ul class="list-unstyled" id="transferDecisionEffects">
                        <!-- Dynamic content based on approve/deny -->
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times"></i> <span id="cancelButtonText">Cancel</span>
                </button>
                <!-- Action buttons for view mode -->
                <div id="transferViewModeButtons" style="display: none;">
                    <button type="button" class="btn btn-success" id="approveTransferFromDetailsBtn">
                        <i class="fas fa-check-circle"></i> Approve Transfer
                    </button>
                    <button type="button" class="btn btn-danger" id="denyTransferFromDetailsBtn">
                        <i class="fas fa-times-circle"></i> Deny Transfer
                    </button>
                </div>
                <!-- Confirm button for decision mode -->
                <button type="button" class="btn btn-primary" id="confirmTransferDecisionBtn">
                    <i class="fas fa-check"></i> <span id="confirmTransferDecisionText">Confirm</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Admin Contact Owner Modal -->
<div class="modal fade" id="adminContactModal" tabindex="-1" role="dialog" aria-labelledby="adminContactModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header card-header-er-primary">
                <h5 class="modal-title card-header-er-primary-text" id="adminContactModalLabel">
                    <i class="fas fa-shield-alt"></i> Administrator Contact Owner
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="adminContactForm" method="POST" action="<?= $us_url_root ?>app/admin/includes/process-admin-contact.php">
                <div class="modal-body">
                    <div class="alert alert-primary">
                        <i class="fas fa-info-circle"></i> <strong>Administrator Contact:</strong>
                        This will send an email to the car owner.
                    </div>

                    <!-- Owner Information Display -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h6 class="text-primary">Car Information</h6>
                            <div class="bg-light p-3 rounded">
                                <div id="contactCarInfo">
                                    <!-- Populated by JavaScript -->
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-primary">Owner Information</h6>
                            <div class="bg-light p-3 rounded">
                                <div id="contactOwnerInfo">
                                    <!-- Populated by JavaScript -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quality Issue Context -->
                    <div class="mb-3">
                        <label for="qualityIssue" class="form-label">
                            <i class="fas fa-exclamation-triangle text-warning"></i> Data Quality Issue
                        </label>
                        <select class="form-control" id="qualityIssue" name="quality_issue">
                            <option value="">Select the data quality issue (optional)</option>
                            <option value="Missing Information">Missing Critical Information</option>
                            <option value="Invalid Data">Invalid Data Entry</option>
                            <option value="Duplicate Records">Duplicate Records</option>
                            <option value="Duplicate Email Addresses">Duplicate Email Addresses</option>
                            <option value="Missing Chassis">Missing Chassis Number</option>
                            <option value="Invalid Chassis">Invalid Chassis Number</option>
                            <option value="Missing Series">Missing Series Information</option>
                            <option value="Car Registration Encouragement">Car Registration Encouragement</option>
                            <option value="Other">Other Data Quality Issue</option>
                        </select>
                    </div>

                    <!-- Message -->
                    <div class="mb-3">
                        <label for="adminMessage" class="form-label">
                            <i class="fas fa-comment text-primary"></i> Your Message to Owner
                        </label>
                        <textarea class="form-control" id="adminMessage" name="message" rows="6"
                                  placeholder="Enter your message to the car owner..." required></textarea>
                        <div class="form-text">
                            <small class="text-muted">
                                <i class="fas fa-lightbulb"></i> <strong>Tip:</strong> Be specific about what information needs to be updated and why.
                            </small>
                        </div>
                    </div>

                    <!-- Hidden fields -->
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(Token::generate(), ENT_QUOTES, 'UTF-8') ?>" />
                    <input type="hidden" name="action" value="admin_contact_owner" />
                    <input type="hidden" name="car_id" id="contactCarId" value="" />
                    <input type="hidden" name="owner_id" id="contactOwnerId" value="" />
                    <input type="hidden" name="target_email" id="contactTargetEmail" value="" />
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-envelope"></i> Send Administrator Message
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/confirmation-modal.php'; ?>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>

<!-- Location Picker Styles -->
<link rel="stylesheet" href="<?=$us_url_root?>app/assets/css/location-picker.min.css?v=<?= ASSET_VERSION ?>">

<!-- Location Picker Script -->
<script src="<?=$us_url_root?>app/assets/js/location-picker.min.js?v=<?= ASSET_VERSION ?>"></script>

<!-- Include custom CSS and JavaScript -->
<link rel="stylesheet" href="assets/admin-core.min.css?v=<?= ASSET_VERSION ?>">
<script src="assets/admin-core.min.js?v=<?= ASSET_VERSION ?>"></script>
<script src="<?= $us_url_root ?>app/admin/assets/js/load-owner-profile.min.js?v=<?= ASSET_VERSION ?>"></script>
