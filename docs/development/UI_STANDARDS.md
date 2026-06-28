# UI Standards — Elan Registry

**Live reference:** [`/app/admin/color-preview.php`](../../app/admin/color-preview.php) —
renders every token, card level, and component pattern in context. Admin-only page.

**Token source:** [`usersc/templates/customizer.css`](../../usersc/templates/customizer.css) —
the single source of truth for all `--er-*` tokens and global utility classes.

---

## The Golden Rule

> **Every new UI component or token must be demonstrated in `color-preview.php` before it is used elsewhere on the site.**

When you introduce a new pattern — a new CSS class, a new token, a new component — add a section
to `color-preview.php` showing it in context first. This keeps the reference page authoritative
and ensures the pattern is visually validated before being applied site-wide.

---

## Color Token System

All colors are defined as CSS custom properties in `customizer.css`. Never use Bootstrap's default
palette or hardcoded hex values in project-owned PHP, CSS, or JS files.

### Token Reference

| Token | Hex | WCAG vs white | Use |
| --- | --- | --- | --- |
| `--er-primary` | `#00563F` | 8.4:1 AAA | Primary buttons, card headers, brand anchor |
| `--er-primary-dark` | `#003D2C` | 12.1:1 AAA | Hover, active states, focus rings |
| `--er-primary-light` | `#E6EFEC` | 1.1:1 (bg) | Subtle tints, table hover |
| `--er-primary-rgb` | `0, 86, 63` | — | `rgba()` calculations: `rgba(var(--er-primary-rgb), 0.1)` |
| `--er-accent` | `#FFF200` | 1.07:1 ❌ | Lotus Yellow — **graphic/border/fill ONLY**, never text on white |
| `--er-warning` | `#B8860B` | 4.6:1 AA | Warnings, "Unverified" badge — replaces Bootstrap `#ffc107` |
| `--er-warning-rgb` | `184, 134, 11` | — | `rgba()` calculations |
| `--er-danger` | `#A52218` | 6.4:1 AA | Destructive actions only |
| `--er-danger-rgb` | `165, 34, 24` | — | `rgba()` calculations |
| `--er-link` | `#0B5394` | 8.6:1 AAA | Hyperlinks **only** — not buttons, not headings |
| `--er-link-hover` | `#073763` | 11.4:1 AAA | Link hover / visited |
| `--er-neutral` | `#6C757D` | 4.7:1 AA | Muted text, secondary UI (`text-muted`) |
| `--er-neutral-light` | `#F4F5F3` | 1.05:1 (bg) | Page background tint, table stripes, L3 card headers |
| `--er-neutral-dark` | `#3B413D` | 9.4:1 AAA | Dark sections, hero banners, `--er-neutral-dark` |
| `--er-true-black` | `#010101` | 20.9:1 AAA | Authentic Lotus black — text on yellow |

### Bootstrap Cascade

The tokens override Bootstrap's defaults in `:root` — most Bootstrap utilities (`bg-primary`, `text-primary`, `btn-primary`, `alert-primary`) inherit automatically:

```css
--bs-primary: var(--er-primary);        /* cascades to bg-primary, text-primary */
--bs-link-color: var(--er-link);        /* cascades to all <a> tags */
--bs-warning: var(--er-warning);        /* cascades to alert-warning, badge-warning */
--bs-secondary-color: var(--er-neutral); /* cascades to .text-muted */
```

