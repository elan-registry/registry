<?php

declare(strict_types=1);

namespace ElanRegistry\Email;

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\ExceptionLogSummary;

/**
 * BrevoBlocklistClient - removes one address from Brevo's transactional
 * blocklist (`DELETE /v3/smtp/blockedContacts/{email}`)
 *
 * Admin Clear Block uses it (#2357). Without the call, Brevo keeps the
 * address on its blocklist: Brevo still drops the mail, and the next full
 * suppression backfill sets the registry flag again.
 *
 * The address goes to `smtpBlockedContactsEmailDelete()` as given. The SDK
 * encodes the path value once (`ObjectSerializer::toPathValue()` calls
 * `rawurlencode()`). An address encoded here too would reach Brevo encoded
 * twice, Brevo would answer 404, and the 404 reads as "not blocked".
 *
 * It never logs, and the result never holds an exception message. The SDK
 * puts the request URL, with the address, in its messages, and a Brevo error
 * body can echo the address. The caller logs the owner ID and the result's
 * reason, HTTP status and detail only. The detail is an
 * {@see ExceptionLogSummary}, or a {@see BrevoApiFactory} cause code
 * (followed by " (SQLSTATE <code>)" for a `db_error` key query failure, or by
 * ": <ExceptionLogSummary>" when the SDK autoloader threw).
 *
 * Not `final`: the protected {@see self::createApi()} seam lets a unit test
 * replace the SDK API object, so the test makes no network call and needs no
 * SDK, as in {@see \ElanRegistry\Cron\BrevoSuppressionSyncClient}.
 * Subclassing outside of test doubles is not intended.
 *
 * In local development, {@see BrevoApiFactory::createApi()} points the call
 * at mock-brevo through {@see BrevoDevOverride}.
 *
 * @package ElanRegistry\Email
 * @since v2.30.9
 * @see https://github.com/elan-registry/registry/issues/2357
 */
class BrevoBlocklistClient
{
    /**
     * The {@see BrevoApiFactory} cause of the SDK load failure in the last
     * {@see self::createApi()} call, or null. A test double that returns null
     * from the seam leaves it null, which reads as "no SDK".
     */
    private ?string $sdkLoadCause = null;

    /**
     * The log-safe {@see BrevoApiFactory} detail of that SDK load failure (an
     * ExceptionLogSummary when the autoloader threw), or an empty string.
     */
    private string $sdkLoadDetail = '';

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Remove an address from Brevo's transactional blocklist.
     *
     * Never throws. A 2xx answer gives `removed`, a 404 gives `notBlocked`.
     * Each other case gives `failed` with a reason: `no_address` (empty or
     * whitespace address, no call), `db_error` (the API key query failed),
     * `no_api_key`, `no_sdk` (no SDK autoloader file), `sdk_load_failed` (the
     * autoloader threw, or it did not define the API class), `http` (with the
     * status) or `transport` (no HTTP answer, or a throw before the call).
     * A `db_error` failure has the factory cause as its detail, followed by
     * " (SQLSTATE <code>)" when the query gave a valid SQLSTATE. The detail
     * never holds the database error string. Another key or SDK failure has
     * the factory cause as its detail, followed by ": <ExceptionLogSummary>"
     * when the SDK autoloader threw. An `http` or `transport` failure has the
     * {@see ExceptionLogSummary} as its detail.
     *
     * @param string $address The address to unblock, not URL-encoded
     * @return BrevoUnblockResult The result, with no address and no exception message
     */
    public function unblock(string $address): BrevoUnblockResult
    {
        if (trim($address) === '') {
            return BrevoUnblockResult::failed(BrevoUnblockResult::REASON_NO_ADDRESS);
        }

        try {
            $keyRead = (new BrevoApiFactory($this->db))->readApiKey();
            $apiKey = $keyRead['key'];
            if ($apiKey === null) {
                // A failed query (other than a missing table) is not a missing
                // key: the admin gets the retry text.
                // The detail holds the cause and SQLSTATE, never the error string,
                // because the error string can hold SQL text.
                if ($keyRead['cause'] === BrevoApiFactory::CAUSE_DB_ERROR) {
                    return BrevoUnblockResult::failed(
                        BrevoUnblockResult::REASON_DB_ERROR,
                        null,
                        $keyRead['sqlState'] !== null
                            ? $keyRead['cause'] . ' (SQLSTATE ' . $keyRead['sqlState'] . ')'
                            : $keyRead['cause']
                    );
                }

                return BrevoUnblockResult::failed(BrevoUnblockResult::REASON_NO_API_KEY, null, $keyRead['cause']);
            }

            $this->sdkLoadCause = null;
            $this->sdkLoadDetail = '';
            $api = $this->createApi($apiKey);
            if ($api === null) {
                return self::sdkFailureResult($this->sdkLoadCause, $this->sdkLoadDetail);
            }

            $api->smtpBlockedContactsEmailDelete($address);

            return BrevoUnblockResult::removed();
        } catch (\Throwable $e) {
            // Caught as \Throwable: the SDK raises \Brevo\Client\ApiException
            // for a non-2xx answer, Guzzle raises its own exceptions for a
            // connection failure, and the SDK raises \InvalidArgumentException
            // for a bad argument. The HTTP status and the summary are kept, never the message.
            return self::failureResult($e);
        }
    }

