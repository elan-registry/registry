<?php

declare(strict_types=1);

namespace Tests\Support;

use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Exceptions\CarDatabaseException;

/**
 * SpyEmailEventApplier - Recording stand-in for EmailEventApplier, for
 * BrevoEventReconciliationJobTest and BrevoSuppressionSyncJobTest
 *
 * {@see EmailEventApplier} is deliberately *not* `final` for exactly this
 * reason: it is an injected collaborator of BrevoWebhookEventProcessor,
 * BrevoEventReconciliationJob and BrevoSuppressionSyncJob, whose unit tests
 * must substitute it (see
 * its own class docblock, which follows CarRepository's and
 * CarVerificationManager's precedent). This class therefore extends it and
 * overrides `apply()`, so it satisfies the constructor's type hint directly
 * without any wrapping.
 *
 * Records every apply() call so tests can assert both the arguments and the
 * ordering, and can be scripted to throw CarDatabaseException for one chosen
 * message-id (per-event failure isolation) or owner. The owner case tests
 * per-owner-write counting. One event that matches several owners makes calls that share one
 * message-id, so the message-id alone cannot tell them apart.
 *
 * The constructor skips the parent's, so an inherited public method would
 * run real code on uninitialized collaborators. The spy therefore overrides
 * every public method of EmailEventApplier, and
 * SpyEmailEventApplierContractTest fails when a new one is not overridden.
 *
 * Named class, not anonymous: see FakeDatabase (`impureMethod.pure`).
 *
 * @package Tests\Support
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1889
 */
class SpyEmailEventApplier extends EmailEventApplier
{
    /** @var list<array{userId: int, email: string, event: string, reason: ?string, messageId: string, occurredAt: string, source: string}> */
    public array $calls = [];

    /** apply() throws CarDatabaseException when the message-id equals this. */
    private ?string $failOnMessageId = null;

    /**
     * apply() throws CarDatabaseException when the owner user ID is in this set.
     *
     * @var list<int>
     */
    private array $failOnUserIds = [];

    /** apply() throws this exception on every call when set (a crash, not a write failure). */
    private ?\Throwable $failWith = null;

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
     * Make apply() throw for these owner user IDs, regardless of message-id.
     *
     * Needed when several calls for the same event share one message-id (a
     * single event applied to several matched owners), so failOn() alone
     * cannot isolate individual calls. Accepts one or more IDs so a test can
     * script more than one failing owner-write within the same event,
     * proving skippedCount is counted per owner-write, not "any failure in
     * this event marks it skipped".
     */
    public function failOnUserId(int ...$userIds): void
    {
        $this->failOnUserIds = array_values($userIds);
    }

    /**
     * Make apply() throw $e, whatever its type. A CarDatabaseException is a
     * handled write failure; any other type models a crash of the job.
     */
    public function failWith(\Throwable $e): void
    {
        $this->failWith = $e;
    }

    public function apply(
        int $userId,
        string $email,
        string $event,
        ?string $reason,
        string $messageId,
        string $occurredAt,
        string $source
    ): void {
        $this->calls[] = compact('userId', 'email', 'event', 'reason', 'messageId', 'occurredAt', 'source');

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        if ($messageId === $this->failOnMessageId || in_array($userId, $this->failOnUserIds, true)) {
            throw new CarDatabaseException('simulated write failure');
        }
    }

    /** @return list<string> The message-id of each recorded call, in order. */
    public function messageIds(): array
    {
        return array_column($this->calls, 'messageId');
    }
}
