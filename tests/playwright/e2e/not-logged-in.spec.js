const { test, expect } = require('@playwright/test');
const { CAR_ID_STANDARD, CAR_ID_REDIRECT_TEST } = require('../fixtures.js');
const { assertPageTitle } = require('../auth-helper.js');

// True when running against Local/Dev MAMP rather than a deployed Test/Prod
// environment. E2E_AUTH_TIER is set to 'test'/'prod' only by
// playwright.config.test.js/.prod.js — unset under Local/Dev's
// playwright.config.js/.dev.js. Local MAMP does not reliably apply
// .htaccess Redirect/RedirectMatch/RewriteRule directives (confirmed
// directly: even a bare root-path request that should 301 instead 404s) —
// tests asserting that behavior only run against the real deployed
// environment (#2055). A second, narrower environment gap (case-insensitive
// local filesystem) is documented separately at its one call site below.
const IS_LOCAL_DEV_TIER = !process.env.E2E_AUTH_TIER;
const HTACCESS_SKIP_REASON = '.htaccess rules are not reliably applied on Local/Dev MAMP (#2055)';

// Helper: skip test if running on Local/Dev (for .htaccess-dependent tests)
const skipOnLocalDev = (condition = IS_LOCAL_DEV_TIER, reason = HTACCESS_SKIP_REASON) => {
  test.skip(condition, reason);
};

// Helper: strip leading slash from a path before resolving against baseURL
// (new URL() joining a leading-slash path against baseURL discards
// baseURL's own path segment under a non-root mount, the exact bug fixed in #2055)
const stripLeadingSlash = (path) => path.replace(/^\//, '');

// Helper: normalize Location headers (absolute URLs or relative paths) to
// path + query for comparison
const toLocationPath = (location) =>
  location.startsWith('http')
    ? new URL(location).pathname + new URL(location).search
    : location;

test.describe('Elan Registry - All Pages (Not Logged In)', () => {
  // Skip these tests if running in logged-in project
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'not-logged-in') {
      testInfo.skip();
    }
  });
  const pages = [
    {
      path: '',
      name: 'Home',
      selector: 'h1',
      expectedText: 'Lotus Elan Registry',
    },
    {
      path: 'app/owner/cars/index.php',
      name: 'List Cars',
      selector: 'h2',
      expectedText: 'Registry Cars',
      expectedTitle: 'Browse All Registered Cars',
      expectedDescription: 'Search and browse every Lotus Elan and Elan Plus 2 currently registered, with chassis, model, and ownership history details.',
    },
    {
      path: 'users/join.php',
      name: 'Register',
      selector: 'h1.h3.text-primary',
      expectedText: 'Join the Lotus Elan Registry',
    },
    {
      path: 'app/owner/reports/statistics.php',
      name: 'Statistics',
      selector: 'h1',
      expectedText: 'Registry Analytics & Statistics',
      expectedTitle: 'Registry Analytics & Statistics',
      expectedDescription: 'Explore production trends, geographic distribution, paint colour popularity, and data-completeness statistics across the Lotus Elan Registry.',
    },
    {
      path: 'docs/reference/identification-guide.php',
      name: 'Identification Guide',
      selector: 'h1',
      expectedText: 'Lotus Elan Identification Guide',
      expectedTitle: 'How to Identify a Lotus Elan or Elan Plus 2',
      expectedDescription: 'Identify your Lotus Elan or Elan Plus 2 variant by chassis number, body style, and distinguishing features like Roadster, Drophead, and Coupé.',
    },
    {
      path: 'app/owner/cars/factory.php',
      name: 'Factory Data',
      selector: 'h2',
      expectedText: 'Elan Factory Information',
      expectedTitle: 'Lotus Elan Factory Build Records — Registry Factory Data',
      expectedDescription: 'Browse original factory build records for registered Lotus Elan and Elan Plus 2 cars, cross-referenced against registry ownership data.',
    },
    {
      path: 'docs/',
      name: 'Docs Index',
      selector: 'h1',
      expectedText: 'Documentation',
      expectedTitle: 'Documentation Hub',
      expectedDescription: 'Guides, technical references, and car histories for Lotus Elan and Elan Plus 2 owners, organized by topic.',
    },
    {
      path: 'docs/reference/index.php',
      name: 'Reference Index',
      selector: 'h1',
      expectedText: 'Technical Reference',
      expectedTitle: 'Technical Reference Library',
      expectedDescription: 'Workshop manuals, parts lists, technical articles, and identification guides for the Lotus Elan and Elan Plus 2.',
    },
    {
      path: 'docs/reference/chassis-validation.php',
      name: 'Chassis Validation',
      selector: 'h1',
      expectedText: 'Chassis Validation Rules',
      expectedTitle: 'Lotus Elan Chassis Number Formats',
      expectedDescription: 'Reference guide to the chassis numbering formats the Lotus Elan Registry recognizes and validates during car registration.',
    },
    {
      path: 'docs/reference/paint-colors.php',
      name: 'Paint Colors',
      selector: 'h1',
      expectedText: 'Lotus Elan',
      expectedTitle: 'Lotus Elan Paint Codes — Official Colour Chart L01–L26',
      expectedDescription: 'Complete reference for Lotus Elan and Elan Plus 2 paint codes L01–L26 with colour chips, date ranges, model applicability, and early pre-code colours.',
    },
    {
      path: 'docs/reference/technical-articles.php',
      name: 'Technical Articles',
      selector: 'h1',
      expectedText: 'Technical Articles',
      expectedTitle: 'Lotus Elan Technical Articles — Club Lotus Reference Archive',
      expectedDescription: 'Historical Club Lotus technical articles covering maintenance, engineering, and restoration topics for the Lotus Elan and Elan Plus 2.',
    },
    {
      path: 'docs/reference/workshop.php',
      name: 'Workshop & Parts',
      selector: 'h1',
      expectedText: 'Workshop',
      expectedTitle: 'Lotus Elan Workshop Manuals & Parts References',
      expectedDescription: 'Workshop manuals, parts lists, and engine reference documents for maintaining and restoring the Lotus Elan and Elan Plus 2.',
    },
    {
      path: 'docs/car-stories.php',
      name: 'Car Stories',
      selector: 'h1',
      expectedText: 'Car Stories',
      expectedTitle: 'Lotus Elan Car Stories — Registry Ownership Histories',
      expectedDescription: 'Read individual ownership histories and stories for Lotus Elan and Elan Plus 2 cars in the registry.',
    },
    {
      path: 'docs/stories/brian_walton/index.php',
      name: 'Brian Walton Story',
      selector: 'h1',
      expectedText: 'Elan Experimental Rally Car',
    },
    {
      path: 'docs/stories/SGO_2F/index.php',
      name: 'SGO 2F Story',
      selector: 'h1',
      expectedText: 'SGO 2F',
    },
    {
      path: 'docs/stories/type26register.php',
      name: 'Type 26 Register',
      selector: 'h2',
      expectedText: 'type26register.com',
    },
    {
      path: 'docs/guides/index.php',
      name: 'Owner Guides',
      selector: 'h1',
      expectedText: 'Owner Guides',
      expectedTitle: 'Owner Guides',
      expectedDescription: 'Practical guides for Lotus Elan and Elan Plus 2 owners, covering registration, transfers, and car management.',
    },
    {
      path: 'docs/guides/car-transfer-faq.php',
      name: 'Car Transfer FAQ',
      selector: 'h1',
      expectedText: 'Car Transfer FAQ',
    },
    {
      path: 'docs/pdf-viewer.php',
      name: 'PDF Viewer',
      selector: 'h1',
      expectedText: 'Document Viewer',
      expectedTitle: 'Document Viewer — Lotus Elan Registry Reference Library',
      expectedDescription: 'View and download PDF reference documents from the Lotus Elan Registry technical library, including workshop manuals, parts lists, and technical articles.',
    },
    {
      path: `docs/pdf-viewer.php?subdir=reference&doc=${encodeURIComponent('All Elan and Elan Plus 2 Paint Codes.pdf')}`,
      name: 'PDF Viewer — Paint Codes',
      selector: 'h1',
      // h1 renders the metadata-map title when the doc/subdir resolve to a
      // known reference PDF (#1538) — not the raw filename, which is what
      // pathinfo()['filename'] would give (no ".pdf" extension, unlike this
      // string).
      expectedText: 'Lotus Elan Paint Codes PDF — Official Factory Reference',
      expectedTitle: 'Lotus Elan Paint Codes PDF — Official Factory Reference',
      expectedDescription: 'Official factory paint codes for all Elan and Plus 2 models — downloadable PDF for offline reference.',
    },
    {
      path: 'usersc/login.php',
      name: 'Log In',
      selector: '.modal-header',
      expectedText: 'Please Log In',
      isLoginPage: true,
    },
    {
      path: 'users/forgot_password.php',
      name: 'Forgot Password',
      selector: 'h2',
      expectedText: 'Reset Password',
    },
  ];

  test('page-specific titles are unique across all pages (#1432)', () => {
    const titles = pages.map(p => p.expectedTitle).filter(Boolean);
    expect(new Set(titles).size).toBe(titles.length);
  });

  test('page-specific descriptions are unique across all pages (#1432)', () => {
    const descriptions = pages.map(p => p.expectedDescription).filter(Boolean);
    expect(new Set(descriptions).size).toBe(descriptions.length);
  });

  pages.forEach(({ path, name, selector, expectedText, isLoginPage, expectedTitle, expectedDescription }) => {
    test(`should be able to reach ${name} page`, async ({ page }) => {
      const response = await page.goto(path);

      // Layer 1: HTTP response must be successful
      expect(response.status()).toBeLessThan(400);

      await page.waitForLoadState('domcontentloaded');

      // Layer 2: Must not have been redirected to the login page
      // (skip this check for the login page itself)
      if (!isLoginPage) {
        expect(page.url()).not.toContain('login.php');
      }

      // Layer 3: Verify expected page content is present
      if (selector && expectedText) {
        await expect(page.locator(selector)).toContainText(expectedText);
      }

      // Layer 4: Verify page-specific title and meta description (#1432)
      if (expectedTitle) {
        await assertPageTitle(page, { expectedTitle, expectedDescription, checkSocialMeta: true });
      }

      console.log(`✓ Successfully reached: ${name} (${path})`);
    });
  });
});

