# DataTables Implementation Guide

## Overview

This document records the DataTables pages, extensions, and asset update
process used by this project.

## What is DataTables?

DataTables is a jQuery plugin that adds advanced interaction controls to HTML
tables. We use it throughout the Elan Registry for searchable, sortable,
paginated table views of cars and factory data.

**Official Documentation**: <https://datatables.net>

## Current Configuration

### Active Extensions (checked 2026-09-27)

As of v2.11.0, we use **only 3 DataTables extensions** for optimal performance:

| Extension       | Version | Purpose                     | Used on                       |
| --------------- | ------- | --------------------------- | ----------------------------- |
| DataTables Core | 3.1.2   | Base table functionality    | All DataTables views          |
| FixedHeader     | 5.1.1   | Sticky table headers        | List, factory, history, admin |
| Responsive      | 4.1.1   | Mobile-responsive tables    | Main and admin views          |

## Where DataTables is Used

### Car Listing Pages

**File**: `/app/owner/cars/index.php` (List Cars)

**Configuration**:

```javascript
const table = $("#cartable").DataTable({
  fixedHeader: true, // Sticky headers
  responsive: true, // Mobile responsive
  pageLength: 15, // 15 rows per page
  scrollX: true, // Horizontal scroll for wide tables
  processing: true, // Show "Processing..." indicator
  serverSide: true, // Server-side data loading
  serverMethod: "post",
  ajax: {
    url: "../api/cars/list.php",
    dataSrc: "data"
  }
});
```

**Key Features**:

- Server-side processing (loads 15 rows at a time via AJAX)
- Searchable across 11 columns (year, type, chassis, series, variant, etc.)
- Sortable by year, type, chassis
- Image carousel rendering in "Image" column
- Details button with link to car details page

### Factory Information Page

**File**: `/app/owner/cars/factory.php` (List Factory)

**Configuration**:

```javascript
const table = $("#cartable").DataTable({
  fixedHeader: true,
  responsive: true,
  pageLength: 25, // 25 rows per page (factory data)
  scrollX: true,
  processing: true,
  serverSide: true,
  serverMethod: "post",
  ajax: {
    url: "../api/cars/factory-list.php",
    dataSrc: "data"
  }
});
```

**Key Features**:

- Server-side processing (loads 25 rows at a time)
- 14 columns (year, month, batch, type, serial, engine, gearbox, color, etc.)
- AJAX-based registry link lookup (checks if chassis exists in registry)
- Custom rendering for "Registry Link" column

### Backend Data Providers

