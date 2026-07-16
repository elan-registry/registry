<?php
// Security Headers can be scanned using https://securityheaders.io/

/*
1. Content Security Policy
*/

$userspice_nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' " .
        "'nonce-" . $userspice_nonce . "' " .
        "'sha256-Gp7ipy0WBym3p5WvlmBvmssRnJFaat6PlQiZ9FC7k7A=' " .
        "'sha256-p0PjOpqpTgBYc04Ujji9kTgR4nn7/Fmqy5WArI/yZSc=' " .
        "'sha256-XypEqq0A9tnLE3DLjvBL9sCA2H6c7NOx43R843oAkmE=' " .
        "'sha256-38VPq9JsPUZTzEN/WNclAVm82+XGI17KgkbMO8mZIlE=' " .
        "'sha256-KMScC9XivLUfpNhV56/pFlU+TqsVfQXxfmqreYTflHg=' " .
        "https://challenges.cloudflare.com " .
        "https://code.jquery.com " .
        "https://static.cloudflareinsights.com " .
        "https://cdnjs.cloudflare.com; " .
    "style-src 'self' 'unsafe-inline' " .
        "https://cdnjs.cloudflare.com " .
        "https://fonts.googleapis.com; " .
    "img-src 'self' data: blob: " .
        "https://tiles.versatiles.org " .
        "https://www.gravatar.com; " .
    "font-src 'self' https://fonts.gstatic.com; " .
    "connect-src 'self' " .
        "https://tiles.versatiles.org " .
        "https://challenges.cloudflare.com " .
        "https://cloudflareinsights.com " .
        "https://static.cloudflareinsights.com; " .
    "frame-src 'self' https://challenges.cloudflare.com; " .
    "frame-ancestors 'self'; " .
    "form-action 'self'; " . // does not fall back to default-src; must be listed explicitly
    "worker-src 'self' blob:; " .
    "object-src 'none'; " .
    "base-uri 'self'"
);


if ($is_https) {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
}



header("X-Frame-Options: SAMEORIGIN");

header("X-Content-Type-Options: nosniff");

header("Referrer-Policy: no-referrer-when-downgrade");

header("Permissions-Policy: camera=(), microphone=(), payment=(), usb=(), interest-cohort=()");

header_remove("X-Powered-By");

