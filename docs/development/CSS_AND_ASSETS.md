<!-- markdownlint-disable MD013 -->

# CSS and Frontend Assets Guide

This document provides comprehensive guidance for managing stylesheets and frontend assets in the Lotus Elan Registry application.

## CSS Architecture (v2.12.0+)

### File Structure

**Location:** `/usersc/templates/ElanRegistry/assets/css/`

```text
usersc/templates/ElanRegistry/assets/css/
├── consolidated.css          # Main source CSS (unminified, readable) ✅ USED
├── consolidated.min.css      # Production CSS (minified, loaded in production) ✅ USED
├── hamburgers.min.css        # Hamburger menu library (third-party, minified) ✅ USED
└── bootswatchSimplex.min.css # Bootswatch Simplex backup (NOT USED - loaded from CDN)
```

### CSS Consolidation (v2.12.0)

**Overview:**

- All registry-specific CSS is consolidated in `consolidated.css` and its minified variant
- Consolidation merged two files: original `ElanRegistry.css` + `style.css`
- Removed 40+ lines of duplicate CSS rules
- Removed unused utility classes (`.w-20`, `.w-30`, `.polaroid`)

**Removed Original Files:**

- ~~`usersc/templates/ElanRegistry.css`~~ (REMOVED - consolidated into consolidated.css)
- ~~`usersc/templates/ElanRegistry/assets/css/style.css`~~ (REMOVED - consolidated into consolidated.css)

### CSS Loading (header.php)

The template header loads CSS in this order:

```php
// 1. Bootstrap 4.6.2 CSS from CDN (minified, SRI protected)
echo html_entity_decode($settings->elan_bootstrap_css_cdn);

// 2. Bootswatch Simplex theme from CDN (NOT from local file)
// → Loaded from elan_bootswatch_cdn database setting
// → Current: https://cdn.jsdelivr.net/npm/bootswatch@4.6.1/dist/simplex/bootstrap.min.css
echo html_entity_decode($settings->elan_bootswatch_cdn);

// 3. jQuery from CDN (loaded early for DOM manipulation)
echo html_entity_decode($settings->elan_jquery_cdn);

// 4. Bootstrap JS from CDN
echo html_entity_decode($settings->elan_bootstrap_js_cdn);

// 5. Popper.js from CDN (required for Bootstrap dropdowns/modals)
echo html_entity_decode($settings->elan_popper_cdn);

// 6. Font Awesome from CDN
echo html_entity_decode($settings->elan_fontawesome_cdn);

// 7. Hamburger menu CSS (local, minified)
<link href=".../hamburgers.min.css" rel="stylesheet">

// 8. Consolidated registry CSS (local, minified)
<link href=".../consolidated.min.css" rel="stylesheet">
```

**Key Performance Points:**

- CDN resources use SRI (Subresource Integrity) hashes for security
- All external resources are minified (`.min.css` / `.min.js`)
- Consolidated CSS loads as single file instead of multiple requests
- Bootstrap theme loads after Bootstrap core CSS (for overrides)
- Third-party minified files loaded before custom CSS (CSS cascade priority)

## Unused CSS Files

### bootswatchSimplex.min.css

**Status:** Not currently used (✋ Can be removed)

**Details:**

- Local backup of Bootswatch Simplex theme
- Currently loaded from CDN: `https://cdn.jsdelivr.net/npm/bootswatch@4.6.1/dist/simplex/bootstrap.min.css`
- Managed via `elan_bootswatch_cdn` database setting
- Never referenced in any PHP/JS code
- Not required for current production setup

**Recommendation:**

- Safe to remove from source control if CDN is always available
- Keep in repository as fallback if offline capability needed
- Currently kept for reference purposes

## CSS Modification Workflow

### When to Modify CSS

**Modify consolidated.css when:**

- Adding new component styles
- Updating existing registry styles (cards, forms, tables, maps)
- Adding responsive design rules
- Modifying utility classes

