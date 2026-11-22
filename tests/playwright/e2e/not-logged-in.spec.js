const { test, expect } = require('@playwright/test');
const { CAR_ID_STANDARD, CAR_ID_REDIRECT_TEST } = require('../fixtures.js');
const { assertPageTitle } = require('../auth-helper.js');
const { assertValidTier } = require('./auth-staleness-tier.js');
const {
  discoverInternalLinks,
  splitDownloadableLinks,
  checkNavigableLinks,
  checkDownloadableLinks,
} = require('./link-discovery.js');

// True on the local stack (E2E_AUTH_TIER unset). assertValidTier() makes a
// stray shell export fail loudly instead of picking the wrong tier.
assertValidTier(process.env.E2E_AUTH_TIER, { allowUnset: true }, 'not-logged-in.spec.js');
const IS_LOCAL_DEV_TIER = process.env.E2E_AUTH_TIER === undefined;

// A leading slash would drop baseURL's path under a non-root mount (#2055).
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
      testInfo.skip(true, 'Only runs under the not-logged-in project');
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
      // h1 shows the metadata-map title for a known reference PDF (#1538).
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

// Pages without $pageTitle still render the generic site title (#1432).
test('docs/guides/car-transfer-faq.php still renders the generic site title/description (regression guard) (#1432)', async ({ page }, testInfo) => {
  if (testInfo.project.name !== 'not-logged-in') {
    testInfo.skip(true, 'Only runs under the not-logged-in project');
  }

  await page.goto('docs/guides/car-transfer-faq.php');

  await expect(page).toHaveTitle(/^Lotus Elan Registry$/);

  const description = await page
    .locator('meta[name="description"]')
    .getAttribute('content');
  expect(description).toContain('Registry for the Lotus Elan (1963-1973) and Elan Plus 2 (1967-1974)');

  // og:title falls back to $site_title ($og_title in head_tags.php, #1432).
  const ogTitle = await page.locator('meta[property="og:title"]').getAttribute('content');
  expect(ogTitle).toBe('Lotus Elan Registry');
  const twitterTitle = await page.locator('meta[name="twitter:title"]').getAttribute('content');
  expect(twitterTitle).toBe('Lotus Elan Registry');
});

// verify_car.php (#1881) answers 404 for a missing vericode (no enumeration
// signal), so it cannot use the shared "status < 400" loop.
test('app/verify/verify_car.php reaches the invalid-link page for a missing vericode without a fatal error', async ({ page }, testInfo) => {
  if (testInfo.project.name !== 'not-logged-in') {
    testInfo.skip(true, 'Only runs under the not-logged-in project');
  }

  const response = await page.goto('app/verify/verify_car.php');

  expect(response.status()).toBe(404);

  await page.waitForLoadState('domcontentloaded');

  await expect(page.locator('h2')).toContainText('This link has expired or is no longer valid');
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

  test('test all unique internal links found across all pages', async ({ page }) => {
    test.setTimeout(120000); // 2 minutes for testing 50+ links on production
    // All discovery pages except Forgot Password count as public pages for warning purposes
    const publicPageNames = pages.filter(p => p.name !== 'Forgot Password').map(p => p.name);

    const foundOn = await discoverInternalLinks(page, pages);
    const uniqueLinks = Array.from(foundOn.keys()).sort();
    const { navigableLinks: allNavigableLinks, downloadableLinks } = splitDownloadableLinks(uniqueLinks);
    // Check only the CAR_ID_STANDARD details page, not every listed car.
    const navigableLinks = allNavigableLinks.filter(link =>
      !link.includes('/app/owner/cars/details.php') || link.includes(`car_id=${CAR_ID_STANDARD}`));

    console.log(`\n=== Testing Links ===`);
    console.log(`Navigable pages: ${navigableLinks.length}`);
    console.log(`Downloadable files: ${downloadableLinks.length}`);
    console.log(`Total unique links: ${uniqueLinks.length}\n`);

    // A protected page (one that redirects to login) linked from a public
    // page is a warning, not a failure.
    const protectedLinksFromPublic = [];
    await checkNavigableLinks(page, navigableLinks, (linkPath, loadedPage) => {
      const linkFoundOn = foundOn.get(linkPath) ?? [];
      if (loadedPage.url().includes('login.php') && linkFoundOn.some(name => publicPageNames.includes(name))) {
        protectedLinksFromPublic.push({ link: linkPath, foundOn: linkFoundOn });
        console.log(`⚠️  ${linkPath} - Protected (requires login, found on: ${linkFoundOn.join(', ')})`);
      }
    });

    if (protectedLinksFromPublic.length > 0) {
      console.log('\n=== Protected Pages Linked From Public Pages ===\n');
      protectedLinksFromPublic.forEach((item, index) => {
        console.log(`${index + 1}. ${item.link}`);
        console.log(`   Found on: ${item.foundOn.join(', ')}\n`);
      });
    }

    await checkDownloadableLinks(page, downloadableLinks);
  });
});

