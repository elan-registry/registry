# DataTables Implementation Guide

## Overview

This document provides comprehensive guidance on our DataTables implementation,
including which extensions we use, where they're used, and how to manage CDN
configuration.

## What is DataTables?

DataTables is a jQuery plugin that adds advanced interaction controls to HTML
tables. We use it throughout the Elan Registry for searchable, sortable,
paginated table views of cars and factory data.

**Official Documentation**: <https://datatables.net>

## Current Configuration

### Active Extensions (v2.11.0+)

As of v2.11.0, we use **only 3 DataTables extensions** for optimal performance:

| Extension           | Version    | Purpose                  | Usage      |
| ------------------- | ---------- | ------------------------ | ---------- |
| **DataTables Core** | dt-1.10.23 | Base table functionality | All tables |
| **FixedHeader**     | fh-3.1.8   | Sticky table headers     | All tables |
| **Responsive**      | r-2.2.7    | Mobile-responsive tables | All tables |

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

Three dedicated POST-only endpoints (v2.25.3+, issue #1036):

- **`app/api/cars/list.php`** — Car registry DataTable (`table=cars` branch, now implicit)
- **`app/api/cars/factory-list.php`** — Factory records DataTable (`table=factory` branch, now implicit)
- **`app/api/cars/chassis-lookup.php`** — Chassis-to-car-ID lookup for registry links; returns `{success, message, car_id}`

## Asset Loading

### Self-Hosted DataTables JS (v2.17.0+)

As of #405 (ADR-015), DataTables JavaScript is self-hosted in the repository
rather than loaded from a CDN. The bundle (DataTables Core + FixedHeader +
Responsive, BS4 styling) is committed at:

```text
usersc/js/datatables.min.js
```

Pages that need DataTables load it directly with a source-controlled
`<script>` tag:

```php
<script src="<?=$us_url_root?>usersc/js/datatables.min.js"></script>
```

The previous `elan_datatables_js_cdn` and `elan_datatables_css_cdn`
settings-table columns are no longer referenced. The matching CSS bundle is
vendored alongside the JS at `usersc/css/datatables.min.css` and loaded with:

```php
<link rel="stylesheet" href="<?=$us_url_root?>usersc/css/datatables.min.css">
```

### Bundle Contents

The vendored bundle was generated from the official DataTables download
builder with:

| Extension Code | Full Name       | Version    |
| -------------- | --------------- | ---------- |
| `dt`           | DataTables Core | `1.10.23`  |
| `fh`           | FixedHeader     | `3.1.8`    |
| `r`            | Responsive      | `2.2.7`    |

The styling target is `bs4` (Bootstrap 4).

### Updating the Vendored Bundle

When a security advisory or required feature drives an update:

1. Visit the official CDN/download builder: <https://datatables.net/download/>
2. Select Bootstrap 4 styling and the extensions listed above (or the new
   set), choose the desired versions
3. Download both the combined `datatables.min.js` and `datatables.min.css` files
4. Replace `usersc/js/datatables.min.js` and `usersc/css/datatables.min.css` with the new files
5. Bump the DataTables version pin in `package.json`
6. Commit — the diff documents what changed and why
7. Test the List Cars and Factory Information pages

This workflow is the standard maintenance flow established in ADR-015 for all
vendored frontend libraries.

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

**Important Limitation**: Some DataTables extensions (SearchPanes, SearchBuilder)
are **incompatible** with server-side processing without significant backend
work. They require ALL data to be loaded at once, which defeats the purpose of
server-side processing.

**Guideline**: Before adding any new DataTables extension, verify it supports
server-side processing or assess if the UX trade-offs are acceptable.

### Version Management

**Current versions are stable and battle-tested**:

- DataTables Core: 1.10.23
- FixedHeader: 3.1.8
- Responsive: 2.2.7

**When to upgrade**:

- Security vulnerabilities discovered
- Critical bug fixes needed
- New features required that aren't in current version

**Upgrade process**:

1. Test on development/staging environment first
2. Review DataTables release notes for breaking changes
3. Replace `usersc/js/datatables.min.js` with the new bundle and bump the
   version pin in `package.json` (see "Updating the Vendored Bundle" above)
4. Clear browser caches (users may need to hard refresh)
5. Monitor for JavaScript console errors

## Troubleshooting

### Common Issues

**Issue**: Blank page or JavaScript errors after a bundle update

**Solution**:

- Check browser console for specific error messages
- Verify `usersc/js/datatables.min.js` was replaced cleanly (no truncation)
- Ensure all extension dependencies are met (e.g., SearchPanes requires Select)
- Clear browser cache and hard refresh (Ctrl+Shift+R)

**Issue**: "Processing..." indicator never disappears

**Solution**:

- Check `/app/api/cars/list.php` or `/app/api/cars/factory-list.php` for PHP errors
- Verify CSRF token is being passed correctly
- Check database connection and query performance
- Review server error logs

**Issue**: Table columns not rendering properly

**Solution**:

- Verify column count in HTML matches JavaScript configuration
- Check for responsive breakpoints hiding columns on mobile
- Ensure `usersc/css/datatables.min.css` was replaced from the same download
  builder run as `usersc/js/datatables.min.js` (same extensions)

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
# Run Playwright UI tests
npm run playwright:functionality
npm run playwright:ui
```

## Testing DataTables Implementations

This section documents testing strategies for DataTables-based features, with
examples from the Registry Link feature on the factory page.

### Unit Tests

Unit tests for DataTables endpoints validate logic without database dependencies.

**Example**: `/tests/unit/api/GetDataTablesFindCarByChassisTest.php`

Tests the chassis lookup logic now in `/app/api/cars/chassis-lookup.php`:

```php
/**
 * Test SQL query uses prepared statement (prevents SQL injection)
 */
public function testSqlQueryUsesPreparedStatement(): void
{
    // Verify prepared statement with ? placeholder
    $this->assertStringContainsString(
        'SELECT id FROM cars WHERE chassis = ? LIMIT 1',
        $content
    );

    // Verify chassis is passed as bound parameter
    $this->assertStringContainsString(
        '[$chassis]',
        $content
    );
}
```

**Coverage Areas**:

- Input validation (missing/empty parameters)
- SQL injection prevention (prepared statements)
- Response format (ApiResponse: `{success, message, ...data}`)
- Error handling

**Run unit tests**:

```bash
vendor/bin/phpunit tests/unit/api/GetDataTablesFindCarByChassisTest.php
```

### Integration Tests

Integration tests validate database interactions with real data.

**Example**: `/tests/integration/FactoryRegistryLinkIntegrationTest.php`

Tests the complete `findCarByChassis` workflow:

```php
/**
 * Test findCarByChassis finds registered car by chassis number
 */
public function testFindCarByChassisWithMatchingCar(): void
{
    // Create test car with known chassis
    $this->createTestCar($this->testUserId, [
        'chassis' => $this->testChassis
    ]);

    // Query database directly
    $query = $this->db->query(
        "SELECT id FROM cars WHERE chassis = ? LIMIT 1",
        [$this->testChassis]
    );

    $this->assertTrue($query->count() > 0);
}
```

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
- **CDN Builder**: <https://datatables.net/download/>
- **Server-Side Processing**: <https://datatables.net/manual/server-side>
- **Extensions Reference**: <https://datatables.net/extensions/>
- **GitHub Issue #168**: SearchPanes/SearchBuilder investigation and removal
  decision
- **FIX Script #19**: DataTables CDN optimization implementation

## Version History

| Version | Date    | Changes                                              |
| ------- | ------- | ---------------------------------------------------- |
| v2.11.0 | TBD     | Removed 5 unused extensions (62.5% reduction)        |
| v2.8.1  | 2025-01 | Initial documented configuration (8 extensions)      |

## See Also

- [ARCHITECTURE.md](ARCHITECTURE.md) - Overall application architecture
- [CODING_STANDARDS.md](CODING_STANDARDS.md) - Code quality requirements
- [RELEASE_NOTES_V2.11.0.md](RELEASE_NOTES_V2.11.0.md) - v2.11.0 release notes
  including DataTables optimization
- [FIX_SCRIPTS.md](FIX_SCRIPTS.md) - FIX script creation guidelines