// paint-colors.php's page-specific <title>/meta description assertions
// (#1372) are now covered by the table-driven pages[] array above via
// expectedTitle/expectedDescription (#1432). The regression guard below
// (confirming the generic site-wide title/description still renders on
// pages outside #1432's scope) is retained, retargeted at
// car-transfer-faq.php since identification-guide.php now has a
// page-specific title of its own.
test('docs/guides/car-transfer-faq.php still renders the generic site title/description (regression guard) (#1432)', async ({ page }, testInfo) => {
  if (testInfo.project.name !== 'not-logged-in') {
    testInfo.skip();
  }

  await page.goto('docs/guides/car-transfer-faq.php');

  await expect(page).toHaveTitle(/^Lotus Elan Registry$/);

  const description = await page
    .locator('meta[name="description"]')
    .getAttribute('content');
  expect(description).toContain('Registry for the Lotus Elan (1963-1973) and Elan Plus 2 (1967-1974)');

  // og:title/twitter:title must still fall back to $site_title (the generic
  // site name) on pages that don't set $pageTitle — this is the actual new
  // conditional in usersc/includes/head_tags.php ($og_title) introduced by
  // #1432, and this is its only regression coverage.
  const ogTitle = await page.locator('meta[property="og:title"]').getAttribute('content');
  expect(ogTitle).toBe('Lotus Elan Registry');
  const twitterTitle = await page.locator('meta[name="twitter:title"]').getAttribute('content');
  expect(twitterTitle).toBe('Lotus Elan Registry');
});