**DO NOT modify** Bootstrap, Bootswatch, or Hamburger CSS directly - these are third-party libraries managed via CDN.

### CSS Modification Steps

1. **Edit the source file:**

   ```bash
   # Edit unminified CSS for readability
   vim usersc/templates/ElanRegistry/assets/css/consolidated.css
   ```

2. **Generate minified version:**

   ```bash
   # For quick minification, use an online tool or npm package:
   npm install -g csso-cli  # Install CSS minifier
   csso-cli consolidated.css -o consolidated.min.css

   # OR use an online tool: https://cssminifier.com/
   # Paste consolidated.css → copy minified output → save as consolidated.min.css
   ```

3. **Verify in browser:**
   - Hard refresh: `Cmd+Shift+R` (Mac) or `Ctrl+Shift+R` (Windows/Linux)
   - Check browser console for any CSS warnings
   - Test all components that use modified styles

4. **Commit changes:**

   ```bash
   git add usersc/templates/ElanRegistry/assets/css/consolidated.{css,min.css}
   git commit -m "Update registry CSS: [describe changes]"
   ```

## CSS Minification Guide

### Why Minification Matters

**Performance Impact (v2.12.0 baseline):**

- Source: `consolidated.css` = ~2.8 KB
- Minified: `consolidated.min.css` = ~2.2 KB
- **Savings: ~22% reduction**
- After gzip: ~15% reduction (CSS already compresses well)

**Why both files exist:**

- `consolidated.css` - Source file for development and readability
- `consolidated.min.css` - Production file loaded by users (required for performance)

### Manual Minification Methods

#### Method 1: Node.js csso-cli (RECOMMENDED)

```bash
# Install once
npm install -g csso-cli

# Generate minified version
csso-cli consolidated.css -o consolidated.min.css

# Verify (compare file sizes)
ls -lh consolidated.css consolidated.min.css
```

**Why csso-cli:**

- Preserves CSS functionality while removing whitespace
- Optimizes selectors and values
- Extremely fast for local development

#### Method 2: Online CSS Minifier

1. Go to <https://cssminifier.com/>
2. Copy contents of `consolidated.css`
3. Paste into minifier
4. Copy minified output
5. Save to `consolidated.min.css`

**Trade-off:** Manual process, but no tool installation required

#### Method 3: Build Tools (Future Enhancement)

For future releases, consider implementing automated minification:

```bash
# Using postcss + cssnano
npm install --save-dev postcss cssnano postcss-cli

# Add to package.json:
"scripts": {
  "css:minify": "postcss usersc/templates/ElanRegistry/assets/css/consolidated.css -o usersc/templates/ElanRegistry/assets/css/consolidated.min.css"
}

# Run before releases:
npm run css:minify
```

### Minification Validation

**Always verify minified CSS works correctly:**

```bash
# 1. Check file syntax
# Copy minified content into https://www.cleancss.com/css-optimize/
# Ensure no errors reported

# 2. Test in browser
# Hard refresh: Cmd+Shift+R
# Check browser DevTools > Console for CSS errors

# 3. Visual regression testing
# Compare before/after screenshots of:
# - Cards (registry-card class)
# - Tables (registry-table class)
# - Forms (Bootstrap form classes)
# - Maps (map-container class)
# - Responsive design (resize browser to test media queries)

# 4. Specific component tests
# Test these CSS-dependent features:
# - Card headers and body styling
# - Form validation states (.is-valid, .is-invalid)
# - Image containers (carousel-indicators, img-fluid)
# - Width utilities (.w-15, .w-50, .w-85)
# - Media query responsiveness (<768px)
```

## CDN Resource Management

### CDN Configuration

All CDN URLs are stored in the database `settings` table:

