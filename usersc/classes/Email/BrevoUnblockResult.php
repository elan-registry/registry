<?php

declare(strict_types=1);

namespace ElanRegistry\Email;

/**
 * BrevoUnblockResult - the result of one {@see BrevoBlocklistClient::unblock()} call
 *
 * Three outcomes: `removed` (Brevo answered 2xx), `notBlocked` (Brevo
 * answered 404, so the address was not on the blocklist) and `failed`. Both
 * `removed` and `notBlocked` count as success: the address is off the
 * blocklist after the call.
 *
 * A failed result holds a reason code, the HTTP status when Brevo sent
 * one, and a log-safe detail: an {@see \ElanRegistry\Exceptions\ExceptionLogSummary},
 * or a {@see BrevoApiFactory} cause code (followed by " (SQLSTATE <code>)" for
 * a `db_error` key query failure, or by ": <ExceptionLogSummary>" when the SDK
 * autoloader threw). It holds no address, no response
 * body and no exception message, because the SDK puts the request URL, with
 * the address, in its messages.
 *
 * @package ElanRegistry\Email
 * @since v2.30.9
 * @see https://github.com/elan-registry/registry/issues/2357
 */
final class BrevoUnblockResult
{
    public const OUTCOME_REMOVED = 'removed';
    public const OUTCOME_NOT_BLOCKED = 'notBlocked';
    public const OUTCOME_FAILED = 'failed';

    /** No Brevo API key could be read. */
    public const REASON_NO_API_KEY = BrevoApiFactory::REASON_NO_API_KEY;

    /** The vendored Brevo SDK is not installed: no autoloader file. */
    public const REASON_NO_SDK = BrevoApiFactory::REASON_NO_SDK;

    /**
     * The SDK autoloader is there, but the SDK did not load: the autoloader
     * threw, or the API class is still undefined. A fault, not a missing setup.
     */
    public const REASON_SDK_LOAD_FAILED = 'sdk_load_failed';

    /** Brevo answered with a non-2xx status other than 404. */
    public const REASON_HTTP = 'http';

    /** The request did not get an HTTP answer (connection, timeout, SDK error). */
    public const REASON_TRANSPORT = 'transport';

    /** The query that reads the API key failed. A retry can work. No call was made. */
    public const REASON_DB_ERROR = 'db_error';

    /** The address was empty or whitespace only, so no call was made. */
    public const REASON_NO_ADDRESS = 'no_address';

    private function __construct(
        private readonly string $outcome,
        private readonly ?string $reason,
        private readonly ?int $httpStatus,
        private readonly ?string $detail = null
    ) {
    }

    /**
     * Brevo removed the address from its blocklist (2xx).
     *
     * @return self The result
     */
    public static function removed(): self
    {
        return new self(self::OUTCOME_REMOVED, null, null);
    }

    /**
     * The address was not on Brevo's blocklist (404).
     *
     * @return self The result
     */
    public static function notBlocked(): self
    {
        return new self(self::OUTCOME_NOT_BLOCKED, null, 404);
    }

    /**
     * The unblock did not happen.
     *
     * @param string      $reason     One of the `REASON_*` constants
     * @param int|null    $httpStatus The HTTP status for {@see self::REASON_HTTP}, or null
     * @param string|null $detail     A log-safe detail: an ExceptionLogSummary,
     *                                or a BrevoApiFactory cause code, optionally
     *                                followed by " (SQLSTATE <code>)" or by
     *                                ": <ExceptionLogSummary>". Never
     *                                an exception message or an address.
     * @return self The result
     */
    public static function failed(string $reason, ?int $httpStatus = null, ?string $detail = null): self
    {
        return new self(self::OUTCOME_FAILED, $reason, $httpStatus, $detail);
    }

    /**
     * @return string One of the `OUTCOME_*` constants
     */
    public function outcome(): string
    {
        return $this->outcome;
    }

    /**
     * True when the address is off Brevo's blocklist: removed or not blocked.
     *
     * @return bool True on success
     */
    public function isSuccess(): bool
    {
        return $this->outcome !== self::OUTCOME_FAILED;
    }

    /**
     * @return string|null One of the `REASON_*` constants for a failed
     *                     result, or null on success
     */
    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * @return int|null The HTTP status that Brevo sent, or null when there
     *                  was no HTTP answer or no call
     */
    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * @return string|null A log-safe detail for a failed result, or null.
     *                     It holds no address and no exception message.
     */
    public function detail(): ?string
    {
        return $this->detail;
    }
}
