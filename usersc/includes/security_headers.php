<?php
//feel free to edit these as desired. They're just suggestions.

////////////////////////////////////////////////////////////////////////////////

// Security Headers can be scanned using https://securityheaders.io/

/*
1. Content Security Policy

The content-security-policy HTTP header provides an additional layer of security. This policy helps prevent attacks such as Cross Site Scripting (XSS) and other code injection attacks by defining content sources which are approved and thus allowing the browser to load them.
*/

// Content Security Policy for ElanRegistry
// Most libraries are self-hosted — CDN origins needed for:
//   - VersaTiles (map tiles for self-hosted MapLibre GL JS), Cloudflare Turnstile (CAPTCHA),
//     Cloudflare Analytics, code.jquery.com (UserSpice loads jQuery from there via users/js/jquery.php),
//     cdnjs.cloudflare.com (Customizer template loads Bootstrap CSS/JS; UserSpice dashboard loads Chart.js)
// worker-src blob: required because MapLibre GL JS spawns tile-processing Web Workers from blob URLs
header("Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'unsafe-inline' 'unsafe-eval' " .
        "https://challenges.cloudflare.com " .
        "https://code.jquery.com " .
        "https://static.cloudflareinsights.com " .
        "https://cdnjs.cloudflare.com; " .
    "style-src 'self' 'unsafe-inline' " .
        "https://cdnjs.cloudflare.com; " .
    "img-src 'self' data: blob: " .
        "https://tiles.versatiles.org " .
        "https://www.gravatar.com; " .
    "font-src 'self'; " .
    "connect-src 'self' " .
        "https://tiles.versatiles.org " .
        "https://challenges.cloudflare.com " .
        "https://cloudflareinsights.com " .
        "https://static.cloudflareinsights.com; " .
    "frame-src 'self' https://challenges.cloudflare.com; " .
    "frame-ancestors 'self'; " .
    "worker-src 'self' blob:; " .
    "object-src 'none'; " .
    "base-uri 'self'"
);


/*
2. HTTP Strict Transport Security (HSTS)

The strict-transport-security header is a security enhancement that restricts web browsers to access web servers solely over HTTPS. This ensures the connection cannot be establish through an insecure HTTP connection which could be susceptible to attacks.
*/

// Server global $is_https already available from server_globals.php
// loaded in Phase 1.11.12 (usersc/includes/loader.php)
// Uses validated Server::getScheme() with proper proxy handling

if ($is_https) {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
}


/*
3. X-Frame-Options

The x-frame-options header provides clickjacking protection by not allowing iframes to load on your site.
helps prevent clickjacking by indicating to a browser that it should not render the page in a frame (or an iframe or object).
*/

header("X-Frame-Options: SAMEORIGIN");


/*
4. X-Content-Type-Options

The X-content-type header prevents Internet Explorer and Google Chrome from sniffing a response away from the declared content-type. This helps reduce the danger of drive-by downloads and helps treat the content the right way.
X-Content-Type-Options header instructs IE not to sniff mime types, preventing attacks related to mime-sniffing.
*/

header("X-Content-Type-Options: nosniff");


/*
5. The referrer directive specifies information for the referrer header in links away from the page.

    No Referrer - Prevents the UA sending a referrer header.
    No Referrer When Downgrade - Prevents the UA sending a referrer header when navigating from https to http.
    Origin Only - Allows the UA to only send the origin in the referrer header.
    Origin When Cross Origin - Allows the UA to only send the origin in the referrer header when making cross-origin requests.
    Unsafe URL - Allows the UA to send the full URL in the referrer header with same-origin and cross-origin requests. This is unsafe.
*/

header("Referrer-Policy: no-referrer-when-downgrade");


// 6. There is no direct security risk, but exposing an outdated (and possibly vulnerable) version of PHP may be an invitation for people to try and attack it.

header_remove("X-Powered-By");

/////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
