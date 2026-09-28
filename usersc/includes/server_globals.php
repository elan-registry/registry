<?php
declare(strict_types=1);

/**
 * Server Globals Initialization Module
 *
 * Provides validated, application-wide globals for server variables during
 * page load initialization (Phase 1.11.12).
 *
 * All globals are initialized using the Server class which provides comprehensive
 * sanitization and validation of $_SERVER values. This eliminates scattered
 * $_SERVER access throughout the codebase and ensures consistent security
 * practices.
 *
 * CRITICAL: This file is included in loader.php which is used in parser files
 * and API calls. DO NOT echo or output anything to screen or it will break
 * important functionality.
 *
 * Available Globals:
 * - $scheme        HTTP scheme ('http' or 'https')
 * - $is_https      Boolean for quick HTTPS detection
 * - $host          Domain name (validated, no port)
 * - $method        HTTP request method (GET, POST, etc.)
 * - $request_uri   Request URI (sanitized)
 * - $current_url   Full URL (scheme://host[:port]/path?query)
 * - $current_origin Origin only (scheme://host[:port]). The port is added only
 *                   when it is not the scheme default and the request has no
 *                   X-Forwarded-Proto of http or https (Server::get() keeps
 *                   the first token, lowercased, and turns any other value
 *                   into '')
 * - $referer       HTTP referer (sanitized, optional)
 * - $user_agent    User agent string (sanitized, max 512 chars)
 * - $php_self      Current script path (for securePage)
 * - $remote_addr   Client IP address (for logging)
 *
 * Security Features:
 * - All variables validated via Server::get()
 * - Control character stripping (\x00-\x1F, \x7F)
 * - CRLF injection prevention on URIs
 * - Hostname validation (DNS label rules)
 * - Safe defaults for missing values
 *
 * @package ElanRegistry
 * @subpackage Initialization
 */

// HTTP Scheme Detection
// Determines if request is secure (HTTPS) or plain HTTP.
// Also checks X-Forwarded-Proto for reverse proxy / Cloudflare Tunnel setups
// where SSL is terminated upstream and the backend sees plain HTTP internally.
$scheme = Server::get('REQUEST_SCHEME', 'http');
$forwarded_proto = Server::get('HTTP_X_FORWARDED_PROTO', '');
if ($forwarded_proto === 'https') {
    $scheme = 'https';
}
$is_https = ($scheme === 'https');

// Host and Origin
// HTTP_HOST is validated via Server::get() with stripPort=true
// This gives us just the domain/hostname without port information
$host = Server::get('HTTP_HOST', '');

// Construct origin (scheme://host[:port]) - used in canonical/og:url tags, the
// sitemap and emailed links, via getBaseUrl() and directly
$current_origin = $is_https ? "https://{$host}" : "http://{$host}";

// $host has no port, so re-add a non-default one (e.g. Docker's localhost:8001).
// Apache takes SERVER_PORT from the Host header's port, else its own port. Behind
// a proxy (X-Forwarded-Proto set), that describes the proxy-to-Apache hop, not
// the port the client used (cloudflared sends no port, so Apache reports 80), so
// the port is never added there.
$server_port = Server::get('SERVER_PORT', 0);
if ($server_port !== 0
    && $server_port !== ($is_https ? 443 : 80)
    && $forwarded_proto === ''
) {
    $current_origin .= ':' . $server_port;
}
unset($server_port, $forwarded_proto);

// Request Details
// REQUEST_METHOD is normalized to uppercase (GET, POST, PUT, DELETE, etc.)
// REQUEST_URI includes path and query string (/path?query=value)
// PHP_SELF is the script path (/index.php or /app/owner/cars/details.php)
$method = Server::get('REQUEST_METHOD', 'GET');
$request_uri = Server::get('REQUEST_URI', '/');
$php_self = Server::get('PHP_SELF', '/');

// Full URL Construction
// Combines scheme, host, and path for complete URL reference
// Example: https://elanregistry.org/app/owner/cars/details.php?id=123
$current_url = $current_origin . $request_uri;

// Optional/Tracking Variables
// HTTP_REFERER is sanitized but not validated (user-controlled)
// HTTP_USER_AGENT is truncated to 512 chars for safety
// REMOTE_ADDR is the client IP address (used in logging/security)
$referer = Server::get('HTTP_REFERER', '');
$user_agent = Server::get('HTTP_USER_AGENT', '');
$remote_addr = Server::get('REMOTE_ADDR', '');
