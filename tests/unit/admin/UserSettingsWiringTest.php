<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for usersc/user_settings.php's DB-failure handling and
 * missing-profile handling.
 *
 * Covers:
 * - usersc/user_settings.php (the try/catch around Owner::syncOwnerFieldsToCars()/
 *   getCarsOwned(), and the missing-$profiledetails log+exit branch)
 *
 * The page cannot be require()'d from PHPUnit for a full behavioral test: it
 * renders a complete HTML page inline (no isolated return path), reads
 * superglobals, and both branches under test need conditions that can't be
 * triggered deterministically over a real request (a genuine DB fault, or a
 * profiles row missing for an authenticated user). Following
 * AccountPageWiringTest.php's established precedent (#1505 PR B), both
 * branches are instead asserted against the file's source text.
 *
 * The throw condition itself — a DB/query failure surfacing as
 * OwnerDatabaseException from Owner::getCarsOwned()/syncOwnerFieldsToCars() — is
 * already exercised against a stubbed DatabaseInterface in
 * tests/unit/OwnerReadMethodsDatabaseFailureTest.php; this test covers
 * only user_settings.php's handling of that exception once thrown.
 *
 * @author Elan Registry Development Team
 */
#[Group('fast')]
#[Group('unit')]
#[Group('owner-account')]
final class UserSettingsWiringTest extends TestCase
{
    /** Endpoint path, relative to the repository root. */
    private const USER_SETTINGS_ENDPOINT = 'usersc/user_settings.php';

    /** Owner class path, relative to the repository root. */
    private const OWNER_CLASS_PATH = 'usersc/classes/Owner.php';

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Read an endpoint's source text for wiring assertions.
     *
     * @param string $relativePath Path relative to the repository root
     * @return string The endpoint file contents
     */
    private function readEndpointSource(string $relativePath): string
    {
        $filePath = __DIR__ . '/../../../' . $relativePath;
        $this->assertFileExists($filePath, "Endpoint file must exist: {$relativePath}");

        $content = file_get_contents($filePath);
        $this->assertIsString($content, "Endpoint file must be readable: {$relativePath}");

        return $content;
    }

    // =========================================================================
    // user_settings.php — car location sync DB-failure handling
    // (source inspection)
    // =========================================================================

    /**
     * A DB failure inside Owner::syncOwnerFieldsToCars()/getCarsOwned() must be
     * caught, logged under LOG_CATEGORY_DATABASE_ERROR, and must add a
     * friendly message to $errors[] rather than let the exception propagate
     * and crash the page — the profile fields above this block may have
     * already saved successfully.
     *
     * Source inspection: reaching this branch needs a genuine \Throwable
     * from the DB layer, which cannot be produced deterministically from a
     * PHPUnit process or a real HTTP request (see class docblock). The
     * assertions below are ordered and scoped so that moving the catch
     * block, changing the caught type, dropping the log call, or leaving
     * $errors[] unpopulated in the catch body would each fail a specific
     * assertion rather than passing on a loose substring match.
     */
    public function testCarLocationSyncDbFailureIsCaughtLoggedAndDegradesGracefully(): void
    {
        $content = $this->readEndpointSource(self::USER_SETTINGS_ENDPOINT);

        $this->assertStringContainsString(
            'try {',
            $content,
            'The owner-field sync call must be wrapped in a try block'
        );
        $this->assertStringContainsString(
            '$owner->updateProfileAndSync($ownerFields)',
            $content,
            'The endpoint must call updateProfileAndSync() inside the try block'
        );

        // Isolate the catch block that immediately follows the sync call, so
        // assertions below can't accidentally match an unrelated catch block
        // elsewhere in the file (e.g. a future addition). The call site is a
        // ternary ($ownerFields !== [] ? updateProfileAndSync() :
        // syncOwnerFieldsToCars(), for the website-only-clear case where
        // $ownerFields is empty) — anchor on its assignment, not on either
        // branch alone.
        $tryStart = strpos($content, '$syncResult = $ownerFields !== []');
        $this->assertIsInt($tryStart, 'Could not locate the sync-call ternary');

        // Find the OwnerDatabaseException | CarDatabaseException catch itself
        // first (not a fixed-length window after $tryStart — the sibling
        // OwnerValidationException | OwnerUpdateException catch that precedes
        // it in source order can grow independently of this one), then
        // brace-balance to its own closing brace.
        $catchHeaderPos = strpos($content, 'catch (OwnerDatabaseException | CarDatabaseException $e) {', $tryStart);
        $this->assertIsInt($catchHeaderPos, 'Could not locate the combined-catch header');

        // Confirm the header is a real `} catch (...)` clause closing the
        // preceding catch block, not matched by a fixed-length window —
        // the preceding sibling catch's body can grow independently (nested
        // try/catch for the website-clear retry, #1891 round two), so scan
        // backward past only whitespace and closing braces to the nearest
        // non-brace character, which must be a semicolon or a block's `}`.
        $i = $catchHeaderPos - 1;
        while ($i >= 0 && ($content[$i] === ' ' || $content[$i] === "\n" || $content[$i] === "\t" || $content[$i] === '}')) {
            $i--;
        }
        $this->assertGreaterThanOrEqual(0, $i, 'Ran off the start of the file looking for the previous statement');
        $this->assertSame(
            ';',
            $content[$i],
            'The combined-catch header must immediately follow closing syntax (braces back '
            . 'to a statement\'s semicolon), not be embedded inside unrelated code'
        );

        // Isolate just the body of this catch block (up to its own closing
        // brace) so the following assertions can't accidentally match
        // content from a later, unrelated block.
        $catchBodyStart = $catchHeaderPos;
        $catchOpenBrace = strpos($content, '{', $catchBodyStart);
        $this->assertIsInt($catchOpenBrace, 'Could not locate the combined-catch body\'s opening brace');
        $catchBodyEnd = $this->matchingBraceEnd($content, $catchOpenBrace);
        $this->assertIsInt($catchBodyEnd, 'Could not locate the end of the combined-catch body');
        $catchBody = substr($content, $catchBodyStart, $catchBodyEnd - $catchBodyStart);

        $this->assertStringContainsString(
            'LOG_CATEGORY_DATABASE_ERROR',
            $catchBody,
            'The catch block must log the failure under LOG_CATEGORY_DATABASE_ERROR'
        );
        $this->assertStringContainsString(
            '$errors[] = ',
            $catchBody,
            'The catch block must populate $errors[] with a friendly message rather than let the exception propagate'
        );
    }

