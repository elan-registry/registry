<?php

declare(strict_types=1);

namespace Tests\Support;

use ElanRegistry\Cron\BrevoEventReconciliationClient;

/**
 * FakeBrevoEventReconciliationClient - Scripted stand-in for the Brevo
 * statistics poll, for BrevoEventReconciliationJobTest
 *
 * Extends the real client so it satisfies the job's constructor type, but
 * overrides fetchEvents() to return a canned page and record the exact
 * arguments it was called with — which is how the tests assert the window,
 * page size, offset, and the "exactly one page per run" bound.
 *
 * The real class's constructor is bypassed entirely (no parent::__construct()
 * call): it takes a DatabaseInterface only to read the Brevo API key, and
 * nothing in the overridden method path touches it.
 *
 * Deliberately a *named* class rather than an anonymous one, per the
 * `impureMethod.pure` rationale in CronJobGuardFakeDatabase's docblock.
 *
 * @package Tests\Support
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1889
 */
class FakeBrevoEventReconciliationClient extends BrevoEventReconciliationClient
{
    /** Number of times fetchEvents() has been called. */
    public int $fetchCalls = 0;

    /** @var list<array{start: \DateTimeImmutable, end: \DateTimeImmutable, limit: int, offset: int}> */
    public array $fetchArgs = [];

    /**
     * @param list<object>|null $events Returned verbatim by every fetchEvents()
     *        call. Null scripts a poll failure — the same contract the real
     *        client's `fetchEvents()` now has (#2061).
     */
    public function __construct(private ?array $events = [])
    {
    }

    /**
     * @return list<object>|null Stub events shaped like the SDK's
     *         GetEmailEventReportEvents (the vendored SDK is not on the unit
     *         suite's autoloader, so real model instances aren't available),
     *         or null to script a poll failure.
     */
    public function fetchEvents(
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        int $limit,
        int $offset
    ): ?array {
        $this->fetchCalls++;
        $this->fetchArgs[] = [
            'start' => $startDate,
            'end' => $endDate,
            'limit' => $limit,
            'offset' => $offset,
        ];

        return $this->events;
    }
}
