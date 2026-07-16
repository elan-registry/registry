# ADR-007: Implement Content Security Policy and Security Headers

## Status

**Accepted** (retroactive; nonce strategy adopted and `unsafe-inline` removed in v2.27.0 — see Revision History)

## Date

Retroactive -- documented 2026-02-25

## Context

A PHP web application serving a public registry must protect against a range of
browser-exploitable attacks: Cross-Site Scripting (XSS), clickjacking, MIME
sniffing, protocol downgrade, and information disclosure via response headers.
The HTTP security header landscape has evolved significantly since the application
was first deployed on the UserSpice framework.

### Problem Statement

The application required a security header strategy that:

1. Protects against clickjacking for all pages, including error pages that load

   outside the normal UserSpice initialization sequence

2. Establishes a Content Security Policy that permits all required third-party CDN

   domains (see ADR-006) without triggering browser violations

3. Enforces HTTPS via HSTS when running in a secure context, without false-positives

   on local development or HTTP environments

4. Removes PHP version disclosure from response headers
5. Plays well with the UserSpice framework, which sets some security headers

   itself in its default `security_headers.php` file

6. Does not require a CSP nonce system given the UserSpice framework's use of

   inline JavaScript and inline styles throughout its templates *(this constraint
   was reversed in v2.27.0 — see Nonce Feasibility Assessment below)*

### Threat Model

The specific attacks addressed by this decision:

- **Clickjacking (UI Redress)**: An attacker embeds the registry in a hidden

  iframe on another site and tricks visitors into clicking UI elements. Particularly
  relevant for car transfer and account management forms.

- **XSS via MIME Sniffing**: A browser incorrectly interprets a non-script MIME

  type as executable JavaScript and executes malicious content.

- **Protocol Downgrade (SSL Strip)**: An attacker intercepts an HTTP redirect and

  prevents the browser from upgrading to HTTPS.

- **Information Disclosure**: Exposing PHP version via `X-Powered-By` aids

  fingerprinting for known PHP vulnerability exploitation.

- **Cross-Site Content Injection**: Malicious scripts or styles injected from

  unauthorized origins execute in the user's browser.

- **Referrer Leakage**: Full URLs including path and query parameters from the

  registry (which may include car IDs or owner identifiers) sent as the `Referer`
  header to third-party CDN domains.

### UserSpice Framework Constraint

UserSpice ships with its own version of `usersc/includes/security_headers.php`
as a user-customizable file. This file is loaded during Phase 1.11.2 of the page
initialization sequence (inside `users/includes/loader.php`). The UserSpice
design explicitly allows operators to customize this file. The application
replaces the default stub with a fully implemented policy.

### Error Page Isolation

The 403, 404, and 500 branded error pages are invoked by Apache's `ErrorDocument`
directive when the normal application fails. These pages attempt to load
`users/init.php` for session continuity, but that load may fail (database
connection error, missing file, etc.). Security headers on error pages cannot rely
on `users/init.php` loading successfully. This creates a secondary requirement:
error pages must emit minimum anti-clickjacking headers independently, before any
`require_once` that may fail.

### Nonce Feasibility Assessment