// Each row must answer 301 with Location set to `to` (#1369, #1539, #1409, #1473).
// Flags:
// - followStatus: also request the target and expect this status. A redirect
//   into a login wall passes the Location check alone (#1539).
// - mountPrefixed: the Location carries the mount prefix (pdf-viewer.php), so
//   resolve both sides against baseURL (#2055).
// - caseSensitiveFsOnly: the redirect depends on a case-sensitive filesystem,
//   which the local stack does not have.
test.describe('Redirect map — legacy and GSC-flagged URLs answer 301 to the canonical path', () => {
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'not-logged-in') {
      testInfo.skip(true, 'Only runs under the not-logged-in project');
    }
  });

  const redirects = [
    // GSC 404 and soft 404 cleanup (#1369)
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
    // Legacy URLs that returned 404 in Search Console (#2247)
    {
      from: 'stats.php',
      to: '/app/owner/reports/statistics.php',
      label: '/stats.php legacy statistics page (#2247)',
    },
    {
      from: 'stories',
      to: '/docs/car-stories.php',
      label: '/stories bare path (#2247)',
    },
    {
      from: 'stories/',
      to: '/docs/car-stories.php',
      label: '/stories/ bare directory (#2247)',
    },
    // Bare-directory 403s (#1539)
    {
      from: 'app/owner/reports/',
      to: '/app/owner/reports/statistics.php',
      label: 'app/owner/reports/ bare-directory 403 → statistics.php',
      followStatus: 200,
    },
    {
      from: 'app/owner/',
      to: '/app/owner/cars/',
      label: 'app/owner/ bare-directory 403 → cars/',
      followStatus: 200,
    },
    {
      from: 'docs/stories/',
      to: '/docs/car-stories.php',
      label: 'docs/stories/ bare-directory 403 → car-stories.php',
      followStatus: 200,
    },
    {
      // The URL GSC flagged (#1539): must land in one hop.
      from: 'app/reports/',
      to: '/app/owner/reports/statistics.php',
      label: 'app/reports/ (legacy, GSC-flagged) → statistics.php, single hop',
      followStatus: 200,
    },
    // GSC 404 cleanup (#1409)
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
    // PDF viewer subdir normalization (#1473)
    {
      from: 'docs/pdf-viewer.php?subdir=reference/assets&doc=elan_s1_s2_coupe_masterpartslist.pdf',
      to: '/docs/pdf-viewer.php?subdir=reference&doc=elan_s1_s2_coupe_masterpartslist.pdf',
      label: 'pdf-viewer.php legacy subdir=reference/assets → subdir=reference',
      mountPrefixed: true,
    },
    {
      from: `docs/pdf-viewer.php?subdir=stories/assets&doc=${encodeURIComponent('Mag _issue_50_p12-15_Barry-Shapecraft.pdf')}`,
      to: `/docs/pdf-viewer.php?subdir=stories&doc=${encodeURIComponent('Mag _issue_50_p12-15_Barry-Shapecraft.pdf')}`,
      label: 'pdf-viewer.php legacy subdir=stories/assets → subdir=stories',
      mountPrefixed: true,
    },
    // #1594: wrong subdir, wrong case, or both must 301 to the canonical path.
    {
      from: `docs/pdf-viewer.php?subdir=reference&doc=${encodeURIComponent('Mag _issue_50_p12-15_Barry-Shapecraft.pdf')}`,
      to: `/docs/pdf-viewer.php?subdir=stories&doc=${encodeURIComponent('Mag _issue_50_p12-15_Barry-Shapecraft.pdf')}`,
      label: 'pdf-viewer.php #1594 correct filename/case but wrong subdir (reference → stories)',
      mountPrefixed: true,
    },
    {
      from: 'docs/pdf-viewer.php?subdir=reference&doc=Elan_S1_S2_Coupe_Masterpartslist.pdf',
      to: '/docs/pdf-viewer.php?subdir=reference&doc=elan_s1_s2_coupe_masterpartslist.pdf',
      label: 'pdf-viewer.php #1594 correct subdir but wrong filename case',
      // The local macOS bind mount is case-insensitive, so this resolves
      // directly (200) there.
      caseSensitiveFsOnly: true,
      mountPrefixed: true,
    },
    {
      from: 'docs/pdf-viewer.php?subdir=stories&doc=Elan_S1_S2_Coupe_Masterpartslist.pdf',
      to: '/docs/pdf-viewer.php?subdir=reference&doc=elan_s1_s2_coupe_masterpartslist.pdf',
      label: 'pdf-viewer.php #1594 wrong subdir AND wrong filename case',
      mountPrefixed: true,
    },
  ];

  redirects.forEach(({ from, to, label, followStatus, mountPrefixed, caseSensitiveFsOnly }) => {
    test(`301: ${label}`, async ({ request, baseURL }) => {
      test.skip(
        IS_LOCAL_DEV_TIER && !!caseSensitiveFsOnly,
        'macOS bind mount is case-insensitive, so filename case mismatches do not redirect locally'
      );
      const response = await request.get(from, { maxRedirects: 0 });
      expect(response.status(), `Expected 301 for ${from}`).toBe(301);
      const location = response.headers()['location'] ?? '';
      const expected = new URL(stripLeadingSlash(to), baseURL);

      if (mountPrefixed) {
        const actual = new URL(location, baseURL);
        expect(
          actual.pathname + actual.search,
          `Expected Location: ${expected.pathname}${expected.search} for ${from}`
        ).toBe(expected.pathname + expected.search);
      } else {
        expect(toLocationPath(location), `Expected Location: ${to} for ${from}`).toBe(to);
      }

      if (followStatus !== undefined) {
        const followed = await request.get(expected.href);
        expect(followed.status(), `Expected ${followStatus} for redirect target ${to}`).toBe(followStatus);
      }
    });
  });
});

