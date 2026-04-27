# ADR-006: Use Database-Stored CDN URLs for Frontend Dependencies

**Status: Superseded by [ADR-015](ADR-015-self-host-frontend-libraries.md)**

## Status

**In Review** (retroactive)

## Date

Retroactive -- documented 2026-02-25

## Context

The application depends on several third-party frontend libraries (jQuery, Bootstrap, DataTables, etc.) delivered via CDN. These URLs need to be managed across
environments, updated when library versions change, and protected against supply-chain attacks via SRI hashes.

### Problem Statement

A PHP application on shared hosting needs:

1. Frontend dependency URLs manageable without code deployment
2. SRI (Subresource Integrity) hashes stored alongside URLs for tamper detection
3. Ability to quickly switch CDN providers if one experiences an outage
4. Admin-accessible configuration (no SSH/code access required)
5. Compatibility with the UserSpice `$settings` object pattern (ADR-001)

## Decision

Store complete HTML `<script>`and`<link>`tags (including`src`/`href`, `integrity`, and `crossorigin`attributes) as HTML-entity-encoded strings in
the`settings`database table. Tags are decoded at render time via`html_entity_decode()`.

### Database Schema

14 `mediumtext`columns in the`settings`table (defined in`database/1-schema.sql`, lines 216-228, 240):

| Column | Library | Version | CDN Provider |
| --- | --- | --- | --- |
| `elan_jquery_cdn` | jQuery | 3.6.0 | cdn.jsdelivr.net |
| `elan_jquery_ui_cdn` | jQuery UI | 1.12.1 | code.jquery.com |
| `elan_bootstrap_css_cdn` | Bootstrap CSS | 4.6.2 | cdn.jsdelivr.net |
| `elan_bootstrap_js_cdn` | Bootstrap JS | 4.6.2 | cdn.jsdelivr.net |
| `elan_popper_cdn` | Popper.js | 1.16.1 | cdn.jsdelivr.net |
| `elan_bootswatch_cdn` | Bootswatch Simplex | 4.6.1 | cdn.jsdelivr.net |
| `elan_fontawesome_cdn` | Font Awesome | Kit | kit.fontawesome.com |
| `elan_datatables_js_cdn` | DataTables JS | 1.11.3+ | cdn.datatables.net |
| `elan_datatables_css_cdn` | DataTables CSS | 1.11.3+ | cdn.datatables.net |
| `elan_datepicker_js_cdn` | Bootstrap Datepicker JS | 1.9.0 | cdnjs.cloudflare.com |
| `elan_datepicker_css_cdn` | Bootstrap Datepicker CSS | 1.9.0 | cdnjs.cloudflare.com |
| `elan_dropzone_js_cdn` | Dropzone JS | 5.7.6 | cdn.jsdelivr.net |
| `elan_dropzone_css_cdn` | Dropzone CSS | 5.7.6 | cdn.jsdelivr.net |
| `elan_chartjs_cdn` | Chart.js | 4.4.0 | cdn.jsdelivr.net |

### Storage Format

Values are stored as HTML-entity-encoded complete HTML tags. Example from `database/3-configuration.sql`:

```sql
elan_jquery_cdn = '&lt;script src=&quot;https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js&quot; integrity=&quot;sha384-vtXRMe3mGCbOeY7l30aIg8H9p3GdeSe4IFlP6G8JMa7o7lXvnz3GFKzPxzJdPfGK&quot; crossorigin=&quot;anonymous&quot;&gt;&lt;/script&gt;'
```

### Template Loading

**Global dependencies** loaded in `usersc/templates/ElanRegistry/header.php`:

```php
// Bootstrap CSS, Bootswatch theme, jQuery, Bootstrap JS, Popper.js, Font Awesome
echo html_entity_decode($settings->elan_bootstrap_css_cdn);
echo html_entity_decode($settings->elan_bootswatch_cdn);
echo html_entity_decode($settings->elan_jquery_cdn);
echo html_entity_decode($settings->elan_bootstrap_js_cdn);
echo html_entity_decode($settings->elan_popper_cdn);
echo html_entity_decode($settings->elan_fontawesome_cdn);
```

**Page-specific dependencies** loaded only where needed:

- `app/cars/index.php`, `app/cars/factory.php`, `app/cars/details.php` — DataTables JS/CSS
- `app/cars/edit.php` — jQuery UI, Dropzone JS/CSS, Datepicker JS/CSS
- `app/reports/statistics.php` — Chart.js (with hardcoded fallback if setting not present)

### SRI Protection

All CDN resources (except Font Awesome Kit) include `integrity`attributes with SHA-384 hashes and`crossorigin="anonymous"`. Storing complete HTML tags ensures
SRI hashes are never separated from their URLs.

### Admin Interface

`app/admin/includes/tab-settings.php` provides a web-based UI for managing all CDN URLs. Includes:

- Auto-creation of missing CDN columns via `processSettingsAutoCreation()`
- Field name whitelisting against SQL injection
- Textarea form fields for pasting complete HTML tags
- Descriptive labels and placeholder examples

### Update Mechanisms

Two approaches for updating CDN versions:

1. **FIX Scripts** (recommended for major updates): Dedicated PHP scripts (e.g., `FIX/17-Add-SRI-To-CDN-Resources.php`,
   `FIX/19-Add-Select-Extension-DataTables-CDN.php`, `FIX/23-Optimize-CDN-Resources.php`) with two-phase UI, progress logging, and audit trail in
   `fix_script_runs` table.

2. **Admin UI** (for minor changes): Direct editing via the admin settings panel.

### Initialization

Default CDN values are seeded in `database/3-configuration.sql`. The `processSettingsAutoCreation()` function in the admin panel auto-creates any missing
columns with sensible defaults, ensuring forward compatibility.

## Consequences

### Positive

- **Runtime flexibility.** CDN URLs can be changed instantly without code deployment, SSH access, or server restart. If a CDN experiences an outage, the admin
  can switch providers in seconds via the admin panel.

- **Complete tag storage prevents partial implementation.** Storing full `<script>`/`<link>`elements (not just URLs) ensures SRI hashes,`crossorigin`
  attributes, and correct tag structure are always present together. A URL-only approach risks administrators forgetting to include integrity hashes.

- **Consistent with UserSpice patterns.** The `$settings` object is the established mechanism for application configuration in UserSpice (ADR-001). CDN URLs
  follow the same pattern as other configurable values, requiring no additional infrastructure.

- **Admin-accessible without technical skills.** The admin settings panel allows URL changes without SSH access, Git knowledge, or code deployment. Appropriate
  for a single-admin, shared-hosting deployment model.

- **Page-specific lazy loading.** Only pages that need DataTables, Dropzone, or Chart.js load those libraries, reducing unnecessary HTTP requests and improving
  page load times for pages that don't use them.

- **Auditable updates via FIX scripts.** Major CDN version changes go through FIX scripts that log to `fix_script_runs`, creating a database-level audit trail
  of what changed and when.

- **SRI hashes prevent supply-chain attacks.** All CDN resources include Subresource Integrity hashes. If a CDN is compromised and serves modified files,
  browsers will reject the tampered resources.

### Negative

- **CDN configuration not version-controlled.** Database-stored values are not tracked in Git. Changes made via the admin panel have no code review, no diff
  history, and no easy rollback beyond database backups. A typo in a CDN URL can break the site without any commit to blame.

- **HTML-entity-encoding adds fragility.** Storing entity-encoded HTML tags (`&lt;script...&gt;`) and decoding with `html_entity_decode()` introduces a layer of
  encoding/decoding that can silently produce broken output if encoding is inconsistent. A URL + SRI hash stored as separate fields would be simpler to
  validate.

- **No local fallback for CDN outages.** If the CDN is unreachable (outage, DNS failure, geographic blocking), the page loads without critical libraries
  (jQuery, Bootstrap). There is no automatic fallback to local copies. The `bootswatchSimplex.min.css` local backup exists but is not actively used.

- **Database query required for every page load.** Every page request reads CDN URLs from the `settings`table. While the UserSpice framework already
  loads`$settings`for other purposes (so no additional query), the`mediumtext` columns add to the result set size.

- **Full HTML tags are harder to validate.** The admin panel accepts raw HTML tag input. There is no server-side validation that the submitted value is a
  well-formed `<script>`or`<link>` tag with valid SRI attributes. Malformed input silently breaks pages.

- **Seed data drifts from production.** `database/3-configuration.sql` contains initial CDN values (some at older versions like Bootstrap 4.5.3, jQuery 3.5.1)
  that no longer match production values (updated via FIX scripts to Bootstrap 4.6.2, jQuery 3.6.0). New installations seed outdated URLs that require running
  FIX scripts to update.

### Risks

| Risk | Likelihood | Impact | Mitigation |
| --- | --- | --- | --- |
| CDN outage makes site non-functional (no jQuery/Bootstrap) | Low | High | Admin can switch CDN provider via settings panel; consider adding local fallback copies; monitor CDN status |
| Typo in admin panel breaks all pages (malformed HTML tag) | Low | High | Test in staging before production; consider adding HTML tag validation in admin form; database backup before changes |
| SRI hash mismatch after CDN version bump blocks resource loading | Medium | High | Always verify hashes when updating; use `openssl dgst -sha384` to generate correct hashes; test in staging |
| Supply chain attack via compromised CDN | Very Low | Critical | SRI hashes detect file tampering; browser blocks modified files; restricted admin access prevents hash modification |
| Seed data drift causes outdated libraries on fresh install | Medium | Medium | Document FIX script run order for new installations; consider updating seed data when FIX scripts modify CDN values |
| Font Awesome Kit URL tied to specific account | Low | Medium | Kit is account-specific; document account credentials separately; consider self-hosted Font Awesome as alternative |