test.describe('Internal Links Discovery and Testing (Not Logged In)', () => {
  const pages = [
    { path: '', name: 'Home' },
    { path: 'app/owner/cars/index.php', name: 'List Cars' },
    { path: 'users/join.php', name: 'Register' },
    { path: 'app/owner/reports/statistics.php', name: 'Statistics' },
    { path: 'docs/reference/identification-guide.php', name: 'Identification Guide' },
    { path: 'app/owner/cars/factory.php', name: 'Factory Data' },
    { path: 'docs/reference/index.php', name: 'Reference Index' },
    { path: 'docs/car-stories.php', name: 'Car Stories' },
    { path: 'docs/guides/index.php', name: 'Owner Guides' },
    { path: 'usersc/login.php', name: 'Log In' },
    { path: 'users/forgot_password.php', name: 'Forgot Password' },
  ];

  test('find all internal links across all pages (excluding header)', async ({ page }) => {
    const allInternalLinks = new Map(); // Use Map to track unique links with their source pages

    for (const { path, name } of pages) {
      await page.goto(path);
      await page.waitForLoadState('domcontentloaded');

      // Get all links NOT in the header/nav
      const contentLinks = await page.locator('a:not(header a, nav a)').all();

      for (const link of contentLinks) {
        const href = await link.getAttribute('href');
        const text = await link.textContent();

        // Filter for internal links (elanregistry.org or relative paths)
        // Security: Use proper URL parsing to validate hostname, not substring matching
        let isInternalLink = href && href.startsWith('/');
        if (href && href.startsWith('http')) {
          try {
            const url = new URL(href);
            const siteHost = new URL(page.url()).hostname;
            isInternalLink = url.hostname === siteHost || url.hostname === `www.${siteHost}`;
          } catch (_e) {
            isInternalLink = false;
          }
        }

        if (isInternalLink) {
          // Convert to relative path if it's a full URL. A root-relative
          // href (e.g. '/docs/...') is NOT stripped of its leading slash
          // like the literals fixed elsewhere in this file for #2055 —
          // these are scraped from the app's own rendered markup, which
          // UserSpice's $us_url_root already mount-prefixes (e.g.
          // '/ElanRegistry/Registry2/docs/...'), so re-resolving against a
          // non-root baseURL at line ~450/~507 below is a correct no-op,
          // not the leading-slash bug. This only holds because every href
          // the app emits is mount-prefixed — a hand-written or
          // third-party partial emitting a genuinely origin-root href
          // would silently navigate to the wrong mount here.
          const relativePath = href.startsWith('http')
            ? new URL(href).pathname
            : href;

          // Track which page this link was found on
          if (!allInternalLinks.has(relativePath)) {
            allInternalLinks.set(relativePath, {
              url: relativePath,
              text: text?.trim(),
              foundOn: [name],
            });
          } else {
            // Add this page to the list of pages where this link was found
            const existing = allInternalLinks.get(relativePath);
            if (!existing.foundOn.includes(name)) {
              existing.foundOn.push(name);
            }
          }
        }
      }

      console.log(`✓ Scanned ${name} (${path})`);
    }

    console.log('\n=== All Internal Links Found (Excluding Header) ===');
    console.log(`Total unique internal links: ${allInternalLinks.size}\n`);

    const sortedLinks = Array.from(allInternalLinks.values()).sort((a, b) =>
      a.url.localeCompare(b.url)
    );

    sortedLinks.forEach((link, index) => {
      console.log(`${index + 1}. ${link.url}`);
      console.log(`   Text: ${link.text || '(no text)'}`);
      console.log(`   Found on: ${link.foundOn.join(', ')}\n`);
    });

    // Verify we found at least some links
    expect(allInternalLinks.size).toBeGreaterThan(0);
  });

  test('test all unique internal links found across all pages', async ({ page }) => {
    test.setTimeout(120000); // 2 minutes for testing 50+ links on production
    const allInternalLinks = new Map();
    // All discovery pages except Forgot Password count as public pages for warning purposes
    const publicPageNames = pages.filter(p => p.name !== 'Forgot Password').map(p => p.name);

    console.log('\n=== Discovering Internal Links ===');
    for (const { path, name } of pages) {
      await page.goto(path);
      await page.waitForLoadState('domcontentloaded');

      const contentLinks = await page.locator('a:not(header a, nav a)').all();

      for (const link of contentLinks) {
        const href = await link.getAttribute('href');

        // Security: Use proper URL parsing to validate hostname, not substring matching
        let isInternalLink = href && href.startsWith('/');
        if (href && href.startsWith('http')) {
          try {
            const url = new URL(href);
            const siteHost = new URL(page.url()).hostname;
            isInternalLink = url.hostname === siteHost || url.hostname === `www.${siteHost}`;
          } catch (_e) {
            isInternalLink = false;
          }
        }

        if (isInternalLink) {
          // Convert to relative path if it's a full URL
          const relativePath = href.startsWith('http')
            ? new URL(href).pathname
            : href;

          // Track which page this link was found on
          if (!allInternalLinks.has(relativePath)) {
            allInternalLinks.set(relativePath, {
              url: relativePath,
              foundOn: [name]
            });
          } else {
            const existing = allInternalLinks.get(relativePath);
            if (!existing.foundOn.includes(name)) {
              existing.foundOn.push(name);
            }
          }
        }
      }
    }

    const uniqueLinks = Array.from(allInternalLinks.values()).map(link => link.url).sort();

    // Separate links into navigable pages and downloadable files
    const downloadExtensions = ['.pdf', '.zip', '.doc', '.docx', '.xls', '.xlsx', '.jpg', '.jpeg', '.png', '.gif', '.svg'];
    const navigableLinks = [];
    const downloadableLinks = [];

    uniqueLinks.forEach(link => {
      const isDownloadable = downloadExtensions.some(ext => link.toLowerCase().endsWith(ext));
      if (isDownloadable) {
        downloadableLinks.push(link);
      } else {
        // Exclude /app/owner/cars/details.php links except for CAR_ID_STANDARD (to avoid testing many individual car pages)
        if (link.includes('/app/owner/cars/details.php') && !link.includes(`car_id=${CAR_ID_STANDARD}`)) {
          // Skip this link - it's a car details page other than CAR_ID_STANDARD
          return;
        }
        navigableLinks.push(link);
      }
    });

    console.log(`\n=== Testing Links ===`);
    console.log(`Navigable pages: ${navigableLinks.length}`);
    console.log(`Downloadable files: ${downloadableLinks.length}`);
    console.log(`Total unique links: ${uniqueLinks.length}`);
    console.log(`Links from public pages: ${allInternalLinks.size}\n`);

    let successCount = 0;
    let failCount = 0;
    let protectedLinksFromPublic = []; // Track protected pages linked from public pages

    // Test navigable pages
    console.log('=== Testing Navigable Pages ===\n');
    for (const linkPath of navigableLinks) {
      try {
        const response = await page.goto(linkPath);
        const status = response.status();
        const currentUrl = page.url();
        const redirectsToLogin = currentUrl.includes('login.php');

        if (status < 400) {
          if (redirectsToLogin) {
            // This link is protected (redirects to login)
            const linkData = allInternalLinks.get(linkPath);
            const foundOn = linkData ? linkData.foundOn : [];
            const foundOnPublic = foundOn.some(name => publicPageNames.includes(name));

            if (foundOnPublic) {
              // Protected link found on public page - log as warning
              protectedLinksFromPublic.push({
                link: linkPath,
                foundOn: foundOn
              });
              console.log(`⚠️  ${linkPath} - Protected (requires login, found on: ${foundOn.join(', ')})`);
            } else {
              // Protected link found on non-public pages - OK
              console.log(`✓ ${linkPath} - Protected (found on non-public pages)`);
              successCount++;
            }
          } else {
            successCount++;
            console.log(`✓ ${linkPath} - Status: ${status}`);
          }
        } else {
          failCount++;
          console.log(`✗ ${linkPath} - Status: ${status}`);
        }

        expect(status).toBeLessThan(400);
      } catch (error) {
        failCount++;
        console.log(`✗ ${linkPath} - Error: ${error.message}`);
        throw error;
      }
    }

    // Report protected links found on public pages
    if (protectedLinksFromPublic.length > 0) {
      console.log('\n=== Protected Pages Linked From Public Pages ===\n');
      protectedLinksFromPublic.forEach((item, index) => {
        console.log(`${index + 1}. ${item.link}`);
        console.log(`   Found on: ${item.foundOn.join(', ')}\n`);
      });
    }

    // Test downloadable files using fetch API
    console.log('\n=== Testing Downloadable Files ===\n');
    for (const linkPath of downloadableLinks) {
      try {
        const context = page.context();
        // Resolve against the current page's origin, not a hardcoded prod
        // host — otherwise test:e2e:test silently checks prod's files.
        const fullURL = linkPath.startsWith('http') ? linkPath : new URL(linkPath, page.url()).toString();

        // Use API request context to check if file exists without downloading
        const response = await context.request.head(fullURL);
        const status = response.status();

        if (status < 400) {
          successCount++;
          console.log(`✓ ${linkPath} - Status: ${status} (file exists)`);
        } else {
          failCount++;
          console.log(`✗ ${linkPath} - Status: ${status}`);
        }

        expect(status).toBeLessThan(400);
      } catch (error) {
        failCount++;
        console.log(`✗ ${linkPath} - Error: ${error.message}`);
        throw error;
      }
    }

    console.log(`\n=== Results ===`);
    console.log(`Total links tested: ${uniqueLinks.length}`);
    console.log(`Navigable pages: ${navigableLinks.length}`);
    console.log(`Downloadable files: ${downloadableLinks.length}`);
    console.log(`Successful: ${successCount}`);
    console.log(`Failed: ${failCount}`);
  });
});