test.describe('Bare-directory 403s and docs/assets/ CSS relocation (#1539)', () => {
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'not-logged-in') {
      testInfo.skip(true, 'Only runs under the not-logged-in project');
    }
  });

  test('regression guard: /app/owner/reports/statistics.php requested directly is NOT redirected (mod_alias prefix-match trap)', async ({ request }) => {
    // An unanchored rule would mangle this into .../statistics.phpstatistics.php.
    const response = await request.get(`app/owner/reports/statistics.php`, { maxRedirects: 0 });
    expect(response.status()).toBe(200);
  });

  test('regression guard: /app/reports/statistics.php redirects in a single hop, not a 301->301 chain', async ({ request }) => {
    // The specific-file rule must match first, so this resolves in one hop.
    const response = await request.get(`app/reports/statistics.php`, { maxRedirects: 0 });
    expect(response.status()).toBe(301);
    const location = response.headers()['location'] ?? '';
    const locationPath = toLocationPath(location);
    expect(locationPath).toBe('/app/owner/reports/statistics.php');
  });

  test('GET /app/ bare directory renders the branded error/500.php handler, not a raw server 403', async ({ request }) => {
    // Status 403 alone also matches Apache's default page, so check the branded markup.
    const response = await request.get(`app/`, { maxRedirects: 0 });
    expect(response.status()).toBe(403);
    const body = await response.text();
    expect(body).toContain('error-card');
    expect(body).toContain('Access Forbidden');
  });

  test('GET /docs/assets/document-content.css (old path) redirects to docs/reference/assets/, which 404s — the file was moved, not copied', async ({ request, baseURL }) => {
    // The #1369 blanket rule still 301s this old path.
    const response = await request.get(`docs/assets/document-content.css`, { maxRedirects: 0 });
    expect(response.status()).toBe(301);
    const location = response.headers()['location'] ?? '';
    const locationPath = toLocationPath(location);
    expect(locationPath).toBe('/docs/reference/assets/document-content.css');

    // Nothing was copied to the new path, so the chain ends in 404 (#2055 for the slash).
    const followed = await request.get(new URL(stripLeadingSlash(locationPath), baseURL).href);
    expect(followed.status()).toBe(404);
  });

  test('GET /app/assets/css/document-content.min.css (new path) resolves 200 with no redirect', async ({ request }) => {
    const response = await request.get(`app/assets/css/document-content.min.css`, { maxRedirects: 0 });
    expect(response.status()).toBe(200);
  });
});

