<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

/**
 * SendResult - Outcome of one car's verification-email send attempt
 *
 * Returned by {@see CarVerificationSendService::sendOne()}. Immutable value
 * object with two named constructors instead of a public constructor, so a
 * caller can never build an inconsistent state (e.g. status "sent" with a
 * non-null reason).
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
}
