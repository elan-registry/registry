<?php if(count(get_included_files()) ==1) die(); //Direct Access Not Permitted Leave this line in place?>
<?php
// Issue #1958: syncs a confirmed email change (users.email) to cars.email.
//
// users/verify.php fires the verifySuccess hook from THREE call sites:
//   * line 224 — the "already verified" re-display branch. Email has not
//     changed, so the sync is a harmless no-op. That branch exit()s, so it
//     never reaches the other two.
//   * line 295 — fires unconditionally after every successful vericode
//     confirmation, whether or not that confirmation actually changed
//     users.email (the re-verify sub-branch doesn't touch email at all).
//     Harmless either way since the sync is idempotent.
//   * line 315 — the unconditional `if ($verify_success)` final-output block.
//
// 295 and 315 fire on the SAME request: the confirm branch sets
// $verify_success = TRUE and falls through rather than exit()ing, so every
// real email confirmation would run this hook twice. That would double the
// per-car cars_hist audit trigger rows and do the whole sync twice. The guard
// below makes the sync run once per request. It keys on the verified user id
// rather than a bare boolean so that a second fire for a genuinely different
// user would still be allowed through (defense in depth — in practice both
// fires are the same request and the same user).
//
// Issue #1890: this hook now performs two operations under that same
// run-once guard — bounce-clear first, then the owner-field sync below —
// each in its own try/catch so a failure in one doesn't block the other.
global $verify;

if (!isset($verify) || !$verify->exists()) {
    return;
}

$userId = (int) $verify->data()->id;

// $GLOBALS, not `static`: this file has no enclosing function, so a top-level
// `static` here would not behave as a persistent function-local static.
$syncedKey = '__elanregistry_verify_email_synced_' . $userId;
if (!empty($GLOBALS[$syncedKey])) {
    return;
}
$GLOBALS[$syncedKey] = true;

// A fresh Owner lookup, not $verify->data()->email: $verify is the User
// object verify.php constructed BEFORE the email-change UPDATE runs, and
// User::update() never refreshes its cached _data — so $verify->data()->email
// is the pre-change address on the exact path this feature targets. Owner's
// own find() issues a fresh SELECT, same as the sync block below already
// relies on; sharing one instance means both blocks see the same
// post-update row.
$owner = new \ElanRegistry\Owner($userId);

try {
    $currentEmail = (string) ($owner->data()->email ?? '');
    if ($currentEmail === '') {
        logger($userId, \ElanRegistry\LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            "sync_owner_email_on_verify: skipping bounce-clear for user {$userId} — "
            . 'could not read a confirmed email after verification.');
    } else {
        $repo = new \ElanRegistry\Car\CarRepository(dbi());

        $integrityCarIds = $repo->carIdsWithBouncedFlagButNoAddress($userId);
        if (!empty($integrityCarIds)) {
            logger($userId, \ElanRegistry\LogCategories::LOG_CATEGORY_EMAIL_BOUNCED,
                "sync_owner_email_on_verify: car(s) " . implode(',', $integrityCarIds)
                . " had email_bounced=1 with no recorded bounced address for user {$userId} "
                . '— clearing as part of confirmed email change (data-integrity anomaly).');
        }

        $cleared = $repo->clearBouncedForUser($userId, $currentEmail);
        if ($cleared > 0) {
            logger($userId, \ElanRegistry\LogCategories::LOG_CATEGORY_EMAIL_BOUNCED,
                "sync_owner_email_on_verify: cleared bounce flag on {$cleared} car(s) "
                . "for user {$userId} after confirmed email change.");
        }
    }
} catch (\ElanRegistry\Exceptions\CarDatabaseException $e) {
    logger($userId, \ElanRegistry\LogCategories::LOG_CATEGORY_DATABASE_ERROR,
        "sync_owner_email_on_verify: bounce-clear failed for user {$userId}: " . $e->getMessage());
} catch (\Throwable $e) {
    // \Throwable, matching the sync block's own rationale below: this is a
    // silent background repair and must never interrupt verify.php's render.
    logger($userId, \ElanRegistry\LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
        'sync_owner_email_on_verify: unexpected ' . get_class($e)
        . " during bounce-clear for user {$userId}: " . $e->getMessage());
}

try {
    $result = $owner->syncOwnerFieldsToCars();

    if (!$result->isCompleteSuccess()) {
        // Per-car failures (a history insert failed and rolled back, any per-car
        // \Throwable) come back through the return value, NOT as an exception —
        // the catch blocks below never see them. Without this branch they would
        // vanish entirely, since this hook surfaces nothing to the user.
        //
        // A car that left this owner mid-sync deliberately does NOT reach here:
        // it lands in the result's `skipped` bucket, which isCompleteSuccess()
        // treats as success — it is expected behavior, not an error (#1954).
        logger($userId, \ElanRegistry\LogCategories::LOG_CATEGORY_OWNER_ERRORS,
            "sync_owner_email_on_verify: partial sync for user {$userId} — "
            . "{$result->updatedCount()} of {$result->totalCount()} car(s) updated; "
            . $result->failedCarsPhrase());
    }
} catch (\ElanRegistry\Exceptions\OwnerDatabaseException | \ElanRegistry\Exceptions\CarDatabaseException $e) {
    // Infrastructure fault (deadlock, lock-wait timeout) rather than a per-car
    // failure — syncOwnerFieldsToCars() reports per-car failures via its return
    // value, handled above. This must never interrupt verify.php's page render,
    // so log and continue silently.
    logger($userId, \ElanRegistry\LogCategories::LOG_CATEGORY_DATABASE_ERROR,
        "sync_owner_email_on_verify: car owner-field sync failed for user {$userId}: " . $e->getMessage());
} catch (\Throwable $e) {
    // \Throwable, not Exception: PHP's Error hierarchy (TypeError et al.) does
    // not extend Exception, and syncOwnerFieldsToCars() builds its field bundle
    // from untyped $_data properties. A TypeError is not a database fault, so
    // this branch logs under SYSTEM_ERROR rather than DATABASE_ERROR — matching
    // usersc/user_settings.php's two-tier precedent. This is a silent background
    // repair — nothing here may surface to the user or interrupt verify.php.
    logger($userId, \ElanRegistry\LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
        'sync_owner_email_on_verify: unexpected ' . get_class($e)
        . " during owner-field sync for user {$userId}: " . $e->getMessage());
}