test.describe('Origin deny rules for private files (#2326, #2212, #2121)', () => {
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'not-logged-in') {
      testInfo.skip(true, 'Only runs under the not-logged-in project');
    }
  });

  // #1768 made PHP write errors to a file literally named %{ENV:PHP_ERROR_LOG}.
  // Locally each path gets 403 only from a deny rule: the %{ENV rule denies
  // even a missing file, and .git/HEAD and docker-compose.yml exist in the
  // checkout. On a host the files may be absent (.deployignore strips
  // docker-compose.yml, and the post-receive hook keeps .git outside the
  // docroot), so 404 also passes there. Never 200.
  [
    '%25%7BENV:PHP_ERROR_LOG%7D',
    'users/%25%7BENV:PHP_ERROR_LOG%7D',
    '.git/HEAD',
    'docker-compose.yml',
  ].forEach((path) => {
    test(`GET /${path} is denied`, async ({ request }) => {
      const response = await request.get(path, { maxRedirects: 0 });
      const allowed = IS_LOCAL_DEV_TIER ? [403] : [403, 404];
      expect(allowed, `Expected ${allowed.join(' or ')} for /${path}, got ${response.status()}`)
        .toContain(response.status());
    });
  });
});

test.describe('GSC 404 cleanup redirects (#1409)', () => {
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'not-logged-in') {
      testInfo.skip(true, 'Only runs under the not-logged-in project');
    }
  });

  test('renamed PDF asset (lowercase) returns 200', async ({ request }) => {
    const response = await request.get(
      `docs/reference/assets/elan_s1_s2_coupe_masterpartslist.pdf`
    );
    expect(response.status()).toBe(200);
  });

  test('embed.php?doc=... regression: lands on a working pdf-viewer.php page, not "Invalid document path."', async ({ page }) => {
    const response = await page.goto(`embed.php?doc=elan_s1_s2_coupe_masterpartslist.pdf`);

    expect(response.status()).toBeLessThan(400);
    await page.waitForLoadState('domcontentloaded');

    // Must land on the PDF viewer, not be bounced to the login page
    expect(page.url()).toContain('/docs/pdf-viewer.php');
    expect(page.url()).not.toContain('login.php');

    // #1409 / #1473 error texts must be absent.
    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toContain('Invalid document path.');
    expect(bodyText).not.toContain('Invalid document type');

    // #1648: scoped by title so it cannot match Turnstile's untitled iframe;
    // the full-path regex catches a wrong-subdir src (#1594).
    await expect(
      page.locator('iframe[title="elan_s1_s2_coupe_masterpartslist.pdf"]')
    ).toHaveAttribute('src', /\/docs\/reference\/assets\/elan_s1_s2_coupe_masterpartslist\.pdf$/);
  });
});