    /**
     * Regression guard (#1891): `new Owner($userId)` must be in its own try
     * block, separate from the write/sync try block, so a failure loading
     * the owner (nothing written yet) is never reported with the same
     * "Owner details saved, but..." message used when the write actually
     * committed and only the sync step afterward failed. Both failures throw
     * the same OwnerDatabaseException type, so only the source structure —
     * not the exception type — can tell them apart.
     *
     * Source inspection: the real failure (a DB fault inside new Owner()'s
     * own find() call) cannot be triggered deterministically from a PHPUnit
     * process or a real HTTP request (same constraint as this file's other
     * DB-failure test above).
     */
    public function testOwnerConstructionHasItsOwnTryBlockSeparateFromTheWriteAndSync(): void
    {
        $content = $this->readEndpointSource(self::USER_SETTINGS_ENDPOINT);

        $constructPos = strpos($content, '$owner = new Owner($userId);');
        $this->assertIsInt($constructPos, 'Could not locate the new Owner($userId) call site');

        // The nearest preceding "try {" must be the one that wraps ONLY the
        // constructor — not the one that also wraps updateProfileAndSync().
        $precedingTry = strrpos(substr($content, 0, $constructPos), 'try {');
        $this->assertIsInt($precedingTry, 'Could not locate the try block wrapping new Owner($userId)');

        // Brace-balance the try block itself, then its matching catch block,
        // rather than a fixed-length window — a fixed window truncates once
        // the catch body grows past it (#1891 round two).
        $tryOpenBrace = strpos($content, '{', $precedingTry);
        $this->assertIsInt($tryOpenBrace, 'Could not locate the try block\'s opening brace');
        $tryCloseBrace = $this->matchingBraceEnd($content, $tryOpenBrace);
        $this->assertIsInt($tryCloseBrace, 'Could not locate the try block\'s closing brace');

        $catchOpenBrace = strpos($content, '{', $tryCloseBrace);
        $this->assertIsInt($catchOpenBrace, 'Could not locate the catch block\'s opening brace');
        $catchCloseBrace = $this->matchingBraceEnd($content, $catchOpenBrace);
        $this->assertIsInt($catchCloseBrace, 'Could not locate the catch block\'s closing brace');

        $constructTryBlock = substr($content, $precedingTry, $catchCloseBrace - $precedingTry + 1);
        $this->assertStringNotContainsString(
            'updateProfileAndSync',
            substr($content, $precedingTry, $tryCloseBrace - $precedingTry + 1),
            'new Owner($userId) must be the ONLY statement in its try block — '
            . 'updateProfileAndSync() must not be called in the same try, or a '
            . 'construction failure and a post-write sync failure become '
            . 'indistinguishable to the catch ladder (#1891)'
        );

        $this->assertMatchesRegularExpression(
            '/catch\s*\(\\\\?(ElanRegistry\\\\Exceptions\\\\)?OwnerDatabaseException\s+\$e\)\s*\{.*?\$owner\s*=\s*null;/s',
            $constructTryBlock,
            'new Owner($userId)\'s own try block must catch OwnerDatabaseException and '
            . 'set $owner = null, so the write/sync block below can tell "owner failed '
            . 'to load" apart from "owner loaded, then something else failed" (#1891)'
        );

        $this->assertStringContainsString(
            'if ($owner !== null) {',
            $content,
            'The write/sync try block must be gated on $owner !== null, so a failed '
            . 'new Owner($userId) never reaches updateProfileAndSync() with a null $owner (#1891)'
        );
    }

