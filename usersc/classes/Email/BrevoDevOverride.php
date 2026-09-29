<?php

declare(strict_types=1);

namespace ElanRegistry\Email;

/**
 * BrevoDevOverride - Local-dev-only Brevo API host override guard
 *
 * Routes the Brevo PHP SDK to a local mock (e.g. mock-brevo in the Docker
 * Compose dev stack) instead of the real Brevo API, but only when
 * `US_ENVIRONMENT` (defined in usersc/includes/rate_limits_dev_override.php)
 * is exactly `'development'`. Fails closed to `null` (real Brevo host) in
 * every other environment, including when the constant is undefined.
 *
 * Pure function: reads only `$_ENV`/`getenv()` and the `US_ENVIRONMENT`
 * constant, no I/O or framework dependencies.
 *
 * @package ElanRegistry\Email
 * @see https://github.com/elan-registry/registry/issues/2127
 */
class BrevoDevOverride
{
    /**
     * Returns the local-dev Brevo API host override, or null if inactive.
     *
     * @return string|null The `BREVO_API_HOST` value when `US_ENVIRONMENT`
     *   is `'development'` and the value is non-empty; `null` otherwise.
     */
    public static function hostOverride(): ?string
    {
        if (!defined('US_ENVIRONMENT') || US_ENVIRONMENT !== 'development') {
            return null;
        }

        $host = $_ENV['BREVO_API_HOST'] ?? getenv('BREVO_API_HOST') ?: '';

        return $host !== '' ? $host : null;
    }
}