test.describe('PDF viewer subdir normalization and 404 fixes (#1473)', () => {
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'not-logged-in') {
      testInfo.skip(true, 'Only runs under the not-logged-in project');
    }
  });

  test('200: pdf-viewer.php valid subdir and existing document', async ({ page, baseURL }) => {
    const targetPath = `docs/pdf-viewer.php?subdir=reference&doc=elan_s1_s2_coupe_masterpartslist.pdf`;
    const response = await page.goto(targetPath, { waitUntil: 'networkidle' });
    expect(response?.status()).toBe(200);

    // #1594: an already-canonical request must not go through the glob/301 path.
    const landed = new URL(page.url());
    const target = new URL(targetPath, baseURL);
    expect(landed.pathname + landed.search).toBe(target.pathname + target.search);

    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toContain('Invalid document path.');
    expect(bodyText).not.toContain('Invalid document type');

    // #1648: see the sibling test for the title scope and full-path regex.
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

    // #1594: proves the resolution loop falls through cleanly to 404.
    const bodyText = await page.locator('body').innerText();
    expect(bodyText).toContain('Document not found.');
  });

  test('404: pdf-viewer.php omitted subdir with existing document — regression for wrong-directory file_exists() fallback', async ({ page }) => {
    // ?amp&doc=X.pdf is the real malformed shape; ?doc=X.pdf alone is caught by .htaccess (#1369).
    const response = await page.goto(
      `docs/pdf-viewer.php?amp&doc=${encodeURIComponent('Lotus Elan Plus 2 serial numbers.pdf')}`,
      { waitUntil: 'networkidle' }
    );
    expect(response?.status()).toBe(404);
  });

  test('200: pdf-viewer.php array-valued doc param does not crash (regression for TypeError on strpos())', async ({ page }) => {
    // An array doc once threw a TypeError (fatal 500) under strict_types.
    const response = await page.goto(`docs/pdf-viewer.php?doc[]=x`, {
      waitUntil: 'networkidle',
    });
    expect(response?.status()).toBe(200);
    const bodyText = await page.locator('body').innerText();
    expect(bodyText).toContain('No document specified.');
  });

  test('404: pdf-viewer.php array-valued subdir param does not crash, treated as omitted', async ({ page }) => {
    // A real doc value is needed to reach the subdir is_string() guard.
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
      testInfo.skip(true, 'Only runs under the not-logged-in project');
    }
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
      testInfo.skip(true, 'Only runs under the not-logged-in project');
    }
  });

  test('GET /llms.txt returns the AI crawler policy as plain text', async ({ page }) => {
    const response = await page.goto('llms.txt');

    // Layer 1: HTTP response must be successful
    expect(response.status()).toBeLessThan(400);

    // Layer 2: Must be served as plain text, not HTML (e.g. a login redirect or error page)
    const contentType = response.headers()['content-type'] ?? '';
    expect(contentType.toLowerCase()).toContain('text/plain');

    // Layer 3: test.elanregistry.org serves llms-test.txt, so accept either policy heading.
    const body = await response.text();
    expect(body).toMatch(/^## (Allow|Disallow)$/m);
  });
});

