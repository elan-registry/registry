<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use ElanRegistry\Email\BrevoBlocklistClient;
use ElanRegistry\Email\BrevoUnblockResult;

/**
 * FakeBrevoBlocklistClient - scripted stand-in for the Brevo unblock call,
 * for the Clear Block tests
 *
 * Records the address of each `unblock()` call and returns a set result. An
 * optional callback runs inside `unblock()`, before the result returns. A
 * test uses it to change the database while the "Brevo call" is in flight.
 *
 * The parent constructor is skipped: it needs a database only for the API key.
 *
 * Named class, not anonymous: see FakeDatabase (`impureMethod.pure`).
 *
 * @package Tests\Support
 * @since v2.30.9
 * @see https://github.com/elan-registry/registry/issues/2357
 */
class FakeBrevoBlocklistClient extends BrevoBlocklistClient
{
    /** @var list<string> The address of each unblock() call, in order. */
    public array $calls = [];

    /**
     * @param BrevoUnblockResult $result    Result that every call returns
     * @param Closure|null       $onUnblock Runs inside unblock(), with the address
     */
    public function __construct(
        private readonly BrevoUnblockResult $result,
        private readonly ?Closure $onUnblock = null
    ) {
    }

    public function unblock(string $address): BrevoUnblockResult
    {
        $this->calls[] = $address;
        if ($this->onUnblock !== null) {
            ($this->onUnblock)($address);
        }

        return $this->result;
    }
}
