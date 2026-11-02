<?php

declare(strict_types=1);

namespace ElanRegistry\Email;

/**
 * BrevoUnblockResult - the result of one {@see BrevoBlocklistClient::unblock()} call
 *
 * Three outcomes: `Removed` (Brevo answered 2xx), `NotBlocked` (Brevo
 * answered 404, so the address was not on the blocklist) and `Failed`. Both
 * `Removed` and `NotBlocked` count as success: the address is off the
 * blocklist after the call.
 *
 * Only the named constructors build a result. A failed result holds a
 * reason, the HTTP status for {@see BrevoUnblockReason::Http} only, and a
 * log-safe detail: an {@see \ElanRegistry\Exceptions\ExceptionLogSummary},
 * or a {@see BrevoApiFactory} cause code (followed by " (SQLSTATE <code>)" for
 * a `db_error` key query failure, or by ": <ExceptionLogSummary>" when the SDK
 * autoloader threw). A successful result holds no reason, no HTTP status and
 * no detail. It holds no address, no response body and no exception message,
 * because the SDK puts the request URL, with the address, in its messages.
 *
 * @package ElanRegistry\Email
 * @since v2.30.9
 * @see https://github.com/elan-registry/registry/issues/2357
 */
final readonly class BrevoUnblockResult
{
    /**
     * @param BrevoUnblockOutcome     $outcome    What the call did
     * @param BrevoUnblockReason|null $reason     Why a failed call failed, or null on success
     * @param int|null                $httpStatus The HTTP status of a {@see BrevoUnblockReason::Http} failure, else null
     * @param string|null             $detail     A log-safe detail for a failed result, or null.
     *                                            It holds no address and no exception message.
     */
    private function __construct(
        public BrevoUnblockOutcome $outcome,
        public ?BrevoUnblockReason $reason = null,
        public ?int $httpStatus = null,
        public ?string $detail = null
    ) {
    }

    /**
     * Brevo removed the address from its blocklist (2xx).
     *
     * @return self The result
     */
    public static function removed(): self
    {
        return new self(BrevoUnblockOutcome::Removed);
    }

    /**
     * The address was not on Brevo's blocklist (404).
     *
     * @return self The result
     */
    public static function notBlocked(): self
    {
        return new self(BrevoUnblockOutcome::NotBlocked);
    }

    /**
     * The unblock did not happen.
     *
     * @param BrevoUnblockReason $reason     Why the call failed
     * @param int|null           $httpStatus The HTTP status for {@see BrevoUnblockReason::Http}, else null
     * @param string|null        $detail     A log-safe detail: an ExceptionLogSummary,
     *                                       or a BrevoApiFactory cause code, optionally
     *                                       followed by " (SQLSTATE <code>)" or by
     *                                       ": <ExceptionLogSummary>". Never
     *                                       an exception message or an address.
     * @return self The result
     * @throws \InvalidArgumentException If `$httpStatus` is null for an HTTP failure,
     *                                   or set for any other reason
     */
    public static function failed(BrevoUnblockReason $reason, ?int $httpStatus = null, ?string $detail = null): self
    {
        if (($reason === BrevoUnblockReason::Http) !== ($httpStatus !== null)) {
            throw new \InvalidArgumentException(sprintf(
                'BrevoUnblockResult: reason http needs an HTTP status, and no other reason takes one (reason %s, HTTP status %s)',
                $reason->value,
                $httpStatus === null ? 'none' : (string) $httpStatus
            ));
        }

        return new self(BrevoUnblockOutcome::Failed, $reason, $httpStatus, $detail);
    }

    /**
     * True when the address is off Brevo's blocklist: removed or not blocked.
     *
     * @return bool True on success
     */
    public function isSuccess(): bool
    {
        return $this->outcome !== BrevoUnblockOutcome::Failed;
    }
}