test.describe('Redirect verification — GSC 404 and soft 404 cleanup (#1369)', () => {
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'not-logged-in') {
      testInfo.skip();
    }
    // Entire block asserts .htaccess Redirect/RedirectMatch behavior — Test/Prod only (#2055)
    skipOnLocalDev();
  });

  const redirects = [
    {
      from: 'docs/embed.php?doc=Elan_26_36_Workshop_Manual.pdf',
      to: '/docs/pdf-viewer.php?subdir=reference&doc=Elan_26_36_Workshop_Manual.pdf',
      label: 'embed.php soft 404 → pdf-viewer.php with subdir',
    },
    {
      from: 'docs/pdf-viewer.php?doc=Elan_26_36_Workshop_Manual.pdf',
      to: '/docs/pdf-viewer.php?subdir=reference&doc=Elan_26_36_Workshop_Manual.pdf',
      label: 'pdf-viewer.php single-param → two-param format',
    },
    {
      from: 'stories/type26register.com/index.html',
      to: '/docs/stories/type26register.com/index.html',
      label: 'stories/* migration',
    },
    // Legacy doc viewer removed in #911
    {
      from: 'docs/view.php?doc=IDENTIFICATION_GUIDE.md',
      to: '/docs/reference/identification-guide.php',
      label: 'docs/view.php IDENTIFICATION_GUIDE.md',
    },
    {
      from: 'docs/view.php?doc=CAR_TRANSFER_FAQ.md',
      to: '/docs/guides/car-transfer-faq.php',
      label: 'docs/view.php CAR_TRANSFER_FAQ.md',
    },
    {
      from: 'docs/guide-viewer.php?doc=CAR_TRANSFER_FAQ.md',
      to: '/docs/guides/car-transfer-faq.php',
      label: 'docs/guide-viewer.php CAR_TRANSFER_FAQ.md',
    },
    {
      from: 'docs/guide-viewer.php?doc=CAR_TRANSFER_USER_GUIDE.md',
      to: '/docs/guides/',
      label: 'docs/guide-viewer.php CAR_TRANSFER_USER_GUIDE.md',
    },
    {
      from: `app/car_details.php?car_id=${CAR_ID_REDIRECT_TEST}`,
      to: `/app/owner/cars/details.php?car_id=${CAR_ID_REDIRECT_TEST}`,
      label: '/app/car_details.php preserves car_id query string',
    },
    {
      from: 'app/identification.php',
      to: '/docs/reference/identification-guide.php',
      label: '/app/identification.php legacy path',
    },
    {
      from: 'app/list_cars.php',
      to: '/app/owner/cars/index.php',
      label: '/app/list_cars.php legacy path',
    },
    {
      from: 'list_cars.php',
      to: '/app/owner/cars/index.php',
      label: '/list_cars.php root-level legacy path',
    },
    {
      from: 'guide.php',
      to: '/docs/',
      label: '/guide.php legacy guide index',
    },
    // Duplicate PDF path: /docs/assets/ renamed to /docs/reference/assets/ in #715
    {
      from: 'docs/assets/Elan_26_36_Workshop_Manual.pdf',
      to: '/docs/reference/assets/Elan_26_36_Workshop_Manual.pdf',
      label: '/docs/assets/ → /docs/reference/assets/ (duplicate PDF path)',
    },
  ];

  redirects.forEach(({ from, to, label }) => {
    test(`301: ${label}`, async ({ request }) => {
      const response = await request.get(from, { maxRedirects: 0 });
      expect(response.status(), `Expected 301 for ${from}`).toBe(301);
      const location = response.headers()['location'] ?? '';
      const locationPath = toLocationPath(location);
      expect(locationPath, `Expected Location: ${to} for ${from}`).toBe(to);
    });
  });
});