// CAR_ID_STANDARD is missing on some test DBs, so discover cars from the
// index. DataTables renders the Details links, so wait for the first one.
const discoverCarIds = async (page, count) => {
  await page.goto('app/owner/cars/index.php');
  const detailsLinks = page.locator('a[href*="details.php?car_id="]');
  await detailsLinks.first().waitFor();
  const hrefs = await detailsLinks.evaluateAll((links) => links.map((link) => link.getAttribute('href')));
  const carIds = [...new Set(hrefs.map((href) => new URL(href, page.url()).searchParams.get('car_id')))];
  expect(carIds.length, `the car index must list at least ${count} cars`).toBeGreaterThanOrEqual(count);
  const selected = carIds.slice(0, count);
  selected.forEach((carId) => expect(carId, 'each Details link must carry a car_id').toMatch(/^\d+$/));
  return selected;
};

test.describe('SEO metadata: JSON-LD, noindex, apple-touch-icon (#1371)', () => {
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'not-logged-in') {
      testInfo.skip(true, 'Only runs under the not-logged-in project');
    }
  });

  test('GET /app/owner/cars/details.php for the first listed car renders a Schema.org Car JSON-LD block', async ({ page }) => {
    const [carId] = await discoverCarIds(page, 1);

    const response = await page.goto(`app/owner/cars/details.php?car_id=${carId}`);

    // Layer 1: HTTP response must be successful
    expect(response.status()).toBeLessThan(400);

    // Layer 2: Must not have been redirected away — securePage() permits
    // guest access to car details, and a missing car 302s to index.php.
    expect(page.url()).not.toContain('login.php');
    expect(page.url()).toContain(`car_id=${carId}`);

    // Layer 3: compact json_encode() output puts "@type":"Car" adjacent.
    const body = await response.text();
    expect(body).toContain('application/ld+json');
    expect(body).toContain('"@type":"Car"');

    // Layer 4: The VIN field ties the structured data to the actual car
    // record's chassis number.
    expect(body).toContain('vehicleIdentificationNumber');

    // Layer 5: the site-wide robots default (no $pageRobots).
    expect(body).toContain('<meta name="robots" content="index, follow">');
  });

  // Reads the URL and title head tags, and the JSON-LD url, of the open page.
  const readHeadTags = async (page) => {
    const jsonLd = await page.locator('script[type="application/ld+json"]').textContent();
    return {
      canonical: await page.locator('link[rel="canonical"]').getAttribute('href'),
      ogUrl: await page.locator('meta[property="og:url"]').getAttribute('content'),
      twitterUrl: await page.locator('meta[name="twitter:url"]').getAttribute('content'),
      jsonLdUrl: JSON.parse(jsonLd).url,
      title: await page.title(),
      description: await page.locator('meta[name="description"]').getAttribute('content'),
      ogTitle: await page.locator('meta[property="og:title"]').getAttribute('content'),
      twitterTitle: await page.locator('meta[name="twitter:title"]').getAttribute('content'),
    };
  };

  // #1960: the canonical keeps only the validated car_id. A crafted suffix, a
  // padded ID or a tracking parameter must not reach it.
  [
    { name: 'crafted suffix', query: (carId) => `car_id=${carId}-spam` },
    { name: 'padded integer', query: (carId) => `car_id=000${carId}` },
    { name: 'tracking parameter', query: (carId) => `car_id=${carId}&utm_source=newsletter` },
  ].forEach(({ name, query }) => {
    test(`details.php canonical, og:url, twitter:url and JSON-LD url drop a ${name} (#1960)`, async ({ page, baseURL }) => {
      const [carId] = await discoverCarIds(page, 1);
      const expected = new URL(`app/owner/cars/details.php?car_id=${carId}`, baseURL).href;

      const response = await page.goto(`app/owner/cars/details.php?${query(carId)}`);
      expect(response.status()).toBe(200);

      const tags = await readHeadTags(page);
      expect(tags.canonical).toBe(expected);
      expect(tags.ogUrl).toBe(expected);
      expect(tags.twitterUrl).toBe(expected);
      expect(tags.jsonLdUrl).toBe(expected);
    });
  });

  test('paint-colors.php canonical has no query string (#1960)', async ({ page, baseURL }) => {
    const response = await page.goto('docs/reference/paint-colors.php?utm_source=test');
    expect(response.status()).toBe(200);

    const canonical = await page.locator('link[rel="canonical"]').getAttribute('href');
    expect(canonical).toBe(new URL('docs/reference/paint-colors.php', baseURL).href);
  });

  // #1528: a car that does not load keeps the redirect and gets no per-car
  // title. The car loads before any output, so the redirect is a real 302.
  ['abc', '-spam'].forEach((carId) => {
    test(`details.php?car_id=${carId} sends a 302 to the car index (#1960)`, async ({ request }) => {
      const response = await request.get(`app/owner/cars/details.php?car_id=${carId}`, { maxRedirects: 0 });

      expect(response.status()).toBe(302);
      expect(toLocationPath(response.headers()['location'] ?? '')).toMatch(/app\/owner\/cars\/index\.php$/);
      expect(await response.text()).not.toContain('Chassis');
    });
  });

  test('details.php sets a per-car title and description, and two cars differ (#1528)', async ({ page }) => {
    const carIds = await discoverCarIds(page, 2);
    const tagsPerCar = [];
    for (const carId of carIds) {
      await page.goto(`app/owner/cars/details.php?car_id=${carId}`);
      tagsPerCar.push(await readHeadTags(page));
    }

    tagsPerCar.forEach((tags) => {
      expect(tags.ogTitle).toContain('Lotus Elan');
      expect(tags.title.startsWith(tags.ogTitle), `<title> "${tags.title}" must start with og:title`).toBe(true);
      expect(tags.twitterTitle).toBe(tags.ogTitle);
      expect(tags.description).toMatch(/^Registry record for a /);
    });
    expect(tagsPerCar[0].title).not.toBe(tagsPerCar[1].title);
    expect(tagsPerCar[0].description).not.toBe(tagsPerCar[1].description);
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

test.describe('Issue #2144 — anonymous visitor sees a login prompt, not car history', () => {
  // history.php requires login (#2144). Guests see only a login prompt and no
  // history assets. This file also runs against production.
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'not-logged-in') {
      testInfo.skip(true, 'Only runs under the not-logged-in project');
    }
  });

  /**
   * Discover a real car id from the list page; fixed ids differ per DB.
   * @param {import('@playwright/test').Page} page
   * @returns {Promise<string>} a car id, asserted to be numeric
   */
  async function discoverCarId(page) {
    await page.goto('/app/owner/cars/index.php');
    const firstDetailsLink = page.locator('a[href*="details.php?car_id="]').first();
    await firstDetailsLink.waitFor();
    const href = await firstDetailsLink.getAttribute('href');
    const carId = new URL(href, page.url()).searchParams.get('car_id');
    expect(carId, 'first Details link must carry a car_id').toMatch(/^\d+$/);
    return carId;
  }

  test('anonymous details.php shows a login prompt in #historyCard, with no table, toggle or summary', async ({ page }) => {
    // Register the collector after discovery, so index.php's in-flight
    // DataTable requests are not counted.
    const carId = await discoverCarId(page);

    const consoleErrors = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        consoleErrors.push({ text: msg.text(), url: msg.location().url || '' });
      }
    });

    await page.goto(`/app/owner/cars/details.php?car_id=${carId}`, { waitUntil: 'networkidle' });

    const prompt = page.locator('#historyCard .alert.alert-primary');
    await expect(prompt).toHaveCount(1);
    await expect(prompt).toContainText("to see this car's update history.");

    const loginLink = prompt.locator('a[href*="users/login.php"]');
    await expect(loginLink).toHaveCount(1);

    await expect(page.locator('#carHistoryTable')).toHaveCount(0);
    await expect(page.locator('#historyToggleBtn')).toHaveCount(0);
    await expect(page.locator('#historySummary')).toHaveCount(0);

    // A third-party tile sprite can 404 in test environments for every
    // visitor. Ignore only that parsed hostname.
    const isVersatilesHost = (url) => {
      try {
        return new URL(url).hostname === 'tiles.versatiles.org';
      } catch {
        return false;
      }
    };
    const unexpectedErrors = consoleErrors.filter((e) => !isVersatilesHost(e.url));
    expect(unexpectedErrors, `console errors: ${unexpectedErrors.map((e) => `${e.text} (${e.url})`).join('; ')}`).toEqual([]);
  });

  test('anonymous details.php does not request history-only assets, but does request imagedisplay.min.js', async ({ page }) => {
    // Register after discovery (see the previous test).
    const carId = await discoverCarId(page);

    const requestUrls = [];
    page.on('request', (request) => {
      requestUrls.push(request.url());
    });

    await page.goto(`/app/owner/cars/details.php?car_id=${carId}`, { waitUntil: 'networkidle' });

    const historyOnlyAssets = [
      'car_details.min.js',
      'highlightDifferences.min.js',
      'datatables.min.js',
      'datatables-fixedheader.min.js',
      'datatables-responsive.min.js',
      'datatables.min.css',
    ];
    for (const asset of historyOnlyAssets) {
      const matched = requestUrls.filter((url) => url.includes(asset));
      expect(matched, `unexpected request(s) for ${asset}: ${matched.join(', ')}`).toEqual([]);
    }

    // Proves the request collector actually saw script tags — imagedisplay.min.js
    // is unrelated to history and must still be requested for every visitor.
    expect(requestUrls.some((url) => url.includes('imagedisplay.min.js'))).toBe(true);
  });
});

