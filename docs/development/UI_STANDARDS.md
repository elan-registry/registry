# UI Standards — Elan Registry

**Live reference:** [`/app/admin/design-system.php`](../../app/admin/design-system.php) —
renders every token, card level, and component pattern in context. Admin-only page.

**Token source:** [`usersc/templates/customizer.css`](../../usersc/templates/customizer.css) —
the single source of truth for all `--er-*` tokens and global utility classes.

---

## The Golden Rule

> **Every new UI component or token must be demonstrated in `design-system.php` before it is used elsewhere on the site.**

When you introduce a new pattern — a new CSS class, a new token, a new component — add a section
to `design-system.php` showing it in context first. This keeps the reference page authoritative
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
| `--er-danger` | `#A52218` | 7.39:1 AAA | Destructive actions only |
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

Generic badges use Bootstrap classes. Do not use them for car status. Car
status has its own system, described in "Car status badges" below.

```html
<span class="badge text-bg-warning">Unverified</span>   <!-- dark goldenrod, WCAG AA -->
<span class="badge text-bg-secondary">Archived</span>
<span class="badge text-bg-danger">Removed</span>
<!-- Lotus Yellow badge — text must be --er-on-accent (near-black), NEVER white -->
<span class="badge er-badge-yellow">Featured</span>
```

Do not use `text-bg-primary Verified` for car status. The Verified car
status badge is `er-badge er-badge--verified`.

#### Car status badges