// This block mixes .htaccess-dependent tests (each calls skipOnLocalDev()
// individually, since not every test here needs it) with plain
// static-file/direct-hit tests that don't. A new test added below that
// asserts real .htaccess Redirect/RedirectMatch/ErrorDocument behavior must
// call skipOnLocalDev() itself — nothing here enforces that structurally
// (#2055).
test.describe('Bare-directory 403s and docs/assets/ CSS relocation (#1539)', () => {
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'not-logged-in') {
      testInfo.skip();
    }
  });

  const redirects = [
    {
      from: 'app/owner/reports/',
      to: '/app/owner/reports/statistics.php',
      label: 'app/owner/reports/ bare-directory 403 → statistics.php',
    },
    {
      from: 'app/owner/',
      to: '/app/owner/cars/',
      label: 'app/owner/ bare-directory 403 → cars/',
    },
    {
      from: 'docs/stories/',
      to: '/docs/car-stories.php',
      label: 'docs/stories/ bare-directory 403 → car-stories.php',
    },
    {
      // The exact URL GSC originally flagged (Context section of #1539) — the
      // legacy #1040 bare-directory rule must land in a single hop, not chain
      // through /app/owner/reports/ first.
      from: 'app/reports/',
      to: '/app/owner/reports/statistics.php',
      label: 'app/reports/ (legacy, GSC-flagged) → statistics.php, single hop',
    },
  ];

  redirects.forEach(({ from, to, label }) => {
    test(`301: ${label}`, async ({ request, baseURL }) => {
      // .htaccess RedirectMatch behavior — Test/Prod only (#2055)
      skipOnLocalDev();
      const response = await request.get(from, { maxRedirects: 0 });
      expect(response.status(), `Expected 301 for ${from}`).toBe(301);
      const location = response.headers()['location'] ?? '';
      const locationPath = toLocationPath(location);
      expect(locationPath, `Expected Location: ${to} for ${from}`).toBe(to);

      // Follow through to the destination — a redirect to a page that's been
      // locked down to authenticated-only would silently send anonymous
      // visitors/crawlers into a login wall instead of the intended content,
      // and the assertions above alone wouldn't catch that regression. `to`
      // is an absolute path (asserted against the server's Location header
      // above) — strip its leading slash before resolving against baseURL (#2055).
      const followed = await request.get(new URL(stripLeadingSlash(to), baseURL).href);
      expect(followed.status(), `Expected 200 for redirect target ${to}`).toBe(200);
    });
  });

  test('regression guard: /app/owner/reports/statistics.php requested directly is NOT redirected (mod_alias prefix-match trap)', async ({ request }) => {
    // The bare-directory RedirectMatch above is anchored (^...$), but a naive
    // unanchored Redirect on /app/owner/reports/ would also prefix-match this
    // exact file path and mangle it into .../statistics.phpstatistics.php.
    // This guards that the anchored rule does NOT catch the file itself.
    const response = await request.get(`app/owner/reports/statistics.php`, { maxRedirects: 0 });
    expect(response.status()).toBe(200);
  });

  test('regression guard: /app/reports/statistics.php redirects in a single hop, not a 301->301 chain', async ({ request }) => {
    // .htaccess redirect-chain behavior — Test/Prod only (#2055)
    skipOnLocalDev();
    // Before this fix, the bare-directory rule for /app/reports/ could catch
    // this specific-file path first depending on rule order, chaining through
    // /app/owner/reports/ (itself now a 403->redirect) before finally landing
    // on statistics.php. The specific-file rule must be matched first so this
    // resolves in exactly one hop.
    const response = await request.get(`app/reports/statistics.php`, { maxRedirects: 0 });
    expect(response.status()).toBe(301);
    const location = response.headers()['location'] ?? '';
    const locationPath = toLocationPath(location);
    expect(locationPath).toBe('/app/owner/reports/statistics.php');
  });

  test('GET /app/ bare directory renders the branded error/500.php handler, not a raw server 403', async ({ request }) => {
    // .htaccess ErrorDocument 403 routing — Test/Prod only (#2055)
    skipOnLocalDev();
    // Options -Indexes with no index.php in app/ produces a genuine 403, but
    // .htaccess's ErrorDocument 403 (line 5) already routes that to the
    // branded handler — this is existing behavior, locked in as a regression
    // guard rather than new behavior from this PR. Status 403 alone would
    // also pass for Apache's bare default error page, so the body is checked
    // for the handler's known markup (title "Access Forbidden" + its
    // error-card wrapper class) to actually distinguish "branded" from
    // "raw" — this couples the test to error/500.php's copy, which is an
    // accepted, maintainable trade-off since that text is stable, non-dynamic
    // page chrome, not user data.
    const response = await request.get(`app/`, { maxRedirects: 0 });
    expect(response.status()).toBe(403);
    const body = await response.text();
    expect(body).toContain('error-card');
    expect(body).toContain('Access Forbidden');
  });

  test('GET /docs/assets/document-content.css (old path) redirects to docs/reference/assets/, which 404s — the file was moved, not copied', async ({ request, baseURL }) => {
    // .htaccess Redirect 301 behavior — Test/Prod only (#2055)
    skipOnLocalDev();
    // document-content.css was relocated to app/assets/css/, not copied. The
    // pre-existing blanket rule (Redirect 301 /docs/assets/ /docs/reference/assets/,
    // #1369) still fires for this now-nonexistent old path, since the rule
    // itself was untouched by this fix — it just no longer matters for this
    // file. This test locks in that the old path still 301s (unchanged
    // legacy behavior) rather than asserting a direct 404, which would be
    // incorrect given the blanket rule is still in place.
    const response = await request.get(`docs/assets/document-content.css`, { maxRedirects: 0 });
    expect(response.status()).toBe(301);
    const location = response.headers()['location'] ?? '';
    const locationPath = toLocationPath(location);
    expect(locationPath).toBe('/docs/reference/assets/document-content.css');

    // Follow the redirect: nothing was ever copied to docs/reference/assets/,
    // so the chain terminates in a 404, not a working asset. locationPath is
    // an absolute path (parsed from the server's Location header above) —
    // strip its leading slash before resolving against baseURL (#2055).
    const followed = await request.get(new URL(stripLeadingSlash(locationPath), baseURL).href);
    expect(followed.status()).toBe(404);
  });

  test('GET /app/assets/css/document-content.min.css (new path) resolves 200 with no redirect', async ({ request }) => {
    const response = await request.get(`app/assets/css/document-content.min.css`, { maxRedirects: 0 });
    expect(response.status()).toBe(200);
  });
});

// Same mixed-block caveat as #1539 above: new .htaccess-dependent tests must
// call skipOnLocalDev() themselves (#2055).
test.describe('GSC 404 cleanup redirects (#1409)', () => {
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'not-logged-in') {
      testInfo.skip();
    }
  });

  const redirects = [
    {
      from: 'docs/guide-viewer.php?doc=ADD_CAR_GUIDE.md',
      to: '/docs/guides/',
      label: 'docs/guide-viewer.php ADD_CAR_GUIDE.md',
    },
    {
      from: 'app/manage_cars.php',
      to: '/app/owner/cars/index.php',
      label: '/app/manage_cars.php legacy path',
    },
    {
      from: 'app/edit_car.php',
      to: '/app/owner/cars/edit.php',
      label: '/app/edit_car.php legacy path',
    },
    {
      from: 'app/statistics.php',
      to: '/app/owner/reports/statistics.php',
      label: '/app/statistics.php legacy path',
    },
    {
      from: 'docs/guide-viewer.php?doc=PRIVACY.md',
      to: '/app/owner/privacy.php',
      label: 'docs/guide-viewer.php PRIVACY.md',
    },
    {
      from: 'docs/stories/type26registry/',
      to: '/docs/stories/type26register.com/',
      label: 'docs/stories/type26registry/ typo-fix redirect',
    },
    {
      from: 'docs/reference/assets/Elan_S1_S2_Coupe_Masterpartslist.pdf',
      to: '/docs/reference/assets/elan_s1_s2_coupe_masterpartslist.pdf',
      label: 'PDF asset case mismatch: capitalized legacy URL → renamed lowercase asset',
    },
    {
      from: 'embed.php?doc=elan_s1_s2_coupe_masterpartslist.pdf',
      to: '/docs/pdf-viewer.php?subdir=reference&doc=elan_s1_s2_coupe_masterpartslist.pdf',
      label: 'root embed.php soft 404 → pdf-viewer.php',
    },
  ];

  redirects.forEach(({ from, to, label }) => {
    test(`301: ${label}`, async ({ request }) => {
      // .htaccess redirect behavior — Test/Prod only (#2055)
      skipOnLocalDev();
      const response = await request.get(from, { maxRedirects: 0 });
      expect(response.status(), `Expected 301 for ${from}`).toBe(301);
      const location = response.headers()['location'] ?? '';
      const locationPath = toLocationPath(location);
      expect(locationPath, `Expected Location: ${to} for ${from}`).toBe(to);
    });
  });

  test('renamed PDF asset (lowercase) returns 200', async ({ request }) => {
    const response = await request.get(
      `docs/reference/assets/elan_s1_s2_coupe_masterpartslist.pdf`
    );
    expect(response.status()).toBe(200);
  });

  test('embed.php?doc=... regression: lands on a working pdf-viewer.php page, not "Invalid document path."', async ({ page }) => {
    // Relies on the embed.php .htaccess soft-404 rewrite — Test/Prod only (#2055)
    skipOnLocalDev();
    const response = await page.goto(`embed.php?doc=elan_s1_s2_coupe_masterpartslist.pdf`);

    expect(response.status()).toBeLessThan(400);
    await page.waitForLoadState('domcontentloaded');

    // Must land on the PDF viewer, not be bounced to the login page
    expect(page.url()).toContain('/docs/pdf-viewer.php');
    expect(page.url()).not.toContain('login.php');

    // Regression guard: the pre-fix redirect used an invalid `subdir` value,
    // which pdf-viewer.php rejected with this exact error text (#1409). Also
    // checked against the extension-allowlist error text for the same reason
    // as the sibling test below (#1473).
    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toContain('Invalid document path.');
    expect(bodyText).not.toContain('Invalid document type');

    // Positive assertion (#1648): the iframe actually renders the requested
    // document, from the correct subdir. Scoped by `title` (pdf-viewer.php
    // sets it to the document filename) so this locator can't match
    // Cloudflare Turnstile's injected, untitled 1x1 iframe. The src regex
    // asserts the full reference/assets path, not just the filename suffix
    // — a bare filename match would also pass for a wrong-subdir src (the
    // #1594 bug class the sibling redirect tests above guard against).
    await expect(
      page.locator('iframe[title="elan_s1_s2_coupe_masterpartslist.pdf"]')
    ).toHaveAttribute('src', /\/docs\/reference\/assets\/elan_s1_s2_coupe_masterpartslist\.pdf$/);
  });
});

