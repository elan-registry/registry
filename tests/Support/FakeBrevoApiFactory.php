<?php

declare(strict_types=1);

namespace Tests\Support;

use ElanRegistry\Email\BrevoApiFactory;

/**
 * FakeBrevoApiFactory - BrevoApiFactory whose SDK load and API object are
 * scripted, for the BrevoBlocklistClient unit tests
 *
 * The API key read stays real, so a test scripts the key with a database
 * double such as {@see SendinblueSettingsFakeDatabase}. `loadSdk()` returns
 * `$sdkLoad`, and `createApi()` returns `$api`, which can be a stand-in for
 * the SDK class: CI has no SDK. The test needs no SDK and makes no network
 * call.
 *
 * Named class, not anonymous: see FakeDatabase (`impureMethod.pure`).
 *
 * @package Tests\Support
 * @since v2.31.0
 * @see https://github.com/elan-registry/registry/issues/2357
 */
class FakeBrevoApiFactory extends BrevoApiFactory
{
    /** @var array{reason: string|null, cause: string|null, autoload: string, detail: string} */
    public array $sdkLoad = ['reason' => null, 'cause' => null, 'autoload' => '', 'detail' => ''];

    /** The object that createApi() returns. */
    public object $api;

    public int $createApiCalls = 0;

    public ?string $lastApiKey = null;

    /**
     * @param \ElanRegistry\DatabaseInterface $db  Database that holds the API key row
     * @param object|null                     $api The object that createApi() returns; null gives an empty object
     */
    public function __construct(\ElanRegistry\DatabaseInterface $db, ?object $api = null)
    {
        parent::__construct($db);
        $this->api = $api ?? new \stdClass();
    }

    public function loadSdk(): array
    {
        return $this->sdkLoad;
    }

    public function createApi(string $apiKey): object
    {
        $this->createApiCalls++;
        $this->lastApiKey = $apiKey;

        return $this->api;
    }
}
