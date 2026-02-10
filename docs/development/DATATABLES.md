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

**File**: `/app/cars/index.php` (List Cars)

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
    url: "../action/getDataTables.php",
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

**File**: `/app/cars/factory.php` (List Factory)

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
    url: "../action/getDataTables.php",
    dataSrc: "data"
  }
});
```

**Key Features**:

- Server-side processing (loads 25 rows at a time)
- 14 columns (year, month, batch, type, serial, engine, gearbox, color, etc.)
- AJAX-based registry link lookup (checks if chassis exists in registry)
- Custom rendering for "Registry Link" column

### Backend Data Provider

**File**: `/app/action/getDataTables.php`

**Purpose**: Server-side data provider for DataTables AJAX requests

**Tables Supported**:

- `cars` - Car registry data
- `factory` - Factory information data
- `findCarByChassis` - Lookup car by chassis number (for factory links)

## CDN Configuration

### Current CDN URLs (v2.11.0+)

**JavaScript CDN**:

```html
<script
  type="text/javascript"
  src="https://cdn.datatables.net/v/bs4/dt-1.10.23/fh-3.1.8/r-2.2.7/datatables.min.js"
></script>
```

**CSS CDN**:

```html
<link
  rel="stylesheet"
  type="text/css"
  href="https://cdn.datatables.net/v/bs4/dt-1.10.23/fh-3.1.8/r-2.2.7/datatables.min.css"
/>
```

### CDN URL Structure

DataTables CDN URLs follow this pattern:

```text
https://cdn.datatables.net/v/{styling}/{extensions}/datatables.min.{js|css}
```

**Components**:

- `{styling}`: Bootstrap version (we use `bs4` for Bootstrap 4)
- `{extensions}`: Slash-separated list of extensions with versions

**Extension Format**: `{code}-{version}`

| Extension Code | Full Name       | Example      |
| -------------- | --------------- | ------------ |
| `dt`           | DataTables Core | `dt-1.10.23` |
| `fh`           | FixedHeader     | `fh-3.1.8`   |
| `r`            | Responsive      | `r-2.2.7`    |

### Building CDN URLs

**Example**: To build a CDN URL with DataTables Core, FixedHeader, and
Responsive:

1. Start with base: `https://cdn.datatables.net/v/bs4/`
2. Add extensions: `dt-1.10.23/fh-3.1.8/r-2.2.7/`
3. Add file: `datatables.min.js`
4. Result:
   `https://cdn.datatables.net/v/bs4/dt-1.10.23/fh-3.1.8/r-2.2.7/datatables.min.js`

**Official CDN Builder**: <https://datatables.net/download/>

### Updating CDN Configuration

CDN URLs are stored in the `settings` table and can be updated via:

1. **Admin Panel** (Recommended):

   - Navigate to Admin Panel → System Settings
   - Find "DataTables JavaScript CDN" setting
   - Update URL and save

2. **FIX Script** (For systematic changes):
   - Create FIX script following template pattern
   - Update both `elan_datatables_js_cdn` and `elan_datatables_css_cdn` fields
   - Use `htmlspecialchars()` when storing URLs
   - Use `html_entity_decode()` when rendering URLs

**Example FIX Script Code**:

```php
// Update JavaScript CDN
$newJsCdn = '<script type="text/javascript" src="https://cdn.datatables.net/v/bs4/dt-1.10.23/fh-3.1.8/r-2.2.7/datatables.min.js"></script>';

$db->update('settings', 1, [
    'elan_datatables_js_cdn' => htmlspecialchars($newJsCdn)
]);

// Update CSS CDN
$newCssCdn = '<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/v/bs4/dt-1.10.23/fh-3.1.8/r-2.2.7/datatables.min.css" />';

$db->update('settings', 1, [
    'elan_datatables_css_cdn' => htmlspecialchars($newCssCdn)
]);
```

## Configuration Best Practices

### Extension Selection

**Only include extensions you actually use**:

1. Audit codebase for actual DataTables configuration
2. Search for extension-specific options (e.g., `fixedHeader: true`,
   `responsive: true`)
3. Remove unused extensions from CDN URLs
4. Test thoroughly after changes

**Analysis Commands**:

```bash
# Check for FixedHeader usage
grep -r "fixedHeader:\s*true" app/cars/

# Check for Responsive usage
grep -r "responsive:\s*true" app/cars/

# Check for unused extensions
grep -r "rowGroup\|scroller\|searchBuilder\|searchPanes" app/cars/
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
3. Update CDN URLs via FIX script
4. Clear browser caches (users may need to hard refresh)
5. Monitor for JavaScript console errors

## Troubleshooting

### Common Issues

**Issue**: Blank page or JavaScript errors after CDN change

**Solution**:

- Check browser console for specific error messages
- Verify CDN URLs are correctly formatted (no typos)
- Ensure all extension dependencies are met (e.g., SearchPanes requires Select)
- Clear browser cache and hard refresh (Ctrl+Shift+R)

**Issue**: "Processing..." indicator never disappears

**Solution**:

- Check `/app/action/getDataTables.php` for PHP errors
- Verify CSRF token is being passed correctly
- Check database connection and query performance
- Review server error logs

**Issue**: Table columns not rendering properly

**Solution**:

- Verify column count in HTML matches JavaScript configuration
- Check for responsive breakpoints hiding columns on mobile
- Ensure CSS CDN URL matches JavaScript CDN URL (same extensions)

### Testing After Changes

**Manual Testing Checklist**:

1. Navigate to List Cars page (`/app/cars/index.php`)
2. Verify table loads without JavaScript errors
3. Test search functionality (type in search box)
4. Test sorting (click column headers)
5. Test pagination (navigate between pages)
6. Test responsive layout (resize browser window)
7. Repeat for Factory Information page (`/app/cars/factory.php`)

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

Tests the `findCarByChassis` endpoint logic in `/app/action/getDataTables.php`:

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
- Response format (ApiResponse Pattern A)
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
  await page.goto('/app/cars/factory.php');
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