    // =========================================================================
    // user_settings.php — missing profile row handling
    // (source inspection)
    // =========================================================================

    /**
     * A missing profiles row for an authenticated user (the else branch of
     * `if ($userQ->count() > 0)`) must be logged under LOG_CATEGORY_USER, and
     * must stop page execution via `exit` rather than fall through into the
     * unconditional use of $profiledetails below (city/state/country/website
     * comparisons at 12+ call sites), which would otherwise fatal on an
     * undefined variable.
     *
     * Source inspection: reaching this branch needs a profiles row to be
     * missing for an authenticated user — data corruption that cannot be
     * produced deterministically from a PHPUnit process or a real HTTP
     * request without directly manipulating the database out from under an
     * active session. The assertions below are ordered and scoped so that
     * removing the log call, or removing/reordering the `exit` relative to
     * the log call, would each fail a specific assertion.
     */
    public function testMissingProfileRowIsLoggedAndStopsExecution(): void
    {
        $content = $this->readEndpointSource(self::USER_SETTINGS_ENDPOINT);

        $this->assertStringContainsString(
            'if ($userQ->count() > 0) {',
            $content,
            'The endpoint must branch on whether the profile query returned a row'
        );

        $elseStart = strpos($content, '} else {');
        $this->assertIsInt($elseStart, 'Could not locate the missing-profile else branch');
        $elseBlock = substr($content, $elseStart, 700);

        $elseBodyEnd = strpos($elseBlock, "\n}\n");
        $this->assertIsInt($elseBodyEnd, 'Could not locate the end of the missing-profile else branch');
        $elseBody = substr($elseBlock, 0, $elseBodyEnd);

        $this->assertStringContainsString(
            'LOG_CATEGORY_USER',
            $elseBody,
            'The missing-profile branch must log under LOG_CATEGORY_USER'
        );
        $this->assertStringContainsString(
            'exit;',
            $elseBody,
            'The missing-profile branch must stop execution rather than fall through to unconditional $profiledetails use'
        );

        // The log call must precede exit, not the reverse (a reordered exit
        // would silently drop the audit trail).
        $logPos = strpos($elseBody, 'LOG_CATEGORY_USER');
        $exitPos = strpos($elseBody, 'exit;');
        $this->assertLessThan(
            $exitPos,
            $logPos,
            'The log call must occur before exit, so the failure is recorded before execution stops'
        );
    }

