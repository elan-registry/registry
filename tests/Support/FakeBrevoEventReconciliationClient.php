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
 * page size, offset, event type, and the "one page per event type" bound.
 *
 * It filters like Brevo's `event` parameter: a call returns only the
 * scripted events whose getEvent() equals the asked-for type, newest-first
 * order kept, cut to `$limit`. So a test can show that a page full of one
 * type does not hide another type. With `$applyTypeFilter` false, every
 * call returns all scripted events, like an API that ignores the filter.
 * Use it for events whose type field is not a string.
 *
 * The real class's constructor is bypassed entirely (no parent::__construct()
 * call): it takes a DatabaseInterface only to read the Brevo API key, and
 * nothing in the overridden method path touches it.
 *
 * Named class, not anonymous: see FakeDatabase (`impureMethod.pure`).
 *
 * @package Tests\Support
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1889
 */
class FakeBrevoEventReconciliationClient extends BrevoEventReconciliationClient
{
    /** Number of times fetchEvents() has been called. */
    public int $fetchCalls = 0;

    /** @var list<array{start: \DateTimeImmutable, end: \DateTimeImmutable, limit: int, offset: int, eventType: string}> */
    public array $fetchArgs = [];

    /**
     * @param list<object>|null $events The scripted events of all types. Null
     *        scripts a poll failure for every type — the same contract the
     *        real client's `fetchEvents()` has (#2061).
     * @param list<string> $failingTypes Event types whose poll fails (returns null)
     * @param bool $applyTypeFilter False returns every scripted event on every call
     */
    public function __construct(
        private ?array $events = [],
        private array $failingTypes = [],
        private bool $applyTypeFilter = true
    ) {
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
        int $offset,
        string $eventType
    ): ?array {
        $this->fetchCalls++;
        $this->fetchArgs[] = [
            'start' => $startDate,
            'end' => $endDate,
            'limit' => $limit,
            'offset' => $offset,
            'eventType' => $eventType,
        ];

        if ($this->events === null || in_array($eventType, $this->failingTypes, true)) {
            return null;
        }

        $ofType = $this->applyTypeFilter
            ? array_values(array_filter(
                $this->events,
                static fn (object $event): bool => method_exists($event, 'getEvent') && $event->getEvent() === $eventType
            ))
            : $this->events;

        return array_slice($ofType, $offset, $limit);
    }

    /** @return list<string> The event type of each fetchEvents() call, in order. */
    public function fetchedTypes(): array
    {
        return array_column($this->fetchArgs, 'eventType');
    }
}
