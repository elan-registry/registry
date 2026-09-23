<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use DateTimeImmutable;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\VerificationConfigException;
use ElanRegistry\LogCategories;

/**
 * VerificationSettings - Feature switch and readiness probes for the car verification system
 *
 * Owns the single-row `er_verification_settings` table (`id = 1`), which gates the
 * verification UI and workflow site-wide, plus the two readiness probes an admin
 * needs in order to decide whether turning the switch on will actually work:
 * Brevo (the email transport verification reminders go out over) and cron (the
 * transport that drives the reminder job).
 *
 * THE ASYMMETRIC GATE. `setEnabled(true)` refuses when Brevo is not configured;
 * `setEnabled(false)` never refuses, for any reason. An admin must always be able
 * to turn verification off mid-incident, including when the very subsystems this
 * class probes are the thing that is broken. Every failure path here is written
 * to preserve that: the probes swallow their own errors and report "not ready"
 * rather than throwing, and the disable path never consults them at all.
 *
 * Cron readiness is advisory, not a gate — a stalled cron transport means
 * reminders queue up rather than being sent, which is recoverable, whereas an
 * unconfigured Brevo key means the verification emails have nowhere to go.
 *
 * PERMISSIONS. This class does **not** check permissions. Callers reaching
 * `setEnabled()` must have already run the appropriate `hasPerm()` check on the
 * admin surface that invokes it.
 *
 * @package ElanRegistry\Car
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1926
 */
final class VerificationSettings
{
    /**
     * Primary key of the one row `er_verification_settings` ever holds.
     *
     * The migration seeds `id = 1` and nothing enforces the single-row invariant
     * at schema level, so every read and write here is scoped `WHERE id = 1`.
     */
    private const SETTINGS_ROW_ID = 1;

    /**
     * Smallest batch size {@see setBatchSize()} will persist.
     *
     * A batch of zero or fewer is not "sending paused" — pausing is what the
     * feature switch and the cron job's own enable flag are for — it is a
     * configuration that would make the send job do nothing while still
     * reporting itself healthy. Clamp up to 1 instead of storing it.
     */
    private const BATCH_SIZE_MIN = 1;

    /**
     * Largest batch size {@see setBatchSize()} will persist.
     *
     * The verification programme deliberately sends in small batches to protect
     * sender reputation (#1922); 25 is the ceiling an admin can reach from the
     * dashboard without a code change.
     */
    private const BATCH_SIZE_MAX = 25;

    /**
     * Minutes to fall back to for {@see cronStaleAfterSeconds()} if
     * `CRON_TRANSPORT_INTERVAL_MINUTES` (`usersc/includes/config.php`) is
     * somehow undefined. Matches that constant's current value exactly — this
     * is not an independent guess, just insurance against a class-load fatal
     * on a path that should never occur (`config.php` loads during UserSpice
     * bootstrap, before any application class is autoloaded) but that this
     * class's own never-throw contract (see class docblock) can't risk.
     */
    private const CRON_TRANSPORT_INTERVAL_MINUTES_FALLBACK = 10;

    /**
     * Path of the Brevo plugin's override file, relative to the site root.
     *
     * The sendinblue plugin only takes over UserSpice's mail sending once this
     * file is renamed into place; without it the API key is configured but unused.
     */
    private const BREVO_OVERRIDE_RELATIVE_PATH = 'usersc/plugins/sendinblue/override.php';

    public function __construct(private DatabaseInterface $db) {}