A CSP nonce approach was originally assessed and rejected when this ADR was
written. The decision was reversed in v2.27.0 once the prerequisite extraction
of inline scripts was complete (#1327, #1328).

**Why nonces became viable by v2.27.0:**

1. All custom inline `<script>` blocks were extracted from admin templates (#1327)
   and user-facing templates (#1328) into external cached files. The remaining
   inline blocks are either pure data islands (window.*Config objects) or upstream
   UserSpice templates.

2. UserSpice already supports nonces: every inline `<script>` block in
   `usersc/login.php`, `usersc/includes/system_messages_footer.php`,
   `usersc/templates/customizer/header.php`, `usersc/templates/customizer/customize.php`,
   `users/login.php`, `users/join.php`, and other framework files carries a
   `nonce="<?= htmlspecialchars($userspice_nonce ?? '') ?>"` attribute. These
   attributes were non-functional only because `$userspice_nonce` was never
   populated in the CSP header.

3. Some UserSpice inline scripts contain PHP conditionals (e.g., the login page
   script toggles TOTP vs. password flow) and `Math.random()` calls (the flash
   message system), making hash-allowlisting impossible. Nonces are the only
   viable option for these dynamic scripts.

4. Generating `$userspice_nonce` in `security_headers.php` (before the CSP header
   call) and including it as `'nonce-{$userspice_nonce}'` in `script-src` wires
   up the entire nonce chain without modifying any upstream files:
   - `security_headers.php` runs at Phase 1.11.2, before any HTML output
   - The variable is in PHP global scope, visible to all subsequent template renders
   - Upstream templates already carry `nonce="<?= htmlspecialchars($userspice_nonce ?? '') ?>"`

5. Data island `<script>` blocks in the new external-JS architecture (e.g.,
   `window.carListConfig = {...}`) are PHP-generated per request and must also
   carry the nonce attribute. These were added during the v2.27.0 extraction.

## Decision

Implement security headers as a two-layer defense:

### Layer 1: Application-Level Headers (Primary)

Replace the default UserSpice `usersc/includes/security_headers.php` with a
fully implemented version that sets seven HTTP headers on every application page.
This file is loaded at Phase 1.11.2 of the page initialization sequence (called
from `users/includes/loader.php`), before any HTML output is produced.

**File:** `usersc/includes/security_headers.php`

#### 1. Content Security Policy (CSP)

A multi-directive CSP is emitted via a single `header()` call using PHP string
concatenation for readability. The policy uses `'unsafe-inline'` and
`'unsafe-eval'` (rationale in the Alternatives section) and whitelists all
third-party domains required by the application:

**Directives:**

| Directive | Value | Purpose |
| --- | --- | --- |
| `default-src` | `'self'` | Catch-all fallback for unlisted resource types |
| `script-src` | `'self' 'nonce-{per-request}'` + 5 SHA-256 hashes + CDN domains | Allow scripts via per-request nonce; belt-and-suspenders SHA-256 hashes for static upstream scripts |
| `style-src` | `'self' 'unsafe-inline'` + CDN domains | Allow Bootstrap/Bootswatch/FontAwesome inline and CDN styles |
| `img-src` | `'self' data: blob:` + image domains | Allow embedded SVGs (`data:`), canvas exports (`blob:`), MapLibre GL JS / VersaTiles map tiles (`https://tiles.versatiles.org`), Gravatar avatars |
| `font-src` | `'self'` + font CDN domains | FontAwesome kit and Google Fonts |
| `connect-src` | `'self'` + API domains | AJAX calls to application endpoints, MapLibre GL JS tile fetches (`https://tiles.versatiles.org`), Cloudflare Analytics beacon |
| `frame-src` | `'self' https://challenges.cloudflare.com` | Cloudflare Turnstile CAPTCHA iframe on registration page |
| `frame-ancestors` | `'self'` | Anti-clickjacking: prevents cross-origin iframes (CSP3, preferred method) |
| `form-action` | `'self'` | Restricts form POST targets to same-origin (does not fall back to `default-src`, must be set explicitly) |
| `object-src` | `'none'` | Blocks Flash/plugin embeds entirely |
| `base-uri` | `'self'` | Prevents`<base>` tag injection attacks that redirect relative URLs |

**Whitelisted CDN Domains (script-src):**

```text
https://challenges.cloudflare.com     Cloudflare Turnstile CAPTCHA
https://code.jquery.com               jQuery (UserSpice loads from CDN via users/js/jquery.php)
https://static.cloudflareinsights.com Cloudflare Analytics beacon
https://cdnjs.cloudflare.com          Bootstrap CSS/JS and Chart.js (UserSpice admin dashboard)
```

> **2026-05-06 (v2.22.0):** Removed `https://maps.googleapis.com`,
> `https://www.gstatic.com`, and `https://ssl.gstatic.com` from `script-src`
> when the application migrated off Google Maps. MapLibre GL JS replaced the
> Google Maps JavaScript API and is served as a vendored asset
> (`usersc/js/maplibre-gl.min.js`, ADR-015), so no third-party `script-src`
> entry is required for map rendering. Map tile fetches use
> `https://tiles.versatiles.org`, which is whitelisted under `img-src` and
> `connect-src` rather than `script-src`.

**Whitelisted CDN Domains (style-src):**

```text
https://cdnjs.cloudflare.com       Bootstrap CSS and Chart.js (UserSpice admin dashboard)
```

> **2026-05-06 (v2.22.0):** Removed `https://www.gstatic.com` from `style-src`
> alongside the Google Maps removal. The previous entry covered Google-injected
> styles for Maps; MapLibre GL JS uses the vendored `usersc/css/maplibre-gl.css`
> stylesheet served from `'self'` and requires no third-party `style-src` entry.

**Whitelisted Tile Domains (img-src and connect-src):**

```text
https://tiles.versatiles.org       MapLibre GL JS map tiles (raster + vector tiles)
```

The VersaTiles tile server is reached as an image origin (raster tiles, glyphs,
and sprite PNGs) and as a `fetch()` origin (vector tile PBFs and the style
JSON), so it appears in both `img-src` and `connect-src`. It does not appear
in `script-src` because the MapLibre GL JS library itself is vendored locally
(ADR-015).

#### 2. HTTP Strict Transport Security (HSTS)

HSTS is emitted only when the request is served over HTTPS, using the `$is_https`
server global (which in turn uses `REQUEST_SCHEME`via the`Server`
class, making it proxy-aware):

```php
if ($is_https) {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
}
```

Parameters:

- `max-age=31536000`: One year; browsers remember the HTTPS requirement
- `includeSubDomains`: Applies to all subdomains of elanregistry.org
- `preload`: Eligible for browser HSTS preload list submission

The conditional emission prevents false HSTS headers on local HTTP development
environments, which would lock developers out of their HTTP local server.

#### 3. X-Frame-Options

```text
X-Frame-Options: SAMEORIGIN
```

Legacy clickjacking protection for browsers that do not support `frame-ancestors`.
`SAMEORIGIN` allows framing within the same origin (e.g., admin panels using
iframes of application pages) while blocking cross-origin iframes.

`DENY` was rejected because it would block same-origin iframes that may be used
legitimately within the application itself (e.g., admin tools, documentation
viewers).

#### 4. X-XSS-Protection

```text
X-XSS-Protection: 1; mode=block
```

Enables and enforces the browser's built-in XSS filter for older browsers (Internet
Explorer, older Chrome/Safari). Modern browsers have deprecated this header in
favor of CSP, but it provides a defense layer for legacy clients. `mode=block`
prevents partial page rendering on XSS detection.

Note: This header has no effect in current versions of Firefox, Chrome 78+, or
Safari. It is retained for defense-in-depth coverage of any legacy browsers in
the registry's user base.

#### 5. X-Content-Type-Options

```text
X-Content-Type-Options: nosniff
```

Prevents browsers from MIME-type sniffing: the browser will not attempt to
interpret a file as a different MIME type than declared. Blocks drive-by-download
attacks where a server serves a JavaScript file with an image MIME type.

#### 6. Referrer-Policy

```text
Referrer-Policy: no-referrer-when-downgrade
```

Sends the full `Referer` header on same-origin and HTTPS-to-HTTPS requests, but
omits it on HTTPS-to-HTTP (protocol downgrade) requests. This balances:

- Usability: analytics and navigation logic that reads the referer still function
- Privacy: registry URLs are not leaked to insecure external sites

Note: A discrepancy exists between this value and the `.htaccess` value. See
the Known Issues section.

#### 7. Remove X-Powered-By

```php
header_remove("X-Powered-By");
```

Removes the PHP version disclosure header that PHP sets by default. This prevents
fingerprinting the application for known PHP CVE exploitation.

### Layer 2: Apache-Level Headers (.htaccess Baseline)

The root `.htaccess` sets three headers at the Apache level as a defense-in-depth
baseline. These headers are emitted for any response Apache serves, including
static assets, responses to requests that never reach PHP, and scenarios where
PHP fails before `security_headers.php` loads:

```apache
Header always set X-Frame-Options "SAMEORIGIN"
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
```

Apache headers are emitted in addition to PHP `header()` calls. When both are
present, the browser receives both values. RFC 7230 permits multiple values for
the same header name (browsers use the last value or concatenate, behavior varies
by header and browser). In practice:

- `X-Frame-Options`with duplicate`SAMEORIGIN` values is harmless; browsers

  honor `SAMEORIGIN`

- `X-Content-Type-Options`with duplicate`nosniff` is harmless
- `Referrer-Policy`with conflicting values (PHP:`no-referrer-when-downgrade`;

  Apache: `strict-origin-when-cross-origin`) may result in browser-specific
  behavior

### Layer 3: Error Page Fallback Headers

The 403, 404, and 500 error pages set minimum anti-clickjacking headers before
any `require_once` that could fail:

```php
// Anti-clickjacking headers (set explicitly in case init.php fails to load)
header("X-Frame-Options: SAMEORIGIN");
header("Content-Security-Policy: frame-ancestors 'self'");
```

This minimal CSP covers only `frame-ancestors` (the anti-clickjacking directive)
rather than the full application CSP. The error pages load Bootstrap CSS and JS
from self-hosted assets (`usersc/css/` and `usersc/js/`), which `'self'` covers,
so no CDN domains need to be whitelisted in the fallback. The full application CSP
(from `security_headers.php`) is emitted if `init.php` succeeds; if it fails, the
fallback CSP at minimum prevents clickjacking.

Additionally, if `init.php` fails to load on an error page, the error page
directly requires the `Server`class and`server_globals.php` to ensure validated
request globals are available for logging:

```php
if (!isset($request_uri)) {
    require_once __DIR__ . '/../users/classes/Server.php';
    require_once __DIR__ . '/../usersc/includes/server_globals.php';
}
```

### Layer 4: Scripts Directory Hardening

The `scripts/.htaccess` sets stricter headers for the administrative scripts
directory:

```apache
Header always set X-Frame-Options DENY
```

`DENY`(instead of`SAMEORIGIN`) is used for the scripts directory because
administrative scripts should never be framed by any page, including same-origin
pages.

### CSP Violation Monitoring

No `report-uri`or`report-to` directive is included in the CSP. Violation
monitoring is handled entirely via Playwright end-to-end tests
(`tests/playwright/csp-validation.spec.js`) that:

- Listen for CSP violations in the browser console
- Monitor `pageerror` events for CSP-related errors
- Test multiple page types: home page, car listings, car details, login page,

  statistics page (which loads MapLibre GL JS and Chart.js)

- Verify critical external domains (cloudflareinsights.com) load without failure

PHPUnit unit tests (`tests/unit/security/SecurityHeadersTest.php`) and integration
tests (`tests/integration/ErrorPageHeadersTest.php`) verify static correctness of
the header configuration in code, and Playwright E2E tests
(`tests/playwright/security/clickjacking.spec.ts`) verify runtime header values
via HTTP response inspection.

## Revision History

| Date | Change | Issue |
| --- | --- | --- |
| 2026-04-22 | Replace reCAPTCHA CSP entries with Cloudflare Turnstile | #630 |
| 2026-04-27 | Remove 11 stale/library CDN domains from CSP allowlist (libraries self-hosted per ADR-015) | #405 |
| 2026-05-06 | Remove Google Maps domains (`maps.googleapis.com`, `maps.gstatic.com`, `gstatic.com`, `ssl.gstatic.com`, `www.gstatic.com`) from CSP allowlist; add `https://tiles.versatiles.org` to `img-src` and `connect-src` for MapLibre GL JS tile fetches | v2.22.0 |
| 2026-07-13 | Add `form-action 'self'` directive; remove `'unsafe-eval'` from `script-src` (grep-verified: no `eval()` or `new Function()` in first-party JS under `app/assets/js/`, `app/admin/assets/`, `usersc/js/`, or inline `<script>` blocks in customizer templates) | #1326 |
| 2026-07-15 | Remove `'unsafe-inline'` from `script-src`; add per-request nonce (`'nonce-{$userspice_nonce}'`) generated in `security_headers.php`; add SHA-256 hashes for 5 static upstream scripts as belt-and-suspenders; wire nonce attributes to all data-island `<script>` blocks introduced in #1328 | #1328 |

> **2026-04-27 (#405):** Removed the following domains from the CSP allowlist:
>
> - Library-specific (now self-hosted): `https://code.jquery.com`,
>   `https://cdn.datatables.net`, `https://kit.fontawesome.com`
> - Stale/unused: `https://unpkg.com`, `https://maxcdn.bootstrapcdn.com`,
>   `https://stackpath.bootstrapcdn.com`, `https://cdn.popper.js.org`,
>   `https://bootswatch.com`, `https://cdn.bootswatch.com`,
>   `https://use.fontawesome.com`, `https://ka-f.fontawesome.com`
>
> jQuery UI, DataTables JS/CSS, and Chart.js are now vendored in `usersc/`.
> Font Awesome is served from `users/fonts/css/`. Remaining CDN domains
> (`cdn.jsdelivr.net`, `cdnjs.cloudflare.com`) will be removed in #618 when
> Bootstrap migrates to self-hosted.
>
> **2026-05-06 (v2.22.0):** Removed all Google Maps related domains from the
> CSP allowlist: `https://maps.googleapis.com`, `https://maps.gstatic.com`,
> `https://gstatic.com`, `https://ssl.gstatic.com`, and
> `https://www.gstatic.com`. MapLibre GL JS plus the VersaTiles tile service
> replaced the Google Maps JavaScript API in v2.22.0. The MapLibre GL JS
> library is vendored locally (`usersc/js/maplibre-gl.min.js` and
> `usersc/css/maplibre-gl.css`, ADR-015), so no third-party `script-src` or
> `style-src` entry is required for map rendering. Map tiles are fetched from
> `https://tiles.versatiles.org`, which is whitelisted under both `img-src`
> (raster tiles, sprites, glyphs) and `connect-src` (vector tile PBFs and the
> style JSON).

## Known Issues

### `'unsafe-inline'` Removed — v2.27.0

`'unsafe-inline'` was removed from `script-src` in v2.27.0 as the final step of
the three-phase CSP migration plan (#1326, #1327, #1328). The Known Issues and
Consequences sections for this limitation have been retired. The CSP now enforces
strict inline-script control via per-request nonces and SHA-256 hash allowlisting.

### Referrer-Policy Discrepancy

The PHP `security_headers.php`sets`Referrer-Policy: no-referrer-when-downgrade`
while the root `.htaccess`sets`Referrer-Policy: strict-origin-when-cross-origin`.
These are different policies:

- `no-referrer-when-downgrade`: Sends full URL on same-origin and HTTPS-to-HTTPS

  cross-origin requests; omits on HTTPS-to-HTTP

- `strict-origin-when-cross-origin`: Sends full URL on same-origin requests, only

  origin (no path) on HTTPS-to-HTTPS cross-origin requests, omits on HTTP-to-HTTPS

`strict-origin-when-cross-origin` is the more privacy-preserving policy (MDN
recommends it as the default) and prevents registry car/owner URL paths from
leaking to CDN analytics and tracking endpoints. The PHP value should be updated
to match the Apache value, or the Apache value should be removed to eliminate
the duplicate header entirely.

### CSP Migration Plan — Completed (v2.27.0)

The three-phase inline-script extraction and `'unsafe-inline'` removal is complete:

| Phase | Issue | Status | Summary |
| --- | --- | --- | --- |
| A — CSP quick wins | #1326 | ✅ Merged | Added `form-action 'self'`; removed `'unsafe-eval'` |
| B — Admin JS extraction | #1327 | ✅ Merged | Extracted inline `<script>` blocks from all admin templates into `app/admin/assets/js/` |
| C — User-facing JS extraction + nonce flip | #1328 | ✅ Merged | Extracted inline `<script>` blocks from user-facing templates; wired `$userspice_nonce`; removed `'unsafe-inline'` |

**Upstream-template resolution (Phase C):**

The customizer templates (`header.php`, `customize.php`) were handled via a
hybrid nonce + hash approach:

- **Nonce**: `$userspice_nonce` is now generated in `security_headers.php` and
  propagated as `'nonce-{value}'` in `script-src`. All inline scripts in these
  templates already carry `nonce="<?= htmlspecialchars($userspice_nonce ?? '') ?>"`.
- **SHA-256 hashes (belt-and-suspenders)**: Five static script blocks in
  `header.php` and `customize.php` are also hash-allowlisted (`'sha256-Gp7...'`
  etc.) as defense in depth in case the nonce mechanism fails.
- **Dynamic scripts**: Scripts in `users/login.php`, `usersc/login.php`,
  `usersc/includes/system_messages_footer.php`, and other framework files that
  contain PHP conditionals or `Math.random()` calls are covered exclusively by
  the nonce (they cannot be hashed).

## Consequences

### Positive

- **Clickjacking protection on all pages.** The dual-layer approach (CSP

  `frame-ancestors`+`X-Frame-Options`) ensures clickjacking protection regardless
  of browser CSP3 support level. Error pages have independent fallback headers that
  work even when `init.php` fails to load.

- **HTTPS enforcement for sessions.** HSTS with a one-year max-age prevents

  SSL-stripping attacks and ensures the registry's authentication cookies are
  only transmitted over HTTPS.

- **Supply-chain attack surface reduced.** The CSP domain whitelist means that

  even if an attacker injects a `<script src="https://evil.com/...">` tag (via
  stored XSS), the browser will block it. Only the explicitly whitelisted CDN
  domains can serve scripts.

- **PHP version hidden.** Removing `X-Powered-By` prevents automated scanners

  from trivially identifying the PHP version for known CVE matching.

- **MIME sniffing blocked.** `X-Content-Type-Options: nosniff` prevents a class

  of attacks where uploaded files served with incorrect MIME types execute as
  scripts.

- **Tested at multiple levels.** Unit tests verify static header file content.

  Integration tests verify error page header structure. Playwright E2E tests
  verify actual HTTP response headers and CSP violation absence at runtime.

- **Integrated with server globals abstraction.** HSTS emission uses

  `$is_https`from the validated server globals rather than direct`$_SERVER`
  access, ensuring correct behavior behind reverse proxies.

- **No CSP reporting overhead.** Without a `report-uri` endpoint, there is no

  server-side overhead from browser violation reports. Playwright tests cover
  the same use case in the CI/CD pipeline.

### Negative

- **`'unsafe-inline'` removed (v2.27.0)** — inline-script XSS protection is now
  enforced via nonces. Injected inline `<script>` blocks lacking the per-request
  nonce are blocked by the browser.

- **Nonce security degrades if nonce is leaked per-request.** The nonce is in
  global PHP scope and is visible in rendered HTML source. An attacker with XSS
  could read the current page's nonce and craft a same-request injection that uses
  it. Per-request nonces mitigate this because the token changes on every reload.

- **Broad CDN whitelist increases supply-chain risk surface.** Whitelisting CDNs

  like `cdn.jsdelivr.net`, `cdnjs.cloudflare.com`, and `unpkg.com` means any
  package on those CDNs can be loaded if an attacker injects a `<script>` tag
  pointing to a malicious package on a whitelisted domain. This is a known
  limitation of CDN-based CSP and is partially mitigated by SRI hashes on CDN
  resources (ADR-006).

- **Referrer-Policy value inconsistency.** Two different Referrer-Policy values

  (PHP vs. Apache) can be sent simultaneously. Browser behavior with conflicting
  same-name headers varies; the stricter or more recent value is typically used,
  but this is not guaranteed.

- **No CSP violation reporting.** Without a `report-uri` endpoint, CSP violations

  that occur in production (not caught by Playwright tests) are invisible.
  Violations would only surface through user-reported broken functionality.

- **HSTS `preload` directive creates long-term commitment.** Submitting

  elanregistry.org to the browser HSTS preload lists means the domain will be
  hardcoded as HTTPS-only in browsers for potentially years. Removing from
  preload lists takes months. Switching to HTTP-only hosting after preload
  submission would break the site for all preload-list browsers.

- **Apache `.htaccess`dependency.** The Layer 2 headers require`mod_headers`

  to be loaded in Apache. On shared hosting, this is typically available but not
  guaranteed. Missing `mod_headers` silently removes the baseline Apache headers.

- **Duplicate headers for `X-Frame-Options`and`X-Content-Type-Options`.** PHP

  and Apache both set these headers. While currently harmless, any future change
  to the PHP values requires coordinated changes to `.htaccess` or the duplicate
  Apache value will persist.

### Risks

| Risk | Likelihood | Impact | Mitigation |
| --- | --- | --- | --- |
| Stored XSS via injected inline script | Very Low | High | `'unsafe-inline'` removed (v2.27.0); per-request nonce blocks injected inline scripts; input validation, output escaping, and CSRF tokens mitigate primary injection vectors |
| CDN-domain CSP bypass (e.g., malicious npm package on jsdelivr.net) | Very Low | High | SRI hashes (ADR-006) block tampered CDN files; domain allowlist is a necessary trade-off for CDN-based dependencies |
| HSTS preload causes prolonged downtime if HTTPS fails | Very Low | Critical | Ensure HTTPS renewal is automated (Let's Encrypt); monitor cert expiry; do not submit to preload lists until production HTTPS is stable |
| CSP violation in production breaks user functionality silently | Medium | Medium | Playwright E2E tests catch CSP violations in CI; users experiencing issues should report to admin; consider adding temporary `report-uri` during major changes |
| `mod_headers`not available on shared hosting | Low | Low | Verify`mod_headers` is enabled on A2 Hosting; Apache headers are defense-in-depth, not primary security layer |
| Future CDN domain addition blocked by CSP | Medium | Low | Maintain the CDN whitelist in `security_headers.php` when adding new CDN dependencies; document this requirement in CLAUDE.md |

## Alternatives Considered

### Nonce-Based CSP (Strict CSP)

Replace `'unsafe-inline'` with per-request cryptographic nonces on all
`<script>` tags. Emit `'nonce-{base64value}'` in the CSP header and add
`nonce="..."` attributes to each inline script block.

**Originally rejected** (retroactive ADR) because UserSpice upstream templates
could not be modified to carry nonce attributes, and adding nonces while keeping
`'unsafe-inline'` provides no security benefit.

**Adopted in v2.27.0 (#1328)** once the prerequisites were met:

- All custom inline scripts were extracted to external files (#1327, #1328),
  so no custom template contains `<script>` blocks that need nonces added.
- UserSpice templates already carry `nonce="<?= htmlspecialchars($userspice_nonce ?? '') ?>"`
  on all their inline scripts — the nonce attribute infrastructure was always there.
- `$userspice_nonce` is now generated in `security_headers.php` (Phase 1.11.2)
  and included in `'nonce-...'` in `script-src`.
- `'unsafe-inline'` is no longer needed once nonces are wired.

**Implementation:** `$userspice_nonce = base64_encode(random_bytes(16));` in
`security_headers.php`, before the `header()` call. Data-island `<script>` blocks
(PHP-generated per-request JSON objects) carry the nonce via
`<script nonce="<?= htmlspecialchars($userspice_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">`.
SHA-256 hashes are kept for static upstream scripts as belt-and-suspenders.

### Hash-Based CSP

Pre-compute SHA-256 hashes of each known inline script block and list them in
the CSP `script-src` directive (`'sha256-{base64hash}'`). No `'unsafe-inline'`
required.

**Rejected because:**

- Requires computing hashes for every inline script in UserSpice templates,

  plugins, and helpers—dozens of blocks across the framework.

- Framework updates break hashes; every UserSpice update requires re-hashing all

  inline scripts.

- Dynamic inline scripts (any PHP-generated JavaScript) cannot be pre-hashed

  because their content varies per request.

- The UserSpice framework generates significant amounts of dynamic inline

  JavaScript (flash messages, configuration values, etc.) that cannot be
  pre-hashed.

### `DENY` for X-Frame-Options Application-Wide

Set `X-Frame-Options: DENY` for all application pages (not just the scripts
directory) to block all framing including same-origin frames.

**Rejected because:**

- `DENY` prevents legitimate same-origin use cases such as admin iframes and

  documentation viewers.

- The documentation viewer feature (`app/docs/`) may render documents in iframes.
- `SAMEORIGIN` provides equivalent protection against cross-origin clickjacking

  attacks (which is the actual threat model) while permitting legitimate
  same-origin framing.

- The CSP `frame-ancestors 'self'` directive already enforces the same restriction

  via the modern CSP3 mechanism.

### CSP Reporting Endpoint

Add `report-uri /app/ajax/csp-report.php` to the CSP header. Create a PHP
endpoint that receives, validates, and stores violation reports in the database
or logs them via the `logger()` function.

**Not adopted (but identified as a future enhancement) because:**

- Adds server-side overhead: every CSP violation in every browser session sends

  an HTTP request to the application server.

- Public CSP reporting endpoints are commonly abused for denial-of-service (DoS)

  by flooding the endpoint with fake violation reports.

- The application already has Playwright E2E tests that catch CSP violations in

  CI/CD; runtime production reporting would be redundant.

- The added complexity (endpoint, authentication, rate limiting, storage,

  alerting) is not warranted for the application's scale.

- Cloudflare Analytics (already whitelisted in `connect-src`) provides general

  error visibility; CSP violations are a development-time concern addressed by
  the Playwright test suite.

- If a significant policy change is planned (e.g., removing `'unsafe-inline'`),

  a temporary `report-only` CSP header can be added at that time without
  committing to a permanent reporting infrastructure.

### Web Application Firewall (WAF) Headers

Rely on a WAF layer (Cloudflare WAF, AWS WAF, ModSecurity) to inject security
headers at the infrastructure level, removing header management from PHP.

**Rejected because:**

- The application runs on shared hosting (A2 Hosting) without WAF capabilities.
- Cloudflare proxy is not in use; the application connects directly to A2 Hosting.
- Adding a WAF layer introduces a new infrastructure dependency incompatible with

  the shared-hosting deployment model (ADR-001).

- Application-level headers provide more granular control (e.g., conditional HSTS

  based on `$is_https`) than infrastructure-level rules.

### Permissions-Policy Header

Add a `Permissions-Policy` header to restrict browser feature access
(geolocation, camera, microphone, payment, etc.).

**Not adopted (but identified as planned) because:**

- The application does not use browser geolocation APIs (MapLibre GL JS

  renders from vendored assets and pre-computed marker coordinates; address
  coordinates are geocoded server-side via ElanRegistryOwner).

- No camera, microphone, payment, or other restricted API access occurs.
- The benefit is minimal for this application's feature set.
- Adding a restrictive `Permissions-Policy` could inadvertently block future

  features without clear documentation that the header must be updated.

- Remains a planned addition once the feature set stabilizes and requirements

  for browser permissions are well understood.

## References

**Implementation Files:**

- **Security Headers:** [usersc/includes/security_headers.php](../../usersc/includes/security_headers.php)
- **Root .htaccess:** [.htaccess](../../.htaccess)
- **Scripts Directory .htaccess:** [scripts/.htaccess](../../scripts/.htaccess)
- **Error Pages:** [error/403.php](../../error/403.php), [error/404.php](../../error/404.php), [error/500.php](../../error/500.php)
- **Footer (ElanRegistryAPI):** [usersc/includes/footer.php](../../usersc/includes/footer.php)
- **Join Page:** [usersc/join.php](../../usersc/join.php)

**Testing:**

- **Unit Tests:** [tests/unit/security/SecurityHeadersTest.php](../../tests/unit/security/SecurityHeadersTest.php)
- **Integration Tests:** [tests/integration/ErrorPageHeadersTest.php](../../tests/integration/ErrorPageHeadersTest.php)
- **Playwright Clickjacking Tests:** [tests/playwright/security/clickjacking.spec.ts](../../tests/playwright/security/clickjacking.spec.ts)
- **Playwright CSP Validation Tests:** [tests/playwright/csp-validation.spec.js](../../tests/playwright/csp-validation.spec.js)

**Related Documentation:**

- **Page Loading Flow:** [docs/development/PAGE_LOADING_FLOW.md](../development/PAGE_LOADING_FLOW.md) (Phase 1.11.2)
- **v2.15.1 Release Notes:** [docs/releases/RELEASE_NOTES_v2.15.1.md](../releases/RELEASE_NOTES_v2.15.1.md) (Issue #420)

**Related ADRs:**

- **ADR-001:** UserSpice framework constraint preventing nonce-based CSP
- **ADR-006:** CDN URL management and SRI hash protection

**External References:**

- [MDN: Content Security Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP)
- [OWASP: Content Security Policy Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html)
- [Nygard: Documenting Architecture Decisions](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions)
