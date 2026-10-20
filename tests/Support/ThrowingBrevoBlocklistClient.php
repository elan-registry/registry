<?php

declare(strict_types=1);

namespace Tests\Support;

use ElanRegistry\Email\BrevoBlocklistClient;

/**
 * ThrowingBrevoBlocklistClient - BrevoBlocklistClient whose SDK call throws,
 * for the Clear Block log tests
 *
 * It replaces the `createApi()` seam only, so the real `unblock()` runs: the
 * address check, the API key read, the call, and the catch. The API object
 * throws a RuntimeException whose message holds an address, as the SDK does
 * (its messages hold the request URL). A test can then show that the address
 * reaches neither the result nor the log.
 *
 * `unblock()` reads the API key from the database, so the test passes a
 * database double that returns a key.
 *
 * Named class, not anonymous: see FakeDatabase (`impureMethod.pure`).
 *
 * @package Tests\Support
 * @since v2.30.9
 * @see https://github.com/elan-registry/registry/issues/2357
 */
class ThrowingBrevoBlocklistClient extends BrevoBlocklistClient
{
    /** The address that the thrown exception message holds. */
    public const LEAKED_ADDRESS = 'leak-test-2357@example.org';

    /** The code of the thrown exception. Not an HTTP status. */
    public const EXCEPTION_CODE = 2357;

    protected function createApi(string $apiKey): object
    {
        // @phpstan-ignore return.type (intentional: the stand-in cannot extend the SDK class, which CI does not have)
        return new ThrowingBrevoUnblockApi();
    }
}

/**
 * ThrowingBrevoUnblockApi - stand-in for the SDK's TransactionalEmailsApi whose
 * `smtpBlockedContactsEmailDelete()` always throws
 *
 * It shares this file with the client double, so PSR-4 cannot load it alone.
 * A test gets it with `require_once` on this file.
 *
 * @package Tests\Support
 * @since v2.30.9
 */
final class ThrowingBrevoUnblockApi
{
    /**
     * @throws \RuntimeException Always
     */
    public function smtpBlockedContactsEmailDelete(string $email): never
    {
        throw new \RuntimeException(
            sprintf('[0] cURL error 7: Failed to connect (DELETE /smtp/blockedContacts/%s)', ThrowingBrevoBlocklistClient::LEAKED_ADDRESS),
            ThrowingBrevoBlocklistClient::EXCEPTION_CODE
        );
    }
}