// Same mixed-block caveat as #1539/#1409 above. This block's forEach also
// supports a per-entry `caseSensitiveFsOnly: true` flag for redirects that
// depend on filesystem case-sensitivity rather than .htaccess (#2055).
test.describe('PDF viewer subdir normalization and 404 fixes (#1473)', () => {
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'not-logged-in') {
      testInfo.skip();
    }
  });

  const redirects = [
    {
      from: 'docs/pdf-viewer.php?subdir=reference/assets&doc=elan_s1_s2_coupe_masterpartslist.pdf',
      to: '/docs/pdf-viewer.php?subdir=reference&doc=elan_s1_s2_coupe_masterpartslist.pdf',
      label: 'pdf-viewer.php legacy subdir=reference/assets → subdir=reference',
    },
    {
      from: `docs/pdf-viewer.php?subdir=stories/assets&doc=${encodeURIComponent('Mag _issue_50_p12-15_Barry-Shapecraft.pdf')}`,
      to: `/docs/pdf-viewer.php?subdir=stories&doc=${encodeURIComponent('Mag _issue_50_p12-15_Barry-Shapecraft.pdf')}`,
      label: 'pdf-viewer.php legacy subdir=stories/assets → subdir=stories',
    },
    // #1594: case-insensitive, cross-subdir document resolution. An orphaned
    // docs/embed.php still lives on production disk (deleted from git) and
    // always links with subdir=reference, without normalizing filename case —
    // so requests can arrive with the right filename but wrong subdir, the
    // right subdir but wrong case, or both wrong at once. All three should
    // 301 to the canonical subdir + on-disk exact case.
    {
      from: `docs/pdf-viewer.php?subdir=reference&doc=${encodeURIComponent('Mag _issue_50_p12-15_Barry-Shapecraft.pdf')}`,
      to: `/docs/pdf-viewer.php?subdir=stories&doc=${encodeURIComponent('Mag _issue_50_p12-15_Barry-Shapecraft.pdf')}`,
      label: 'pdf-viewer.php #1594 correct filename/case but wrong subdir (reference → stories)',
    },
    {
      from: 'docs/pdf-viewer.php?subdir=reference&doc=Elan_S1_S2_Coupe_Masterpartslist.pdf',
      to: '/docs/pdf-viewer.php?subdir=reference&doc=elan_s1_s2_coupe_masterpartslist.pdf',
      label: 'pdf-viewer.php #1594 correct subdir but wrong filename case',
      // Local MAMP's default macOS filesystem is case-insensitive, so this
      // exact-case-mismatch file resolves directly (200) without ever
      // reaching the case-normalization redirect branch. Test/Prod run on a
      // case-sensitive filesystem where the mismatch, and therefore the
      // redirect, is real. Unrelated to #2055; pre-existing (confirmed via
      // git stash against pre-fix code).
      caseSensitiveFsOnly: true,
    },
    {
      from: 'docs/pdf-viewer.php?subdir=stories&doc=Elan_S1_S2_Coupe_Masterpartslist.pdf',
      to: '/docs/pdf-viewer.php?subdir=reference&doc=elan_s1_s2_coupe_masterpartslist.pdf',
      label: 'pdf-viewer.php #1594 wrong subdir AND wrong filename case',
    },
  ];

  redirects.forEach(({ from, to, label, caseSensitiveFsOnly }) => {
    test(`301: ${label}`, async ({ request, baseURL }) => {
      // Local/Dev MAMP runs on a case-insensitive filesystem — Test/Prod only (#2055)
      test.skip(
        IS_LOCAL_DEV_TIER && caseSensitiveFsOnly,
        'filename case mismatches only redirect on a case-sensitive filesystem'
      );
      const response = await request.get(from, { maxRedirects: 0 });
      expect(response.status(), `Expected 301 for ${from}`).toBe(301);
      const location = response.headers()['location'] ?? '';
      // pdf-viewer.php builds its own redirect target via UserSpice's
      // $us_url_root, so under a non-root mount the real Location header is
      // itself mount-prefixed (e.g. /ElanRegistry/Registry2/docs/...), unlike
      // the .htaccess-driven redirects elsewhere in this file, which are
      // origin-root absolute. Resolve both sides against baseURL rather than
      // comparing `to` as a bare absolute string (#2055).
      const actual = new URL(location, baseURL);
      const expected = new URL(stripLeadingSlash(to), baseURL);
      expect(
        actual.pathname + actual.search,
        `Expected Location: ${expected.pathname}${expected.search} for ${from}`
      ).toBe(expected.pathname + expected.search);
    });
  });

  test('200: pdf-viewer.php valid subdir and existing document', async ({ page, baseURL }) => {
    const targetPath = `docs/pdf-viewer.php?subdir=reference&doc=elan_s1_s2_coupe_masterpartslist.pdf`;
    const response = await page.goto(targetPath, { waitUntil: 'networkidle' });
    expect(response?.status()).toBe(200);

    // #1594 regression: the case-insensitive/cross-subdir resolution step
    // only runs when the direct subdir+doc path does not exist on disk — an
    // already-canonical request like this one must never be bounced through
    // the glob/301 path. Compare against the absolute URL targetPath resolves
    // to (not the bare relative string) — page.url() is always absolute
    // (#2055).
    const landed = new URL(page.url());
    const target = new URL(targetPath, baseURL);
    expect(landed.pathname + landed.search).toBe(target.pathname + target.search);

    // Also discriminate on the error text, not just the status: a real render
    // and any 200-status error branch would both pass a bare status check
    // alone (there's no 200-status error branch left as of #1538, but this
    // keeps the assertion meaningful if that ever changes again).
    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toContain('Invalid document path.');
    expect(bodyText).not.toContain('Invalid document type');

    // Positive assertion (#1648): the iframe actually renders the requested
    // document, from the correct subdir. Scoped by `title` (pdf-viewer.php
    // sets it to the document filename) so this locator can't match
    // Cloudflare Turnstile's injected, untitled 1x1 iframe. The src regex
    // asserts the full reference/assets path, not just the filename suffix
    // — a bare filename match would also pass for a wrong-subdir src (the
    // #1594 bug class the sibling redirect tests above guard against).
    await expect(
      page.locator('iframe[title="elan_s1_s2_coupe_masterpartslist.pdf"]')
    ).toHaveAttribute('src', /\/docs\/reference\/assets\/elan_s1_s2_coupe_masterpartslist\.pdf$/);
  });

  test('404: pdf-viewer.php directory traversal attempt in doc param (#1538)', async ({ page }) => {
    const response = await page.goto(
      `docs/pdf-viewer.php?subdir=reference&doc=${encodeURIComponent('../../../etc/passwd')}`,
      { waitUntil: 'networkidle' }
    );
    expect(response?.status()).toBe(404);
  });

  test('404: pdf-viewer.php invalid document extension (#1538)', async ({ page }) => {
    const response = await page.goto(
      `docs/pdf-viewer.php?subdir=reference&doc=readme.txt`,
      { waitUntil: 'networkidle' }
    );
    expect(response?.status()).toBe(404);
  });

  test('404: pdf-viewer.php genuinely invalid subdir value', async ({ page }) => {
    const response = await page.goto(
      `docs/pdf-viewer.php?subdir=etc&doc=elan_s1_s2_coupe_masterpartslist.pdf`,
      { waitUntil: 'networkidle' }
    );
    expect(response?.status()).toBe(404);
  });

  test('404: pdf-viewer.php valid subdir but non-existent document', async ({ page }) => {
    const response = await page.goto(
      `docs/pdf-viewer.php?subdir=reference&doc=does-not-exist-12345.pdf`,
      { waitUntil: 'networkidle' }
    );
    expect(response?.status()).toBe(404);

    // #1594: this filename matches nothing in the case-insensitive glob scan
    // across allowlisted subdirs either, so this also proves the new
    // resolution loop falls through cleanly to the original 404 rather than
    // erroring out along the way.
    const bodyText = await page.locator('body').innerText();
    expect(bodyText).toContain('Document not found.');
  });

  test('404: pdf-viewer.php omitted subdir with existing document — regression for wrong-directory file_exists() fallback', async ({ page }) => {
    // Replicates the real malformed-URL shape from the issue's log analysis: an
    // unescaped &amp; HTML entity produces ?amp&doc=X.pdf, which PHP parses as
    // $_GET = ['amp' => '', 'doc' => 'X.pdf'] — subdir is never set. Deliberately
    // NOT ?doc=X.pdf alone: that bare single-param shape is already caught and
    // 301'd by the existing .htaccess rule (#1369), which would mask this
    // regression by never reaching PHP with subdir omitted.
    const response = await page.goto(
      `docs/pdf-viewer.php?amp&doc=${encodeURIComponent('Lotus Elan Plus 2 serial numbers.pdf')}`,
      { waitUntil: 'networkidle' }
    );
    expect(response?.status()).toBe(404);
  });

  test('200: pdf-viewer.php array-valued doc param does not crash (regression for TypeError on strpos())', async ({ page }) => {
    // Before this fix, ?doc[]=x made $_GET['doc'] an array; strpos() on an array
    // throws an uncaught TypeError under declare(strict_types=1) — a fatal 500,
    // not a soft failure. An array-valued doc is now coerced to '' and falls
    // through to the unchanged "No document specified" branch.
    const response = await page.goto(`docs/pdf-viewer.php?doc[]=x`, {
      waitUntil: 'networkidle',
    });
    expect(response?.status()).toBe(200);
    const bodyText = await page.locator('body').innerText();
    expect(bodyText).toContain('No document specified.');
  });

  test('404: pdf-viewer.php array-valued subdir param does not crash, treated as omitted', async ({ page }) => {
    // Without the is_string() guard, the str_ends_with()/strpos() calls this PR
    // adds for subdir validation would throw a TypeError on an array value — a
    // real doc value is required to reach that branch at all.
    const response = await page.goto(
      `docs/pdf-viewer.php?subdir[]=x&doc=elan_s1_s2_coupe_masterpartslist.pdf`,
      { waitUntil: 'networkidle' }
    );
    expect(response?.status()).toBe(404);
  });

  test('200: pdf-viewer.php representative document renders description prose and direct download link (#1538)', async ({ page }) => {
    const response = await page.goto(
      `docs/pdf-viewer.php?subdir=reference&doc=${encodeURIComponent('All Elan and Elan Plus 2 Paint Codes.pdf')}`,
      { waitUntil: 'networkidle' }
    );
    expect(response?.status()).toBe(200);

    const bodyText = await page.locator('body').innerText();
    expect(bodyText).toContain('Official factory paint codes for all Elan and Plus 2 models — downloadable PDF for offline reference.');

    const downloadLink = page.locator('a[download][href*="docs/reference/assets/"]');
    await expect(downloadLink).toHaveCount(1);
    const href = await downloadLink.getAttribute('href');
    expect(href).toContain(encodeURIComponent('All Elan and Elan Plus 2 Paint Codes.pdf'));
  });

  test('200: pdf-viewer.php stories document with no metadata entry still renders generic fallback and download link (#1538)', async ({ page }) => {
    const response = await page.goto(
      `docs/pdf-viewer.php?subdir=stories&doc=${encodeURIComponent('Mag _issue_50_p12-15_Barry-Shapecraft.pdf')}`,
      { waitUntil: 'networkidle' }
    );
    expect(response?.status()).toBe(200);

    const bodyText = await page.locator('body').innerText();
    expect(bodyText).toContain('View this PDF document online below, or use the direct download link for offline access.');

    const downloadLink = page.locator('a[download][href*="docs/stories/assets/"]');
    await expect(downloadLink).toHaveCount(1);
  });
});