    /**
     * Regression guard for #1879: every project-owned vericode write must go
     * through hashVericode() rather than storing a plaintext value that
     * users/verify.php's hash-only comparison can never match.
     *
     * The hashVericode()/hash_equals() contract itself is pinned by real-DB
     * round-trip tests in tests/integration/CarVerificationTest.php, which do
     * not execute these source files, so they cannot fail if one of these lines is
     * ever reverted back to plaintext. This source-inspection test is what
     * actually goes red on a regression, following the same pattern as this
     * file's other tests.
     */
    public function testAllProjectOwnedVericodeWritesAreHashed(): void
    {
        $userSettings = $this->readEndpointSource(self::USER_SETTINGS_ENDPOINT);

        // Scope to each DB-write call individually ($db->update(...) /
        // ->update(...)), not the whole file — the email-options array at
        // line ~371 legitimately passes the raw plaintext $vericode so the
        // recipient can use it, and must NOT be flagged as a violation.
        // Checking each call's own body independently (rather than a single
        // file-wide regex) is what makes this discriminate correctly: a
        // regression in either call is caught on its own, not masked by the
        // other call still being correct.
        preg_match_all('/->update\(([^;]*)\);/s', $userSettings, $updateCalls);
        $this->assertNotEmpty($updateCalls[1], 'Could not locate any ->update() DB write calls to inspect');
        $vericodeUpdateCallsChecked = 0;
        foreach ($updateCalls[1] as $updateCallBody) {
            if (!str_contains($updateCallBody, 'vericode')) {
                continue;
            }
            $vericodeUpdateCallsChecked++;
            $this->assertMatchesRegularExpression(
                '/[\'"]vericode[\'"]\s*=>\s*hashVericode\(/',
                $updateCallBody,
                self::USER_SETTINGS_ENDPOINT . ' must hash every vericode written via ->update() (#1879): ' . $updateCallBody
            );
        }
        $this->assertSame(
            2,
            $vericodeUpdateCallsChecked,
            'Expected exactly 2 ->update() calls touching vericode (email-change + password-reset); '
            . 'a different count means this test is no longer checking what it claims to'
        );

        $ownerClass = $this->readEndpointSource(self::OWNER_CLASS_PATH);

        $this->assertMatchesRegularExpression(
            '/[\'"]vericode[\'"]\]\s*=\s*hashVericode\(/',
            $ownerClass,
            self::OWNER_CLASS_PATH . ' must hash the vericode it generates on owner creation (#1879)'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/[\'"]vericode[\'"]\]\s*=\s*randomString\(\d+\)(?!\s*\))/i',
            $ownerClass,
            self::OWNER_CLASS_PATH . ' must not store a bare randomString() result unhashed (#1879)'
        );
    }

    // =========================================================================
    // user_settings.php — $ownerFields / $websiteCleared sync gating (source inspection)
    // =========================================================================

    /**
     * The unconfirmed-email guard (#1873).
     *
     * `cars.email` is the address verification batches send to. When
     * `email_act == 1`, user_settings.php writes only `email_new` — `users.email`
     * is unchanged until the owner confirms via users/verify.php. Setting
     * Queuing $ownerFields['email'] in that branch would push an UNCONFIRMED address onto
     * every car the owner has.
     *
     * Only a comment enforces this today, and a mutation adding the flag to that
     * branch survives the whole suite. This pins it by source inspection: the
     * page cannot be included in a unit test (it executes on load and depends on
     * UserSpice globals), which is why this file already inspects source text
     * elsewhere.
     */
    public function testUnconfirmedEmailBranchDoesNotTriggerCarSync(): void
    {
        $content = $this->readEndpointSource('usersc/user_settings.php');

        // Anchor on the `if` statement itself, not a bare 'email_act == 1'
        // substring — the comment above the email_act == 0 branch mentions
        // 'email_act == 1' by name, and matching that would extract the wrong
        // branch entirely (and pass for the wrong reason).
        $marker = 'if ($emailR->email_act == 1) {';
        $branchStart = strpos($content, $marker);
        $this->assertNotFalse($branchStart, 'user_settings.php must still branch on email_act == 1');

        // Bound the window by BRACE BALANCING, not by matching an indented closing
        // brace. An indentation-anchored delimiter encodes a nesting depth rather
        // than a structural relationship: dedent this region by one level (a
        // plausible refactor — the block sits four `if`s deep) and the delimiter
        // matches an inner brace instead, silently truncating the body to roughly
        // half. The assertions below would then still pass, against half a branch,
        // and would keep passing even if the truncated-away half gained the flag.
        // Brace balancing cannot truncate regardless of indentation.
        $depth = 0;
        $branchEnd = null;
        for ($i = $branchStart, $len = strlen($content); $i < $len; $i++) {
            if ($content[$i] === '{') {
                $depth++;
            } elseif ($content[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $branchEnd = $i;
                    break;
                }
            }
        }
        $this->assertNotNull($branchEnd, 'Could not brace-balance the end of the email_act == 1 branch');
        $branchBody = substr($content, $branchStart, $branchEnd - $branchStart + 1);

        // Anchor on content, not just length: this string is the last distinctive
        // marker inside the branch, so its presence proves the body reaches the
        // real closing brace rather than merely being long enough to look complete.
        $this->assertStringContainsString(
            'Verify Your Email',
            $branchBody,
            'The extracted email_act == 1 branch body does not reach the end of the '
            . 'branch — the extraction is truncating, which would make the assertions '
            . 'below vacuous'
        );

        $this->assertStringContainsString(
            'email_new',
            $branchBody,
            'The email_act == 1 branch must still be the one that stages email_new'
        );
        $this->assertStringNotContainsString(
            "\$ownerFields['email']",
            $branchBody,
            'The email_act == 1 branch stages email_new only — users.email is unchanged '
            . "until the owner confirms, so it must NOT queue \$ownerFields['email'] or an "
            . 'unconfirmed address would be synced onto every car (#1873, #1891)'
        );
    }

    /**
     * The sync must remain gated on $ownerFields being non-empty, or a
     * website clear, and each branch that persists a synced owner-contact
     * field must queue its key into $ownerFields.
     *
     * Mutations that drop a field's `$ownerFields['...'] = ...` assignment
     * (e.g. from the location block) silently stop syncing that field class —
     * the exact defect #1873 was filed to fix — and no behavioral test
     * catches it. A website clear carries no field for update() to write (it
     * drops empty values), so it cannot queue into $ownerFields the way the
     * other fields do — $websiteCleared covers that case in the gate.
     */
    public function testSyncIsGatedOnOwnerFieldsBeingNonEmpty(): void
    {
        $content = $this->readEndpointSource('usersc/user_settings.php');

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$ownerFields\s*!==\s*\[\]\s*\|\|\s*\$websiteCleared\s*\)/',
            $content,
            'The car sync must stay gated on $ownerFields !== [] or $websiteCleared, so a '
            . 'save that persisted nothing does not push stale values onto the cars, and so '
            . 'a website-only clear (which queues nothing into $ownerFields) still syncs'
        );