```text
elan_bootstrap_css_cdn      → Bootstrap 4.6.2 CSS (full HTML link tag)
elan_jquery_cdn             → jQuery 3.6.0 JS (full HTML script tag)
elan_bootstrap_js_cdn       → Bootstrap 4.6.2 JS (full HTML script tag)
elan_popper_cdn             → Popper.js 1.16.1 JS (full HTML script tag)
elan_bootswatch_cdn         → Bootswatch Simplex theme (full HTML link tag)
elan_fontawesome_cdn        → Font Awesome icons CDN (full HTML script tag)
elan_datatables_js_cdn      → DataTables JS library (full HTML script tag)
elan_datatables_css_cdn     → DataTables CSS library (full HTML link tag)
elan_chartjs_cdn            → Chart.js library (full HTML script tag)
```

### Updating CDN Resources

**Two approaches:**

#### Approach 1: Use FIX Scripts (RECOMMENDED for major updates)

Example: `/FIX/23-Optimize-CDN-Resources.php`

**Advantages:**

- Two-phase UI (description → processing)
- Progress logging and error handling
- Automatic audit trail in `fix_script_runs` table
- Easy rollback (revert previous setting values)
- Documented in database history

**Steps:**

1. Create FIX script following FIX/17 pattern
2. Update settings with new CDN URLs
3. Log to `fix_script_runs` table
4. Test thoroughly before running in production

#### Approach 2: Direct Database Update (for minor changes)

```sql
-- Update single CDN resource
UPDATE settings
SET elan_jquery_cdn = '<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"
                       integrity="sha384-vtXRMe3mGCbOeY7l30aIg8H9p3GdeSe4IFlP6G8JMa7o7lXvnz3GFKzPxzJdPfGK"
                       crossorigin="anonymous"></script>'
WHERE id = 1;
```

**Caution:** Direct database updates lack audit trail. Use FIX scripts for production changes.

### SRI Integrity Hashes

**What is SRI?**
Subresource Integrity (SRI) is a security feature that ensures CDN resources haven't been tampered with. Each resource includes a cryptographic hash.

**Format:**

```html
<!-- Example with SRI hash -->
<script src="https://example.com/file.min.js"
        integrity="sha384-[BASE64-ENCODED-HASH]"
        crossorigin="anonymous"></script>
```

### Generating SRI Hashes

Use the provided PHP script to generate correct hashes:

```php
<?php
$url = 'https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css';
$content = file_get_contents($url);
$hash = base64_encode(hash('sha384', $content, true));
echo "sha384-$hash";
?>
```

**Current Valid Hashes (v2.12.0):**

| Resource | Version | Hash |
| -------- | ------- | ---- |
| Bootstrap CSS | 4.6.2 | `sha384-xOolHFLEh07PJGoPkLv1IbcEPTNtaed2xpHsD9ESMhqIYd0nLMwNLD69Npy4HI+N` |
| jQuery | 3.6.0 | `sha384-vtXRMe3mGCbOeY7l30aIg8H9p3GdeSe4IFlP6G8JMa7o7lXvnz3GFKzPxzJdPfGK` |
| Bootstrap JS | 4.6.2 | `sha384-+sLIOodYLS7CIrQpBjl+C7nPvqq+FbNUBDunl/OZv93DB7Ln/533i8e/mZXLi/P+` |
| Popper.js | 1.16.1 | `sha384-9/reFTGAW83EW2RDu2S0VKaIzap3H66lZH81PoYlFhbGU+6BZp6G7niu735Sk7lN` |

**Critical:** Always verify SRI hashes match actual file content. Incorrect hashes will cause browser security failures (see: browser console "Failed integrity metadata check" errors).

**How to Verify Hashes:**

```bash
# Download the file
curl -s https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css -o bootstrap.min.css

# Generate hash locally
openssl dgst -sha384 -binary bootstrap.min.css | openssl enc -base64

# Compare with expected hash
# Should match: sha384-xOolHFLEh07PJGoPkLv1IbcEPTNtaed2xpHsD9ESMhqIYd0nLMwNLD69Npy4HI+N
```