test.describe('Sitemap endpoint (#1373)', () => {
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'not-logged-in') {
      testInfo.skip();
    }
    // /sitemap.xml is served via an .htaccess RewriteRule — Test/Prod only (#2055)
    skipOnLocalDev();
  });

  test('GET /sitemap.xml returns a valid sitemap with at least one car entry', async ({ page }) => {
    const response = await page.goto('sitemap.xml');

    // Layer 1: HTTP response must be successful
    expect(response.status()).toBeLessThan(400);

    // Layer 2: Must be served as XML, not HTML (e.g. a login redirect or error page)
    const contentType = response.headers()['content-type'] ?? '';
    expect(contentType.toLowerCase()).toContain('application/xml');

    // Layer 3: Must be a real sitemap document, not an error page
    const body = await response.text();
    expect(body).toContain('<urlset');

    // Layer 4: Must include at least one car entry — this registry always
    // has cars in its test/prod data, so this should never be empty.
    expect(body).toContain('details.php?car_id=');
  });
});

test.describe('llms.txt AI crawler guidance (#1413)', () => {
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'not-logged-in') {
      testInfo.skip();
    }
  });

  test('GET /llms.txt returns the AI crawler policy as plain text', async ({ page }) => {
    const response = await page.goto('llms.txt');

    // Layer 1: HTTP response must be successful
    expect(response.status()).toBeLessThan(400);

    // Layer 2: Must be served as plain text, not HTML (e.g. a login redirect or error page)
    const contentType = response.headers()['content-type'] ?? '';
    expect(contentType.toLowerCase()).toContain('text/plain');

    // Layer 3: Must be the real llms.txt content, not an error page. The
    // deploy hook swaps in llms-test.txt on test.elanregistry.org (a
    // Disallow-everything policy), so accept either policy section heading
    // rather than the prod-only "## Allow".
    const body = await response.text();
    expect(body).toMatch(/^## (Allow|Disallow)$/m);
  });
});

