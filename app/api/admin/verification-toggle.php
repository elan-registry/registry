<?php

declare(strict_types=1);

use ElanRegistry\ApiResponse;
use ElanRegistry\Car\VerificationSettings;
use ElanRegistry\Exceptions\VerificationConfigException;
use ElanRegistry\Input;
use ElanRegistry\LogCategories;

/**
 * verification-toggle.php - Car verification feature switch endpoint
 *
 * Turns the site-wide car verification system on or off by writing the
 * `er_verification_settings` feature switch via VerificationSettings.
 *
 * ADMIN ONLY (hasPerm([2])). Editors can read verification status on the admin
 * UI tab but cannot toggle it, so this endpoint does not accept the editor role.
 *
 * POST params:
 *   csrf    — CSRF token
 *   enabled — '1'/'0', 'true'/'false', or 'on'/'off'; anything else is rejected
 *
 * Enabling is refused with 422 when Brevo is not configured; disabling is never
 * gated (see VerificationSettings' asymmetric-gate contract).
 *
 * @since v2.30.2
 * @see https://github.com/elan-registry/registry/issues/1926
 */

require_once '../../../users/init.php';

if ($method !== 'POST') {
    ApiResponse::error('Method not allowed', 405)->send();
}

if (!Input::existsPost() || !Token::check(Input::get('csrf'))) {
    ApiResponse::error('Invalid request token', 400)->send();
}

if (!$user->isLoggedIn() || !hasPerm([2], (int) $user->data()->id)) {
    ApiResponse::forbidden('Admin access required')
        ->withLogging(
            $user->isLoggedIn() ? (int) $user->data()->id : 0,
            LogCategories::LOG_CATEGORY_ACCESS_DENIED,
            'verification-toggle.php: non-admin toggle attempt from ' . $remote_addr
        )
        ->send();
}

$userId = (int) $user->data()->id;

// Explicit, narrow coercion — an ambiguous value is a client bug, not a "false".
// Input::raw() returns null for absent keys and for arrays, both of which land here.
$rawEnabled = Input::raw('enabled');
$enabled    = match (strtolower((string) $rawEnabled)) {
    '1', 'true', 'on'   => true,
    '0', 'false', 'off' => false,
    default             => null,
};

if ($enabled === null) {
    ApiResponse::validationError(
        ['enabled' => 'Must be one of: 1, 0, true, false, on, off.'],
        'Invalid value for "enabled".'
    )->send();
}

try {
    if (!(new VerificationSettings(dbi()))->setEnabled($enabled, $userId)) {
        ApiResponse::serverError('Could not save the verification setting. Please try again.')
            ->withLogging(
                $userId,
                LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING,
                'verification-toggle.php: setEnabled(' . ($enabled ? 'true' : 'false') . ') failed to write'
            )
            ->send();
    }

    // VerificationSettings::setEnabled() already logged the successful change
    // under LOG_CATEGORY_VERIFICATION_CONFIG_CHANGED — no duplicate log here.
    ApiResponse::success($enabled ? 'Verification enabled.' : 'Verification disabled.')
        ->withData('enabled', $enabled)
        ->send();

} catch (VerificationConfigException $e) {
    ApiResponse::validationError(['enabled' => $e->getUserMessage()], $e->getUserMessage())
        ->withLogging($userId, $e->getLogCategory(), 'verification-toggle.php: ' . $e->getMessage())
        ->send();

} catch (\Throwable $e) {
    ApiResponse::serverError('An unexpected error occurred while updating the verification setting.')
        ->withLogging(
            $userId,
            LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING,
            'verification-toggle.php system error [' . get_class($e) . ']: ' . $e->getMessage()
        )
        ->send();
}
