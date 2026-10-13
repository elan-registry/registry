<?php if(count(get_included_files()) ==1) die(); //Direct Access Not Permitted Leave this line in place?>
<?php
// Each car keeps a copy of its owner's address (cars.email), and the owner's
// profile can hold a bounce or a block recorded against an old address. When
// an owner confirms a different address, the copies must change to it, and the
// bounce and block for the old address are cleared.
//
// users/verify.php fires the verifySuccess hook from THREE call sites:
//   * the "already verified" re-display branch. Email has not changed, so
//     the sync is a harmless no-op. That branch exit()s, so it never reaches
//     the other two.
//   * the vericode confirm branch. It fires after every successful vericode
//     confirmation, whether or not that confirmation changed users.email
//     (the re-verify sub-branch does not touch email). The sync is
//     idempotent, so this is harmless.
//   * the unconditional `if ($verify_success)` final-output block.
//
// The confirm branch and the final-output block fire on the SAME request.
// The confirm branch sets $verify_success = TRUE and falls through rather
// than exit()ing, so every real email confirmation would run this hook
// twice. That would double the per-car cars_hist audit trigger rows and do
// the whole sync twice. The guard below makes the sync run once per request.
// It keys on the verified user id rather than a bare boolean so that a second
// fire for a genuinely different user would still be allowed through (defense
// in depth — in practice both fires are the same request and the same user).
//
// Issue #1890: this hook performs two operations under that same
// run-once guard — the flag clear for a confirmed email change first (#2356),
// then the owner-field sync below — each in its own try/catch so a failure in
// one doesn't block the other.
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

// A fresh Owner lookup, not $verify: $verify is the User object verify.php
// constructed BEFORE the email-change UPDATE runs, and User::update() never
// refreshes its cached _data. Owner's own find() issues a fresh SELECT, so
// the sync block below sees the post-update row.
//
// The construction itself lives INSIDE this try block, not above it:
// Owner::find() throws OwnerDatabaseException on a DB error (not a return
// value), and this file has no enclosing function for a top-level try to
// wrap — an uncaught throw here would propagate straight out of
// includeHook()'s bare, unguarded include() and fatal verify.php's render,
// exactly what this hook exists to never do.
$owner = null;

try {
    $owner = new \ElanRegistry\Owner($userId);

    // One call. The repository reads users.email under the profile lock, so
    // the hook passes no address. The manager runs the clear, its audit rows
    // and the no-address anomaly log (#2356).
    (new \ElanRegistry\Car\CarVerificationManager(new \ElanRegistry\Car\CarRepository(dbi())))
        ->clearFlagsForConfirmedEmailChange($userId, $userId);
} catch (\ElanRegistry\Exceptions\CarDatabaseException $e) {
    logger($userId, \ElanRegistry\LogCategories::LOG_CATEGORY_DATABASE_ERROR,
        "sync_owner_email_on_verify: flag clear failed for user {$userId}: " . $e->getMessage());
} catch (\Throwable $e) {
    // \Throwable, matching the sync block's own rationale below: this is a
    // silent background repair and must never interrupt verify.php's render.
    // Covers OwnerDatabaseException from the $owner lookup itself as well as
    // any failure inside the flag clear above.
    logger($userId, \ElanRegistry\LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
        'sync_owner_email_on_verify: unexpected ' . get_class($e)
        . " during flag clear for user {$userId}: " . $e->getMessage());
}

// $owner is null only if its construction above threw — already logged by
// the catch blocks above. Nothing further to do; the sync below needs the
// same successfully-loaded Owner.
if ($owner === null) {
    return;
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