    /**
     * Read one column off the `id = 1` settings row, or `null` if it is unreadable
     *
     * THE SHARED MECHANISM, NOT THE SHARED POLICY. Every read method in this
     * class runs the same three steps — issue the scoped single-row SELECT,
     * check `error()`, check the returned row is an object carrying the column —
     * but each one then applies a *different* fail-closed policy on top:
     * a different sentinel (`false`, `5`, `null`), a different amount of extra
     * validation (numeric range, date parsing), and a different log wording.
     * This helper owns only the part that is genuinely identical, and hands the
     * caller back a bare `null` for "could not read it" so the caller can apply
     * its own policy. It deliberately does NOT decide the sentinel and does NOT
     * log the row-shape failure — folding either of those in here would force
     * every caller through one generic message and lose the per-method
     * explanations an admin reads in the log.
     *
     * It DOES log the query-error case, because that message is mechanically
     * identical in shape across callers (only the column name varies) and is
     * about the database failing, not about what the caller wanted — except for
     * {@see lastCronRequestAt()}, whose historical behaviour is to stay silent
     * on a failed query, expressed here as an empty `$errorSubject` rather than
     * by hand-rolling the query again.
     *
     * The two failure modes return the same `null` but are distinguishable via
     * `$queryFailed`, set by reference. Callers need that not for their return
     * value — every one of them fails closed identically either way — but to
     * decide whether to add their own row-shape warning line, since the
     * query-error line has already been written by then and this class has
     * always logged exactly one line per failed read. Reporting it out here
     * rather than having callers re-check `$this->db->error()` keeps the answer
     * tied to the read that produced it instead of to whatever state the
     * connection happens to be in afterwards.
     *
     * @param string $column Column to select. Interpolated into the SQL, so it
     *                       must be a hard-coded identifier from this class —
     *                       never caller- or request-supplied. Every call site
     *                       passes a literal.
     * @param string|null $errorSubject What the query-error line names as the thing
     *                                  it failed to read. Defaults to
     *                                  `er_verification_settings.<column>`.
     *                                  {@see isEnabled()} passes the bare table name
     *                                  instead, preserving the exact wording that
     *                                  line has always had. Null here means "use the
     *                                  default"; passing `''` suppresses the line
     *                                  entirely, which only {@see lastCronRequestAt()}
     *                                  does — it has always failed silently on a
     *                                  failed query.
     * @param bool|null $queryFailed Set by reference to true when the SELECT
     *                               itself errored (and the query-error line was
     *                               therefore already logged), false when the
     *                               query succeeded but the row or column was
     *                               absent or NULL. Accepts null purely so an
     *                               uninitialised local can be passed in; it is
     *                               always a bool on the way out.
     * @param-out bool $queryFailed
     * @return mixed The raw column value, or null if the query failed or the
     *               row/column was absent. A stored NULL also reads back as
     *               null, which every caller already treats as unreadable.
     */
    private function readSettingsColumn(
        string $column,
        ?string $errorSubject = null,
        ?bool &$queryFailed = null
    ): mixed {
        $queryFailed = false;

        $this->db->query(
            sprintf('SELECT %s FROM er_verification_settings WHERE id = ?', $column),
            [self::SETTINGS_ROW_ID]
        );

        if ($this->db->error()) {
            $queryFailed = true;
            $subject = $errorSubject ?? 'er_verification_settings.' . $column;
            if ($subject !== '') {
                $this->logWarning(sprintf(
                    'Failed to read %s: %s',
                    $subject,
                    $this->db->errorString() ?: 'unknown'
                ));
            }
            return null;
        }

        $row = $this->db->first();

        return is_object($row) && isset($row->$column) ? $row->$column : null;
    }

    /**
     * Confirm the `id = 1` row still exists after a plain `SET col = ?` UPDATE
     *
     * ONLY FOR PLAIN-SET WRITES. PDO's `rowCount()` after an UPDATE reports rows
     * CHANGED, not rows MATCHED (no `MYSQL_ATTR_FOUND_ROWS` is set on this
     * connection) — writing the same value the row already had legitimately
     * yields `count() === 0`, so that alone cannot distinguish "row missing"
     * from "value unchanged". A follow-up read can. This is the confirmation
     * {@see setEnabled()} and {@see setBatchSize()} share.
     *
     * NOT the confirmation used by {@see incrementUnmatchedRecipientCounter()}
     * or {@see recordCronRequest()}: `col = col + 1` and `col = NOW()` always
     * change the row's value on a match, so `count() === 0` is already an
     * unambiguous "row missing" signal for those two and a second round-trip
     * would buy nothing. Those methods keep their own `count()` check
     * deliberately — see their docblocks for the full argument. Do not
     * generalise this helper to cover them.
     *
     * Logs nothing itself: the caller's warning line names the column and value
     * it was writing, which this helper does not know.
     *
     * @return bool True if the settings row was confirmed present
     */
    private function settingsRowConfirmedPresent(): bool
    {
        $this->db->query(
            'SELECT id FROM er_verification_settings WHERE id = ?',
            [self::SETTINGS_ROW_ID]
        );

        return !$this->db->error() && is_object($this->db->first());
    }

