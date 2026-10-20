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
 * A failed result holds a reason code and the HTTP status when Brevo sent
 * one. It holds no address, no response body and no exception message,
 * because the SDK puts the request URL, with the address, in its messages.
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

    /** The vendored Brevo SDK did not load. */
    public const REASON_NO_SDK = BrevoApiFactory::REASON_NO_SDK;

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
        private readonly ?int $httpStatus
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
     * @param string   $reason     One of the `REASON_*` constants
     * @param int|null $httpStatus The HTTP status for {@see self::REASON_HTTP}, or null
     * @return self The result
     */
    public static function failed(string $reason, ?int $httpStatus = null): self
    {
        return new self(self::OUTCOME_FAILED, $reason, $httpStatus);
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
}
