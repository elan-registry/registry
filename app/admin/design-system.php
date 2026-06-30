<?php
declare(strict_types=1);

/**
 * design-system.php
 * Living reference page for the Elan Registry color token system (issue #757).
 *
 * Renders every --er-* token in context (buttons, card headers, badges,
 * stat tiles, form fields, charts) so the visual system can be reviewed in
 * one screen before site-wide rollout.
 *
 * Admin/Editor access via UserSpice securePage(). Tokens themselves live in
 * usersc/templates/customizer.css.
 */

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

if (!securePage($php_self)) {
    die();
}

$pageTitle = 'Color Preview — Elan Registry Token System';
?>

<style nonce="<?= htmlspecialchars($userspice_nonce ?? '') ?>">
    .er-preview-hero {
        background-color: var(--er-neutral-dark);
        color: #fff;
        padding: 3rem 2rem 2.5rem;
        border-bottom: 4px solid var(--er-accent);
        margin-bottom: 2rem;
    }
    .er-preview-hero h1 {
        color: #fff;
        margin: 0;
        font-weight: 600;
        letter-spacing: 0.02em;
    }
    .er-preview-hero p {
        color: rgba(255,255,255,0.75);
        margin: 0.5rem 0 0;
    }

    .er-swatch {
        width: 100%;
        height: 64px;
        border-radius: 4px;
        border: 1px solid rgba(0,0,0,0.1);
    }
    .er-swatch-label {
        font-family: 'Courier New', monospace;
        font-size: 0.8rem;
        display: block;
        margin-top: 4px;
        color: var(--er-neutral);
    }

    .er-token-table {
        font-size: 0.875rem;
    }
    .er-token-table td { vertical-align: middle; }
    .er-token-table code {
        background: var(--er-neutral-light);
        padding: 2px 6px;
        border-radius: 3px;
        color: var(--er-neutral-dark);
    }

    .er-token-swatch {
        display: inline-block;
        width: 28px;
        height: 28px;
        border-radius: 4px;
        border: 1px solid rgba(0,0,0,0.15);
        vertical-align: middle;
    }

    .er-compare-card {
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 1rem;
        height: 100%;
    }
    .er-compare-swatch-pair {
        display: flex;
        gap: 0.5rem;
        margin: 0.5rem 0;
    }
    .er-compare-swatch {
        flex: 1;
        height: 60px;
        border-radius: 4px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: 'Courier New', monospace;
        font-size: 0.75rem;
        color: #fff;
        text-shadow: 0 1px 2px rgba(0,0,0,0.3);
    }

    .er-badge-yellow {
        background-color: var(--er-accent);
        color: var(--er-on-accent);
    }

    .er-link-demo a {
        color: var(--er-link);
        text-decoration: none;
    }
    .er-link-demo a:hover {
        color: var(--er-link-hover);
    }

    /* Form focus demo */
    .er-focus-input:focus {
        border-color: var(--er-primary) !important;
        box-shadow: 0 0 0 0.25rem rgba(var(--er-primary-rgb), 0.25) !important;
    }
</style>

<div class="er-preview-hero">
    <div class="container">
        <h1>Color Preview</h1>
        <p>Living reference for the <code style="color: var(--er-accent);">--er-*</code> token system &mdash; <a href="https://github.com/unibrain1/elanregistry/issues/757" style="color: var(--er-accent);">issue #757</a></p>
    </div>
</div>