    /**
     * Log a fail-closed warning under this class's single warning category
     *
     * Every failure path in this class logs at severity 0 under
     * LOG_CATEGORY_VERIFICATION_CONFIG_WARNING; only the acting user and the
     * message differ. Centralising the call keeps that invariant structural
     * rather than a convention ten call sites each have to remember, and gives
     * one place to change if the category or severity ever needs to move.
     *
     * @param string $message Fully formatted message; callers do their own sprintf()
     *                        so each keeps its own wording and placeholders
     * @param int $actingUserId User id to attribute the line to (0 for no known actor)
     */
    private function logWarning(string $message, int $actingUserId = 0): void
    {
        logger($actingUserId, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, $message);
    }

    /**
     * Whether the admin tab's feature-switch checkbox should render `disabled`
     *
     * Pure UI-affordance logic, extracted out of `tab-verification.php`'s inline
     * PHP so it is unit-testable in isolation. The critical invariant this
     * enforces: turning verification OFF must never be blocked by Brevo
     * readiness — only turning it ON is gated. Dropping the `!$isEnabled &&`
     * conjunct here would silently re-introduce the exact incident this
     * feature exists to prevent (an admin unable to disable verification
     * during a Brevo outage because the checkbox itself renders `disabled`).
     *
     * Not a security control — the toggle endpoint re-checks `hasPerm()`
     * server-side regardless of what this method returns.
     *
     * @param bool $canToggle Whether the current user holds the admin permission required to toggle at all
     * @param bool $isEnabled Current value of the feature switch
     * @param bool $brevoReady Current value of {@see brevoReady()}
     * @return bool True if the checkbox should render `disabled`
     */
    public static function toggleShouldBeDisabled(bool $canToggle, bool $isEnabled, bool $brevoReady): bool
    {
        return !$canToggle || (!$isEnabled && !$brevoReady);
    }

    /**
     * Whether the car verification system is currently switched on
     *
     * Fails closed: a missing settings row or a failed query reports `false`
     * rather than throwing. This is the read path consulted on every page that
     * renders verification UI, and a database hiccup should hide the feature,
     * not break the page.
     *
     * @return bool True if verification is enabled
     */
    public function isEnabled(): bool
    {
        // The error line names the bare table, not `...enabled`, so pin the
        // subject rather than taking the helper's column-qualified default.
        $enabled = $this->readSettingsColumn('enabled', 'er_verification_settings', $queryFailed);

        if ($enabled === null) {
            // Either the query failed (already logged by the helper) or the row
            // / column is absent. The migration seeds id=1 and nothing in the
            // app ever deletes it, so an absent row means the migration
            // part-applied or the table was truncated/restored incompletely — a
            // schema problem, not "switched off". Fail closed, but never
            // silently: an admin trying to enable verification here would
            // otherwise see a misleading Brevo-related rejection with no clue
            // the real fault is a missing settings row.
            //
            // The helper logs the query-error case and stays quiet on the
            // row-shape case, so this line fires only for the latter — the same
            // one-line-per-failure output this method has always produced.
            if (!$queryFailed) {
                $this->logWarning(sprintf(
                    'er_verification_settings row id=%d is missing — reporting verification OFF '
                    . 'as a fail-closed default. Re-run `composer migrate` to reseed the row.',
                    self::SETTINGS_ROW_ID
                ));
            }
            return false;
        }

        return (bool) $enabled;
    }