test.describe('Location picker city disambiguation (#1400)', () => {
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'not-logged-in') {
      testInfo.skip(true, 'Only runs under the not-logged-in project');
    }
  });

  // Needs live geocoding; e2e configs only. location-picker-dedupe.spec.js is
  // the deterministic guard. Asserts distinct same-country results, because
  // a raw count passes without the fix.
  test('searching an ambiguous city name shows multiple distinct same-country results (regression guard)', async ({ page }) => {
    await page.goto('users/join.php');

    const input = page.locator('#location-picker-registration-input');
    const resultsContainer = page.locator('#location-picker-registration-results');

    await input.fill('Springfield');

    // Input is debounced 300ms and API latency varies, so poll.
    await expect(async () => {
      // The "No locations found" item has no <small>, so query <small> directly.
      const texts = await resultsContainer.locator('.list-group-item small').allTextContents();
      const distinctUsResults = new Set(texts.map(t => t.trim()).filter(t => t.includes('United States')));
      expect(distinctUsResults.size).toBeGreaterThan(1);
    }).toPass({ timeout: 15000 });
  });
});

test.describe('Issue #2246 — X-Robots-Tag noindex on the Test host only', () => {
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'not-logged-in') {
      testInfo.skip(true, 'Only runs under the not-logged-in project');
    }
  });

  // The live hosts run LiteSpeed, not the Docker Apache, so check each
  // response type there too. stories/x is a RewriteRule 301.
  const cases = [
    { path: '', status: 200 },
    { path: 'robots.txt', status: 200 },
    { path: 'stories/x', status: 301 },
    { path: 'no-such-page-2246', status: 404 },
  ];

  for (const { path, status } of cases) {
    test(`GET /${path} (${status}) sends noindex only on test.elanregistry.org`, async ({ request, baseURL }) => {
      const response = await request.get(path, { maxRedirects: 0 });
      expect(response.status()).toBe(status);

      const robotsTag = response.headers()['x-robots-tag'];
      if (new URL(baseURL).hostname === 'test.elanregistry.org') {
        expect(robotsTag, 'Test host must send noindex (#2246)').toContain('noindex');
      } else {
        expect(robotsTag, 'Only the Test host sends X-Robots-Tag').toBeUndefined();
      }
    });
  }
});
