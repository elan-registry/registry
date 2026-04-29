# ADR-015: Self-Host Frontend Libraries via Source-Controlled Assets

## Status

Accepted

**Supersedes:** [ADR-006](ADR-006-use-database-stored-cdn-urls-for-frontend-dependencies.md)

## Date

2026-04-27

## Context

The ElanRegistry template was loading 14 frontend libraries via
HTML-entity-encoded `<script>`/`<link>` tags stored in the database `settings`
table (ADR-006). This DB-driven approach proved fragile in practice: there was
no version history, no code review, and no easy way to diff what changed
between deployments. It was hard to audit for security vulnerabilities (no
Dependabot coverage, no clear list of pinned versions in source) and
incompatible with source-controlled deployments where the application files
move forward but the database does not.

As a prerequisite for the Bootstrap 5 template migration (#618), all library
loading must move to source-controlled files. Pinning library versions in
`package.json` enables Dependabot security alerts and produces a clear,
reviewable history of dependency changes.

### Problem Statement

The application required a frontend dependency strategy that:

1. Stores library versions in source control (Git) for auditability
2. Enables automated security advisory monitoring (Dependabot on `package.json`)
3. Eliminates the database/code split where templates depend on DB rows that
   may not match the deployed code
4. Reduces the CSP allowlist surface (ADR-007) by removing third-party CDN
   domains
5. Removes the HTML-entity-encoding fragility of storing complete `<script>`
   tags as encoded strings

## Decision

Replace database-driven CDN configuration with vendored local files committed
to the repository under `usersc/js/` and `usersc/css/`. Library versions are
pinned in `package.json` to enable Dependabot security alerts. Global
libraries already managed by UserSpice (jQuery, Font Awesome, DataTables CSS,
jQuery UI) continue to be loaded from their existing self-hosted locations in
`users/`.

### Libraries Vendored

| Library | Version | Vendored Path |
| --- | --- | --- |
| Bootstrap CSS | 5.3.3 | `usersc/css/bootstrap.min.css` (error pages only — see note) |
| Bootstrap JS | 5.3.3 | `usersc/js/bootstrap.bundle.min.js` (error pages only — see note) |
| DataTables JS (BS4 bundle) | dt-1.10.23 + extensions | `usersc/js/datatables.min.js` |
| DataTables CSS (BS4 bundle) | dt-1.10.23 + extensions | `usersc/css/datatables.min.css` |
| Dropzone JS | 5.7.6 | `usersc/js/dropzone.min.js` |
| Dropzone CSS | 5.7.6 | `usersc/css/dropzone.min.css` |
| Chart.js | 4.5.1 | `usersc/js/chart.umd.min.js` |
| jQuery UI | 1.12.1 | `usersc/js/jquery-ui.min.js` |
| flatpickr JS | 4.6.13 | `usersc/js/flatpickr.min.js` |
| flatpickr CSS | 4.6.13 | `usersc/css/flatpickr.min.css` |

> **Bootstrap note:** The Customizer template's `header.php` loads Bootstrap
> 5.3.3 from `cdnjs.cloudflare.com` (upstream default, with SRI hashes). The
> vendored Bootstrap files are retained solely for the standalone error pages
> (`error/403.php`, `error/404.php`, `error/500.php`) which run outside the
> template system and cannot use CDN assets.

### Libraries Eliminated

- **Bootstrap Datepicker** — replaced by flatpickr (`dateFormat: 'Y-m-d'`) as part of #619.
  flatpickr has no jQuery dependency and survives the planned jQuery UI removal in #726.

### Libraries Already Self-Hosted (UserSpice-Managed)

Global dependencies managed by the UserSpice framework remain loaded from
their existing self-hosted locations in `users/`:

- jQuery
- Font Awesome (served from `users/fonts/css/`)

### Loading Pattern

Vendored libraries are loaded directly in pages that need them via
source-controlled `<script>` and `<link>` tags using the `$us_url_root`
variable for the path:

```php
<script src="<?=$us_url_root?>usersc/js/datatables.min.js"></script>
```

No database lookup is performed. The `elan_*_cdn` columns in the `settings`
table are no longer referenced by the application.

### Version Pinning

Library versions are recorded in `package.json` so that Dependabot can issue
security alerts when CVEs affect the pinned versions. The vendored files are
committed alongside the version pin so the deployed asset always matches the
declared version.

## Consequences

### Positive

- **Source-controlled dependencies.** Every change to a vendored library
  appears in the Git history with a diff, a commit message, and a code review.
  Rollback is a `git revert` away.

- **Dependabot coverage.** Pinning versions in `package.json` lets Dependabot
  scan against the GitHub Advisory Database and open PRs (or alerts) when a
  vendored library has a known CVE.

- **Reduced CSP allowlist surface.** Removing CDN-hosted scripts allowed
  removing `https://code.jquery.com`, `https://cdn.datatables.net`, and
  `https://kit.fontawesome.com` from the CSP `script-src` allowlist (ADR-007).
  Bootstrap is loaded from `cdnjs.cloudflare.com` via the upstream Customizer
  template (SRI-hashed); `cdnjs.cloudflare.com` is in the CSP allowlist.

- **Code/data alignment.** The deployed code and the deployed assets move
  together. No more drift between an updated `header.php` and stale `settings`
  rows from a pre-deployment database snapshot.

- **No HTML-entity-encoding fragility.** Vendored files are referenced with
  plain `<script src="...">` tags written directly in PHP templates. No
  `html_entity_decode()` round-trip needed.

- **Faster initial render in some networks.** Files served from the
  application origin avoid a separate DNS resolution and TLS handshake to a
  CDN domain.

### Negative

- **Manual maintenance when Dependabot fires.** Vendored files must be
  manually updated when security advisories fire. Dependabot alerts on the
  `package.json` version pin, but the actual file replacement is a manual
  download/replace step (see Maintenance Workflow below).

- **No global CDN edge caching.** Files are served from the application
  origin (A2 Hosting) rather than a global CDN edge network. Cloudflare in
  front of the origin mitigates this for most users.

- **Larger Git repository.** Vendored minified library files are committed to
  the repository, modestly increasing clone size. Trade-off accepted in
  exchange for a coherent deployment artifact.

- **No runtime configurability.** Switching CDN providers or rolling back a
  bad library version now requires a code deploy rather than an admin panel
  change. This is the deliberate inverse of the ADR-006 design and reflects
  the maturity of the deployment process (Git-driven, not admin-panel-driven).

### Maintenance Workflow When Dependabot Alert Fires

1. Review the CVE in the Dependabot alert
2. Download the patched version of the library
3. Replace the vendored file in `usersc/js/` or `usersc/css/`
4. Bump the version in `package.json`
5. Commit — the diff documents what changed and why

## Alternatives Considered

### Continue Database-Stored CDN URLs (ADR-006 Status Quo)

Keep the existing 14 `elan_*_cdn` columns and HTML-entity-encoded tag storage.

**Rejected because:**

- No version control or code review on dependency changes
- No automated security advisory coverage
- Drift between deployed code and `settings` rows is hard to detect and
  reproduce locally
- Incompatible with the goal of a source-controlled deployment pipeline

### Build Pipeline with npm + Bundler (Webpack/Vite)

Install dependencies via npm and bundle with Webpack or Vite, serving a
fingerprinted bundle from the application.

**Rejected because:**

- Requires a build step on every deployment, adding infrastructure that the
  shared-hosting deployment model does not natively support
- The application has no JavaScript module system; introducing one purely
  for asset management is over-engineering
- The vendored-file approach achieves the same source-control and security
  benefits without a build pipeline

### Composer-Managed Frontend Assets

Use a Composer package (e.g., `oomphinc/composer-installers`) to install
frontend libraries into a vendored directory.

**Rejected because:**

- Frontend libraries on Packagist are inconsistently maintained
- Adds Composer-managed paths to the asset loading logic for marginal benefit
  over a manual download/replace
- The download/replace flow is infrequent enough (Dependabot alerts) that
  automation is not justified

## References

- **Issue:** #405 — Self-host frontend libraries
- **Follow-up Issue:** #618 — Bootstrap 5 migration (will remove remaining
  CDN domains)
- **Follow-up Issue:** #619 — Native date input replaces Bootstrap Datepicker
- **Superseded ADR:** [ADR-006](ADR-006-use-database-stored-cdn-urls-for-frontend-dependencies.md)
- **Related ADR:** [ADR-007](ADR-007-implement-content-security-policy-and-security-headers.md)
  (CSP allowlist updated alongside this change)
- **Vendored Files:** `usersc/js/`, `usersc/css/`
- **Version Pins:** `package.json`
- **Nygard ADR Format:**
  [https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions)
