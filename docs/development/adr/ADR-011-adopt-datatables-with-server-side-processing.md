# ADR-011: Adopt DataTables with Server-Side Processing

## Status

**In Review** (retroactive)

## Date

Retroactive -- documented 2026-02-25

## Context

The Lotus Elan Registry needs to present two large, structured datasets to authenticated users:

- **Cars table**: All registered vehicles joined with denormalized owner data (first name, city, state, country per ADR-002). 1,000+ records. Each row renders a
  thumbnail image carousel and a "Details" button.
- **Factory build records table**: Lotus factory production data from the `elan_factory_info` table with 13 columns (year, month, batch, type, serial, suffix,
  engine letter, engine number, gearbox, color, build date, note, and a computed "Registry Link" column requiring async lookup per row).

Both datasets require search across multiple text columns, sorting by any column, and pagination. The application runs on shared hosting with MySQL 8.0+,
PHP/UserSpice stack, jQuery 3.6.0 already loaded globally, and a small but engaged user community.

### Problem Statement

The predecessor endpoint (`getList.php`) had SQL injection vulnerabilities and lacked consistent CSRF protection. The replacement needed to achieve:

1. **Security parity**: prepared statements, CSRF validation on AJAX endpoints, typed exception handling
2. **Scalable data transfer**: only current page of rows returned (not all 1,000+ records)
3. **Familiar UI patterns**: familiar table controls (search, sort, pagination) that users expect
4. **Testable business logic**: data query logic extractable from controller code
5. **User experience**: responsive tables with in-browser interactivity (search-as-you-type, column sorting)

Predecessor vulnerabilities and lack of structure made this a critical architectural decision.

## Decision

Adopt **DataTables 1.10.x** (jQuery plugin) in **server-side processing mode** (`serverSide: true`).

### Backend Architecture