        $this->assertStringContainsString(
            'updateProfileAndSync($ownerFields)',
            $content,
            'user_settings.php must call Owner::updateProfileAndSync() with the queued fields (#1873, #1891)'
        );

        $this->assertStringContainsString(
            'syncOwnerFieldsToCars()',
            $content,
            'A website-only clear must fall back to syncOwnerFieldsToCars() directly, since '
            . 'updateProfileAndSync() requires at least one queued field'
        );

        // fname, lname, website, and the confirmed-email branch each queue
        // their own key directly; location queues city/state/country (and
        // lat/lon when present) via one array_merge() call. The unconfirmed
        // email branch must not queue anything — asserted separately above.
        preg_match_all('/\$ownerFields\[[\'"][a-z_]+[\'"]\]\s*=(?!=)/', $content, $assignments);
        $this->assertCount(
            4,
            $assignments[0],
            "Expected exactly 4 direct \$ownerFields['key'] = ... assignments (fname, lname, "
            . 'website, confirmed email). A change here means either a sync trigger was '
            . 'dropped or the unconfirmed-email branch gained one (#1873)'
        );
        $this->assertSame(
            1,
            substr_count($content, '$ownerFields = array_merge($ownerFields,'),
            'Expected exactly one array_merge() call queuing the location fields onto '
            . '$ownerFields (#1873)'
        );
    }

    /**
     * Regression guard (#1891): clearing the website must still reach the
     * owner's cars, even though it cannot go through $ownerFields the way
     * every other field does — Owner::update() drops empty values, so an
     * empty website can never survive validateAndSanitizeFields(). Without
     * $websiteCleared, a website-only clear sets no $ownerFields key, the
     * sync gate sees an empty array, and the cars keep the stale URL forever.
     */
    public function testWebsiteClearSetsTheFlagThatKeepsTheSyncGateOpen(): void
    {
        $content = $this->readEndpointSource(self::USER_SETTINGS_ENDPOINT);

        $clearPos = strpos($content, "\$websiteUrl === ''");
        $this->assertIsInt($clearPos, 'Could not locate the website-clear branch');
        $clearBranch = substr($content, $clearPos, 500);

        $this->assertStringContainsString(
            '$websiteCleared = true;',
            $clearBranch,
            'The website-clear branch must set $websiteCleared so the sync gate below '
            . 'still opens when it is the only change'
        );
        $this->assertStringNotContainsString(
            "\$ownerFields['website']",
            $clearBranch,
            'The clear branch cannot queue into $ownerFields — update() drops empty '
            . 'values, so an empty website would never survive validation'
        );
    }

    /**
     * Regression guard (#1891 round two): a website clear writes directly to
     * `profiles` and commits before `new Owner($userId)` runs. If that load
     * then fails, the clear already committed — the page must say so rather
     * than the generic "could not be saved", or the owner wrongly concludes
     * the clear itself failed and may resubmit indefinitely with no visible
     * effect (resubmitting the same empty value trips no change-detection).
     */
    public function testOwnerLoadFailureAfterWebsiteClearReportsTheCommitAccurately(): void
    {
        $content = $this->readEndpointSource(self::USER_SETTINGS_ENDPOINT);

        $constructPos = strpos($content, '$owner = new Owner($userId);');
        $this->assertIsInt($constructPos, 'Could not locate the new Owner($userId) call site');
        $precedingTry = strrpos(substr($content, 0, $constructPos), 'try {');
        $this->assertIsInt($precedingTry, 'Could not locate the try block wrapping new Owner($userId)');

        $tryOpenBrace = strpos($content, '{', $precedingTry);
        $this->assertIsInt($tryOpenBrace, 'Could not locate the try block\'s opening brace');
        $tryCloseBrace = $this->matchingBraceEnd($content, $tryOpenBrace);
        $this->assertIsInt($tryCloseBrace, 'Could not locate the try block\'s closing brace');
        $catchOpenBrace = strpos($content, '{', $tryCloseBrace);
        $this->assertIsInt($catchOpenBrace, 'Could not locate the catch block\'s opening brace');
        $catchCloseBrace = $this->matchingBraceEnd($content, $catchOpenBrace);
        $this->assertIsInt($catchCloseBrace, 'Could not locate the catch block\'s closing brace');
        $catchBody = substr($content, $tryCloseBrace, $catchCloseBrace - $tryCloseBrace + 1);

        $this->assertStringContainsString(
            'if ($websiteCleared) {',
            $catchBody,
            'The owner-load-failure catch must branch on $websiteCleared so a committed '
            . 'clear is reported differently from "nothing was saved"'
        );

        // Isolate the $websiteCleared branch's own body so the following
        // assertions can't match the unrelated else branch below it.
        $websiteClearedPos = strpos($catchBody, 'if ($websiteCleared) {');
        $this->assertIsInt($websiteClearedPos, 'Could not locate the $websiteCleared branch');
        $branchOpenBrace = strpos($catchBody, '{', $websiteClearedPos);
        $this->assertIsInt($branchOpenBrace, 'Could not locate the $websiteCleared branch\'s opening brace');
        $branchCloseBrace = $this->matchingBraceEnd($catchBody, $branchOpenBrace);
        $this->assertIsInt($branchCloseBrace, 'Could not locate the $websiteCleared branch\'s closing brace');
        $websiteClearedBranch = substr($catchBody, $websiteClearedPos, $branchCloseBrace - $websiteClearedPos);

        $this->assertStringNotContainsString(
            "Your changes could not be saved",
            $websiteClearedBranch,
            'A committed website clear must not be told "could not be saved" — the clear '
            . 'did save; only syncing it to the cars failed'
        );
        $this->assertStringContainsString(
            'reportWebsiteCleared()',
            $websiteClearedBranch,
            'The branch must report the website clear\'s own success message — it must '
            . 'not call $reportOwnerWrite(), which would also report $ownerFields entries '
            . 'that this catch never attempted to write'
        );
        $this->assertStringNotContainsString(
            'reportOwnerWrite()',
            $websiteClearedBranch,
            '$ownerFields (if any) was never attempted when new Owner() itself fails — '
            . 'reporting it here would claim unsaved fields were saved'
        );
    }

    /**
     * Regression guard (#1891 round two): when update() rejects or rolls back
     * a non-website field (e.g. an invalid name), a website clear that
     * already committed on its own must still be synced to the owner's
     * cars here — otherwise it stays stale until some unrelated field
     * happens to change in a later submit.
     */
    public function testValidationFailureCatchStillSyncsAnAlreadyCommittedWebsiteClear(): void
    {
        $content = $this->readEndpointSource(self::USER_SETTINGS_ENDPOINT);

        $catchPos = strpos($content, 'catch (OwnerValidationException | OwnerUpdateException $e) {');
        $this->assertIsInt($catchPos, 'Could not locate the OwnerValidationException | OwnerUpdateException catch');
        $catchOpenBrace = strpos($content, '{', $catchPos);
        $this->assertIsInt($catchOpenBrace, 'Could not locate the catch body\'s opening brace');
        $catchCloseBrace = $this->matchingBraceEnd($content, $catchOpenBrace);
        $this->assertIsInt($catchCloseBrace, 'Could not locate the catch body\'s closing brace');
        $catchBody = substr($content, $catchPos, $catchCloseBrace - $catchPos);

        $this->assertStringContainsString(
            'if ($websiteCleared) {',
            $catchBody,
            'A rejected/rolled-back update() must not skip syncing an already-committed '
            . 'website clear — it is a separate direct write, not part of $ownerFields'
        );
        $this->assertStringContainsString(
            'syncOwnerFieldsToCars()',
            $catchBody,
            'The website-clear retry must call syncOwnerFieldsToCars(), not '
            . 'updateProfileAndSync(), since there is no $ownerFields entry to pass it'
        );
        $this->assertStringContainsString(
            'reportWebsiteCleared()',
            $catchBody,
            'A successful retry must report the website clear\'s own success message'
        );
        $this->assertStringNotContainsString(
            'reportOwnerWrite()',
            $catchBody,
            '$ownerFields (if any) was rejected/rolled back by update() when this catch '
            . 'runs — calling $reportOwnerWrite() here would report those unsaved fields as saved'
        );
    }

    /**
     * The admin sync endpoint's catch ladder must stay ordered specific-to-general,
     * ending in \Throwable (#1873).
     *
     * Three silent regressions this pins, none of which PHP reports as an error:
     *  - narrowing the final catch back to \Exception would let a TypeError from
     *    syncOwnerFieldsToCars()'s untyped $_data bundle escape, ending an AJAX
     *    request as an uncaught fatal — HTML to the client instead of JSON, and no
     *    row in `logs` at all
     *  - moving \Throwable above the sibling OwnerDatabaseException|CarDatabaseException
     *    clause would make that handler dead code, silently dropping its tailored
     *    "some cars may already have been updated; retrying is safe" message
     *  - dropping either sibling from the combined clause: they are siblings under
     *    ElanRegistryException, not parent and child, so both must be named
     */
    public function testAdminSyncEndpointCatchLadderOrdering(): void
    {
        $content = $this->readEndpointSource('app/admin/includes/process-owner-sync-location.php');

        preg_match_all('/catch\s*\(([^)]+?)\s*\$e\s*\)/', $content, $matches);
        $caught = array_map('trim', $matches[1]);

        $this->assertSame(
            [
                'LocationServiceException',
                'AdminOperationException',
                'OwnerDatabaseException | CarDatabaseException',
                '\\Throwable',
            ],
            $caught,
            'The catch ladder must run specific-to-general and end in \\Throwable. '
            . 'PHP does not flag an unreachable catch, so a reordering here fails silently.'
        );
    }

    // =========================================================================
    // Skipped-vs-failed scoping of the partial-sync message (#1954)
    // =========================================================================

    /**
     * Brace-balance forward from an opening `{` at or after $start, returning
     * the index of its matching close brace.
     *
     * Indentation-anchored delimiters encode a nesting depth rather than a
     * structural relationship, so a dedent silently truncates the extracted
     * body and makes the assertions against it vacuous — see
     * testUnconfirmedEmailBranchDoesNotTriggerCarSync() for the same reasoning.
     *
     * @param string $content Source text
     * @param int    $start   Index of (or before) the block's opening brace
     * @return int|null Index of the matching close brace, or null if unbalanced
     */
    private function matchingBraceEnd(string $content, int $start): ?int
    {
        $depth = 0;
        $seenOpen = false;
        for ($i = $start, $len = strlen($content); $i < $len; $i++) {
            if ($content[$i] === '{') {
                $depth++;
                $seenOpen = true;
            } elseif ($content[$i] === '}') {
                $depth--;
                if ($seenOpen && $depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * The partial-sync error message must live only in the failure branch
     * (#1954).
     *
     * A skip-only outcome keeps isCompleteSuccess() true, so it must take the
     * $successes[] path. If the "Please contact support if this persists."
     * message ever migrated into the isCompleteSuccess() true branch, an owner
     * whose car merely changed hands mid-sync would be told to contact support
     * about a non-error.
     *
     * The same sentence also appears in this file's two catch blocks, so the
     * assertions are scoped by construction: only this if/else pair's two
     * bodies are extracted, never the whole file.
     */
    public function testPartialSyncErrorMessageIsInsideCompleteSuccessElseBranch(): void
    {
        $content = $this->readEndpointSource(self::USER_SETTINGS_ENDPOINT);

        $marker = 'if ($syncResult->isCompleteSuccess()) {';
        $ifStart = strpos($content, $marker);
        $this->assertNotFalse($ifStart, 'user_settings.php must still branch on isCompleteSuccess()');
        $this->assertSame(
            1,
            substr_count($content, $marker),
            'Expected exactly one isCompleteSuccess() branch; a second one would make the '
            . 'anchor below ambiguous and the extraction target the wrong block'
        );

        $ifEnd = $this->matchingBraceEnd($content, $ifStart);
        $this->assertNotNull($ifEnd, 'Could not brace-balance the end of the isCompleteSuccess() branch');
        $ifBody = substr($content, $ifStart, $ifEnd - $ifStart + 1);

        $elseStart = strpos($content, 'else {', $ifEnd);
        $this->assertNotFalse($elseStart, 'The isCompleteSuccess() branch must be followed by an else branch');
        $elseEnd = $this->matchingBraceEnd($content, $elseStart);
        $this->assertNotNull($elseEnd, 'Could not brace-balance the end of the isCompleteSuccess() else branch');
        $elseBody = substr($content, $elseStart, $elseEnd - $elseStart + 1);

        // Anchor on content, not length: proves the extraction reached the real
        // failure-reporting body rather than truncating early.
        $this->assertStringContainsString(
            '$errors[] = ',
            $elseBody,
            'The extracted else branch must be the one that reports the partial sync via $errors[]'
        );
        $this->assertStringContainsString(
            'Please contact support if this persists.',
            $elseBody,
            'The partial-sync error message must live in the isCompleteSuccess() failure branch'
        );

        $this->assertStringContainsString(
            '$successes[] = ',
            $ifBody,
            'The isCompleteSuccess() true branch must report success, not failure'
        );
        $this->assertStringNotContainsString(
            'Please contact support if this persists.',
            $ifBody,
            'A skip-only outcome keeps isCompleteSuccess() true and must take the $successes[] '
            . 'path — the "contact support" message must never appear in the success branch (#1954)'
        );
    }

    /**
     * The admin sync endpoint must return error(500) only for real failures,
     * with skipped cars reported through the success path (#1954).
     *
     * A skip means the car left this owner mid-sync — expected behavior, not an
     * error. The error(500) response therefore has to stay inside the
     * !isCompleteSuccess() guard, and the informational skip reporting
     * (->withData('cars_skipped', ...) and the success message) has to live
     * after that guard closes. The success message itself is built by
     * OwnerSyncResult::successMessage(), not inlined here — its skip-only
     * wording ("No cars were synchronized." vs "...to 0 car(s).") is covered
     * by runtime unit tests in OwnerSyncResultTest, since this endpoint calls
     * send()/exit and cannot be included directly in PHPUnit.
     */
    public function testAdminSyncEndpointReportsSkipsOutsideTheErrorGuard(): void
    {
        $content = $this->readEndpointSource('app/admin/includes/process-owner-sync-location.php');

        $marker = 'if (!$syncResult->isCompleteSuccess()) {';
        $guardStart = strpos($content, $marker);
        $this->assertNotFalse($guardStart, 'The endpoint must still guard its failure response on !isCompleteSuccess()');

        $guardEnd = $this->matchingBraceEnd($content, $guardStart);
        $this->assertNotNull($guardEnd, 'Could not brace-balance the end of the !isCompleteSuccess() guard');
        $guardBody = substr($content, $guardStart, $guardEnd - $guardStart + 1);
        $afterGuard = substr($content, $guardEnd + 1);

        $this->assertStringContainsString(
            'ApiResponse::error(',
            $guardBody,
            'The partial-sync failure response must be inside the !isCompleteSuccess() guard'
        );
        $this->assertStringContainsString(
            '500',
            $guardBody,
            'The partial-sync failure response must be a 500, inside the guard'
        );
        $this->assertStringNotContainsString(
            'ApiResponse::error(',
            $afterGuard,
            'Nothing after the guard may return a partial-sync error — a skip-only outcome '
            . 'must not be reported as a failure (#1954)'
        );

        $this->assertStringContainsString(
            '$syncResult->successMessage()',
            $afterGuard,
            'The success path after the guard must delegate its message to '
            . 'OwnerSyncResult::successMessage(), which handles the skip-only wording (#1954)'
        );
        $this->assertStringContainsString(
            "->withData('cars_skipped'",
            $afterGuard,
            'The success response must still carry the skipped car IDs for the caller'
        );
    }
}