test.describe('SEO metadata: JSON-LD, noindex, apple-touch-icon (#1371)', () => {
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'not-logged-in') {
      testInfo.skip();
    }
  });

  test('GET /app/owner/cars/details.php for the first listed car renders a Schema.org Car JSON-LD block', async ({ page }) => {
    // Discover a real car ID from the list page rather than assuming a fixed
    // one — CAR_ID_STANDARD (default 1) exists on prod but not on every test
    // DB snapshot, where details.php 302s back to index.php and this test
    // failed on the environment, not the feature. The list's Details links
    // are rendered client-side by DataTables (car-list.js), so wait for the
    // first one to appear instead of reading server HTML.
    await page.goto('app/owner/cars/index.php');
    const firstDetailsLink = page.locator('a[href*="details.php?car_id="]').first();
    await firstDetailsLink.waitFor();
    const href = await firstDetailsLink.getAttribute('href');
    const carId = new URL(href, page.url()).searchParams.get('car_id');
    expect(carId, 'first Details link must carry a car_id').toMatch(/^\d+$/);

    const response = await page.goto(`app/owner/cars/details.php?car_id=${carId}`);

    // Layer 1: HTTP response must be successful
    expect(response.status()).toBeLessThan(400);

    // Layer 2: Must not have been redirected away — securePage() permits
    // guest access to car details, and a missing car 302s to index.php.
    expect(page.url()).not.toContain('login.php');
    expect(page.url()).toContain(`car_id=${carId}`);

    // Layer 3: Body must contain a JSON-LD script block declaring the
    // Schema.org Car type. json_encode() (with JSON_UNESCAPED_SLASHES) emits
    // compact, unspaced JSON, so "@type":"Car" appears literally adjacent —
    // see app/owner/cars/details.php.
    const body = await response.text();
    expect(body).toContain('application/ld+json');
    expect(body).toContain('"@type":"Car"');

    // Layer 4: The VIN field ties the structured data to the actual car
    // record's chassis number.
    expect(body).toContain('vehicleIdentificationNumber');

    // Layer 5: details.php doesn't set $pageRobots, so it must still render
    // the site-wide default (index, follow) — confirms head_tags.php's
    // fallback still works, not just the two noindex overrides below.
    expect(body).toContain('<meta name="robots" content="index, follow">');
  });

  const noindexPages = [
    { path: 'app/owner/cars/factory.php', name: 'Factory Data' },
    { path: 'app/owner/privacy.php', name: 'Privacy' },
  ];

  noindexPages.forEach(({ path, name }) => {
    test(`GET ${path} (${name}) sets noindex, follow`, async ({ page }) => {
      const response = await page.goto(path);

      // Layer 1: HTTP response must be successful
      expect(response.status()).toBeLessThan(400);

      // Layer 2: Must not have been redirected to the login page
      expect(page.url()).not.toContain('login.php');

      // Layer 3: Must render the noindex robots meta tag instead of the
      // site-wide default of "index, follow" (see usersc/includes/head_tags.php).
      const body = await response.text();
      expect(body).toContain('<meta name="robots" content="noindex, follow">');
    });
  });

  const appleTouchIcons = [
    'apple-touch-icon.png',
    'apple-touch-icon-precomposed.png',
  ];

  appleTouchIcons.forEach((path) => {
    test(`GET ${path} serves a PNG`, async ({ request }) => {
      const response = await request.get(path);

      // Layer 1: HTTP response must be successful
      expect(response.status()).toBeLessThan(400);

      // Layer 2: Must actually be served as a PNG image, not an HTML error page
      const contentType = response.headers()['content-type'] ?? '';
      expect(contentType.toLowerCase()).toContain('image/png');
    });
  });
});

test.describe('Location picker city disambiguation (#1400)', () => {
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'not-logged-in') {
      testInfo.skip();
    }
  });

  // Live Nominatim/Photon results are required for this test, so it only runs
  // against deployed environments (npm run test:e2e / test:e2e:test) — this
  // file only executes via playwright.config.prod.js / playwright.config.test.js,
  // never locally against MAMP (see playwright.config.js's testIgnore: '**/e2e/**').
  //
  // This is an integration smoke check, not the primary regression guard for
  // the dedupe-key fix itself — see tests/playwright/location-picker-dedupe.spec.js
  // for a deterministic, mock-data test of filterAndRankResults() directly.
  // A plain "more than one result" count would pass even without the fix,
  // since Photon/Nominatim already returns Springfields from *different
  // countries* as distinct entries under the old city|country key too — the
  // bug was specifically about same-country, different-state collisions. So
  // this asserts on distinct *same-country* (United States) result text
  // instead of a raw count.
  test('searching an ambiguous city name shows multiple distinct same-country results (regression guard)', async ({ page }) => {
    await page.goto('users/join.php');

    const input = page.locator('#location-picker-registration-input');
    const resultsContainer = page.locator('#location-picker-registration-results');

    await input.fill('Springfield');

    // The picker debounces input by 300ms before firing the search request;
    // wait for the results list to actually populate rather than a fixed
    // sleep, since the live geocoding API latency varies.
    await expect(async () => {
      // Query <small> elements directly rather than iterating .list-group-item
      // and locating a child inside each — the "No locations found" fallback
      // item has no <small> child, so this avoids a throw on that render.
      const texts = await resultsContainer.locator('.list-group-item small').allTextContents();
      const distinctUsResults = new Set(texts.map(t => t.trim()).filter(t => t.includes('United States')));
      expect(distinctUsResults.size).toBeGreaterThan(1);
    }).toPass({ timeout: 15000 });
  });
});