## Alternatives Considered

### Hardcoded CDN References in Templates

CDN `<script>`and`<link>` tags hardcoded directly in PHP template files (`header.php`, page files).

**Rejected because:**

- Every CDN update requires a code change, code review, merge, and deployment—excessive friction for a URL change.
- No runtime flexibility. If a CDN goes down, fixing it requires SSH access and a code deploy, not a quick admin panel change.
- Security tools (e.g., Fortify) flag hardcoded external domains in HTML as a security finding.
- Mixes configuration (which CDN, which version) with presentation logic (how to render the page).

### Config File-Based CDN References (.env or PHP config)

Store CDN URLs in a `.env` file or PHP configuration file, version-controlled in Git.

**Rejected because:**

- Requires code deployment to change any CDN URL, eliminating runtime flexibility.
- The application already uses SecureEnvPHP (ADR-005) for sensitive credentials; adding non-sensitive configuration to `.env` would conflate secret management
  with general configuration.
- Shared hosting does not support environment variable injection at the server level, so config files would need manual SFTP deployment—the same friction as
  code changes.
- Admin cannot make changes without developer involvement.

### Local Vendored Copies via npm/Composer

Install frontend dependencies locally via npm (`node_modules/`) or Composer, serve from the application's own server.

**Rejected because:**

- Requires a build pipeline (Node.js, npm) on the development machine and potentially on the server, adding infrastructure complexity inappropriate for a
  single-admin shared-hosting deployment.
- Serving libraries from the application server instead of a global CDN edge network reduces performance for geographically distributed users.
- Increases server bandwidth costs and storage requirements.
- The application has no JavaScript module system or build process; adding one solely for dependency management would be over-engineering.
- npm is installed for Playwright testing only, not for frontend asset management.

### Hybrid: Database URLs with Local Fallback

Store CDN URLs in the database (current approach) but also keep local copies and use JavaScript-based fallback detection.

**Not adopted (but recognized as a future improvement) because:**

- Adds complexity: local copies must be kept in sync with CDN versions, and JavaScript fallback detection adds client-side logic.
- The application has not experienced a CDN outage since deployment. The risk is acknowledged but has not warranted the additional maintenance burden.
- The `bootswatchSimplex.min.css` local backup file demonstrates the concept but was never integrated into the loading logic.
- Noted as a potential enhancement for ADR-006 amendment if CDN reliability becomes an issue.

### Separate URL and SRI Hash Fields

Store CDN URLs and SRI hashes as separate database columns (e.g., `elan_jquery_url`and`elan_jquery_hash`), construct HTML tags in a PHP helper function.

**Not adopted (but recognized as architecturally cleaner) because:**

- Would require migrating 14 existing columns to 28 columns (URL + hash for each), plus updating all template rendering code.
- The current full-tag approach has been stable since initial implementation and works reliably.
- A helper function approach would enable input validation (verify URL format, verify hash format) that the current approach lacks.
- Noted as a potential improvement if the admin interface is redesigned or if CDN management is expanded.

## References

- **Schema Definition**: [database/1-schema.sql](../../database/1-schema.sql) (lines 216-228, 240)
- **Initial Configuration**: [database/3-configuration.sql](../../database/3-configuration.sql) (lines 54-66, 83)
- **Template Loading**: [usersc/templates/ElanRegistry/header.php](../../usersc/templates/ElanRegistry/header.php)
- **Admin Interface**: [app/admin/includes/tab-settings.php](../../app/admin/includes/tab-settings.php)
- **FIX Script (SRI)**: [FIX/_ARCHIVE/17-Add-SRI-To-CDN-Resources.php](../../FIX/_ARCHIVE/17-Add-SRI-To-CDN-Resources.php)
- **FIX Script (DataTables)**: [FIX/_ARCHIVE/19-Add-Select-Extension-DataTables-CDN.php](../../FIX/_ARCHIVE/19-Add-Select-Extension-DataTables-CDN.php)
- **FIX Script (Optimization)**: [FIX/_ARCHIVE/23-Optimize-CDN-Resources.php](../../FIX/_ARCHIVE/23-Optimize-CDN-Resources.php)
- **CSS & Assets Guide**: [docs/development/CSS_AND_ASSETS.md](../development/CSS_AND_ASSETS.md)
- **Page Loading Flow**: [docs/development/PAGE_LOADING_FLOW.md](../development/PAGE_LOADING_FLOW.md)
- **UserSpice Integration**: ADR-001 covers UserSpice `$settings` object pattern
- **SRI Specification**: [MDN Subresource Integrity](https://developer.mozilla.org/en-US/docs/Web/Security/Subresource_Integrity)
- **Nygard ADR Format**:
  [https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions)
