<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

/**
 * SendResult - Outcome of one car's verification-email send attempt
 *
 * Returned by {@see CarVerificationSendService::sendOne()}. Immutable value
 * object with named constructors instead of a public constructor. `sent()`
 * and `failed()` enforce status/reason consistency (sent has no reason,
 * failed always has one); `sentUnrecorded()` is the deliberate exception —
 * status is still "sent" (the email really was delivered and must never be
 * retried) but it carries a non-null, renderable warning so a bookkeeping
 * failure after a real send is not entirely invisible to the admin.
 *
 * Deliberately no `skipped` status here — a skip is decided one layer up, in
 * the admin page, before `sendOne()` is ever called for that car (a car that
 * fails the per-row eligibility re-check between GET and POST never reaches
 * this class at all). This type only describes what happened once a send was
 * actually attempted.
 *
 * @package ElanRegistry\Car
 * @since v2.30.3
 * @see https://github.com/elan-registry/registry/issues/1884
 */
final class SendResult
{
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    private function __construct(
        public readonly int $carId,
        public readonly string $status,
        public readonly ?string $reason,
    ) {
    }

    /**
     * @param int $carId Car ID the email was sent for
     */
    public static function sent(int $carId): self
    {
        return new self($carId, self::STATUS_SENT, null);
    }

    /**
     * @param int $carId Car ID the send was attempted for
     * @param string $reason Human-readable failure reason, safe to render (escaped) in the admin report
     */
    public static function failed(int $carId, string $reason): self
    {
        return new self($carId, self::STATUS_FAILED, $reason);
    }

    /**
     * Email was genuinely delivered, but recording it (er_email_events insert
     * and/or the verification_attempts increment) failed afterward. Status
     * stays STATUS_SENT — the car must never be re-sent to on this basis —
     * but $reason is non-null so callers can render a distinct warning
     * instead of silently folding this into an ordinary successful send.
     *
     * @param int $carId Car ID the email was sent for
     * @param string $reason Human-readable warning, safe to render (escaped) in the admin report
     */
    public static function sentUnrecorded(int $carId, string $reason): self
    {
        return new self($carId, self::STATUS_SENT, $reason);
    }

    /**
     * True only for a result built via sentUnrecorded() — status is "sent"
     * but bookkeeping failed. Callers should branch on this (not on
     * `reason !== null` directly) to route an unrecorded send to a distinct
     * report bucket instead of an ordinary successful send, keeping that
     * rule defined once here rather than re-derived at every call site.
     */
    public function isUnrecorded(): bool
    {
        return $this->status === self::STATUS_SENT && $this->reason !== null;
    }
}
