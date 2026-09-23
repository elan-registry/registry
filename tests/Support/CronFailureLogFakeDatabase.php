<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * CronFailureLogFakeDatabase - FakeDatabase double for CronJobFailureLogReaderTest
 *
 * {@see \ElanRegistry\Cron\CronJobFailureLogReader::recentFailures()} issues
 * up to two queries against `logs`: a `COUNT(*)` over the lookback window,
 * and — only when that count is non-zero — a `SELECT logdate, lognote` for the
 * most recent few. This fake dispatches on the SQL so the two can fail
 * independently, which is the point: the reader deliberately keeps a
 * known-good count when only the detail query fails, and that behaviour cannot
 * be exercised by a double whose `error()` answers both queries at once.
 *
 * Deliberately a *named* class rather than `new class extends FakeDatabase { ... }`:
 * PHPStan reports `impureMethod.pure` when an anonymous class overrides one of
 * DatabaseInterface's `@phpstan-impure` methods with a body that doesn't depend
 * on mutable state. See CronJobGuardFakeDatabase's docblock for the same
 * rationale.
 *
 * @package Tests\Support
 * @since v2.30.3
 */
class CronFailureLogFakeDatabase extends FakeDatabase
{
    /** @var list<string> */
    private array $sqlLog = [];

    private string $lastSql = '';

    /** @var array<mixed> */
    private array $lastParams = [];

    private bool $lastQueryWasCount = false;

    /**
     * @param int $count Value reported by the COUNT(*) query.
     * @param list<array{logdate: string, lognote: string}> $rows Rows returned
     *        by the detail query, newest first — the fake does not sort, so a
     *        test asserting on ordering asserts on the SQL, not on this.
     * @param bool $countErrors When true, error() reports true after the count
     *                          query — the reader's null-not-zero path.
     * @param bool $countRowMissing When true, the count query succeeds but
     *                          returns no row at all, which the reader must
     *                          also treat as unreadable rather than as zero.
     * @param bool $detailErrors When true, error() reports true after the
     *                          detail query only — leaving the count good, so
     *                          the reader's "keep the count, drop the detail"
     *                          branch is reachable on its own.
     */
    public function __construct(
        private readonly int $count = 0,
        private readonly array $rows = [],
        private readonly bool $countErrors = false,
        private readonly bool $countRowMissing = false,
        private readonly bool $detailErrors = false,
    ) {
    }

    public function query(string $sql, array $params = []): self
    {
        $this->sqlLog[] = $sql;
        $this->lastSql = $sql;
        $this->lastParams = $params;
        $this->lastQueryWasCount = stripos($sql, 'COUNT(*)') !== false;

        return $this;
    }

    public function error(): bool
    {
        return $this->lastQueryWasCount ? $this->countErrors : $this->detailErrors;
    }

    public function first(bool $assoc = false): array|object
    {
        if (!$this->lastQueryWasCount || $this->countRowMissing) {
            return $assoc ? [] : (object) [];
        }

        $row = ['cnt' => $this->count];

        return $assoc ? $row : (object) $row;
    }

    /** @return array<int, array<string, mixed>|object> */
    public function results(bool $assoc = false): array
    {
        if ($this->lastQueryWasCount) {
            return [];
        }

        return $assoc ? $this->rows : array_map(static fn (array $r): object => (object) $r, $this->rows);
    }

    /** SQL text of every query() call, in order. */
    public function sqlLog(): array
    {
        return $this->sqlLog;
    }

    /** SQL text of the most recent query() call, or '' if none has run yet. */
    public function lastSql(): string
    {
        return $this->lastSql;
    }

    /** Bound params of the most recent query() call, or [] if none has run yet. */
    public function lastParams(): array
    {
        return $this->lastParams;
    }
}
