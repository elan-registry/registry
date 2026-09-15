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
        $this->db->query(
            'SELECT enabled FROM er_verification_settings WHERE id = ?',
            [self::SETTINGS_ROW_ID]
        );

        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, sprintf(
                'Failed to read er_verification_settings: %s',
                $this->db->errorString() ?: 'unknown'
            ));
            return false;
        }

        $row = $this->db->first();
        if (!is_object($row) || !isset($row->enabled)) {
            // The migration seeds id=1 and nothing in the app ever deletes it, so
            // an absent row means the migration part-applied or the table was
            // truncated/restored incompletely — a schema problem, not "switched
            // off". Fail closed, but never silently: an admin trying to enable
            // verification here would otherwise see a misleading Brevo-related
            // rejection with no clue the real fault is a missing settings row.
            logger(0, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, sprintf(
                'er_verification_settings row id=%d is missing — reporting verification OFF '
                . 'as a fail-closed default. Re-run `composer migrate` to reseed the row.',
                self::SETTINGS_ROW_ID
            ));
            return false;
        }

        return (bool) $row->enabled;
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
            logger($actingUserId, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, 'Refused to enable verification: Brevo is not configured.');
            throw new VerificationConfigException();
        }

        $this->db->query(
            'UPDATE er_verification_settings SET enabled = ? WHERE id = ?',
            [$enabled ? 1 : 0, self::SETTINGS_ROW_ID]
        );

        if ($this->db->error()) {
            logger($actingUserId, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, sprintf(
                'Failed to write er_verification_settings (enabled=%d): %s',
                $enabled ? 1 : 0,
                $this->db->errorString() ?: 'unknown'
            ));
            return false;
        }

        // PDO's rowCount() after an UPDATE reports rows CHANGED, not rows MATCHED
        // (no MYSQL_ATTR_FOUND_ROWS is set on this connection) — writing the same
        // value the row already had legitimately yields count() === 0, so that
        // alone can't distinguish "row missing" from "value unchanged". Confirm
        // the row actually exists with a follow-up read instead.
        $this->db->query(
            'SELECT id FROM er_verification_settings WHERE id = ?',
            [self::SETTINGS_ROW_ID]
        );
        if ($this->db->error() || !is_object($this->db->first())) {
            logger($actingUserId, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, sprintf(
                'er_verification_settings UPDATE (enabled=%d) could not be confirmed — the id=%d '
                . 'settings row appears to be missing. Re-run `composer migrate` to reseed it.',
                $enabled ? 1 : 0,
                self::SETTINGS_ROW_ID
            ));
            return false;
        }

        logger($actingUserId, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_CHANGED, sprintf(
            'Verification system %s.',
            $enabled ? 'enabled' : 'disabled'
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
            logger(0, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, sprintf(
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
     * Increment the count of inbound Brevo webhook events matched to no car
     *
     * Called by the Brevo webhook receiver (#1887) when an event's recipient
     * email matches no `cars.email` value. A rising count with verification
     * enabled signals recipients whose emails have drifted from what any car
     * record has on file.
     *
     * Never throws: a failed UPDATE is logged and swallowed, matching this
     * class's fail-quietly contract for the write paths a webhook receiver
     * depends on — the webhook's own 2xx/logged response to Brevo must not
     * hinge on this counter succeeding.
     *
     * @return bool True if the counter was incremented successfully
     */
    public function incrementUnmatchedRecipientCounter(): bool
    {
        $this->db->query(
            'UPDATE er_verification_settings SET unmatched_webhook_recipient_count = unmatched_webhook_recipient_count + 1 WHERE id = ?',
            [self::SETTINGS_ROW_ID]
        );

        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, sprintf(
                'Failed to increment er_verification_settings.unmatched_webhook_recipient_count: %s',
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
            logger(0, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, sprintf(
                'er_verification_settings row id=%d not found; unmatched-recipient counter is not being recorded.',
                self::SETTINGS_ROW_ID
            ));
            return false;
        }

        return true;
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
        $this->db->query(
            'SELECT last_cron_request_at FROM er_verification_settings WHERE id = ?',
            [self::SETTINGS_ROW_ID]
        );

        if ($this->db->error()) {
            return null;
        }

        $row = $this->db->first();
        if (!is_object($row) || empty($row->last_cron_request_at)) {
            return null;
        }

        $value = (string) $row->last_cron_request_at;

        // MySQL's zero-date ('0000-00-00 00:00:00') does not throw when passed
        // to DateTimeImmutable's constructor — it silently parses to a bogus
        // year -1 date instead, which would otherwise slip past the catch
        // block below as a "successfully parsed" value. Since this column is
        // only ever written by recordCronRequest()'s `NOW()`, a zero-date
        // here can only mean external/manual tampering or a schema-level
        // default misconfiguration, never a value this class itself wrote —
        // treat it the same as any other unparseable value.
        if (str_starts_with($value, '0000-00-00')) {
            logger(0, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, sprintf(
                'Unparseable last_cron_request_at "%s": MySQL zero-date',
                $value
            ));
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $e) {
            logger(0, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, sprintf(
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
            logger(0, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, sprintf(
                'Failed to record cron request in er_verification_settings: %s',
                $this->db->errorString() ?: 'unknown'
            ));
            return false;
        }

        if ($this->db->count() === 0) {
            logger(0, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, sprintf(
                'er_verification_settings row id=%d not found; cron request is not being recorded.',
                self::SETTINGS_ROW_ID
            ));
            return false;
        }

        return true;
    }
}
