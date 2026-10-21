<?php

declare(strict_types=1);

namespace Tests\Support;

use ElanRegistry\Cron\BrevoSuppressionSyncClient;

/**
 * ThrowingBrevoSuppressionSyncClient - BrevoSuppressionSyncClient whose poll
 * throws, for BrevoSuppressionSyncClientTest
 *
 * Overrides the `createApi()` seam only, so the real fetchBlockedContacts()
 * runs: the API key read, the call, and the catch. The API object throws a
 * RuntimeException whose message holds an address, as a Brevo error body
 * can. A test can then show that the catch keeps the address out of the log.
 *
 * No SDK and no network are needed, so the test runs in CI (#2425).
 *
 * @package Tests\Support
 * @since v2.30.9
 * @see https://github.com/elan-registry/registry/issues/2425
 */
class ThrowingBrevoSuppressionSyncClient extends BrevoSuppressionSyncClient
{
    /** The address that the thrown exception message holds. */
    public const LEAKED_ADDRESS = 'leak-test-2425@example.org';

    /** The code of the thrown exception, so a test can find it in the log. */
    public const EXCEPTION_CODE = 2425;

    /**
     * Return an API object whose poll throws.
     *
     * @param string $apiKey The Brevo API key (not used)
     * @return ThrowingBrevoTransactionalEmailsApi The throwing API object
     */
    // @phpstan-ignore method.childReturnType (intentional: the stand-in cannot extend the SDK class, which CI does not have)
    protected function createApi(string $apiKey): object
    {
        return new ThrowingBrevoTransactionalEmailsApi();
    }
}

/**
 * ThrowingBrevoTransactionalEmailsApi - stand-in for the SDK's
 * TransactionalEmailsApi whose `getTransacBlockedContacts()` always throws
 *
 * Not a subclass of the SDK class: CI has no SDK, so the class would not load.
 *
 * It shares this file with the client double, so PSR-4 cannot load it alone.
 * A test gets it with `require_once` on this file.
 *
 * Named class, not anonymous: see FakeDatabase (`impureMethod.pure`).
 *
 * @package Tests\Support
 * @since v2.30.9
 * @see https://github.com/elan-registry/registry/issues/2425
 */
final class ThrowingBrevoTransactionalEmailsApi
{
    /**
     * Throw as a failed Brevo call can, with an address in the message.
     *
     * @throws \RuntimeException Always
     */
    public function getTransacBlockedContacts(
        ?string $startDate = null,
        ?string $endDate = null,
        ?int $limit = null,
        ?int $offset = null,
        mixed $senders = null,
        ?string $sort = null
    ): never {
        throw new \RuntimeException(
            sprintf(
                '[400] Client error: contact %s is not valid',
                ThrowingBrevoSuppressionSyncClient::LEAKED_ADDRESS
            ),
            ThrowingBrevoSuppressionSyncClient::EXCEPTION_CODE
        );
    }
}