    /**
     * Number of verification emails the admin manual-send tool sends per batch
     *
     * Fails closed: a missing settings row or a failed query reports the default
     * of 5 rather than throwing. This is the read path consulted by the admin
     * verification-email send tool's batch-size preview, and a database hiccup
     * should fall back to a conservative batch size, not break the page.
     *
     * Written by {@see self::setBatchSize()}, which clamps to `[1, 25]` before
     * writing — so a value outside that range read back here can only have come
     * from a direct database edit, not from the admin dashboard.
     *
     * @return int Configured batch size, or 5 if it could not be read
     */
    public function batchSize(): int
    {
        $batchSize = $this->readSettingsColumn('batch_size', queryFailed: $queryFailed);

        if ($batchSize === null) {
            // Same fail-closed rationale as isEnabled(): the migration seeds
            // id=1 and nothing in the app ever deletes it, so an absent row
            // means the migration part-applied or the table was
            // truncated/restored incompletely — a schema problem, not "no
            // batch size configured". Fail closed, but never silently. The
            // helper has already logged the query-error case, so this line
            // covers only the missing-row one.
            if (!$queryFailed) {
                $this->logWarning(sprintf(
                    'er_verification_settings row id=%d is missing — reporting default batch '
                    . 'size of 5. Re-run `composer migrate` to reseed the row.',
                    self::SETTINGS_ROW_ID
                ));
            }
            return 5;
        }

        return (int) $batchSize;
    }

    /**
     * Turn the car verification system on or off
     *
     * Enabling is gated on Brevo being configured; disabling is not gated on
     * anything and never throws, so verification can always be switched off even
     * while Brevo or cron are broken. When the gate rejects an enable, nothing is
     * written to the table.
     *
     * Does not check permissions — the caller must have already done so.
     *
     * @param bool $enabled True to enable verification, false to disable it
     * @param int $actingUserId User id to attribute this change to in the log
     *                          (0 for no known actor). The class performs no
     *                          session lookups itself, so the caller — who has
     *                          already authenticated the request — passes it.
     * @return bool True if the setting was written successfully
     * @throws VerificationConfigException If enabling is requested while Brevo is not configured
     */
    public function setEnabled(bool $enabled, int $actingUserId = 0): bool
    {
        if ($enabled && !$this->brevoReady()) {
            $this->logWarning('Refused to enable verification: Brevo is not configured.', $actingUserId);
            throw new VerificationConfigException();
        }

        $this->db->query(
            'UPDATE er_verification_settings SET enabled = ? WHERE id = ?',
            [$enabled ? 1 : 0, self::SETTINGS_ROW_ID]
        );

        if ($this->db->error()) {
            $this->logWarning(sprintf(
                'Failed to write er_verification_settings (enabled=%d): %s',
                $enabled ? 1 : 0,
                $this->db->errorString() ?: 'unknown'
            ), $actingUserId);
            return false;
        }

        // A plain `SET enabled = ?` cannot be confirmed by count() — see
        // {@see settingsRowConfirmedPresent()} for the rowCount()
        // changed-vs-matched argument in full.
        if (!$this->settingsRowConfirmedPresent()) {
            $this->logWarning(sprintf(
                'er_verification_settings UPDATE (enabled=%d) could not be confirmed — the id=%d '
                . 'settings row appears to be missing. Re-run `composer migrate` to reseed it.',
                $enabled ? 1 : 0,
                self::SETTINGS_ROW_ID
            ), $actingUserId);
            return false;
        }

        logger($actingUserId, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_CHANGED, sprintf(
            'Verification system %s.',
            $enabled ? 'enabled' : 'disabled'
        ));

        return true;
    }

