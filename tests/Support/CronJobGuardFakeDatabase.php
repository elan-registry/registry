<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * CronJobGuardFakeDatabase - FakeDatabase double for CronJobGuardTest
 *
 * CronJobGuard issues a single `UPDATE settings ...` query per `claim()` call
 * and derives its return value entirely from `count()` (rows changed) and
 * `error()` — canned via constructor flags, following the same shape as
 * VerificationSettingsFakeDatabase.
 *
 * Deliberately a *named* class rather than `new class extends FakeDatabase { ... }`:
 * PHPStan reports `impureMethod.pure` when an anonymous class overrides one of
 * DatabaseInterface's `@phpstan-impure` methods (`query()`, `error()`, `count()`
 * here) with a body that doesn't depend on mutable state, because an anonymous
 * class can never be extended later to add one. A named class with real
 * constructor-driven state is exempt from that check. See
 * VerificationSettingsFakeDatabase's docblock for the same rationale.
 *
 * @package Tests\Support
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/2027
 */
class CronJobGuardFakeDatabase extends FakeDatabase
{
    private string $lastSql = '';

    /** @var array<mixed> */
    private array $lastParams = [];

    private int $queryCount = 0;

    /**
     * @param bool $claimSucceeds When true, count() reports 1 row changed
     *                            (the claim was won); when false, 0. Ignored
     *                            when $claimSucceedsOnce is true.
     * @param bool $queryErrors When true, error() reports true after query().
     * @param bool $claimSucceedsOnce When true, count() reports 1 on the
     *                                first query() call and 0 on every call
     *                                after — simulates a second caller
     *                                racing the same guard column and losing.
     */
    public function __construct(
        private readonly bool $claimSucceeds = true,
        private readonly bool $queryErrors = false,
        private readonly bool $claimSucceedsOnce = false,
    ) {
    }

    public function query(string $sql, array $params = []): self
    {
        $this->lastSql = $sql;
        $this->lastParams = $params;
        $this->queryCount++;
        return $this;
    }

    public function error(): bool
    {
        return $this->queryErrors;
    }

    public function count(): int
    {
        if ($this->claimSucceedsOnce) {
            return $this->queryCount === 1 ? 1 : 0;
        }

        return $this->claimSucceeds ? 1 : 0;
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
