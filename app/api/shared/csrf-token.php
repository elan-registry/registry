<?php
declare(strict_types=1);

use ElanRegistry\ApiResponse;
use ElanRegistry\LogCategories;

/**
 * AJAX Endpoint: CSRF Token
 *
 * Returns the current session CSRF token so that a long-open form can
 * refresh its token before it submits. Token::generate() returns the
 * existing session token and does not rotate it.
 *
 * GET only, AJAX only, login required.
 *
 * @package ElanRegistry
 * @link https://github.com/elan-registry/registry/issues/1455
 */

require_once '../../../users/init.php';

// A cached token can be stale or belong to a different session.
header('Cache-Control: no-store');

if ($method !== 'GET') {
    ApiResponse::error('Method not allowed', 405)->send();
}

if (strtolower(Server::get('HTTP_X_REQUESTED_WITH', '')) !== 'xmlhttprequest') {
    ApiResponse::error('Bad Request: AJAX only', 400)->send();
}

if (!$user->isLoggedIn()) {
    ApiResponse::unauthorized('Login required')
        ->withLogging(0, LogCategories::LOG_CATEGORY_ACCESS_DENIED, 'Unauthenticated access attempt to csrf-token.php')
        ->send();
}

ApiResponse::success('Token issued')
    ->withData('csrf', Token::generate())
    ->send();
