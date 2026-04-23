<?php
declare(strict_types=1);

/**
 * Cloudflare Turnstile CAPTCHA integration.
 *
 * Keys loaded from $_ENV via phpdotenv:
 *   TURNSTILE_SITE_KEY   — widget site key (public, rendered in HTML)
 *   TURNSTILE_SECRET_KEY — server-side verification key (private)
 *
 * Off mode: omit either key from .env — forms work without CAPTCHA.
 * Fail closed: API errors block submission and are logged.
 */

function isTurnstileEnabled(): bool
{
    global $is_https;
    // Turnstile requires HTTPS — its iframe is served over https:// and browsers
    // block cross-protocol frame access, causing error 110200 on plain HTTP.
    return !empty($is_https)
        && !empty($_ENV['TURNSTILE_SECRET_KEY'] ?? '')
        && !empty($_ENV['TURNSTILE_SITE_KEY'] ?? '');
}

function addTurnstile(string $formId): void
{
    if (!isTurnstileEnabled()) {
        return;
    }
    $siteKey = htmlspecialchars($_ENV['TURNSTILE_SITE_KEY'], ENT_QUOTES, 'UTF-8');
    echo '<div class="d-flex justify-content-center my-2">' . "\n";
    echo '    <div class="cf-turnstile" data-sitekey="' . $siteKey . '" data-appearance="always"></div>' . "\n";
    echo '</div>' . "\n";
    echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>' . "\n";
}

function verifyTurnstile(): bool
{
    if (!isTurnstileEnabled()) {
        return true;
    }
    $token = $_POST['cf-turnstile-response'] ?? '';
    if (empty($token)) {
        return false;
    }
    global $remote_addr;
    return _sendTurnstileRequest($_ENV['TURNSTILE_SECRET_KEY'], $token, $remote_addr);
}

function _sendTurnstileRequest(string $secret, string $token, string $ip): bool
{
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ]),
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => defined('EXTRA_CURL_SECURITY') && EXTRA_CURL_SECURITY,
    ]);
    $result    = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if ($curlErrno || $result === false) {
        error_log('[elan-registry] Turnstile cURL error: ' . curl_strerror($curlErrno));
        return false;
    }
    $data = json_decode((string) $result, true);
    if (!is_array($data)) {
        error_log('[elan-registry] Turnstile returned invalid JSON');
        return false;
    }
    if (!($data['success'] ?? false)) {
        error_log('[elan-registry] Turnstile rejected token: ' . implode(', ', $data['error-codes'] ?? ['unknown']));
    }
    return (bool) ($data['success'] ?? false);
}
