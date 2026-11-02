<?php

declare(strict_types=1);

namespace Tests\Support;

use ElanRegistry\Cron\BrevoSuppressionSyncClient;

/**
 * NoSdkBrevoSuppressionSyncClient - BrevoSuppressionSyncClient whose SDK
 * does not load, for BrevoSuppressionSyncClientTest
 *
 * Overrides the `createApi()` seam only, so the real API key read runs.
 * The seam returns null, as the real one does when the SDK is absent or
 * does not load. A test can then show that a set key with no SDK is a
 * fault, whatever SDK the test machine has (#2480).
 *
 * Named class, not anonymous: see FakeDatabase (`impureMethod.pure`).
 *
 * @package Tests\Support
 * @since v2.31.0
 * @see https://github.com/elan-registry/registry/issues/2480
 */
class NoSdkBrevoSuppressionSyncClient extends BrevoSuppressionSyncClient
{
    /**
     * Return no API object, as when the SDK does not load.
     *
     * The real seam logs why. This double does not, so a test sees only the
     * key read's log rows.
     *
     * @param string $apiKey The Brevo API key (not used)
     * @return null Always
     */
    protected function createApi(string $apiKey): ?object
    {
        return null;
    }
}