## Performance Optimization

### CSS Performance Metrics

**Current Performance (v2.12.0):**

| Metric | Value |
| ------ | ----- |
| Consolidated CSS size | ~2.2 KB (minified) |
| Bootstrap CSS (CDN) | ~18 KB (minified) |
| Total CSS payload | ~20 KB (uncompressed) |
| After gzip | ~6 KB (~70% compression) |
| HTTP requests | 8 requests (CDN + local) |

### CSS Loading Optimization Techniques

#### 1. CSS Minification (DONE)

- All CSS files use `.min.css` versions
- Source files (`.css`) are in repository for maintenance
- Minification saves ~22% on consolidated CSS

#### 2. CSS Consolidation (DONE)

- Merged multiple files into single `consolidated.min.css`
- Reduces HTTP requests from 2 to 1
- Single file cached by browser as unit

#### 3. CSS Delivery (DONE)

- Critical CSS (Bootstrap, Bootswatch) from CDN with fast delivery
- Custom CSS (consolidated.min.css) served from origin
- No CSS critical path issues

#### 4. Media Query Optimization

- Single responsive breakpoint: 768px
- Minimal media query rules
- Mobile-first approach

### Future CSS Optimization Opportunities

1. **CSS-in-JS for Dynamic Styles**
   - Move inline styles from style.php to template
   - Reduce total HTML payload

2. **Critical CSS Extraction**
   - Extract above-the-fold CSS
   - Defer non-critical CSS

3. **CSS Variables (CSS Custom Properties)**
   - Define registry colors as CSS variables
   - Easier theme customization

4. **Sass/SCSS Build Pipeline**
   - Add build step for automated minification
   - Variables and mixins for maintainability
   - Currently not implemented (YAGNI principle)

## Common CSS Tasks

### Adding New CSS Rules

```css
/* consolidated.css */

/* Add to appropriate section */
.new-component {
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 0.375rem;
}

/* Ensure responsive design */
@media (max-width: 768px) {
    .new-component {
        padding: 0.75rem;
    }
}
```

Then regenerate minified version and test.

### Removing Unused CSS Classes

1. Search codebase for class name usage
2. If unused, remove from `consolidated.css`
3. Regenerate minified version
4. Test extensively in browser

### Fixing CSS Bugs

1. Reproduce bug in browser (DevTools inspector)
2. Update `consolidated.css` with fix
3. Hard refresh browser to bypass cache
4. Verify fix works across all pages
5. Regenerate minified version
6. Commit both files

## CSS Maintenance Checklist

When releasing new versions:

- [ ] Update consolidated.css with new styles
- [ ] Minify to consolidated.min.css using csso-cli
- [ ] Test CSS in all browsers (Chrome, Firefox, Safari, Edge)
- [ ] Verify responsive design (test on mobile, tablet, desktop)
- [ ] Check browser console for CSS errors
- [ ] Validate CSS with W3C CSS Validator
- [ ] Test all components with new CSS:
  - [ ] Cards (registry-card)
  - [ ] Forms (Bootstrap form classes)
  - [ ] Tables (registry-table)
  - [ ] Maps (map-container)
  - [ ] Images (carousel, img-fluid)
  - [ ] Responsive breakpoint (<768px)
- [ ] Verify minified file compresses correctly (check file size)
- [ ] Commit both `.css` and `.min.css` files
- [ ] Update RELEASE_NOTES with CSS changes

## Related Documentation

- [ARCHITECTURE.md](ARCHITECTURE.md) - Overall system architecture
- [Development Workflow](https://github.com/jimboone/elan-registry/wiki/Development-Workflow) - Development processes (wiki)
- [CODING_STANDARDS.md](CODING_STANDARDS.md) - Code quality requirements
- [QUICK_REFERENCE.md](QUICK_REFERENCE.md) - Common development tasks
