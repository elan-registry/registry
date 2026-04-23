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

/**
 * Check whether Cloudflare Turnstile is enabled and configured.
 *
 * Requires HTTPS — the Turnstile iframe is served over https:// and browsers
 * block cross-protocol frame access, producing error 110200 on plain HTTP.
 *
 * @return bool True when both env keys are present and the connection is HTTPS.
 */
function isTurnstileEnabled(): bool
{
    global $is_https;
    return !empty($is_https)
        && !empty($_ENV['TURNSTILE_SECRET_KEY'])
        && !empty($_ENV['TURNSTILE_SITE_KEY']);
}

/**
 * Render the Cloudflare Turnstile widget into the current form.
 *
 * Outputs a .cf-turnstile div wrapped in a Bootstrap .d-flex.justify-content-center.my-2
 * flex container, followed by the Turnstile api.js script tag.
 * No-ops silently when Turnstile is disabled (off mode or plain HTTP).
 *
 * @return void
 */
function addTurnstile(): void
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

/**
 * Verify the Turnstile token submitted with the current POST request.
 *
 * Returns true immediately when Turnstile is disabled (off mode).
 * Returns false when the token is absent or when the Cloudflare API rejects it.
 * API errors are logged and treated as failures (fail-closed).
 *
 * @return bool True when the challenge passes or Turnstile is disabled.
 */
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
    return _verifyTurnstileToken($_ENV['TURNSTILE_SECRET_KEY'], $token, $remote_addr);
}

/**
 * POST the token to the Cloudflare siteverify endpoint and return the result.
 *
 * @param string $secret Server-side Turnstile secret key.
 * @param string $token  cf-turnstile-response token from the POST body.
 * @param string $ip     Client IP address passed to Cloudflare as a risk signal for bot
 *                       and fraud detection. Optional per the API but improves challenge accuracy.
 * @return bool True when Cloudflare returns success:true.
 */
function _verifyTurnstileToken(string $secret, string $token, string $ip): bool
{
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    if ($ch === false) {
        logger(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Turnstile: curl_init() failed — cURL extension may be unavailable');
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ]),
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result    = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlErrno || $result === false) {
        logger(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Turnstile cURL error: ' . $curlError);
        return false;
    }
    $data = json_decode((string) $result, true);
    if (!is_array($data)) {
        logger(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Turnstile returned invalid JSON: ' . substr((string) $result, 0, 200));
        return false;
    }
    if (!($data['success'] ?? false)) {
        logger(0, LogCategories::LOG_CATEGORY_SECURITY, 'Turnstile rejected token from ' . $ip . ': ' . implode(', ', $data['error-codes'] ?? ['unknown']));
    }
    return (bool) ($data['success'] ?? false);
}
