# ADR-017: Automate Frontend Vendoring via npm Build Pipeline

## Status

Superseded by [ADR-018](ADR-018-build-at-deploy-for-frontend-vendoring.md)

**Supersedes:** [ADR-015](ADR-015-self-host-frontend-libraries.md)

## Date

2026-08-22

## Context

ADR-015 vendors frontend JS/CSS libraries into `usersc/js/` and `usersc/css/`,
pinning versions in `package.json` purely so Dependabot can raise CVE alerts.
That pin is a promise: the declared version should match what's actually
served. It broke.

Dependabot bumped `datatables.net-bs5` in `package.json` from `2.3.8` to
`3.0.1`. The file the application actually loads,
`usersc/js/datatables.min.js`, was hand-built once via the DataTables
downloader-builder website and hand-committed — nothing in the build pipeline
(`scripts/build.js`) ever touched it. The bump was inert: it changed a
version string Dependabot watches without changing a single served byte. This
first surfaced as an incorrect v2.29.1 release-note claim ("DataTables
upgraded to 3.x") and was only caught because CI's milestone review happened
to read the vendored file's header comment directly. Nothing automated would
have caught it.

`usersc/js/chart.umd.min.js` has the identical structural exposure — also
hand-copied outside `scripts/build.js`, currently matching `package.json`'s
`chart.js@4.5.1` pin only by coincidence, one Dependabot PR away from the same
silent drift.

While auditing every frontend dependency to find every instance of this drift
class (issue #1725), the inventory turned out to be less accurate than
ADR-015 claimed on a second axis, independent of version drift: **Dropzone
and jQuery UI were listed as vendored but never actually existed as files**,
in this repository or in a fresh UserSpice 6.1.4 install. Their only traces
were dead PHP (`usersc/includes/timepicker.php`,
`usersc/scripts/datepicker.php`, `usersc/scripts/datetimepicker.php` — none
ever included or required anywhere), an unused vendored `flatpickr.min.js`/
`flatpickr.min.css` pair, and three orphaned `settings` table columns
(`elan_jquery_ui_cdn`, `elan_dropzone_js_cdn`, `elan_dropzone_css_cdn`) never
read by any application code.

DataTables and Chart.js, by contrast, are genuinely used — `usersc/js/
datatables.min.js`/`datatables.min.css` load on `app/owner/cars/index.php`,
`factory.php`, `details.php`, and `app/admin/includes/tab-account_cleanup.php`;
`usersc/js/chart.umd.min.js` loads on `app/owner/reports/statistics.php`.
These are **separate files** from UserSpice's own native copies under
`users/js/` (confirmed byte-identical to a fresh UserSpice 6.1.4 install,
used only by UserSpice's own admin screens) — the native copies remain
entirely outside this project's concern.

Meanwhile, `scripts/build.js` already solves exactly this problem correctly
for 9 other files: FilePond (core + 6 plugins) and MapLibre GL/`@versatiles/
style` are all copied from `node_modules` into `usersc/` on `npm run build`,
with `package.json`'s declared version guaranteed to match the committed
output because the output *is* the build's own artifact, not a separately
hand-maintained file.

### Why npm, not Composer

DataTables and Chart.js are npm-native packages. Using Composer to manage
them would require a Composer→npm asset-plugin shim (e.g.
`oomphinc/composer-installers-extender`) — introducing a second,
narrower frontend-asset pipeline for 2 files while npm already correctly
manages the other 9 via the exact same `scripts/build.js` mechanism. That
inconsistency (two tools solving the same category of problem, split by
which files happened to get automated first) is the opposite of what this
ADR is trying to achieve. Composer's actual role in this codebase is,
and remains, PHP dependencies only (`phinx`, `phpdotenv`, PHPUnit, PHPStan) —
it has never managed a frontend asset here, and this ADR does not change
that.

## Decision

Extend `scripts/build.js`'s existing `node_modules` → `usersc/` copy pattern
(already proven for FilePond and MapLibre GL) to also cover DataTables (Core,
FixedHeader, Responsive — JS and CSS) and Chart.js. No new mechanism is
introduced.

### Re-litigating ADR-015's original objections

ADR-015 rejected two alternatives that this decision revisits under
different, narrower terms:

- **"Build Pipeline with npm + Bundler" — originally rejected** because it
  "requires a build step on every deployment, adding infrastructure that the
  shared-hosting deployment model does not natively support." This decision
  is not that proposal. The build (`npm run build`) runs in a developer's
  local pre-commit hook or in CI — never on the production deploy path.
  The deploy hook (`scripts/server-hooks/post-receive`) still runs only
  `composer install --no-dev` and `phinx migrate`; it will
  continue to never invoke `npm install` or `npm run build`. What's
  committed to the repository, and what deploy actually serves, is the
  build's *output* — exactly how FilePond and MapLibre GL already work.
  ADR-015's objection was correct for build-at-deploy-time; it does not
  apply to build-then-commit-output, which was already the accepted pattern
  for 9 files before this ADR.
- **"Composer-Managed Frontend Assets" — reaffirmed, not re-litigated.** See
  "Why npm, not Composer" above; nothing about this decision touches
  Composer's role.

### Corrected inventory (replaces ADR-015's table as the canonical reference)

1. **Framework-native (`users/js/`, `users/css/`) — not this project's
   dependency to manage.** jQuery, Bootstrap, Font Awesome, and UserSpice's
   own DataTables/Chart.js copies (used only by UserSpice's own admin
   screens). Confirmed byte-identical to a stock UserSpice 6.1.4 install.
   **Note (2026-10-12):** UserSpice 6.1.5 to 6.1.7 do not change
   `users/js/`. The upstream diff has no `users/js` file.
2. **Project-vendored via npm + `scripts/build.js`, drift-guarded by CI.**
   FilePond (core + 6 plugins), MapLibre GL, `@versatiles/style`, and — newly
   added by this ADR — DataTables (`datatables.net-bs5`,
   `datatables.net-fixedheader-bs5`, `datatables.net-responsive-bs5`) and
   Chart.js (`chart.js`).
3. **Removed entirely.** jQuery UI, Dropzone, and `flatpickr` — all
   confirmed to have zero application usage; see "Dead entries removed"
   below.

### DataTables extension set

Only Core, FixedHeader, and Responsive are vendored. Buttons and ColVis
(part of the original hand-built bundle) are dropped: a repo-wide check
found zero references to `buttons:`, `.button(`, or `colvis` in any
consuming JS file, and [ADR-011](ADR-011-adopt-datatables-with-server-side-processing.md)
already documents this exact extension set as the deliberate choice
("Reduced from 8 extensions in v2.11.0... SearchPanes and SearchBuilder
prototyping proved incompatible with server-side processing"). This ADR
does not re-decide that question — it just stops shipping unused code that
contradicts an already-accepted decision.

### Build mechanics

Each `datatables.net-*-bs5` npm package is a thin Bootstrap 5 styling
wrapper; the functional code lives in the corresponding non-`-bs5` core
package (`datatables.net`, `datatables.net-fixedheader`,
`datatables.net-responsive`). `scripts/build.js` concatenates core + wrapper
per extension via `fs.readFileSync`/`fs.writeFileSync` (each is a
self-contained UMD IIFE, safe to concatenate) into three JS files —
`datatables.min.js`, `datatables-fixedheader.min.js`,
`datatables-responsive.min.js` — and concatenates all three extensions' CSS
into a single `datatables.min.css`, preserving the one-`<link>`-tag loading
pattern the 4 consuming pages already use. Chart.js is a direct
`fs.copyFileSync`, matching the MapLibre GL pattern exactly.

DataTables does not bundle jQuery: the vendored files perform runtime
environment detection (AMD/CommonJS/else-use-the-bare-`jQuery`-global) and,
loaded via plain `<script>` tags with no module system present, fall through
to whatever global `jQuery` UserSpice already put on the page. jQuery
therefore does not need to be added to `package.json`/`node_modules`.

Plain concatenation was chosen over esbuild bundling for these files
specifically to avoid disturbing that environment-detection logic — see
Alternatives Considered.

### Version target

`datatables.net-bs5` is pinned to `2.3.8` — the version actually running in
production today — rather than jumping to the 3.x line Dependabot had
requested. This keeps the fix a pure automation refactor (manual bundle →
npm-built bundle, identical behavior) with zero upgrade risk folded in. A
DataTables 3.x upgrade is deliberately deferred to its own future issue, so
any regression it introduces is bisectable independently of this change.

**Update (#1741):** That deferred upgrade landed as its own issue, as planned.
`datatables.net-bs5`/`-fixedheader-bs5`/`-responsive-bs5` were bumped to
`3.0.2`/`5.0.0`/`4.0.2` (a mutually compatible set — all three peer-depend on
`datatables.net-bs5@^3`, deduping to one `datatables.net@3.0.2` core) and the
vendored bundle rebuilt via `npm run build`. Exact-pinning was kept for the
same reason as the original 2.3.8 pin: the declared version should match what
production actually serves.

### Dead entries removed

- **jQuery UI, Dropzone**: no vendored files ever existed (Registry or stock
  UserSpice), zero code usage. Removed: dead PHP
  (`usersc/includes/timepicker.php`, `usersc/scripts/datepicker.php`,
  `usersc/scripts/datetimepicker.php`) and the three orphaned `settings`
  columns via a new Phinx migration (`elan_jquery_ui_cdn`,
  `elan_dropzone_js_cdn`, `elan_dropzone_css_cdn`).
- **flatpickr**: vendored (`usersc/js/flatpickr.min.js`,
  `usersc/css/flatpickr.min.css`) and declared in `package.json`, but never
  loaded or invoked anywhere in the app. Removed entirely, including the one
  dead CSS selector in `usersc/templates/customizer.css` that targeted
  `.flatpickr-input` (a class flatpickr's own JS would have applied, had it
  ever run).

### CI drift-catcher

A new `vendor-drift` job in `.github/workflows/static-analysis.yml` runs
`npm ci && npm run build`, then `git diff --exit-code -- usersc/js
usersc/css`. It triggers only when a PR touches `package.json`,
`package-lock.json`, or `scripts/build.js` (a new `vendor` output on the
existing `changes` job's path filter), following the same unconditional-
trigger-plus-`if:`-gate pattern the file's own header comment documents as
required for a check to satisfy branch protection reliably (see PR #1534).

The check does not assert "nothing changed" — it asserts "the committed
`usersc/js`/`usersc/css` files are exactly what building from the current
`package.json`/lockfile produces right now." A legitimate future version
bump must include the rebuilt output in the same commit. This is what closes
the actual bug: a future Dependabot PR bumping `datatables.net-bs5` alone,
with no rebuilt file, now fails CI instead of landing silently.

## Consequences

### Positive

- Declared version and served bytes are now guaranteed to match for
  DataTables and Chart.js, enforced by CI rather than developer diligence —
  the exact class of bug this ADR exists to close.
- Dead-code inventory shrinks: 3 PHP files, 1 vendored JS/CSS pair, 3 dead
  database columns, and one dead CSS selector removed.
- All project-vendored frontend libraries now flow through one consistent
  mechanism (`scripts/build.js`), not two.

### Negative

- Two new `package.json` dependencies (`datatables.net-fixedheader-bs5`,
  `datatables.net-responsive-bs5`) to keep current alongside
  `datatables.net-bs5`.
- A legitimate intentional version bump now requires committing rebuilt
  output in the same PR — a heavier, but safer, workflow than editing
  `package.json` alone.
- DataTables' script-tag count on the 4 consuming pages goes from 1 to 3
  (Core, FixedHeader, Responsive, in that load order — FixedHeader/Responsive
  are jQuery plugins that register onto the object Core creates).

### Neutral / carried forward from ADR-015 unchanged

- Dependabot still fires on `package.json` version pins.
- The Maintenance Workflow described in ADR-015 (review CVE → replace file →
  bump version → commit) still applies to any vendored library not yet
  covered by `scripts/build.js`.
- UserSpice-managed libraries (jQuery, Bootstrap, Font Awesome, and
  UserSpice's own DataTables/Chart.js copies) remain entirely out of scope,
  as they were under ADR-015.

## Alternatives Considered

### Composer-managed frontend assets

Use a Composer asset plugin to manage DataTables/Chart.js instead of
extending the npm pipeline.

**Rejected because:** see "Why npm, not Composer" in Context. Reaffirms
ADR-015's original rejection of this approach rather than re-opening it.

### Keep manual vendoring, add a drift-check script

Leave the hand-built-bundle workflow in place and add a script that parses
each vendored file's header comment for a version string and compares it
against `package.json`.

**Rejected because:** fragile (depends on free-text header formats that
differ per library and per build tool), and does not fix the root cause — a
human still has to remember to run the downloader-builder and update two
places by hand every time. A build-then-diff check that verifies against the
actual build output is strictly stronger and requires no header parsing.

### esbuild-bundle DataTables into one file, as with first-party JS

Use `scripts/build.js`'s existing esbuild pipeline (already used to minify
first-party app JS/CSS) to bundle DataTables' core and extension packages
into a single output file, the same way a first-party multi-file feature
might be bundled.

**Rejected because:** DataTables' vendored files perform runtime
environment detection expecting a bare `jQuery` global (no AMD/CJS
module system present on these pages). Bundling risks altering that
detection path or introducing an unwanted `require('jquery')` resolution
attempt. Plain per-file concatenation (`fs.readFileSync`/`writeFileSync`)
avoids this risk entirely and matches the "vendor files copied with no
transformation" philosophy already used for FilePond/MapLibre GL.

## References

- **Issue:** #1725
- **Superseded ADR:** [ADR-015](ADR-015-self-host-frontend-libraries.md)
- **Related ADR:** [ADR-011](ADR-011-adopt-datatables-with-server-side-processing.md)
  (DataTables extension-set rationale, cross-referenced not re-litigated)
- **Build pipeline:** `scripts/build.js`
- **CI drift-catcher:** `.github/workflows/static-analysis.yml`
  (`vendor-drift` job)
- **Vendored Files:** `usersc/js/`, `usersc/css/`
- **Version Pins:** `package.json`
- **Nygard ADR Format:**
  [https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions)
