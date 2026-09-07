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
     * boundary sits at a single unambiguous point that a test can pin by inserting
     * a log row at a known age. Doubling the interval gives one missed tick of
     * slack, so a single late or dropped hit does not flap the readiness indicator.
     *
     * Never throws: no cron log at all, an unreadable one, or a missing
     * `CRON_TRANSPORT_INTERVAL_MINUTES` (see {@see cronStaleAfterSeconds()})
     * all report "not ready" rather than raising.
     *
     * @return bool True if a non-denied CronRequest was logged less than
     *              twice the cron transport interval ago
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
     * Age, in seconds, beyond which the newest `CronRequest` log means cron is stalled.
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
     * Timestamp of the most recent (non-denied) cron transport request
     *
     * `users/cron/cron.php` writes a `CronRequest` log row on every hit, including
     * ones its own `cron_ip` allowlist then denies ("Cron request DENIED from
     * $ip.") — a misconfigured transport hitting from the wrong IP would otherwise
     * look identical to a healthy one here. The `NOT LIKE '%DENIED%'` filter
     * excludes those so this reflects "cron actually ran", not merely "something
     * requested the cron URL".
     *
     * @return DateTimeImmutable|null When cron last ran, or null if it never has
     *                                (or the timestamp could not be read)
     */
    public function lastCronRequestAt(): ?DateTimeImmutable
    {
        $this->db->query(
            "SELECT MAX(logdate) AS last_logdate FROM logs WHERE logtype = ? AND lognote NOT LIKE '%DENIED%'",
            [LogCategories::LOG_CATEGORY_CRON_REQUEST]
        );

        if ($this->db->error()) {
            return null;
        }

        $row = $this->db->first();
        // MAX() over zero matching rows yields one row whose column is NULL,
        // so an absent cron history arrives here as a null column, not no row.
        if (!is_object($row) || empty($row->last_logdate)) {
            return null;
        }

        try {
            return new DateTimeImmutable((string) $row->last_logdate);
        } catch (\Exception $e) {
            logger(0, LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING, sprintf(
                'Unparseable CronRequest logdate "%s": %s',
                (string) $row->last_logdate,
                $e->getMessage()
            ));
            return null;
        }
    }
}