Car status badges show Sold, Verified, and New. `CarBadges` decides which
badges a car shows and draws them. See [CLASSES.md](CLASSES.md#carbadges).

**Tokens** (defined in `usersc/templates/customizer.css`):

| Token | Value | Use |
| --- | --- | --- |
| `--er-badge-sold` | `#A52218` (white text 7.39:1, AAA) | Sold fill and border. Text is white |
| `--er-badge-verified` | `var(--er-primary)` | Verified border |
| `--er-badge-verified-bg` | `var(--er-primary-light)` | Verified fill |
| `--er-badge-verified-fg` | `var(--er-primary-dark)` (10.52:1 on the fill, AAA) | Verified text |
| `--er-badge-new` | `var(--er-accent)` | New fill. Text is `--er-on-accent`, never white |

`--er-badge-sold` has its own name because `--er-danger` is for destructive
actions only.

**Classes:**

| Class | Purpose |
| --- | --- |
| `.er-badge` | Base. Small uppercase pill label. The tone class sets the colors |
| `.er-badge--sold`, `.er-badge--verified`, `.er-badge--new` | Tone. One per badge. Sold is a red sign. Verified is a light green tag with ✓ and dark green text, so it does not look like the dark green Details button. New is a yellow sign |
| `.er-badge--stamp` | Adds a thick border, square corners, and a 6 degree counter-clockwise rotation |
| `.er-badges` | Row that holds flat badges in the cars list |

**Two styles:**

| Style | Where | Class |
| --- | --- | --- |
| Stamp | Account page hero, Sold row of the Vehicle Information card | `er-badge er-badge--<tone> er-badge--stamp` |
| Flat | Cars list | `er-badge er-badge--<tone>` |

Use `CarBadges::html($keys, $style)` to draw badges. Get `$keys` from
`CarBadges::forCar()`. Set `$style` to `'stamp'` or `'flat'`. The method
returns the badge spans with no wrapper, or `''` when there are no badges.

The account page hero gets its keys from `CarBadges::forCar()`. The Vehicle
Information card (`app/views/cars/_vehicle_info_card.php`) does not. It draws
only the Sold stamp in its Sold row, from `$soldDate`, with the hard-coded
keys `['sold']`. The card is on the account page, the car details page, and
the public vericode landing page.

Inside the dark green hero (`.card-header-er-primary-text`), a stamp has a
white edge. The sold red against the hero green is only 1.2:1, so the red
edge does not show there. Stamps on white pages keep their tone color edge.
The cars list uses the same markup. `CarBadges::decorateRows()` puts it in
the `badges_html` field of each `list.php` row, and
`app/assets/js/car-list.js` adds it after the Details link.

**Which badges show.** `CarBadges::resolve()` has the rule:

- New shows when the car is new (cars list only).
- Sold shows when the car is sold.
- Verified shows when the car is fresh, not sold, and not new.

The display order is New, Sold, Verified. Do not code this rule in a
template or in JS. Change `CarBadges::resolve()`.

**Accessibility rules:**

- Put the badge row outside the Details link. In the cars list, `.er-badges`
  is a sibling of the Details `<a>`. A badge has `tabindex="0"`, so it is an
  interactive element. A badge inside a link or button nests interactive
  elements and fails WCAG 4.1.2.
- Give each badge `data-bs-toggle="tooltip"`, `data-bs-title`, and
  `tabindex="0"`. The tabindex lets keyboard users reach the tooltip.
  `.er-badge:focus-visible` draws the focus ring: a 2px white ring inside a
  2px `--er-primary-dark` ring (`box-shadow`), so the ring shows on white
  pages and on the dark green hero. In the account hero
  (`.card-header-er-primary-text`), the stamp edge is white, so the rings
  change places: the `--er-primary-dark` ring is inside and the white ring is
  outside. A transparent outline keeps a ring in Windows forced-colors mode,
  which removes `box-shadow`.
- Put an icon (for example the Verified check mark) in
  `<span aria-hidden="true">`. A screen reader then reads "Verified", not
  "✓ Verified".
- Do not add `title` or `aria-label`. Bootstrap adds `aria-describedby`
  when the tooltip shows. A `title` shows a second native tooltip.
- In JS, build badges with DOM methods or `textContent`. Do not build them
  from strings that contain tooltip or label text.
- After the cars list redraws, `drawCallback` disposes the old tooltips and
  starts new ones. Tooltips on rows that DataTables creates do not start
  by themselves.

```html
<!-- ✅ Flat badges in a row outside the Details link (cars list cell) -->
<a class="btn btn-primary btn-sm" href="..."><i class="fas fa-eye"></i> Details</a>
<div class="er-badges d-flex flex-wrap gap-1 mt-1">
  <span class="er-badge er-badge--new" data-bs-toggle="tooltip"
        data-bs-title="Added to the registry in the last 90 days, or one of the 5 newest cars."
        tabindex="0">New</span>
</div>

<!-- ❌ Badge inside the link: nested interactive element -->
<a class="btn btn-primary btn-sm" href="...">Details <span class="badge er-badge-yellow badge-sm">NEW</span></a>

<!-- ❌ Icon read aloud, and a second tooltip from title -->
<span class="er-badge er-badge--verified" title="Verified">✓ Verified</span>
```

To see all badges, open the Car status badges section of the design system
page (`app/admin/design-system.php`). It uses the real partial.

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

### Danger Modal (blocking failure/confirmation messages)

For a message that must be acknowledged rather than a dismissible toast (destructive
confirmations, security-sensitive failure notices), use a `bg-danger`/`text-white` header —
the semantic exception for destructive/severe content — paired with `btn-close-white` and
an `alert-danger` message body:

```html
<div class="modal fade" id="exampleDangerModal" tabindex="-1" aria-labelledby="exampleDangerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="exampleDangerModalLabel">Title</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger mb-0">Message text.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
```

Show it via `bootstrap.Modal.getOrCreateInstance(el).show()` (or a declarative
`data-bs-toggle="modal"` trigger). Dismiss buttons use `btn-secondary`/`btn-primary` —
`btn-danger` is reserved for the destructive action itself, not for dismissing the dialog.

**Always `htmlspecialchars($message, ENT_QUOTES, 'UTF-8')` the message body before echoing it**,
per CLAUDE.md's "Apply `htmlspecialchars()` at the render layer only" — this modal *is* that
render layer. This applies even if the message source looks safe today (e.g. a static string
or a value that's already passed through an upstream allowlist sanitizer like `sanitizeHTML()`)
— the pattern is meant to be reused, and the next consumer's message source may include
user-controlled data. If `<br>`/`<strong>`-style formatting genuinely needs to survive, replicate
a safe-tag allowlist server-side rather than skipping escaping.

Used by the car-deletion confirmation (`app/admin/index.php` / `admin-core.js`) and the
registration-failure notice (`usersc/views/_join.php`, issue #1406) — both surface a message
that page visitors are otherwise likely to miss in a 6-second auto-dismissing toast.
Demoed in `design-system.php` under "Modals".

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
| `app/admin/assets/admin-core.css` | `index.php` | Admin comparison cards, field-match/differ, timestamp display |
| `app/assets/css/edit_car.css` | `app/owner/cars/edit.php` | FilePond overrides, `#editCar` focus, card z-index for drag-drop |
| `app/assets/css/location-picker.css` | `app/owner/cars/edit.php` | `.location-picker-container`-scoped styles |

**Everything else is global in `customizer.css`.** If you find yourself writing a style in a page
file that could apply to multiple pages, move it to `customizer.css` instead. Each rule retained
in a page-specific file should have a comment explaining why it is not global.

---

## Adding New UI Patterns

When introducing a new component, token, or pattern:

1. **Add a demo to `design-system.php`** showing the pattern in context with a label and usage note. This is mandatory — it is the visual contract for the pattern.
2. **Add the CSS to `customizer.css`** if it is globally applicable, or to the relevant page-specific file with a scope comment if not.
3. **Document it in this file** under the appropriate section, including any anti-patterns it replaces.
4. **Run `npm run build`** if you edited a source CSS file other than `customizer.css` (which is served directly without a build step).
5. If the pattern involves PHP class rendering (e.g., `DocumentPortalTemplate`), **add unit tests** pinning the new class names so regressions are caught automatically.

---

## See Also

- [`docs/development/CODING_STANDARDS.md`](CODING_STANDARDS.md) — PHP coding standards
- [`docs/development/CSS_AND_ASSETS.md`](CSS_AND_ASSETS.md) — asset pipeline and build process
- [`app/admin/design-system.php`](../../app/admin/design-system.php) — live token and component reference
- [`usersc/templates/customizer.css`](../../usersc/templates/customizer.css) — token definitions and global CSS