<div class="container">

    <!-- 1. Buttons -->
    <div class="er-section-heading">Buttons</div>
    <div class="d-flex flex-wrap gap-2 mb-2">
        <button type="button" class="btn btn-primary">Primary (BRG)</button>
        <button type="button" class="btn btn-outline-primary">Outline Primary</button>
        <button type="button" class="btn btn-warning">Warning</button>
        <button type="button" class="btn btn-info">Info / Ink Blue</button>
        <button type="button" class="btn btn-danger">Danger</button>
        <button type="button" class="btn btn-link" style="color: var(--er-link);">Link Button</button>
        <button type="button" class="btn btn-primary" disabled>Primary disabled</button>
    </div>
    <p class="text-muted small mb-0">Primary buttons use British Racing Green via <code>.btn-primary</code> cascade. No per-file edits needed across the site.</p>

    <!-- 2. Cards -->
    <div class="er-section-heading">Cards</div>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header card-header-er-primary">
                    <h5 class="mb-0 card-header-er-primary-text">Reference card</h5>
                </div>
                <div class="card-body">
                    <p class="mb-0">BRG header with Lotus-yellow signature stripe. Used on docs index pages (Reference, Guides, Car Stories).</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header card-header-er-dark">
                    <h5 class="mb-0 card-header-er-primary-text">Archive card</h5>
                </div>
                <div class="card-body">
                    <p class="mb-0">Dark header for archival / historical sections (e.g. Chassis Validation &ldquo;Racing Archive&rdquo;).</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0 card-header-er-primary-text">bg-primary (cascaded)</h5>
                </div>
                <div class="card-body">
                    <p class="mb-0">Plain Bootstrap <code>bg-primary</code> &mdash; inherits BRG automatically through the <code>--bs-primary</code> override.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Badges -->
    <div class="er-section-heading">Badges</div>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <span class="badge text-bg-primary">Verified</span>
        <span class="badge text-bg-warning">Unverified</span>
        <span class="badge text-bg-secondary">Archived</span>
        <span class="badge er-badge-yellow">Featured</span>
        <span class="badge text-bg-danger">Removed</span>
    </div>
    <p class="text-muted small mb-0">&ldquo;Unverified&rdquo; uses the new <code>--er-warning</code> dark goldenrod &mdash; readable on white (4.6:1) where Bootstrap&rsquo;s <code>#ffc107</code> was not (1.6:1).</p>

    <!-- 4. Stat tiles -->
    <div class="er-section-heading">Stat tiles</div>
    <div class="row g-3">
        <div class="col-md-3 col-sm-6">
            <div class="er-stat-tile">
                <div class="er-stat-number">1,259</div>
                <div class="er-stat-label">Lotus Elans registered</div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="er-stat-tile">
                <div class="er-stat-number">42</div>
                <div class="er-stat-label">New this year</div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="er-stat-tile">
                <div class="er-stat-number">87</div>
                <div class="er-stat-label">Countries</div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="er-stat-tile">
                <div class="er-stat-number">26R</div>
                <div class="er-stat-label">Rarest variant</div>
            </div>
        </div>
    </div>
    <p class="text-muted small mb-0 mt-2">Single tile style replaces the current four-tile rainbow (blue / green / cyan / yellow). Lotus yellow numeral on Lotus black, yellow signature stripe.</p>

    <!-- 5. Form field -->
    <div class="er-section-heading">Form field with primary focus ring</div>
    <div class="row">
        <div class="col-md-6">
            <label for="er-demo-input" class="form-label">Chassis number</label>
            <input type="text" class="form-control er-focus-input" id="er-demo-input" placeholder="26/1234" value="26/0001">
            <div class="form-text">Focus the field &mdash; the border and ring use <code>--er-primary</code>.</div>
        </div>
    </div>

    <!-- 6. Links -->
    <div class="er-section-heading">Links in prose</div>
    <div class="er-link-demo">
        <p>The Lotus Elan was produced from <a href="#">1962 to 1973</a> in four series. The <a href="#">Type 26R</a> was the homologation racing variant, of which fewer than 50 examples were built. See the <a href="#">chassis number reference</a> for identification.</p>
    </div>
    <p class="text-muted small mb-0">Ink blue (<code>#0B5394</code>) replaces Bootstrap blue (<code>#0d6efd</code>) &mdash; recedes behind BRG, reads as editorial / archival rather than &ldquo;stock template.&rdquo;</p>

    <!-- 7. Charts -->
    <div class="er-section-heading">Charts</div>
    <div class="row g-3">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header card-header-er-primary"><h6 class="mb-0 card-header-er-primary-text">Bar chart &mdash; Production by Year (sample)</h6></div>
                <div class="card-body" style="min-height: 240px;">
                    <canvas id="er-bar-demo" height="160"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header card-header-er-primary"><h6 class="mb-0 card-header-er-primary-text">Line chart &mdash; Registry Timeline (sample)</h6></div>
                <div class="card-body" style="min-height: 240px;">
                    <canvas id="er-line-demo" height="160"></canvas>
                </div>
            </div>
        </div>
    </div>
    <p class="text-muted small mb-0 mt-2">Bar charts: BRG with a single Lotus-yellow highlight bar for emphasis. Line charts: ink blue. Pie/donut charts retain a rainbow palette (categorical distinction is the job).</p>

    <!-- 8. Authentic Lotus brand comparison -->
    <div class="er-section-heading">Old palette vs. authentic Lotus brand</div>
    <div class="row g-3">
        <div class="col-md-6">
            <div class="er-compare-card">
                <strong>Green</strong>
                <div class="er-compare-swatch-pair">
                    <div class="er-compare-swatch" style="background: #3f8704;">old<br>#3f8704</div>
                    <div class="er-compare-swatch" style="background: #00563F;">new<br>#00563F</div>
                </div>
                <p class="text-muted small mb-0">Pivoted from a bright leaf-green (no Lotus provenance) to Classic Team Lotus British Racing Green &mdash; the Type 26/26R racing heritage color.</p>
            </div>
        </div>
        <div class="col-md-6">
            <div class="er-compare-card">
                <strong>Yellow</strong>
                <div class="er-compare-swatch-pair">
                    <div class="er-compare-swatch" style="background: #ffc107;">old<br>#ffc107</div>
                    <div class="er-compare-swatch" style="background: #FFF200; color: #010101; text-shadow: none;">new<br>#FFF200</div>
                </div>
                <p class="text-muted small mb-0">Pivoted from Bootstrap amber to the live Lotus brand yellow extracted from lotuscars.com/emira &mdash; brighter, more saturated, used for accent borders and chart highlights.</p>
            </div>
        </div>
    </div>

    <!-- 9. Nested card levels -->
    <div class="er-section-heading">Nested card levels (L1 → L4)</div>
    <p class="text-muted mb-3">Use these four levels whenever cards are embedded within cards. Each step down reduces visual weight, keeping the BRG brand anchor at the outermost level.</p>
    <div class="card registry-card mb-4">
        <div class="card-header card-header-er-primary">
            <h4 class="mb-0 card-header-er-primary-text">
                <i class="fas fa-layer-group me-2"></i> L1 — Section Card
                <small class="ms-2 card-header-er-primary-text fw-normal">Top-level page section. Uses <code style="color:rgba(255,255,255,0.7)">card-header-er-primary</code>.</small>
            </h4>
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">L1 is the anchor for any nested structure. Everything below it steps down in visual weight.</p>

            <!-- L2 -->
            <div class="card border mb-3">
                <div class="card-header card-header-er-l2">
                    <h5 class="mb-0 card-header-er-l2-text">
                        <i class="fas fa-object-group me-2"></i> L2 — Group / Subsection
                        <small class="ms-2 text-muted fw-normal">Uses <code>card-header-er-l2</code>. For grouping within a section (e.g. duplicate car group, report category).</small>
                    </h5>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3 small">L2 uses a solid light BRG-tinted background (#e8f0ed) with a 4 px BRG left-border accent. Heading text is dark for maximum contrast on the light background.</p>

                    <div class="row">
                        <!-- L3 item A -->
                        <div class="col-md-6 mb-3">
                            <div class="card border">
                                <div class="card-header card-header-er-l3">
                                    <h6 class="mb-0 card-header-er-l3-text">
                                        <i class="fas fa-file-alt me-1"></i> L3 — Item / Record
                                        <small class="ms-2 text-muted fw-normal">Uses <code>card-header-er-l3</code>.</small>
                                    </h6>
                                </div>
                                <div class="card-body p-2">
                                    <p class="small text-muted mb-2">L3 uses <code>--er-neutral-light</code> background and a 3 px muted grey left border. For individual records, cars, or list items within a group.</p>

                                    <!-- L4 -->
                                    <div class="card border-0 mb-0">
                                        <div class="card-header card-header-er-l4">
                                            <span class="card-header-er-l4-text">
                                                <i class="fas fa-info-circle me-1"></i> L4 — Detail Panel &mdash; Uses <code>card-header-er-l4</code>
                                            </span>
                                        </div>
                                        <div class="card-body p-2 bg-white">
                                            <p class="small text-muted mb-0">L4 is transparent with a hairline divider. Header uses small uppercase label text. For supplementary detail panels nested inside an item.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- L3 item B (second item in the group) -->
                        <div class="col-md-6 mb-3">
                            <div class="card border">
                                <div class="card-header card-header-er-l3">
                                    <h6 class="mb-0 card-header-er-l3-text">
                                        <i class="fas fa-file-alt me-1"></i> L3 — Second Item in Same Group
                                    </h6>
                                </div>
                                <div class="card-body p-2">
                                    <p class="small text-muted mb-2">Multiple L3 cards can exist within one L2 group.</p>
                                    <div class="card border-0 mb-0">
                                        <div class="card-header card-header-er-l4">
                                            <span class="card-header-er-l4-text">
                                                <i class="fas fa-cog me-1"></i> L4 — Detail Panel
                                            </span>
                                        </div>
                                        <div class="card-body p-2 bg-white">
                                            <p class="small text-muted mb-0">L4 is the innermost level — keep content concise.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Usage guidance -->
            <div class="alert alert-primary mb-0">
                <strong>Usage rules:</strong>
                <ul class="mb-0 mt-1 small">
                    <li><strong>L1</strong> <code>card-header-er-primary</code> — one per page section or tab pane. Never nest L1 inside L1.</li>
                    <li><strong>L2</strong> <code>card-header-er-l2</code> — groups within a section (duplicate groups, report categories, tool sub-areas).</li>
                    <li><strong>L3</strong> <code>card-header-er-l3</code> — individual records or items within an L2 group.</li>
                    <li><strong>L4</strong> <code>card-header-er-l4</code> — supplementary detail panels inside an L3 item. Skip if content is simple prose.</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- 10. Navigation patterns -->
    <div class="er-section-heading">Navigation</div>
    <div class="card mb-2">
        <div class="card-body p-0">
            <ul class="us_menu dark" style="margin:0; padding: 0.75rem 1.25rem; display:flex; gap:1.5rem; list-style:none; align-items:center;">
                <li><a href="#" style="color:#fff; text-decoration:none;">List Cars</a></li>
                <li class="active"><a href="#" style="color:#fff; text-decoration:none;">Statistics</a></li>
                <li><a href="#" style="color:#fff; text-decoration:none;">Reference</a></li>
                <li style="margin-left:auto;">
                    <a href="#" class="btn btn-er-yellow btn-sm">
                        <i class="fa fa-plus-square"></i> Register
                    </a>
                </li>
                <li><a href="#" style="color:#fff; text-decoration:none;">Log In</a></li>
            </ul>
        </div>
    </div>
    <p class="text-muted small mb-3">
        Active section carries a 3px Lotus Yellow underline (<code>--er-accent</code>)
        and bolder weight; matching is by path prefix so detail pages still
        highlight their parent section. Public-nav Register uses
        <code>btn btn-er-yellow btn-sm</code> &mdash; solid Lotus Yellow with
        <code>--er-on-accent</code> text for maximum CTA visibility on the dark
        nav, hover darkens to <code>--er-accent-dark</code>. Distinct from the
        logged-in <code>btn-primary</code> Add Car so the two states read as
        separate calls to action.
    </p>

    <!-- 11. Token reference table -->
    <div class="er-section-heading">Token reference</div>
    <div class="table-responsive">
        <table class="table table-sm er-token-table">
            <thead>
                <tr>
                    <th style="width: 50px;">Swatch</th>
                    <th>Token</th>
                    <th>Hex</th>
                    <th>WCAG vs #fff</th>
                    <th>Use</th>
                </tr>
            </thead>
            <tbody>
                <tr><td><span class="er-token-swatch" style="background: #00563F;"></span></td><td><code>--er-primary</code></td><td>#00563F</td><td>8.4:1 AAA</td><td>Primary buttons, card headers, brand</td></tr>
                <tr><td><span class="er-token-swatch" style="background: #003D2C;"></span></td><td><code>--er-primary-dark</code></td><td>#003D2C</td><td>12.1:1 AAA</td><td>Hover / active / focus rings</td></tr>
                <tr><td><span class="er-token-swatch" style="background: #E6EFEC;"></span></td><td><code>--er-primary-light</code></td><td>#E6EFEC</td><td>1.1:1 (bg)</td><td>Subtle tints, table hover</td></tr>
                <tr><td><span class="er-token-swatch" style="background: #FFF200;"></span></td><td><code>--er-accent</code></td><td>#FFF200</td><td>1.07:1 ❌ text</td><td>Lotus Yellow &mdash; graphic / border / fill ONLY, never carries text on white</td></tr>
                <tr><td><span class="er-token-swatch" style="background: #B8860B;"></span></td><td><code>--er-warning</code></td><td>#B8860B</td><td>4.6:1 AA</td><td>Warning / Unverified badge (replaces <code>#ffc107</code>)</td></tr>
                <tr><td><span class="er-token-swatch" style="background: #A52218;"></span></td><td><code>--er-danger</code></td><td>#A52218</td><td>6.4:1 AA</td><td>Destructive actions</td></tr>
                <tr><td><span class="er-token-swatch" style="background: #0B5394;"></span></td><td><code>--er-link</code></td><td>#0B5394</td><td>8.6:1 AAA</td><td>Hyperlinks only</td></tr>
                <tr><td><span class="er-token-swatch" style="background: #073763;"></span></td><td><code>--er-link-hover</code></td><td>#073763</td><td>11.4:1 AAA</td><td>Link hover / visited</td></tr>
                <tr><td><span class="er-token-swatch" style="background: #6C757D;"></span></td><td><code>--er-neutral</code></td><td>#6C757D</td><td>4.7:1 AA</td><td>Muted text, secondary UI</td></tr>
                <tr><td><span class="er-token-swatch" style="background: #F4F5F3;"></span></td><td><code>--er-neutral-light</code></td><td>#F4F5F3</td><td>1.05:1 (bg)</td><td>Page bg tint, table stripes</td></tr>
                <tr><td><span class="er-token-swatch" style="background: #3B413D;"></span></td><td><code>--er-neutral-dark</code></td><td>#3B413D</td><td>9.4:1 AAA</td><td>Hero banners, dark sections</td></tr>
                <tr><td><span class="er-token-swatch" style="background: #010101;"></span></td><td><code>--er-true-black</code></td><td>#010101</td><td>20.9:1 AAA</td><td>Authentic Lotus black &mdash; text on yellow</td></tr>
            </tbody>
        </table>
    </div>

    <div class="alert alert-info mt-4">
        <strong>Implementation note:</strong> tokens are defined in
        <code>usersc/templates/customizer.css</code>. Bootstrap utility classes
        (<code>btn-primary</code>, <code>bg-primary</code>,
        <code>text-primary</code>, <code>bg-info</code>) inherit automatically
        via <code>--bs-*</code> variable overrides. Only the docs index pages,
        Chassis Validation Racing Archive header, and chart palette JSON in
        <code>statistics.js</code> require targeted edits in Phase B.
    </div>

    <!-- 12. Document content (static guide pages) -->
    <div class="er-section-heading">Document content (<code>.document-content</code>)</div>
    <p class="text-muted mb-3">
        Guide pages (<code>docs/guides/</code>) render static HTML inlined as PHP heredocs.
        Because the heredoc content is echoed as a raw string rather than processed as a PHP template,
        the individual elements (<code>&lt;h1&gt;</code>, <code>&lt;table&gt;</code>,
        <code>&lt;code&gt;</code>, etc.) cannot receive Bootstrap utility classes inline.
        <code>docs/assets/document-content.css</code> provides scoped typography for those raw elements
        within the <code>.document-content</code> wrapper without touching global styles. Breadcrumb styles are not included here — Bootstrap&rsquo;s
        <code>.breadcrumb</code> component handles those via the global <code>--bs-link-color</code>
        override in <code>customizer.css</code>.
    </p>
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header card-header-er-primary">
                    <i class="fas fa-book me-2"></i> Guide page &mdash; <code>.document-content</code>
                </div>
                <div class="card-body">
                    <div class="document-content">
                        <h1>Section heading</h1>
                        <h2>Subsection</h2>
                        <h3>Sub-subsection</h3>
                        <p>Body text with an <a href="#">inline link</a> and <code>inline code</code>.</p>
                        <pre><code>// Code block example
function example(): string {
    return 'hello';
}</code></pre>
                        <blockquote>A blockquote pulling out an important note.</blockquote>
                        <table>
                            <thead><tr><th>Column A</th><th>Column B</th></tr></thead>
                            <tbody>
                                <tr><td>Row 1A</td><td>Row 1B</td></tr>
                                <tr><td>Row 2A</td><td>Row 2B</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 13. Email color reference -->
    <div class="er-section-heading">Email colors (use hex — CSS vars don't work in email clients)</div>
    <p class="text-muted mb-3">
        CSS custom properties (<code>--er-primary</code> etc.) are not supported by email clients. Always
        use literal hex values when writing inline email CSS. The table below maps each design token to
        its hex equivalent for use in email templates. See
        <code>docs/development/EMAIL_SYSTEM.md</code> for the full template structure.
    </p>
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <table class="table table-sm er-token-table">
                <thead>
                    <tr><th>Token</th><th>Hex</th><th>Email use</th></tr>
                </thead>
                <tbody>
                    <tr><td><span class="er-token-swatch" style="background:#00563F;"></span> <code>--er-primary</code></td><td><code>#00563F</code></td><td>Header bg, primary buttons</td></tr>
                    <tr><td><span class="er-token-swatch" style="background:#003D2C;"></span> <code>--er-primary-dark</code></td><td><code>#003D2C</code></td><td>Button hover state</td></tr>
                    <tr><td><span class="er-token-swatch" style="background:#A52218;"></span> <code>--er-danger</code></td><td><code>#A52218</code></td><td>Warning boxes, urgent notices</td></tr>
                    <tr><td><span class="er-token-swatch" style="background:#0B5394;"></span> <code>--er-link</code></td><td><code>#0B5394</code></td><td>Hyperlinks</td></tr>
                    <tr><td><span class="er-token-swatch" style="background:#6C757D;"></span> <code>--er-neutral</code></td><td><code>#6C757D</code></td><td>Footer text, muted info</td></tr>
                    <tr><td><span class="er-token-swatch" style="background:#3B413D;"></span> <code>--er-neutral-dark</code></td><td><code>#3B413D</code></td><td>Header text on primary bg</td></tr>
                    <tr><td><span class="er-token-swatch" style="border:1px solid #ccc; background:#333333;"></span> n/a</td><td><code>#333333</code></td><td>Body text</td></tr>
                    <tr><td><span class="er-token-swatch" style="border:1px solid #ccc; background:#f8f9fa;"></span> n/a</td><td><code>#f8f9fa</code></td><td>Footer background</td></tr>
                    <tr><td><span class="er-token-swatch" style="border:1px solid #ccc; background:#dee2e6;"></span> n/a</td><td><code>#dee2e6</code></td><td>Borders / dividers</td></tr>
                </tbody>
            </table>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header" style="background:#00563F; color:#fff; font-family:Arial,sans-serif; text-align:center; padding:20px;">
                    <strong style="font-size:1.1rem;">Lotus Elan Registry</strong><br>
                    <small style="opacity:0.85;">[Email Subject]</small>
                </div>
                <div class="card-body" style="font-family:Arial,sans-serif; color:#333333; padding:20px; font-size:0.9rem;">
                    <p>Email content area. Links appear in <a href="#" style="color:#0B5394;">#0B5394 link blue</a>. Primary action buttons use <code>#00563F</code>.</p>
                    <div style="text-align:center; margin:16px 0;">
                        <span style="display:inline-block; padding:10px 20px; background:#00563F; color:#fff; border-radius:4px; font-weight:bold; font-size:0.85rem;">Primary Button</span>
                    </div>
                    <div style="background:#fff3cd; border:1px solid #A52218; border-radius:4px; padding:12px; margin:12px 0; font-size:0.8rem;">
                        Warning box uses <code>#A52218</code> border.
                    </div>
                </div>
                <div class="card-footer" style="background:#f8f9fa; border-top:1px solid #dee2e6; color:#6C757D; text-align:center; font-family:Arial,sans-serif; font-size:0.8rem; padding:14px;">
                    The Lotus Elan Registry Team &mdash; <a href="https://elanregistry.org" style="color:#6C757D;">elanregistry.org</a>
                </div>
            </div>
        </div>
    </div>

</div>

<link rel="stylesheet" href="<?= $us_url_root ?>docs/assets/document-content.css">
<script nonce="<?= htmlspecialchars($userspice_nonce ?? '') ?>" src="<?= $us_url_root ?>users/js/chart.umd.min.js"></script>
<script nonce="<?= htmlspecialchars($userspice_nonce ?? '') ?>">
(function () {
    if (typeof Chart === 'undefined') return;

    var brg    = getComputedStyle(document.documentElement).getPropertyValue('--er-primary').trim() || '#00563F';
    var yellow = getComputedStyle(document.documentElement).getPropertyValue('--er-accent').trim()   || '#FFF200';
    var ink    = getComputedStyle(document.documentElement).getPropertyValue('--er-link').trim()     || '#0B5394';
    var inkRgb = getComputedStyle(document.documentElement).getPropertyValue('--er-link-rgb').trim() || '11, 83, 148';

    var bar = document.getElementById('er-bar-demo');
    if (bar) {
        new Chart(bar.getContext('2d'), {
            type: 'bar',
            data: {
                labels: ['1963', '1964', '1965', '1966', '1967', '1968', '1969'],
                datasets: [{
                    label: 'Cars built',
                    data: [180, 245, 320, 410, 290, 215, 175],
                    backgroundColor: [brg, brg, brg, yellow, brg, brg, brg],
                    borderColor: brg,
                    borderWidth: 0
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        });
    }

    var line = document.getElementById('er-line-demo');
    if (line) {
        new Chart(line.getContext('2d'), {
            type: 'line',
            data: {
                labels: ['2018', '2019', '2020', '2021', '2022', '2023', '2024', '2025', '2026'],
                datasets: [{
                    label: 'Total registered',
                    data: [820, 905, 970, 1040, 1115, 1180, 1220, 1245, 1259],
                    borderColor: ink,
                    backgroundColor: 'rgba(' + inkRgb + ', 0.1)',
                    fill: true,
                    tension: 0.3
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        });
    }
})();
</script>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
