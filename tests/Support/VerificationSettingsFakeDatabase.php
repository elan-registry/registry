<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * VerificationSettingsFakeDatabase - FakeDatabase double for VerificationSettingsTest
 *
 * VerificationSettings issues several distinct query shapes against this double
 * (`er_verification_settings`'s `enabled` column, `plg_sendinblue`, and — since
 * #1974 — `er_verification_settings`'s `last_cron_request_at` column), and each
 * unit test needs to control the row/error state seen by one or more of them
 * independently — canned via constructor flags rather than a single static
 * `first()`/`error()` override.
 *
 * Deliberately a *named* class rather than `new class extends FakeDatabase { ... }`:
 * PHPStan reports `impureMethod.pure` when an anonymous class overrides one of
 * DatabaseInterface's `@phpstan-impure` methods (`query()`, `first()`, `error()` here)
 * with a body that doesn't depend on mutable state, because an anonymous class can
 * never be extended later to add one. A named class with real constructor-driven
 * state is exempt from that check. See SqlRecordingFakeDatabase's docblock for the
 * same rationale, established first for a different test.
 *
 * Query-dispatch note: `setEnabled()` now issues a follow-up
 * `SELECT id FROM er_verification_settings ...` after its `UPDATE` to confirm the
 * row still exists (replacing an earlier, incorrect `count() === 0` check — see
 * #1926 review). This double tracks "which query is `first()`/`error()` about to
 * answer for" by SQL sniffing, the same way it already tracks `wasUpdateCalled()`,
 * so `$confirmSelectRowValue`/`$confirmSelectErrors` can be set independently of
 * whatever `$firstRowValue`/`$queryErrors` say about the *other* queries
 * (`er_verification_settings` SELECT, `plg_sendinblue` SELECT) this class also issues.
 *
 * Query-dispatch note (#1974): `lastCronRequestAt()`'s `SELECT
 * last_cron_request_at FROM er_verification_settings WHERE id = ?` and
 * `isEnabled()`'s `SELECT enabled FROM er_verification_settings WHERE id = ?`
 * share a table but read different columns, so they need independent control
 * the same way the confirmation SELECT does — sniffed by column name, same
 * pattern as `isConfirmSelect()`. `recordCronRequest()`'s `UPDATE ...
 * last_cron_request_at = NOW() ...` is tracked the same way `wasUpdateCalled()`
 * already tracks the `enabled` UPDATE, via a dedicated flag rather than
 * overloading `updateQueryWasIssued` (the two UPDATEs must be distinguishable
 * so a test can simulate one write failing without affecting the other).
 *
 * @package Tests\Support
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1926
 * @see https://github.com/elan-registry/registry/issues/1974
 */
class VerificationSettingsFakeDatabase extends FakeDatabase
{
    private bool $updateQueryWasIssued = false;
    private bool $cronRequestUpdateWasIssued = false;
    private bool $queryWasCalled = false;
    private bool $brevoTableWasQueried = false;
    private string $lastSql = '';

    /** True once the post-UPDATE confirmation SELECT (`SELECT id FROM ...`) has run. */
    private bool $confirmSelectWasIssued = false;

    /** True once `lastCronRequestAt()`'s `SELECT last_cron_request_at ...` has run. */
    private bool $cronRequestSelectWasIssued = false;

    /**
     * @param array<string, mixed>|object $firstRowValue Row handed back by first() for every
     *                                                    query EXCEPT the post-UPDATE confirmation
     *                                                    SELECT (see $confirmSelectRowValue).
     *                                                    Defaults to [] — the real \DB
     *                                                    empty-result value.
     * @param bool $queryErrors When true, error() reports true after any query() EXCEPT the
     *                          post-UPDATE confirmation SELECT (see $confirmSelectErrors).
     * @param bool $errorAfterUpdateOnly When true, error() only reports true once the
     *                                   `UPDATE er_verification_settings` query() has
     *                                   actually run — used to isolate a simulated write
     *                                   failure from an otherwise-healthy read path (e.g.
     *                                   brevoReady()'s own SELECT). VerificationSettings
     *                                   writes via query(), not update() — this double
     *                                   tracks the write by sniffing the SQL text.
     * @param array<string, mixed>|object|null $confirmSelectRowValue Row handed back by first()
     *                                   specifically for the post-UPDATE `SELECT id FROM
     *                                   er_verification_settings ...` confirmation query. Null
     *                                   (the default) means "the row is confirmed present" —
     *                                   i.e. `(object) ['id' => 1]` — since a successful UPDATE
     *                                   finding the row gone is the rare case under test, not the
     *                                   default assumption; this is what every pre-existing test
     *                                   (written before this parameter existed) implicitly expects.
     *                                   Pass `[]` explicitly to simulate the row confirmed missing.
     * @param bool $confirmSelectErrors When true, the post-UPDATE confirmation SELECT itself
     *                                   fails (independent of $queryErrors/$errorAfterUpdateOnly).
     * @param array<int, mixed>|null $errorInfoValue PDO errorInfo() triple to report while
     *                                   error() is true (e.g. `['42S02', 1146, "Table '...'
     *                                   doesn't exist"]` for "table missing", or a different
     *                                   SQLSTATE for a genuine fault). Null (the default) keeps
     *                                   FakeDatabase's real-\DB-shaped `[0, null, null]` no-error
     *                                   triple, EXCEPT while error() is true and no override was
     *                                   given, in which case a generic non-"table missing" triple
     *                                   is reported so tests written before this parameter existed
     *                                   keep exercising the "genuine fault" branch they always did.
     * @param array<string, mixed>|object $cronRequestRowValue Row handed back by first()
     *                                   specifically for `lastCronRequestAt()`'s `SELECT
     *                                   last_cron_request_at FROM er_verification_settings ...`.
     *                                   Defaults to [] (no row / never run). Independent of
     *                                   $firstRowValue, which answers every OTHER query shape.
     * @param bool $cronRequestQueryErrors When true, error() reports true specifically for
     *                                   `lastCronRequestAt()`'s SELECT, independent of $queryErrors.
     * @param bool $cronRequestUpdateErrors When true, error() reports true specifically for
     *                                   `recordCronRequest()`'s `UPDATE ... last_cron_request_at =
     *                                   NOW() ...`, independent of every other error flag.
     * @param int $cronRequestUpdateCount Value count() reports specifically after
     *                                   `recordCronRequest()`'s UPDATE. Defaults to 1 (the row
     *                                   matched and changed) — matching every other write-related
     *                                   default in this fake, which represents the successful case
     *                                   pre-existing tests implicitly expect. Pass 0 to simulate the
     *                                   `id = 1` settings row being absent.
     */
    public function __construct(
        private readonly array|object $firstRowValue = [],
        private readonly bool $queryErrors = false,
        private readonly bool $errorAfterUpdateOnly = false,
        private readonly array|object|null $confirmSelectRowValue = null,
        private readonly bool $confirmSelectErrors = false,
        private readonly ?array $errorInfoValue = null,
        private readonly array|object $cronRequestRowValue = [],
        private readonly bool $cronRequestQueryErrors = false,
        private readonly bool $cronRequestUpdateErrors = false,
        private readonly int $cronRequestUpdateCount = 1,
    ) {
    }

    private function isConfirmSelect(string $sql): bool
    {
        // Distinguish from isEnabled()'s `SELECT enabled FROM er_verification_settings
        // WHERE id = ?` (which also contains the substring "id") by requiring the
        // select-list itself to be exactly `id`, matching setEnabled()'s literal
        // `SELECT id FROM er_verification_settings WHERE id = ?` confirmation query.
        return (bool) preg_match('/^\s*SELECT\s+id\s+FROM\s+er_verification_settings\b/i', $sql);
    }

    private function isCronRequestSelect(string $sql): bool
    {
        return (bool) preg_match('/^\s*SELECT\s+last_cron_request_at\s+FROM\s+er_verification_settings\b/i', $sql);
    }

    private function isCronRequestUpdate(string $sql): bool
    {
        // Anchored, matching isConfirmSelect()/isCronRequestSelect()'s style —
        // unanchored substring checks would misclassify any future statement
        // that merely mentions last_cron_request_at (e.g. a combined UPDATE
        // touching both `enabled` and this column) as this specific write.
        return (bool) preg_match(
            '/^\s*UPDATE\s+er_verification_settings\s+SET\s+last_cron_request_at\b/i',
            $sql
        );
    }

    public function query(string $sql, array $params = []): self
    {
        $this->queryWasCalled = true;
        $this->lastSql = $sql;
        if (stripos($sql, 'plg_sendinblue') !== false) {
            $this->brevoTableWasQueried = true;
        }
        $this->cronRequestUpdateWasIssued = $this->isCronRequestUpdate($sql);
        if (stripos($sql, 'UPDATE') !== false
            && stripos($sql, 'er_verification_settings') !== false
            && !$this->cronRequestUpdateWasIssued
        ) {
            $this->updateQueryWasIssued = true;
        }
        $this->confirmSelectWasIssued = $this->isConfirmSelect($sql);
        $this->cronRequestSelectWasIssued = $this->isCronRequestSelect($sql);
        return $this;
    }

    public function error(): bool
    {
        if ($this->cronRequestUpdateWasIssued) {
            return $this->cronRequestUpdateErrors;
        }
        if ($this->cronRequestSelectWasIssued) {
            return $this->cronRequestQueryErrors;
        }
        if ($this->confirmSelectWasIssued) {
            return $this->confirmSelectErrors;
        }
        if ($this->errorAfterUpdateOnly) {
            return $this->updateQueryWasIssued;
        }
        return $this->queryErrors;
    }

    public function errorString(): string
    {
        return $this->error() ? 'ERROR #42S02: simulated failure' : '';
    }

    public function count(): int
    {
        if ($this->cronRequestUpdateWasIssued) {
            return $this->cronRequestUpdateCount;
        }
        // Hardcoded 0 (not constructor-driven, unlike error()/first()'s
        // fallthroughs) because setEnabled() deliberately does not use
        // count() at all — it abandoned that approach for a confirmation
        // SELECT after #1926's review found count() ambiguous for its own
        // write shape. If a future method here needs a count()-dependent
        // path, add a dedicated constructor param rather than reusing this.
        return 0;
    }

    public function errorInfo(): array
    {
        if (!$this->error()) {
            return [0, null, null];
        }
        return $this->errorInfoValue ?? ['HY000', 1234, 'simulated generic failure'];
    }

    public function first(bool $assoc = false): array|object
    {
        if ($this->cronRequestSelectWasIssued) {
            return $this->cronRequestRowValue;
        }
        if ($this->confirmSelectWasIssued) {
            return $this->confirmSelectRowValue ?? (object) ['id' => 1];
        }
        return $this->firstRowValue;
    }

    public function wasQueried(): bool
    {
        return $this->queryWasCalled;
    }

    public function wasUpdateCalled(): bool
    {
        return $this->updateQueryWasIssued;
    }

    public function wasCronRequestUpdateCalled(): bool
    {
        return $this->cronRequestUpdateWasIssued;
    }

    public function wasBrevoTableQueried(): bool
    {
        return $this->brevoTableWasQueried;
    }

    /** SQL text of the most recent query() call, or '' if none has run yet. */
    public function lastSql(): string
    {
        return $this->lastSql;
    }
}