Per-component overrides in `customizer.css` handle `.btn-primary`, `.btn-warning`, `.btn-info`
(Bootstrap hardcodes these at component scope and they don't inherit `--bs-primary`).

### Email Templates — Special Rule

CSS custom properties **do not work in email clients**. `EmailTemplate.php` and any other email-related code must use **literal hex values**:

```php
// ✓ Correct for email
'background-color: #00563F'

// ✗ Wrong — CSS vars are stripped by email clients
'background-color: var(--er-primary)'
```

The current hex values in `EmailTemplate.php` must stay in sync with `--er-primary` in `customizer.css`. Update both when the brand color changes.

### Color Anti-Patterns

| ❌ Don't use | ✓ Use instead | Why |
| --- | --- | --- |
| `bg-info` / `btn-info` | `bg-primary` / `btn-primary` | info = Bootstrap cyan; we retired cyan |
| `bg-success` / `btn-success` as primary CTA | `btn-primary` | success green ≠ BRG; reserve for genuine success states |
| `bg-warning text-dark` on card headers | `card-header-er-primary` | Inconsistent with hierarchy; warning color is semantic |
| `bg-dark` on card headers | `card-header-er-primary` or `card-header-er-dark` | Use token classes, not Bootstrap bg utilities |
| `#007bff`, `#28a745`, `#17a2b8`, `#ffc107` | `var(--er-primary)`, `var(--er-warning)` etc. | Hardcoded Bootstrap 4 values; bypass the token system |
| `text-white` on `card-header-er-primary` headings | `card-header-er-primary-text` | `text-white` is `#fff` at full opacity; the token class uses `rgba(255,255,255,0.75)` for the correct visual weight |
| `text-primary` on icons inside BRG card headers | Remove the class | `text-primary` resolves to `--er-primary` (green on green = invisible) |
| `btn-close` on BRG modal headers | `btn-close btn-close-white` | Bootstrap's default close icon is a black SVG — invisible on dark backgrounds |

---

## Card Hierarchy (L1–L4)

Use nested card levels whenever cards are embedded within other cards. Each level reduces visual weight while keeping the BRG brand anchor at the outermost level.

### The Four Levels

| Level | Class | Visual treatment | When to use |
| --- | --- | --- | --- |
| **L1** | `card-header-er-primary` | BRG green + 5px Lotus Yellow stripe, white text | Top-level page section card, tab pane anchor |
| **L2** | `card-header-er-l2` | `#e8f0ed` (solid light BRG tint) + 4px BRG left border, dark text | Group or subsection within L1 |
| **L3** | `card-header-er-l3` | `--er-neutral-light` bg + 3px grey left border, dark text | Individual record or item within L2 |
| **L4** | `card-header-er-l4` | White bg + hairline bottom divider, small uppercase label | Detail panel or supplementary info within L3 |

For heading text inside each level:

| Level | Text class | Use on |
| --- | --- | --- |
| L1 | `card-header-er-primary-text` | `h1`–`h6`, `p`, `small` inside `card-header-er-primary` |
| L2 | `card-header-er-l2-text` | Headings and button text inside `card-header-er-l2` |
| L3 | `card-header-er-l3-text` | Headings inside `card-header-er-l3` |
| L4 | `card-header-er-l4-text` | Uppercase label text inside `card-header-er-l4` |

### Nesting Rules

- **Never nest L1 inside L1.** One L1 card per page section or tab pane.
- L2 groups sit directly inside an L1 card body (e.g., duplicate groups, report categories).
- L3 items sit inside an L2 group (e.g., individual records, comparison cards).
- L4 is optional — skip it if the L3 content is simple prose.
- The **CSS specificity firewall** in `customizer.css` uses `(0,4,0)` selectors to override the
  UserSpice-generated Bootstrap rule
  `.card.border-primary .card-header { background: var(--bs-primary) !important }` at `(0,3,0)`.
  Do not remove these rules.

### Semantic Exceptions

- **Permanent deletion / destructive danger cards** inside a hierarchy may keep `bg-danger` with
  explicit `text-white` on the heading element — semantic red communicates the action's severity
  more clearly than following the level system.
- **Card heroes** (e.g., the car hero section on the account page) use `bg-primary` with inline
  `border-top: 5px solid var(--er-accent)` — they are visual showcases, not structural hierarchy
  nodes, so they intentionally look like L1 without being anchors.

---

## Component Patterns

### Buttons

```html
<!-- Primary action — use for the main CTA -->
<button class="btn btn-primary">Save</button>

<!-- Secondary action — use for less-prominent confirmations -->
<button class="btn btn-secondary">Cancel</button>

<!-- Destructive action — deletions, permanent changes only -->
<button class="btn btn-danger">Delete</button>

<!-- Outline — navigation links, non-primary actions within cards -->
<a class="btn btn-outline-primary">View Details</a>

<!-- Warning — administrative use, unverified data actions -->
<button class="btn btn-warning">Override</button>

<!-- Lotus Yellow CTA — high-contrast filled button for loud CTAs that must pop
     on dark backgrounds (e.g. public-nav Register link). Yellow + --er-on-accent
     text, NEVER white text. -->
<a class="btn btn-er-yellow btn-sm">Register</a>
```

**Do not use `btn-success` as a generic primary CTA.** Reserve it for genuine approval/completion states (e.g., "Approve transfer request").

### Badges

```html
<span class="badge text-bg-primary">Verified</span>
<span class="badge text-bg-warning">Unverified</span>   <!-- dark goldenrod, WCAG AA -->
<span class="badge text-bg-secondary">Archived</span>
<span class="badge text-bg-danger">Removed</span>
<!-- Lotus Yellow badge — text must be --er-true-black, NEVER white -->
<span class="badge er-badge-yellow">Featured</span>
```

### Alerts

```html
<!-- Informational — replaces alert-info (Bootstrap cyan retired) -->
<div class="alert alert-primary">...</div>

<!-- Warning — data quality issues, unverified content -->
<div class="alert alert-warning">...</div>

<!-- Danger — destructive actions, irreversible operations -->
<div class="alert alert-danger">...</div>

<!-- Compact variant (defined globally in customizer.css) -->
<div class="alert alert-primary alert-sm">...</div>
```

### Stat Tiles

Use `.er-stat-tile` for header dashboard counters. Dark background, Lotus Yellow accent number, yellow top stripe.

```html
<div class="er-stat-tile">
    <div class="er-stat-number">1,245</div>
    <div class="er-stat-label">Total Cars</div>
</div>
```

Do **not** mix stat tiles with colored Bootstrap cards (`bg-primary`, `bg-success`) for the same metric on the same page — pick one pattern and use it consistently.

### Modal Headers

All modal headers that use `card-header-er-primary` (BRG) or `card-header-er-dark` **must** use `btn-close-white` on the close button:

```html
<div class="modal-header card-header-er-primary">
    <h5 class="modal-title card-header-er-primary-text">Title</h5>
    <button type="button" class="btn-close btn-close-white"
            data-bs-dismiss="modal" aria-label="Close"></button>
</div>
```

### Form Section Headings

```html
<!-- Defined globally in customizer.css -->
<div class="form-section-heading">Vehicle Details</div>
```

---

## Page Structure Conventions

### Standard Page Wrapper

Every app page wraps its content in `.page-wrapper` (global, defined in `customizer.css`):

```html
<div class="page-wrapper">
    <div class="container">
        <!-- page content -->
    </div>
</div>
```

### Registry Card

All content cards use `.registry-card` (no border, subtle box shadow, defined globally):

```html
<div class="card registry-card">
    <div class="card-header card-header-er-primary">
        <h4 class="mb-0 card-header-er-primary-text">
            <i class="fas fa-car"></i> Section Title
        </h4>
    </div>
    <div class="card-body">...</div>
</div>
```

### Tab Layouts

Nav-tab brand styling (BRG active underline, hover tint, mobile horizontal scroll) is global in `customizer.css`. No per-page overrides needed:

```html
<ul class="nav nav-tabs" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab"
                data-bs-target="#pane-id" type="button">
            <i class="fas fa-icon"></i> Tab Label
        </button>
    </li>
</ul>
<div class="tab-content">
    <div class="tab-pane fade show active" id="pane-id" role="tabpanel">
        <!-- tab content -->
    </div>
</div>
```

### Page-Specific CSS Files

Three first-party CSS source files (compiled to `.min.css` by `npm run build`):

| File | Loaded by | Contains |
| --- | --- | --- |
| `app/admin/assets/manage-consolidated.css` | `manage-consolidated.php` | Admin comparison cards, field-match/differ, timestamp display |
| `app/assets/css/edit_car.css` | `app/cars/edit.php` | FilePond overrides, `#editCar` focus, card z-index for drag-drop |
| `app/assets/css/location-picker.css` | `app/cars/edit.php` | `.location-picker-container`-scoped styles |

**Everything else is global in `customizer.css`.** If you find yourself writing a style in a page
file that could apply to multiple pages, move it to `customizer.css` instead. Each rule retained
in a page-specific file should have a comment explaining why it is not global.

---

## Adding New UI Patterns

When introducing a new component, token, or pattern:

1. **Add a demo to `color-preview.php`** showing the pattern in context with a label and usage note. This is mandatory — it is the visual contract for the pattern.
2. **Add the CSS to `customizer.css`** if it is globally applicable, or to the relevant page-specific file with a scope comment if not.
3. **Document it in this file** under the appropriate section, including any anti-patterns it replaces.
4. **Run `npm run build`** if you edited a source CSS file other than `customizer.css` (which is served directly without a build step).
5. If the pattern involves PHP class rendering (e.g., `DocumentPortalTemplate`), **add unit tests** pinning the new class names so regressions are caught automatically.

---

## See Also

- [`docs/development/CODING_STANDARDS.md`](CODING_STANDARDS.md) — PHP coding standards
- [`docs/development/CSS_AND_ASSETS.md`](CSS_AND_ASSETS.md) — asset pipeline, build process, ADR-015
- [`app/admin/color-preview.php`](../../app/admin/color-preview.php) — live token and component reference
- [`usersc/templates/customizer.css`](../../usersc/templates/customizer.css) — token definitions and global CSS