    /**
     * Set how many verification emails each batch sends
     *
     * CLAMPS, NEVER REJECTS. Anything above {@see self::BATCH_SIZE_MAX} is
     * written as the maximum and anything below {@see self::BATCH_SIZE_MIN} as
     * the minimum, rather than the write being refused. This matches how the
     * admin `verification_send_batch` handler already truncates an oversized
     * `car_ids[]` submission instead of rejecting the whole request: an admin
     * who types 500 gets the largest batch the system will send, not a failed
     * form. The clamped value — not the submitted one — is what gets written
     * and logged, so the log line always reflects the effective setting.
     *
     * Unlike {@see setEnabled()} there is no readiness gate here, so this
     * method never throws: a batch size is inert configuration and changing it
     * cannot cause mail to go out. Every failure path logs and returns `false`.
     *
     * Confirms the write with a follow-up read rather than `count()`, for the
     * same reason {@see setEnabled()} does: PDO's `rowCount()` after an UPDATE
     * reports rows CHANGED, not rows MATCHED, so re-saving the value the row
     * already held yields `count() === 0` — indistinguishable from the id=1
     * row being absent entirely.
     *
     * Does not check permissions — the caller must have already done so.
     *
     * @param int $size Requested batch size; clamped into `[1, 25]` before writing
     * @param int $actingUserId User id to attribute this change to in the log
     *                          (0 for no known actor). The class performs no
     *                          session lookups itself, so the caller — who has
     *                          already authenticated the request — passes it.
     * @return bool True if the clamped batch size was written and confirmed
     */
    public function setBatchSize(int $size, int $actingUserId = 0): bool
    {
        $clamped = max(self::BATCH_SIZE_MIN, min(self::BATCH_SIZE_MAX, $size));

        $this->db->query(
            'UPDATE er_verification_settings SET batch_size = ? WHERE id = ?',
            [$clamped, self::SETTINGS_ROW_ID]
        );

        if ($this->db->error()) {
            $this->logWarning(sprintf(
                'Failed to write er_verification_settings (batch_size=%d): %s',
                $clamped,
                $this->db->errorString() ?: 'unknown'
            ), $actingUserId);
            return false;
        }

        if (!$this->settingsRowConfirmedPresent()) {
            $this->logWarning(sprintf(
                'er_verification_settings UPDATE (batch_size=%d) could not be confirmed — the id=%d '
                . 'settings row appears to be missing. Re-run `composer migrate` to reseed it.',
                $clamped,
                self::SETTINGS_ROW_ID
            ), $actingUserId);
            return false;
        }

        logger($actingUserId, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_CHANGED, sprintf(
            'Verification batch size set to %d.',
            $clamped
        ));

        return true;
    }

    /**
     * Whether the Brevo email integration is configured well enough to send
     *
     * Both halves must hold: an API key is saved in `plg_sendinblue`, and the
     * plugin's override file is in place so UserSpice actually routes mail
     * through Brevo. A key without the override sends nothing.
     *
     * Never throws. A missing `plg_sendinblue` table (plugin never installed) or
     * a failed query reports "not ready", which is the same answer an empty key
     * gives and the safe one for the enable gate.
     *
     * @return bool True if Brevo is configured and active
     */
    public function brevoReady(): bool
    {
        $this->db->query('SELECT * FROM plg_sendinblue');
        if ($this->db->error()) {
            // SQLSTATE 42S02 = table missing, i.e. the sendinblue plugin was never
            // installed — an expected, non-alarming "not ready". Any other error
            // (connection lost, grants revoked, lock timeout) means the true Brevo
            // state is unknown, not "not configured" — logged distinctly so an
            // admin who gets the enable-rejected message isn't sent to double-check
            // a key/override that may in fact already be correct.
            $sqlState = (string) ($this->db->errorInfo()[0] ?? '');
            $this->logWarning(sprintf(
                $sqlState === '42S02'
                    ? 'Brevo readiness probe: plg_sendinblue table absent (sendinblue plugin not installed) — reporting not ready. %s'
                    : 'Brevo readiness probe FAILED querying plg_sendinblue — reporting "not ready", but Brevo may in fact be configured. Check DB connectivity/grants on plg_sendinblue. %s',
                $this->db->errorString() ?: 'unknown'
            ));
            return false;
        }

        $settings = $this->db->first();
        if (!is_object($settings) || empty($settings->key)) {
            return false;
        }

        global $abs_us_root, $us_url_root;

        return file_exists(
            ($abs_us_root ?? '') . ($us_url_root ?? '') . self::BREVO_OVERRIDE_RELATIVE_PATH
        );
    }

