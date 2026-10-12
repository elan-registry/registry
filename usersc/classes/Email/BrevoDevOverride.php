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
 * {@see self::hostOverride()} is a pure function: it reads only
 * `$_ENV`/`getenv()` and the `US_ENVIRONMENT` constant.
 *
 * {@see self::applyToSdk()} applies the override to the SDK's shared default
 * `Configuration` once per request (called from
 * usersc/includes/rate_limits_dev_override.php). The upstream sendinblue
 * plugin gets that same shared object and only sets the API key on it, so
 * its sends and delivery-status checks keep the mock host without a change
 * to the plugin (#2184).
 *
 * @package ElanRegistry\Email
 * @see https://github.com/elan-registry/registry/issues/2127
 * @see https://github.com/elan-registry/registry/issues/2184
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

    /**
     * Sets the override host on the Brevo SDK's shared default Configuration.
     *
     * Does nothing when {@see self::hostOverride()} is null, so test and
     * production never load the SDK here. Never throws: this runs on every
     * request from the loader, and a broken or absent plugin must not take
     * the site down.
     *
     * @param string $autoloadPath Absolute path to the sendinblue plugin's
     *   `vendor/autoload.php`.
     * @return bool True when the host was set; false when the override is
     *   inactive, the autoloader is not a readable file, the SDK class is
     *   missing, or loading the SDK failed.
     */
    public static function applyToSdk(string $autoloadPath): bool
    {
        $host = self::hostOverride();
        if ($host === null || !is_file($autoloadPath) || !is_readable($autoloadPath)) {
            return false;
        }

        // A failure here sends dev mail to the real Brevo API, so it is logged,
        // not only reported through the return value.
        try {
            require_once $autoloadPath;

            if (!class_exists(\Brevo\Client\Configuration::class)) {
                self::logFailure('Brevo\\Client\\Configuration not found after loading ' . $autoloadPath);
                return false;
            }

            \Brevo\Client\Configuration::getDefaultConfiguration()->setHost($host);
        } catch (\Throwable $e) {
            self::logFailure($e::class . ': ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Log a failed host override. logger() is absent outside a full UserSpice bootstrap.
     */
    private static function logFailure(string $reason): void
    {
        $message = 'BrevoDevOverride::applyToSdk: dev mail is NOT routed to mock-brevo and goes to the real Brevo API: '
            . $reason;

        // Always in the PHP error log: UserSpice logger() reports no failed
        // database write, so the log row alone may be missing.
        error_log($message);

        // A logger() exception must not break applyToSdk()'s never-throw
        // contract, because the loader runs it on every development request.
        try {
            if (function_exists('logger')) {
                logger(0, \ElanRegistry\LogCategories::LOG_CATEGORY_EMAIL_ERROR, $message);
            }
        } catch (\Throwable $logError) {
            error_log('BrevoDevOverride::applyToSdk: logger() failed: ' . $logError::class . ': ' . $logError->getMessage());
        }
    }
}