    /**
     * Build the Brevo transactional email API client.
     *
     * The seam covers the SDK load too, so a unit test with no SDK can
     * replace it. The return type is `?object`, not the SDK class, because a
     * test double of another class would fail a native SDK return type.
     *
     * @param string $apiKey The Brevo API key
     * @return \Brevo\Client\Api\TransactionalEmailsApi|null The client, or null if the SDK did not load
     */
    protected function createApi(string $apiKey): ?object
    {
        $factory = new BrevoApiFactory($this->db);
        $load = $factory->loadSdk();
        if ($load['reason'] !== null) {
            $this->sdkLoadCause = $load['cause'];
            $this->sdkLoadDetail = $load['detail'];
            return null;
        }

        return $factory->createApi($apiKey);
    }

    /**
     * Map an SDK load failure to a result.
     *
     * Only a missing autoloader file means that the SDK is not installed. An
     * autoloader that threw, or that did not define the API class, is a fault
     * in an installed SDK, so the admin gets the plugin-fault text, not the
     * setup text.
     *
     * @param string|null $cause The {@see BrevoApiFactory} `CAUSE_*` constant, or null
     * @param string $detail The factory's log-safe detail, or an empty string
     * @return BrevoUnblockResult A failed result with the cause, and the detail when present, as its detail
     */
    private static function sdkFailureResult(?string $cause, string $detail): BrevoUnblockResult
    {
        $loadFailed = in_array(
            $cause,
            [BrevoApiFactory::CAUSE_AUTOLOADER_FAILED, BrevoApiFactory::CAUSE_API_CLASS_MISSING],
            true
        );

        return BrevoUnblockResult::failed(
            $loadFailed ? BrevoUnblockResult::REASON_SDK_LOAD_FAILED : BrevoUnblockResult::REASON_NO_SDK,
            null,
            ($cause !== null && $detail !== '') ? $cause . ': ' . $detail : $cause
        );
    }

    /**
     * Map a thrown Brevo call to a result.
     *
     * An `ApiException` code in the HTTP status range is the status that
     * Brevo sent. The SDK gives code 0 when the request got no answer.
     *
     * @param \Throwable $e The exception from the call
     * @return BrevoUnblockResult `notBlocked` for 404, else `failed` with
     *                            the {@see ExceptionLogSummary} as its detail
     */
    private static function failureResult(\Throwable $e): BrevoUnblockResult
    {
        if ($e instanceof \Brevo\Client\ApiException) {
            $status = (int) $e->getCode();
            if ($status === 404) {
                return BrevoUnblockResult::notBlocked();
            }
            if ($status >= 100 && $status <= 599) {
                return BrevoUnblockResult::failed(BrevoUnblockResult::REASON_HTTP, $status, ExceptionLogSummary::of($e));
            }
        }

        return BrevoUnblockResult::failed(BrevoUnblockResult::REASON_TRANSPORT, null, ExceptionLogSummary::of($e));
    }
}