**Three dedicated POST-only endpoints** (v2.25.3+, issue #1036):

- `/app/api/cars/list.php` — car registry DataTable
- `/app/api/cars/factory-list.php` — factory records DataTable
- `/app/api/cars/chassis-lookup.php` — chassis-to-car-ID lookup for registry links

Each endpoint:

- Enforces HTTP method via `$method` server global (POST only)
- Validates CSRF token via `Token::check()` before any data access
- `list.php` and `factory-list.php` delegate data logic to `CarDataTablesService` (extracted from `Car.php` in v2.15.0, issue #463);
  `chassis-lookup.php` executes its SQL query inline
- Returns standard DataTables JSON format: `{draw, recordsTotal, recordsFiltered, data[]}` (list/factory-list) or `{success, message, car_id}` (chassis-lookup)
- Uses `JSON_THROW_ON_ERROR` on all `json_encode()` calls

**Service class**: `usersc/classes/Car/CarDataTablesService.php`

- `declare(strict_types=1)`; full PHP 8+ type declarations on all methods
- Namespace: `ElanRegistry\Car`
- Private whitelist constants: `VALID_TABLES`maps request strings to MySQL table names;`ALLOWED_COLUMNS` defines sortable/searchable columns per table
- Invalid columns/tables silently skipped (fail-safe behavior)
- Prepared statements for all user-supplied values; `sprintf()` only for structural SQL elements
- Sort direction strict ternary: only `'DESC'`or`'ASC'`accepted (others become`'ASC'`)
- Three-query pattern: COUNT(*) for total, COUNT(*) for filtered, SELECT with LIMIT/OFFSET for data

```php
// Example: $service->getCars($draw, $start, $length, $search, $orderColumn, $orderDir)
// Returns: ['draw' => $draw, 'recordsTotal' => 1024, 'recordsFiltered' => 45, 'data' => [...]]
```

### Frontend: Cars Listing

**File**: `/app/cars/index.php`

Configuration:

- `serverSide: true`, `serverMethod: 'post'`, `processing: true`
- CSRF token via `Token::generate()` injected as JavaScript constant
- 13 columns; two non-searchable (id rendered as Details button, image carousel via helper)
- Default sort: year ASC, type ASC, chassis ASC
- Page length: 15 rows
- Extensions: FixedHeader, Responsive (plus Core)

```javascript
const csrf = '<?= Token::generate() ?>';
$('#cartable').DataTable({
    serverSide: true,
    serverMethod: 'post',
    ajax: {
        url: '/app/api/cars/list.php',
        data: function(d) { d.csrf = csrf; }
    },
    columns: [
        { data: 'id', searchable: false, sortable: true, render: detailsButtonRenderer },
        { data: 'year', searchable: true, sortable: true },
        // ... 11 more columns
    ],
    columnDefs: [
        { targets: 0, render: detailsButtonRenderer },
        { targets: 2, render: imageThumbnailRenderer }
    ]
});
```

### Frontend: Factory Build Records

**File**: `/app/cars/factory.php`

Configuration:

- Same server-side pattern as cars listing (no `table` parameter — endpoint is implicit)
- 14 columns; 14th column ("Registry Link") client-rendered via async `checkRegistryLinks()` helper
- Page length: 25 rows
- `checkRegistryLinks()` fires one POST per visible row to `/app/api/cars/chassis-lookup.php`, checking if each factory record has a match in the registry

```javascript
// Factory page fires N+1 requests for registry link population
// Each POST: { chassis: 'ABC123', csrf: token }
// Potential optimization: batch into single POST with chassis array
```

### CDN Management

> **Asset loading superseded by [ADR-015](ADR-015-self-host-frontend-libraries.md):**
> DataTables JS and CSS are now vendored at `usersc/js/datatables.min.js` and
> `usersc/css/datatables.min.css`. The `elan_datatables_js_cdn` and
> `elan_datatables_css_cdn` DB settings have been removed. The rest of this ADR
> (server-side processing, configuration, extensions) remains current.

~~Frontend library assets (DataTables JS/CSS) loaded via database settings per ADR-006~~ (removed in #405 — see ADR-015).

### Extension Rationalization

Only 3 extensions active:

- **Core** - base functionality
- **FixedHeader** - sticky column headers during scroll
- **Responsive** - mobile-friendly collapse behavior

Reduced from 8 extensions in v2.11.0 after SearchPanes and SearchBuilder prototyping (issue #168) proved incompatible with server-side processing. Those
extensions require client-side data access, incompatible with server-side mode.

## Consequences

### Positive

- **Minimal per-request data transfer.** Only the current page of rows is transferred (typically 15–25 KB). Avoiding 1,000+ record JSON transfers dramatically
  improves page load and browser memory usage.

- **Familiar library with strong community support.** DataTables 1.10.x is well-documented, has comprehensive Bootstrap 4 integration, and is used by millions
  of sites. Users have high familiarity with its UI patterns.

- **Security improvement over predecessor.** Prepared statements eliminate SQL injection. CSRF token validation via `Token::check()` prevents request forgery.
  Typed exception handling (ADR-004) standardizes error responses.

- **Testable service layer.** `CarDataTablesService` has no static globals or framework coupling, enabling unit test coverage of data retrieval logic
  independent of the HTTP layer.

- **Runtime CDN flexibility.** Library URLs (and versions) are configurable via admin panel per ADR-006, enabling quick provider switches if a CDN experiences
  downtime.

- **Lean extension footprint.** Only 3 extensions cover all user-facing requirements (search, sort, pagination, sticky headers, mobile responsiveness). Avoids
  the complexity and incompatibility of SearchPanes/SearchBuilder with server-side mode.

### Negative

- **jQuery dependency maintained.** DataTables is a jQuery plugin. This blocks migration away from jQuery on table-heavy pages (cars, factory). jQuery is in LTS
  status but represents technical debt for eventual modernization.

- **DataTables version uncertainty in production.** v1.10.23 (the exact production version) predates the 2.x release. Documentation and changelogs sometimes
  conflate 1.10.x behavior with 2.x behavior, making version-specific bug tracking harder.

- **No Subresource Integrity on DataTables CDN.** The bundled CDN URL format (e.g., `//cdn.datatables.net/1.10.23/js/jquery.dataTables.min.js`) does not support
  per-resource SRI hashing. The SRI concept applies only to the bundled script as a whole, not to modules within it.

- **N+1 AJAX requests on factory page.** The factory listing fires one POST per visible row to `/app/api/cars/chassis-lookup.php`
  to populate the "Registry Link" column. With
  25 rows per page, this results in 25 concurrent POSTs per page draw. Under heavy load, this could overwhelm the server or strain the browser's connection
  pool.

- **Inconsistent logic organization.** ~~`findCarByChassis` lookup was handled inline in the `getDataTables.php` endpoint, not delegated to `CarDataTablesService`.~~
  *Resolved in v2.25.3 (issue #1036): `chassis-lookup.php` is now a dedicated endpoint.*

- **Inline DataTables configuration not externalized.** DataTables initialization is hardcoded in page-level `<script>` blocks, not in external JavaScript
  files. This makes configuration harder to reuse and test.

- **SELECT * returns all columns including sensitive owner data.** The service returns all columns from the cars table, including owner email addresses and
  geographic coordinates (city, state, country). In JSON responses, these are included even if not rendered in the UI. This is a minor exposure but violates the
  principle of least privilege.

### Risks

| Risk | Likelihood | Impact | Mitigation |
| --- | --- | --- | --- |
| CVE-2021-23445 (XSS vulnerability) active if production version is 1.10.23 | Medium | High | Verify actual production CDN URL; check FIX #17 execution record; update seed data if needed; monitor DataTables security advisories |
| N+1 registry link requests degrade factory page under concurrent load | Low | Medium | Batch `findCarByChassis` lookups into single POST with array of chassis; implement request debouncing/coalescence |
| DataTables CDN outage breaks cars and factory listing pages | Low | High | CDN URL is switchable via admin panel; consider local fallback per ADR-006 hybrid approach; monitor uptime |
| SELECT * exposes sensitive owner data (email, coordinates) in JSON | Low | Medium | Replace SELECT * with explicit column list in CarDataTablesService; filter sensitive columns before JSON output |
| jQuery LTS end-of-life creates long-term maintenance pressure | Medium | Low-Medium | Document as technical debt for v3.0 modernization initiative; consider headless grid alternative (TanStack Table, AG Grid) for future migration |
| `findCarByChassis`pattern inconsistency with`CarDataTablesService` | Low | Low | Move lookup to dedicated`CarLookupService`or incorporate into`CarDataTablesService` as internal method |
| Admin misconfigures CDN URL breaking listing pages | Low | High | Add server-side URL validation in admin settings save path (check for well-formed HTML tag); test in staging before production |

## Alternatives Considered

### Client-Side DataTables (no serverSide: true)

Load all 1,000+ car records into the browser and let DataTables paginate/search client-side.

**Rejected because:**

- Full dataset transfer: 1,000+ records with owner names, cities, states, countries, and image paths = 500+ KB JSON response (uncompressed). Initial page load
  would be slow; browser memory overhead would be high.
- Dataset growth: The registry is actively growing; 1,000+ records is current size but will exceed 2,000+ over time. Client-side paging becomes impractical with
  larger datasets.
- Mobile performance: Distributing the full dataset to mobile clients wastes bandwidth and strains memory-constrained devices.
- No search-time filtering: Search would be local JavaScript full-table scan, not indexed database queries. As the dataset grows, search performance degrades.
- Shared hosting bandwidth: Pushing 500+ KB per page load increases bandwidth consumption on shared hosting, directly impacting hosting costs.

### AG Grid (Community Edition)

High-performance JavaScript data grid; no jQuery dependency; enterprise features available.

**Rejected because:**

- **Requires removing jQuery from table pages**, which would be a multi-page refactor. While desirable for long-term modernization, it's out of scope for this
  decision.
- **Custom Bootstrap 4 theme needed.** AG Grid does not provide out-of-box Bootstrap 4 styling; significant CSS customization would be required.
- **Different server-side API.** AG Grid's pagination and sort parameter names differ from DataTables. The backend would require rewriting to produce AG
  Grid-compatible JSON.
- **Licensing uncertainty.** AG Grid Community is free, but advanced features (enterprise filters, pivoting) have commercial licensing. The project would need
  to commit to remaining in Community tier.
- **No existing build pipeline.** The application has no JavaScript module system; adopting AG Grid would require understanding its ES6 module structure.
- **Legitimate candidate for future migration** if the project commits to removing jQuery and establishing a JavaScript build pipeline.

### Tabulator

Modern, dependency-free table library with advanced features.

**Rejected because:**

- **No functional advantage over DataTables** for the current use case. Both support server-side processing, search, sort, pagination, and responsive behavior.
- **Requires rewriting the backend API.** Tabulator's parameter names (page, pageSize, sorters) differ from DataTables. Endpoint changes would be needed for no
  gain.
- **Stronger candidate if migration away from jQuery/Bootstrap 4 is already underway.** If the project decides to modernize the frontend, Tabulator's lack of
  jQuery dependency is an asset. For the current stack, switching is unnecessary churn.

### TanStack Table (formerly React Table)

Headless, framework-agnostic table library designed for React/Vue/Solid.

**Rejected because:**

- **Requires adopting a JavaScript component framework** (React, Vue, Solid). The application uses page-based PHP templates with jQuery for interactivity;
  adding a component framework is architectural overhaul, not a table library decision.
- **Introduces build pipeline dependency.** TanStack Table is designed for npm-based projects with ES6 modules; using it in a PHP/jQuery context would require
  significant scaffolding.
- **Not appropriate for this architecture.** TanStack Table solves the "state management in component-heavy UIs" problem; the Elan Registry does not have
  component-heavy UIs.

### Custom PHP Pagination with Plain HTML Tables

Server-rendered HTML tables with full page reload on sort/pagination.

**Rejected because:**

- **Significant UX regression.** Users expect in-browser search-as-you-type and column sorting without page reloads. Returning to full-page navigation would
  feel archaic.
- **Loss of current page state.** Sorting or paginating would reset the user's search term, scroll position, and open details. User frustration would be high.
- **No responsive design.** DataTables' Responsive extension collapses columns on mobile; plain HTML tables require separate mobile layouts or become unreadable
  on small screens.

### Pure-PHP Service with Fetch API and Vanilla JavaScript

Custom backend endpoint returning DataTables-compatible JSON, but frontend uses vanilla Fetch API instead of jQuery DataTables plugin.

**Rejected because:**

- **Re-implements pagination controls, sort state, search debouncing, loading indicators, responsive behavior from scratch.** DataTables provides these; vanilla
  JavaScript approach would require hundreds of lines of custom code.
- **Maintenance burden exceeds library dependency cost.** The gain (no jQuery on tables) is offset by maintaining custom table code indefinitely.
- **Fewer eyes on the code.** DataTables is battle-tested by millions of sites; custom table code has only the Elan Registry team maintaining it.

## References

- **DataTables Library**: [https://datatables.net](https://datatables.net)
- **DataTables Server-Side Processing**: [https://datatables.net/manual/server-side](https://datatables.net/manual/server-side)
- **Backend Endpoints**: [app/api/cars/list.php](../../app/api/cars/list.php), [app/api/cars/factory-list.php](../../app/api/cars/factory-list.php), [app/api/cars/chassis-lookup.php](../../app/api/cars/chassis-lookup.php)
- **Service Class**: [usersc/classes/Car/CarDataTablesService.php](../../usersc/classes/Car/CarDataTablesService.php)
- **Cars Listing Page**: [app/cars/index.php](../../app/cars/index.php)
- **Factory Listing Page**: [app/cars/factory.php](../../app/cars/factory.php)
- **DataTables Documentation**: [docs/development/DATATABLES.md](../development/DATATABLES.md)
- **ADR-001 (UserSpice)**: [docs/adr/ADR-001-userspice-authentication-framework.md](ADR-001-userspice-authentication-framework.md)
- **ADR-002 (Denormalized cars)**: [docs/adr/ADR-002-denormalized-cars-table-cached-owner-data.md](ADR-002-denormalized-cars-table-cached-owner-data.md)
- **ADR-004 (Pattern A API)**:
  [docs/adr/ADR-004-standardize-api-architecture-pattern-a-responses.md](ADR-004-standardize-api-architecture-pattern-a-responses.md)
- **ADR-006 (CDN management)**:
  [docs/adr/ADR-006-use-database-stored-cdn-urls-for-frontend-dependencies.md](ADR-006-use-database-stored-cdn-urls-for-frontend-dependencies.md)
- **Error Handling Guide**: [docs/development/ERROR_HANDLING.md](../development/ERROR_HANDLING.md)
- **CSS & Assets Guide**: [docs/development/CSS_AND_ASSETS.md](../development/CSS_AND_ASSETS.md)
- **GitHub Issue #168**: SearchPanes/SearchBuilder investigation (incompatible with server-side mode)
- **GitHub Issue #463**: Extract CarDataTablesService from Car.php
- **CVE-2021-23445**: DataTables XSS vulnerability
- **OWASP Top 10**: [https://owasp.org/www-project-top-ten/](https://owasp.org/www-project-top-ten/)
- **Nygard ADR Format**:
  [https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions)
