<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * FakeBrevoBlockedContact - Stub of
 * \Brevo\Client\Model\GetTransacBlockedContactsContacts
 *
 * The vendored Brevo SDK lives under usersc/plugins/sendinblue/vendor/ and is
 * not wired into the project's Composer autoloader (see
 * BrevoSuppressionSyncClient's SDK_AUTOLOAD_RELATIVE_PATH), so the unit suite
 * cannot instantiate or mock the real model class. This reproduces the getters
 * BrevoSuppressionSyncJob actually reads.
 *
 * Every field is `mixed` rather than a nullable string, matching the untyped
 * getters in stubs/brevo-sdk.php: the generated SDK deserializes these from
 * JSON with no type enforcement, so a payload-contract change really can hand
 * the job an array or an int. Keeping them loose is what lets a test construct
 * a deliberately malformed contact and exercise the job's `is_string()` guards.
 *
 * Named class, not anonymous: see FakeDatabase (`impureMethod.pure`).
 *
 * @package Tests\Support
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1923
 */
class FakeBrevoBlockedContact
{
    public function __construct(
        private readonly mixed $email = 'owner@example.com',
        private readonly mixed $senderEmail = 'registry@example.com',
        private readonly ?FakeBrevoBlockedContactReason $reason = null,
        private readonly mixed $blockedAt = '2026-09-08T12:00:00.000Z',
    ) {
    }

    public function getEmail(): mixed
    {
        return $this->email;
    }

    public function getSenderEmail(): mixed
    {
        return $this->senderEmail;
    }

    /**
     * Nullable on purpose: the SDK declares the Reason model as the return
     * type but enforces nothing at deserialization time, so a payload missing
     * `reason` yields null — the case BrevoSuppressionSyncJob guards against.
     */
    public function getReason(): ?FakeBrevoBlockedContactReason
    {
        return $this->reason;
    }

    public function getBlockedAt(): mixed
    {
        return $this->blockedAt;
    }

    /**
     * A Unix time in Brevo's `blockedAt` form: Paris wall time with a `Z`
     * suffix, as the real API sends it (#2501).
     *
     * A time in the first pass of the repeated Paris hour fails at once, so
     * a test that uses the real clock and skips
     * {@see self::outsideParisRepeatedHour()} does not fail only on 1 day
     * each year.
     *
     * @throws \LogicException If the job would read the value back as a different instant
     */
    public static function blockedAtFor(int $timestamp): string
    {
        if (!self::parisWallTimeRoundTrips($timestamp)) {
            throw new \LogicException(
                "Timestamp {$timestamp} is in the first pass of the repeated Paris hour, so the job reads it 1 hour late. Use outsideParisRepeatedHour()."
            );
        }

        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone('Europe/Paris'))
            ->format('Y-m-d\\TH:i:s.000\\Z');
    }

    /**
     * A time that survives the `blockedAt` round trip, or 2 hours earlier.
     *
     * `blockedAt` is Paris wall time, and the job reads it again in
     * Europe/Paris. In the repeated hour of the change to CET (02:00 to 03:00,
     * last Sunday of October) one wall time names 2 instants. PHP picks the
     * CET (later) one, so a time in the first pass comes back 1 hour late
     * (measured on PHP 8.5.12). A test that uses the real clock fails on
     * those days.
     *
     * The span must be less than 1 hour: if both end points are outside the
     * 1-hour repeated hour, no point between them is in it.
     *
     * @param int $ts Unix time to check
     * @param int $spanSeconds Length of the window that starts at $ts (0 to 3599)
     * @throws \InvalidArgumentException If $spanSeconds is negative or is 3600 or more
     */
    public static function outsideParisRepeatedHour(int $ts, int $spanSeconds = 0): int
    {
        if ($spanSeconds < 0 || $spanSeconds >= 3600) {
            throw new \InvalidArgumentException('spanSeconds must be from 0 to 3599, got ' . $spanSeconds);
        }

        return self::parisWallTimeRoundTrips($ts) && self::parisWallTimeRoundTrips($ts + $spanSeconds) ? $ts : $ts - 7200;
    }

    private static function parisWallTimeRoundTrips(int $ts): bool
    {
        $zone = new \DateTimeZone('Europe/Paris');
        $wall = (new \DateTimeImmutable('@' . $ts))->setTimezone($zone)->format('Y-m-d H:i:s');

        return (new \DateTimeImmutable($wall, $zone))->getTimestamp() === $ts;
    }

    /**
     * Build a contact carrying a reason, without naming the reason class.
     *
     * FakeBrevoBlockedContactReason lives in this file rather than its own, so
     * PSR-4 will not autoload it by name: a test that referenced it directly
     * would fatal unless this file happened to be loaded first. This factory
     * is the supported way to construct one — reaching it always loads the
     * class as a side effect.
     */
    public static function withReason(
        mixed $email,
        mixed $reasonCode,
        mixed $reasonMessage = null,
        mixed $blockedAt = '2026-09-08T12:00:00.000Z',
        mixed $senderEmail = 'registry@example.com',
    ): self {
        return new self(
            $email,
            $senderEmail,
            new FakeBrevoBlockedContactReason($reasonCode, $reasonMessage),
            $blockedAt
        );
    }
}

/**
 * FakeBrevoBlockedContactReason - Stub of
 * \Brevo\Client\Model\GetTransacBlockedContactsReason
 *
 * Both getters are `mixed` for the same reason FakeBrevoBlockedContact's are:
 * the real model enforces no types, and BrevoSuppressionSyncJob's `is_string()`
 * guards on the code and message must be exercisable with malformed data.
 *
 * The CODE_* constants mirror the six the real model defines, so tests can
 * name a reason code the way production code does instead of repeating string
 * literals that would drift silently.
 *
 * Lives in FakeBrevoBlockedContact.php because it is only ever reachable
 * through a contact — construct it via
 * {@see FakeBrevoBlockedContact::withReason()}, which loads this file.
 *
 * @package Tests\Support
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1923
 */
class FakeBrevoBlockedContactReason
{
    public const CODE_UNSUBSCRIBED_VIA_MA = 'unsubscribedViaMA';
    public const CODE_UNSUBSCRIBED_VIA_EMAIL = 'unsubscribedViaEmail';
    public const CODE_ADMIN_BLOCKED = 'adminBlocked';
    public const CODE_UNSUBSCRIBED_VIA_API = 'unsubscribedViaApi';
    public const CODE_HARD_BOUNCE = 'hardBounce';
    public const CODE_CONTACT_FLAGGED_AS_SPAM = 'contactFlaggedAsSpam';

    public function __construct(
        private readonly mixed $code = self::CODE_HARD_BOUNCE,
        private readonly mixed $message = null,
    ) {
    }

    public function getCode(): mixed
    {
        return $this->code;
    }

    public function getMessage(): mixed
    {
        return $this->message;
    }
}