Two dedicated POST-only endpoints (v2.25.3+, issue #1036):

- **`app/api/cars/list.php`** — Car registry DataTable (`table=cars` branch, now implicit)
- **`app/api/cars/factory-list.php`** — Factory records DataTable (`table=factory` branch, now implicit)

> A third endpoint, `chassis-lookup.php`, previously resolved a chassis number to
> a car ID one row at a time — roughly 25 AJAX requests per page turn.
> `CarDataTablesService` now embeds `car_id` directly in the factory response via
> a subquery, and the endpoint was removed. `tests/playwright/e2e/factory-registry-link.spec.js`
> asserts that no request to it ever occurs; do not reintroduce the pattern.

## Asset Loading

### Self-Hosted DataTables JS (v2.17.0+)

DataTables assets are self-hosted. `package.json` pins the source packages and
`scripts/build.js` creates the deployed files under `usersc/js/` and
`usersc/css/`. These generated files are ignored by Git and rebuilt during
deployment under [ADR-018](adr/ADR-018-build-at-deploy-for-frontend-vendoring.md).

```text
usersc/js/datatables.min.js
```

Pages that need DataTables load it directly with a source-controlled
`<script>` tag:

```php
<script src="<?=$us_url_root?>usersc/js/datatables.min.js"></script>
```

The pages load the DataTables core, FixedHeader, and Responsive JavaScript
files separately. The CSS bundle at `usersc/css/datatables.min.css` is loaded
with:

```php
<link rel="stylesheet" href="<?=$us_url_root?>usersc/css/datatables.min.css">
```

### Bundle Contents

The build uses the pinned npm packages:

| Extension Code | Full Name       | Version    |
| -------------- | --------------- | ---------- |
| `dt`           | DataTables Core | `3.1.2`    |
| `fh`           | FixedHeader     | `5.1.1`    |
| `r`            | Responsive      | `4.1.1`    |

The styling target is `bs5` (Bootstrap 5).

### Updating the Vendored Bundle

When a security advisory or required feature drives an update:

1. Update the DataTables package versions in `package.json` and
   `package-lock.json`.
2. Run `npm ci` and `npm run build`.
3. Review the generated output locally. Do not commit output under `usersc/js/`
   or `usersc/css/`; deployment regenerates it.
4. Test the List Cars and Factory Information pages.

The build uses Bootstrap 5 integration packages. See
[ADR-017](adr/ADR-017-automate-frontend-vendoring-via-npm-build-pipeline.md)
for the asset selection and build details.

## Configuration Best Practices

### Extension Selection

**Only include extensions you actually use**:

1. Audit codebase for actual DataTables configuration
2. Search for extension-specific options (e.g., `fixedHeader: true`,
   `responsive: true`)
3. Remove unused extensions when regenerating the vendored bundle
4. Test thoroughly after changes

**Analysis Commands**:

```bash
# Check for FixedHeader usage
grep -r "fixedHeader:\s*true" app/owner/cars/

# Check for Responsive usage
grep -r "responsive:\s*true" app/owner/cars/

# Check for unused extensions
grep -r "rowGroup\|scroller\|searchBuilder\|searchPanes" app/owner/cars/
```

### Server-Side vs Client-Side Processing

**We use server-side processing** for all tables because:

- Large datasets (1000+ cars in registry)
- Better performance (only loads visible page of data)
- Reduced memory usage on client browsers

**Important**: DataTables extensions can require additional server-side
parameters and backend query logic. SearchPanes and SearchBuilder support
server-side processing, but the server must handle their filtering requests.
Before adding an extension, check its current documentation and update the
endpoint and tests as required.

### Version Management

**Current versions are stable and battle-tested**:

- DataTables Core: 3.1.2
- FixedHeader: 5.1.1
- Responsive: 4.1.1

**When to upgrade**:

- Security vulnerabilities discovered
- Critical bug fixes needed
- New features required that aren't in current version

**Upgrade process**:

1. Test on development/staging environment first
2. Review DataTables release notes for breaking changes
3. Bump the npm package versions, update `package-lock.json`, and rebuild the
   assets (see "Updating the Vendored Bundle" above)
4. Clear browser caches (users may need to hard refresh)
5. Monitor for JavaScript console errors

## Troubleshooting

### Common Issues

**Issue**: Blank page or JavaScript errors after a bundle update

**Solution**:

- Check browser console for specific error messages
- Run `npm run build` again to regenerate the local assets
- Ensure all extension dependencies are met (e.g., SearchPanes requires Select)
- Clear browser cache and hard refresh (Ctrl+Shift+R)

**Issue**: "Processing..." indicator never disappears

**Solution**:

- Check `/app/api/cars/list.php` or `/app/api/cars/factory-list.php` for PHP errors
- Verify the browser console for a 429 (Too many requests) error — if present, reduce request frequency or wait for the rate-limit window to reset
- Check database connection and query performance
- Review server error logs

**Issue**: Table columns not rendering properly

**Solution**:

- Verify column count in HTML matches JavaScript configuration
- Check for responsive breakpoints hiding columns on mobile
- Run `npm run build` after you change package versions so JavaScript and CSS
  use the same pinned package set

### Testing After Changes

**Manual Testing Checklist**:

1. Navigate to List Cars page (`/app/owner/cars/index.php`)
2. Verify table loads without JavaScript errors
3. Test search functionality (type in search box)
4. Test sorting (click column headers)
5. Test pagination (navigate between pages)
6. Test responsive layout (resize browser window)
7. Repeat for Factory Information page (`/app/owner/cars/factory.php`)

**Automated Testing**:

```bash
# Run the Playwright suite
npm run playwright:test
```

## Testing DataTables Implementations

This section documents testing strategies for DataTables-based features, with
examples from the Registry Link feature on the factory page.

### Unit Tests

Unit tests for DataTables endpoints validate logic without database dependencies.

**Example**: `tests/unit/cars/services/CarDataTablesServiceTest.php`

Covers column whitelisting, ordering, and the search clause built by
`CarDataTablesService` — all without a database.

**Coverage Areas**:

- Input validation (missing/empty parameters)
- SQL injection prevention (prepared statements)
- Response format (ApiResponse: `{success, message, ...data}`)
- Error handling

**Run unit tests**:

```bash
vendor/bin/phpunit tests/unit/cars/services/CarDataTablesServiceTest.php
```

### Integration Tests

Integration tests validate database interactions with real data.

**Example**: `tests/integration/FactoryRegistryLinkIntegrationTest.php`

Verifies the `car_id` subquery against a real database:

- `testFactoryRowContainsCarIdWhenChassisMatches()`
- `testFactoryRowCarIdIsNullWhenNoChassisMatch()`
- `testFactoryDataTablesResponseIncludesCarIdField()`
**Coverage Areas**:

- Real database queries
- Data type correctness (integer car IDs)
- Special character handling
- Performance characteristics
- Concurrent query handling

**Run integration tests** (requires database):

```bash
vendor/bin/phpunit tests/integration/FactoryRegistryLinkIntegrationTest.php
```

### End-to-End Tests

E2E tests validate complete user workflows in a real browser.

**Example**: `/tests/playwright/e2e/factory-registry-link.spec.js`

Tests the Registry Link feature on the factory page:

```javascript
test('should load factory page without errors', async ({ page }) => {
  // Navigate to Factory page
  await page.goto('/app/owner/cars/factory.php');
  await page.waitForLoadState('domcontentloaded');

  // Verify table renders
  const table = page.locator('#cartable');
  await expect(table).toBeVisible();

  // Check for console errors
  const errors = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      errors.push(msg.text());
    }
  });
});
```

**Coverage Areas**:

- Page rendering and layout
- AJAX endpoint calls (network monitoring)
- User interactions (pagination, sorting)
- Real browser JavaScript errors
- Performance timing

**Run E2E tests**:

```bash
npm run playwright:test tests/playwright/e2e/factory-registry-link.spec.js
```

### Testing Best Practices for DataTables

1. **Separate concerns**: Use unit tests for endpoint logic, integration tests for
   database queries, and E2E tests for user workflows.

2. **Test edge cases**: Empty parameters, special characters, missing data,
   pagination boundaries.

3. **Monitor performance**: E2E tests can log load times for observations (not hard
   requirements).

4. **Validate AJAX calls**: Use Playwright's network interception to verify correct
   endpoint paths and parameters are used (prevents issues like #581).

5. **Test pagination**: Verify features work across multiple pages.

6. **Check for errors**: Monitor browser console and HTTP responses for errors that
   might not be visible to users.

### Test Pyramid Strategy

Recommended test distribution for DataTables features:

```text
      /\
     /  \ E2E Tests (few, slower)
    /____\
   /      \
  /        \ Integration Tests (some, medium)
 /  ________\
/            \
Unit Tests (many, fast)
```

- **Unit Tests**: Quick feedback, test logic in isolation
- **Integration Tests**: Validate database interactions
- **E2E Tests**: Catch real-world issues users experience

## References

- **Official Documentation**: <https://datatables.net>
- **Server-Side Processing**: <https://datatables.net/manual/server-side>
- **SearchPanes server-side processing**: <https://datatables.net/manual/server/nodejs/searchpanes>
- **SearchBuilder server-side processing**: <https://datatables.net/extensions/searchbuilder/serverside>
- **Extensions Reference**: <https://datatables.net/extensions/>
- **Package pins and build output**: `package.json`, `package-lock.json`,
  `scripts/build.js`
- **Build-at-deploy decision**: [ADR-018](adr/ADR-018-build-at-deploy-for-frontend-vendoring.md)

The download-builder workflow and FIX Script #19 references describe the old
CDN-based asset process. They do not apply to the current npm build.

## Version History

| Version | Date    | Changes                                              |
| ------- | ------- | ---------------------------------------------------- |
| v2.11.0 | TBD     | Removed 5 unused extensions (62.5% reduction)        |
| v2.8.1  | 2025-01 | Initial documented configuration (8 extensions)      |

## See Also

- [ARCHITECTURE.md](https://github.com/elan-registry/registry/wiki/Elan-Registry-Architecture-and-Database-Design) - Overall application architecture
- [CODING_STANDARDS.md](CODING_STANDARDS.md) - Code quality requirements
- [Release Notes v2.11.0](https://github.com/elan-registry/registry/releases/tag/v2.11.0) - v2.11.0 release notes
  including DataTables optimization
- [FIX_SCRIPTS.md](FIX_SCRIPTS.md) - FIX script creation guidelines