    /**
     * Whether the cron transport has hit this environment recently enough
     *
     * "Recently enough" is strictly less than twice `CRON_TRANSPORT_INTERVAL_MINUTES`
     * (`usersc/includes/config.php`) ago — a request exactly at that age counts as
     * stalled, not ready. The comparison is deliberately strict (`<`) so the
     * boundary sits at a single unambiguous point that a test can pin by setting
     * {@see lastCronRequestAt()}'s underlying column to a known age. Doubling the
     * interval gives one missed tick of slack, so a single late or dropped hit
     * does not flap the readiness indicator.
     *
     * Never throws: no recorded cron request at all, an unreadable/zero-date
     * column value, or a missing `CRON_TRANSPORT_INTERVAL_MINUTES` (see
     * {@see cronStaleAfterSeconds()}) all report "not ready" rather than raising.
     *
     * @return bool True if {@see lastCronRequestAt()} reports a timestamp less
     *              than twice the cron transport interval ago
     */
    public function cronReady(): bool
    {
        $lastRequest = $this->lastCronRequestAt();
        if ($lastRequest === null) {
            return false;
        }

        $elapsedSeconds = time() - $lastRequest->getTimestamp();

        return $elapsedSeconds < $this->cronStaleAfterSeconds();
    }

    /**
     * Age, in seconds, beyond which {@see lastCronRequestAt()}'s value means cron is stalled.
     *
     * Derived from `CRON_TRANSPORT_INTERVAL_MINUTES` (`usersc/includes/config.php`),
     * the shared, discoverable source for how often the UserSpice cron transport
     * fires — see `docs/development/DEPLOYMENT.md`, "Cron Transport (UserSpice Cron
     * Manager)" for the operational record. Doubling it gives one missed tick of
     * slack before the environment is called stalled.
     *
     * Reads the constant at call time rather than as a class constant expression,
     * so a caller that somehow reaches this class before `config.php` has loaded
     * gets the documented fallback instead of a class-load fatal — this class's
     * never-throw contract (see class docblock) must hold even then.
     */
    private function cronStaleAfterSeconds(): int
    {
        $minutes = defined('CRON_TRANSPORT_INTERVAL_MINUTES')
            ? (int) CRON_TRANSPORT_INTERVAL_MINUTES
            : self::CRON_TRANSPORT_INTERVAL_MINUTES_FALLBACK;

        return $minutes * 60 * 2;
    }

    /**
     * Increment the count of inbound Brevo signals matched to no car
     *
     * Called from three places:
     * - The Brevo webhook receiver (#1887), `app/api/webhooks/brevo.php`'s
     *   `NO_CAR_MATCH` branch, when an event's recipient email matches no
     *   `cars.email` value.
     * - `BrevoEventReconciliationJob::applyEvent()`, which replays events
     *   fetched from Brevo's Events API and hits the same no-match condition
     *   as the live webhook receiver.
     * - `BrevoSuppressionSyncJob::syncPage()`, which walks Brevo's
     *   account-wide suppression list. That source is broader and noisier
     *   than the other two: it is not filtered to any specific email tag or
     *   campaign, so it counts any suppressed address with no matching car,
     *   not just ones tied to verification sends. This is accepted
     *   deliberately per #2085's scope, not an oversight.
     *
     * A rising count with verification enabled signals recipients whose
     * emails have drifted from what any car record has on file.
     *
     * Never throws: a failed UPDATE is logged and swallowed, matching this
     * class's fail-quietly contract for the write paths its callers depend
     * on — none of the webhook receiver's, the reconciliation job's, or the
     * suppression sync job's own success/response handling may hinge on this
     * counter succeeding.
     *
     * @return bool True if the counter was incremented successfully
     */
    public function incrementUnmatchedRecipientCounter(): bool
    {
        $this->db->query(
            'UPDATE er_verification_settings SET unmatched_recipient_count = unmatched_recipient_count + 1 WHERE id = ?',
            [self::SETTINGS_ROW_ID]
        );

        if ($this->db->error()) {
            $this->logWarning(sprintf(
                'Failed to increment er_verification_settings.unmatched_recipient_count: %s',
                $this->db->errorString() ?: 'unknown'
            ));
            return false;
        }

        // Unlike a plain `SET col = ?` update, `col = col + 1` always changes
        // the row's value when a row matches, so `count() === 0` here
        // unambiguously means the id=1 row is absent — not "value unchanged".
        // Without this check a missing settings row would silently stop the
        // counter incrementing forever, with no signal anywhere that the only
        // measure of recipient email drift had gone dead.
        if ($this->db->count() === 0) {
            $this->logWarning(sprintf(
                'er_verification_settings row id=%d not found; unmatched-recipient counter is not being recorded.',
                self::SETTINGS_ROW_ID
            ));
            return false;
        }

        return true;
    }

