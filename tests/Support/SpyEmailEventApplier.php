<?php

declare(strict_types=1);

namespace Tests\Support;

use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Exceptions\CarDatabaseException;

/**
 * SpyEmailEventApplier - Recording stand-in for EmailEventApplier, for
 * BrevoEventReconciliationJobTest
 *
 * {@see EmailEventApplier} is deliberately *not* `final` for exactly this
 * reason: it is an injected collaborator of both BrevoWebhookEventProcessor
 * and BrevoEventReconciliationJob, whose unit tests must substitute it (see
 * its own class docblock, which follows CarRepository's and
 * CarVerificationManager's precedent). This class therefore extends it and
 * overrides `apply()`, so it satisfies the constructor's type hint directly
 * without any wrapping.
 *
 * Records every apply() call so tests can assert both the arguments and the
 * ordering, and can be scripted to throw CarDatabaseException for one chosen
 * message-id (per-event failure isolation) or car id (#2061: per-car-write
 * counting within a single multi-car event, where every call shares one
 * message-id and so cannot be distinguished by it alone).
 *
 * Deliberately a *named* class rather than an anonymous one, per the
 * `impureMethod.pure` rationale in CronJobGuardFakeDatabase's docblock.
 *
 * @package Tests\Support
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1889
 */
class SpyEmailEventApplier extends EmailEventApplier
{
    /** @var list<array{carId: int, email: string, event: string, reason: ?string, messageId: string, occurredAt: string}> */
    public array $calls = [];

    /** apply() throws CarDatabaseException when the message-id equals this. */
    private ?string $failOnMessageId = null;

    /** apply() throws CarDatabaseException when the car id is in this set. */
    private array $failOnCarIds = [];

    public function __construct()
    {
        // Deliberately does not call parent::__construct(): every inherited
        // code path is overridden, so the real repo/verification-manager
        // collaborators are never touched.
    }

    /** Make apply() throw for the event carrying this message-id. */
    public function failOn(string $messageId): void
    {
        $this->failOnMessageId = $messageId;
    }

    /**
     * Make apply() throw for these car ids, regardless of message-id.
     *
     * Needed when several calls for the same event share one message-id (a
     * single event applied to multiple matched cars), so failOn() alone
     * cannot isolate individual calls. Accepts one or more ids so a test can
     * script more than one failing car-write within the same multi-car event
     * — proving skippedCount is counted per car-write, not "any failure in
     * this event marks it skipped".
     */
    public function failOnCarId(int ...$carIds): void
    {
        $this->failOnCarIds = $carIds;
    }

    public function apply(
        int $carId,
        string $email,
        string $event,
        ?string $reason,
        string $messageId,
        string $occurredAt
    ): void {
        $this->calls[] = compact('carId', 'email', 'event', 'reason', 'messageId', 'occurredAt');

        if ($messageId === $this->failOnMessageId || in_array($carId, $this->failOnCarIds, true)) {
            throw new CarDatabaseException('simulated write failure');
        }
    }

    /** @return list<string> The message-id of each recorded call, in order. */
    public function messageIds(): array
    {
        return array_column($this->calls, 'messageId');
    }
}