    /**
     * Count of inbound Brevo signals (webhook events, reconciliation events,
     * suppression-list contacts) whose recipient matched no car.
     *
     * Returns `null`, NOT 0, when the value could not be read — a failed
     * query, a missing settings row, or a malformed stored value. This is a
     * read path the admin dashboard consults, and it never throws; but a
     * rising count is this counter's entire signal, so "unreadable" must never
     * render identically to "healthy". Returning 0 for an unreadable counter
     * would let a caller show the reassuring green zero that a genuinely quiet
     * system shows. Callers must branch on null and say so.
     *
     * A negative value takes the same branch as a non-numeric one: the column
     * is `INT UNSIGNED NOT NULL DEFAULT 0` and the only write path is
     * {@see incrementUnmatchedRecipientCounter()}'s `col = col + 1`, so a
     * negative reading is impossible to produce legitimately and signals the
     * same hand-edited/corrupted-data condition.
     *
     * @return int|null Current counter value, or null if it could not be read
     */
    public function unmatchedRecipientCount(): ?int
    {
        $count = $this->readSettingsColumn('unmatched_recipient_count', queryFailed: $queryFailed);

        // The helper collapses "no row" and "column absent/NULL" into null; the
        // is_numeric()/negative checks are this method's own and stay here,
        // since no other read has them. All three conditions share one log line
        // and one null return, exactly as before.
        if ($count === null || !is_numeric($count) || $count < 0) {
            if (!$queryFailed) {
                $this->logWarning(sprintf(
                    'er_verification_settings row id=%d is missing or has a non-numeric or '
                    . 'negative unmatched_recipient_count value — reporting the unmatched-recipient '
                    . 'count as unreadable.',
                    self::SETTINGS_ROW_ID
                ));
            }
            return null;
        }

        return (int) $count;
    }

    /**
     * Timestamp of the most recent cron transport request
     *
     * Originally read `MAX(logdate) FROM logs WHERE logtype = 'CronRequest' AND
     * lognote NOT LIKE '%DENIED%'` — the `NOT LIKE '%DENIED%'` filter existed
     * because `users/cron/cron.php` logged every hit, including ones its own
     * `cron_ip` allowlist then denied, and a misconfigured transport hitting
     * from the wrong IP would otherwise look identical to a healthy one
     * (#1926). #1974 removed that log line entirely (144 near-worthless rows/day)
     * in favor of this dedicated `last_cron_request_at` column, written only by
     * {@see recordCronRequest()}, which `cron.php` calls only from its
     * non-denied path. The filter's entire rationale disappears by
     * construction: there is no write path by which a denied hit could ever
     * reach this column, so no filter is needed to exclude one.
     *
     * @return DateTimeImmutable|null When cron last ran, or null if it never has
     *                                (or the timestamp could not be read)
     */
    public function lastCronRequestAt(): ?DateTimeImmutable
    {
        // Empty $errorSubject: alone among this class's reads, a failed query
        // here has always been silent — cronReady() polls this on every admin
        // page render, so a persistent DB fault would otherwise flood the log
        // with one line per page view. The missing-row case is silent for the
        // same reason.
        $raw = $this->readSettingsColumn('last_cron_request_at', '');

        // empty(), not a null check: a NULL column (cron has never run), an
        // absent row, and a zero-length value all mean the same "no timestamp
        // recorded" here, and always have.
        if (empty($raw) || !is_scalar($raw)) {
            return null;
        }

        $value = (string) $raw;

        // MySQL's zero-date ('0000-00-00 00:00:00') does not throw when passed
        // to DateTimeImmutable's constructor — it silently parses to a bogus
        // year -1 date instead, which would otherwise slip past the catch
        // block below as a "successfully parsed" value. Since this column is
        // only ever written by recordCronRequest()'s `NOW()`, a zero-date
        // here can only mean external/manual tampering or a schema-level
        // default misconfiguration, never a value this class itself wrote —
        // treat it the same as any other unparseable value.
        if (str_starts_with($value, '0000-00-00')) {
            $this->logWarning(sprintf(
                'Unparseable last_cron_request_at "%s": MySQL zero-date',
                $value
            ));
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $e) {
            $this->logWarning(sprintf(
                'Unparseable last_cron_request_at "%s": %s',
                $value,
                $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * Record that the cron transport has hit this environment
     *
     * Called by `users/cron/cron.php` on every non-denied hit — unconditional,
     * not gated by any interval-claiming logic, since {@see lastCronRequestAt()}
     * and {@see cronReady()} need the raw "last touched" timestamp regardless of
     * how recently it was last recorded. Replaces the `CronRequest` log row this
     * class used to read (#1926) with a dedicated column, removing 144 rows/day
     * of log noise carrying no diagnostic value (#1974).
     *
     * Never throws: a failed UPDATE is logged and swallowed, matching this
     * class's fail-quietly contract for write paths (see
     * {@see incrementUnmatchedRecipientCounter()}) — a cron hit must never fail
     * loudly just because this bookkeeping write did. Logged under
     * LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, not LOG_CATEGORY_CRON_REQUEST —
     * this is a bookkeeping-write failure in the verification subsystem, the
     * same category every other write failure in this class uses, not cron
     * transport noise (the category #1974 exists to quiet down).
     *
     * Detects a missing `id = 1` row the same way
     * {@see incrementUnmatchedRecipientCounter()} does: `SET x = NOW()`
     * changes the row's value on match, so `count() === 0` means the row is
     * absent — UNLESS this method is called twice within the same second,
     * in which case a matched row can also report 0 rows changed (no
     * `MYSQL_ATTR_FOUND_ROWS`), producing a false "row missing" warning on a
     * write that actually succeeded. This is safe for `cron.php`'s sole
     * caller today (~10-minute call cadence, no realistic same-second
     * re-hit) but would NOT be safe for any future caller that might invoke
     * this method rapidly in succession (a manual "test cron" admin action,
     * a retry loop, etc.) — such a caller should use a confirmation-SELECT
     * like {@see setEnabled()} instead, not this `count()` shortcut. Without
     * this check at all, a missing settings row would make this method
     * report success having recorded nothing, and {@see cronReady()} would
     * permanently report "stalled" with no signal anywhere that the
     * database, not cron, is the actual problem.
     *
     * @return bool True if the timestamp was recorded successfully
     */
    public function recordCronRequest(): bool
    {
        $this->db->query(
            'UPDATE er_verification_settings SET last_cron_request_at = NOW() WHERE id = ?',
            [self::SETTINGS_ROW_ID]
        );

        if ($this->db->error()) {
            $this->logWarning(sprintf(
                'Failed to record cron request in er_verification_settings: %s',
                $this->db->errorString() ?: 'unknown'
            ));
            return false;
        }

        // count(), not a confirmation SELECT — deliberately. See this method's
        // docblock above for why `SET x = NOW()` makes count() === 0 an
        // unambiguous "row missing" signal here, and why
        // settingsRowConfirmedPresent() must NOT be substituted in.
        if ($this->db->count() === 0) {
            $this->logWarning(sprintf(
                'er_verification_settings row id=%d not found; cron request is not being recorded.',
                self::SETTINGS_ROW_ID
            ));
            return false;
        }

        return true;
    }
}
