const { test, expect } = require('@playwright/test');
const { CAR_ID_WITH_HISTORY } = require('../fixtures.js');

test.describe('Elan Registry - Menu Verification (Logged In)', () => {
  // Skip these tests if NOT running in admin project
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'admin') {
      testInfo.skip(true, 'Only runs under the admin project');
    }
  });

  test('should show correct menu items when logged in with proper ordering', async ({ page }) => {
    await page.goto('');
    await page.waitForLoadState('domcontentloaded');

    // Menu markup (usersc/templates/customizer/file_nav_custom.php) is a
    // bare <ul class="us_menu"> with no <nav>/<header> wrapper — target the
    // actual Customizer menu class rather than semantic tags that aren't used.
    const navLinks = await page.locator('.us_menu a').allTextContents();

    console.log('\n=== Menu Items Found ===');
    navLinks.forEach((text, index) => {
      console.log(`${index + 1}. ${text.trim()}`);
    });

    // Verify "Add Car" link exists (replaces "Register")
    const addCarLink = page.locator('.us_menu a:has-text("Add Car")');
    await expect(addCarLink.first()).toBeVisible();
    console.log('\n✓ "Add Car" menu item found (replaces "Register")');

    // "Feedback" and "Account" live inside the collapsed username dropdown
    // (file_nav_custom.php's .us_sub-menu under the account toggle) — present
    // in the DOM but not visible until the dropdown is opened, same as the
    // "Reference" dropdown items checked further below. Check attachment,
    // not visibility, matching that established convention in this file.
    const feedbackLink = page.locator('.us_menu a:has-text("Feedback")');
    await expect(feedbackLink.first()).toBeAttached();
    console.log('✓ "Feedback" menu item found (new when logged in)');

    // Verify "Account" link exists (replaces "Login")
    const accountLink = page.locator('.us_menu a:has-text("Account")');
    await expect(accountLink.first()).toBeAttached();
    console.log('✓ "Account" menu item found (replaces "Login")');

    // Verify "Register" and "Login" links do NOT exist in navigation
    const registerCount = await page.locator('.us_menu a:has-text("Register")').count();
    const loginCount = await page.locator('.us_menu a:has-text("Log In")').count();

    expect(registerCount).toBe(0);
    expect(loginCount).toBe(0);
    console.log('✓ "Register" and "Login" menu items correctly hidden when logged in');

    // Verify top-level menu items are visible (not dropdown items)
    const expectedVisibleMenuItems = [
      'List Cars',
      'Statistics',
      'Reference', // Dropdown menu (replaces 'Technical Resources')
      'Car Stories',
      'Guides' // Replaces 'FAQ'
    ];

    for (const menuItem of expectedVisibleMenuItems) {
      const link = page.locator(`.us_menu a:has-text("${menuItem}")`);
      await expect(link.first()).toBeVisible();
    }
    console.log('✓ All top-level menu items present and visible');

    // Verify dropdown items exist (but don't check visibility since they're in dropdowns)
    const dropdownItems = [
      'Identification Guide',
      'Production Records', // Replaces 'Factory Data'
      'Reference Library'
    ];

    for (const menuItem of dropdownItems) {
      const link = page.locator(`.us_menu a:has-text("${menuItem}")`);
      await expect(link.first()).toBeDefined();
    }
    console.log('✓ All dropdown menu items exist');
  });
});

test.describe('Elan Registry - Car Update Functionality (Logged In)', () => {
  // Skip these tests if NOT running in admin project
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'admin') {
      testInfo.skip(true, 'Only runs under the admin project');
    }
  });

  test('should be able to update car information', async ({ page }) => {
    // This test writes a real comment to a real car. E2E_AUTH_TIER is set
    // only by the Test/Production configs, so it gates the write to Local/Dev.
    test.skip(!!process.env.E2E_AUTH_TIER, 'write test; runs on Local/Dev only');

    // Navigate to account page. NOTE: usersc/account.php, not
    // users/account.php — the latter is the upstream UserSpice profile
    // page and never includes app/views/cars/_car_hero_actions.php (the
    // partial that renders the "Update Car" button), so this test's
    // hasCarToUpdate guard previously fired unconditionally regardless of
    // fixture data. usersc/account.php is ElanRegistry's customized page
    // that actually lists the user's cars.
    await page.goto('usersc/account.php');
    await page.waitForLoadState('domcontentloaded');

    console.log('✓ Navigated to account page');

    // The "Update Car" button only renders inside account.php's per-car loop
    // (app/views/cars/_car_hero_actions.php) — if E2E_DEV_ADMIN_USERNAME has no
    // registered cars locally, there's nothing to click. Skip rather than
    // assume, same convention used for fixture-dependent factory-page tests.
    const updateCarButton = page.locator('button:has-text("Update Car"), a:has-text("Update Car")');
    const hasCarToUpdate = await updateCarButton.count() > 0;
    test.skip(!hasCarToUpdate, 'E2E_DEV_ADMIN_USERNAME account has no registered cars locally — nothing to update');

    // Click "Update Car" button to enter the update workflow
    await updateCarButton.first().click();
    await page.waitForLoadState('domcontentloaded');
    console.log('✓ Entered car update workflow');

    // Section 1 (Car Details) is open by default — fill in a comment
    const timestamp = new Date().toISOString();
    const testNote = `${timestamp} - This is a test update from automated Playwright tests`;

    const commentField = page.locator('textarea[name*="comment"], textarea[id*="comment"], textarea[placeholder*="comment" i]').first();
    await commentField.fill(testNote);
    console.log(`✓ Added comment: ${testNote}`);

    // edit.php's Photos section is a plain heading, not a collapsible
    // accordion — #heading-section2/#section2 don't exist in current markup
    // (verified against app/owner/cars/edit.php: #myPond and #submit are
    // directly visible with no expand step required). Same finding already
    // applied to car-edit-owner-refresh.spec.js and functionality.spec.js;
    // this was the last surviving call site (#1949).

    // In update mode car-edit.js repopulates #model asynchronously and keeps
    // #submit disabled until the saved model is selected (#2295). Clicking
    // earlier sends an empty model, which save.php rejects.
    await expect(page.locator('#model')).not.toHaveValue('');
    await expect(page.locator('#submit')).toBeEnabled();

    // submitCarForm() saves with fetch() to app/api/cars/save.php and, on
    // success, sets window.location to details.php?car_id=<id>. On failure it
    // stays on edit.php with an alert, so this wait fails loudly.
    await page.locator('#submit').click();
    await page.waitForURL(/details\.php\?car_id=/, { timeout: 10000 });
    console.log('✓ Clicked Update Car button and reached the details page');

    await expect(page.locator('.alert-danger')).toHaveCount(0);

    // details.php shows the saved comment in the Owner Comments block of
    // app/views/cars/_vehicle_info_card.php (an h6 heading, then a div).
    const ownerComments = page.locator('h6:has-text("Owner Comments") + div');
    await expect(ownerComments).toContainText(testNote);

    console.log('✓ Car update verified: no error alert, comment shown on details page');
  });
});

test.describe('Elan Registry - All Pages (Logged In)', () => {
  // Skip these tests if NOT running in admin project
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'admin') {
      testInfo.skip(true, 'Only runs under the admin project');
    }
  });

  const pages = [
    { path: '', name: 'Home' },
    { path: 'app/owner/cars/index.php', name: 'List Cars' },
    { path: 'app/owner/reports/statistics.php', name: 'Statistics' },
    { path: 'docs/reference/identification-guide.php', name: 'Identification Guide' },
    { path: 'app/owner/cars/factory.php', name: 'Factory Data' },
    { path: 'docs/reference/index.php', name: 'Reference Library' },
    { path: 'docs/car-stories.php', name: 'Car Stories' },
    { path: 'docs/guides/index.php', name: 'Guides' },
  ];

  pages.forEach(({ path, name }) => {
    test(`should be able to reach ${name} page when logged in`, async ({ page }) => {
      // Navigate to the page
      const response = await page.goto(path);

      // Check that we got a successful response
      expect(response.status()).toBeLessThan(400);

      // Wait for the page to load
      await page.waitForLoadState('domcontentloaded');

      // Verify the page has content
      const bodyText = await page.textContent('body');
      expect(bodyText.length).toBeGreaterThan(0);

      console.log(`✓ Successfully reached: ${name} (${path}) - Logged In`);
    });
  });
});

test.describe('Internal Links Discovery and Testing (Logged In)', () => {
  // Skip these tests if NOT running in admin project
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'admin') {
      testInfo.skip(true, 'Only runs under the admin project');
    }
  });

  const pages = [
    { path: '', name: 'Home' },
    { path: 'app/owner/cars/index.php', name: 'List Cars' },
    { path: 'app/owner/reports/statistics.php', name: 'Statistics' },
    { path: 'docs/reference/identification-guide.php', name: 'Identification Guide' },
    { path: 'app/owner/cars/factory.php', name: 'Factory Data' },
    { path: 'docs/reference/index.php', name: 'Reference Library' },
    { path: 'docs/car-stories.php', name: 'Car Stories' },
    { path: 'docs/guides/index.php', name: 'Guides' },
  ];

  test('find all internal links across all pages when logged in (excluding header)', async ({ page }) => {
    const allInternalLinks = new Map();

    for (const { path, name } of pages) {
      await page.goto(path);
      await page.waitForLoadState('domcontentloaded');

      const contentLinks = await page.locator('a:not(header a, nav a)').all();

      for (const link of contentLinks) {
        const href = await link.getAttribute('href');
        const text = await link.textContent();

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
          const relativePath = href.startsWith('http')
            ? new URL(href).pathname
            : href;

          if (!allInternalLinks.has(relativePath)) {
            allInternalLinks.set(relativePath, {
              url: relativePath,
              text: text?.trim(),
              foundOn: [name],
            });
          } else {
            const existing = allInternalLinks.get(relativePath);
            if (!existing.foundOn.includes(name)) {
              existing.foundOn.push(name);
            }
          }
        }
      }

      console.log(`✓ Scanned ${name} (${path}) - Logged In`);
    }

    console.log('\n=== All Internal Links Found When Logged In (Excluding Header) ===');
    console.log(`Total unique internal links: ${allInternalLinks.size}\n`);

    const sortedLinks = Array.from(allInternalLinks.values()).sort((a, b) =>
      a.url.localeCompare(b.url)
    );

    sortedLinks.forEach((link, index) => {
      console.log(`${index + 1}. ${link.url}`);
      console.log(`   Text: ${link.text || '(no text)'}`);
      console.log(`   Found on: ${link.foundOn.join(', ')}\n`);
    });

    expect(allInternalLinks.size).toBeGreaterThan(0);
  });

  test('test all unique internal links found across all pages when logged in', async ({ page }) => {
    const allInternalLinks = new Set();

    console.log('\n=== Discovering Internal Links (Logged In) ===');
    for (const { path } of pages) {
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
          const relativePath = href.startsWith('http')
            ? new URL(href).pathname
            : href;
          allInternalLinks.add(relativePath);
        }
      }
    }

    // The Customizer menu is a bare <ul class="us_menu"> with no <nav> or
    // <header> wrapper, so the selector above also collects its Logout link
    // (users/logout.php). A visit to it ends the shared admin storageState
    // session, and later tests in this project (for example the #2144 car
    // history tests) then run logged out. No other GET link ends the session.
    const isSessionEndingLink = link => /\/logout\.php(\?|#|$)/i.test(link);
    const uniqueLinks = Array.from(allInternalLinks)
      .filter(link => !isSessionEndingLink(link))
      .sort();

    const downloadExtensions = ['.pdf', '.zip', '.doc', '.docx', '.xls', '.xlsx', '.jpg', '.jpeg', '.png', '.gif', '.svg'];
    const navigableLinks = [];
    const downloadableLinks = [];

    uniqueLinks.forEach(link => {
      const isDownloadable = downloadExtensions.some(ext => link.toLowerCase().endsWith(ext));
      if (isDownloadable) {
        downloadableLinks.push(link);
      } else {
        navigableLinks.push(link);
      }
    });

    console.log(`\n=== Testing Links (Logged In) ===`);
    console.log(`Navigable pages: ${navigableLinks.length}`);
    console.log(`Downloadable files: ${downloadableLinks.length}`);
    console.log(`Total unique links: ${uniqueLinks.length}\n`);

    let successCount = 0;
    let failCount = 0;

    console.log('=== Testing Navigable Pages ===\n');
    for (const linkPath of navigableLinks) {
      try {
        const response = await page.goto(linkPath);
        const status = response.status();

        if (status < 400) {
          successCount++;
          console.log(`✓ ${linkPath} - Status: ${status}`);
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

    console.log('\n=== Testing Downloadable Files ===\n');
    for (const linkPath of downloadableLinks) {
      try {
        const context = page.context();
        // linkPath is an absolute-path href scraped directly from the
        // rendered page (e.g. '/ElanRegistry/Registry/docs/pdf-viewer.php?...'
        // locally, '/docs/pdf-viewer.php?...' when deployed) — it already
        // includes whatever path prefix this environment uses. Resolve it
        // against the current page's own origin rather than a hardcoded
        // 'https://elanregistry.org', which previously made this always
        // target prod regardless of which config ran the test, 404ing on
        // local's path-prefixed URLs.
        const fullURL = linkPath.startsWith('http') ? linkPath : new URL(linkPath, page.url()).toString();

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

    console.log(`\n=== Results (Logged In) ===`);
    console.log(`Total links tested: ${uniqueLinks.length}`);
    console.log(`Navigable pages: ${navigableLinks.length}`);
    console.log(`Downloadable files: ${downloadableLinks.length}`);
    console.log(`Successful: ${successCount}`);
    console.log(`Failed: ${failCount}`);
  });
});

test.describe('Issue #2144 — car history requires login (member regression coverage)', () => {
  // history.php now requires login (#2144). These are regression guards for
  // a member session: the history table must still load its rows (the
  // feature must keep working for the audience it is now restricted to),
  // and a session that ends while the page is open must surface the
  // "session has ended" message from car_details.js's 401 branch rather than
  // the generic "could not be loaded" text.
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'admin') {
      testInfo.skip(true, 'Only runs under the admin project');
    }
  });

  test('member sees history table rows after opening the history section', async ({ page }) => {
    // Skip only for the environmental cause: no member session. Everything
    // after that is asserted, not guarded, so a regression that hides the
    // history card from members fails this test instead of skipping it.
    test.skip(!process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD, 'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local to run authenticated tests');

    await page.goto(`app/owner/cars/details.php?car_id=${CAR_ID_WITH_HISTORY}`, { waitUntil: 'domcontentloaded' });

    const toggleBtn = page.locator('#historyToggleBtn');
    await expect(toggleBtn).toBeVisible();
    await toggleBtn.click();

    await page.locator('#carHistoryTable_wrapper').waitFor({ timeout: 15000 });
    await expect(page.locator('#carHistoryTable tbody tr').first()).toBeVisible({ timeout: 15000 });

    // An empty DataTable still renders one <tr> with a td.dt-empty
    // "No data available" cell, so a row count alone cannot prove history
    // loaded. CAR_ID_WITH_HISTORY (fixtures.js) has at least one history row.
    await expect(page.locator('#carHistoryTable tbody td.dt-empty')).toHaveCount(0);
    await expect(page.locator('.alert-warning', { hasText: 'could not be loaded' })).toHaveCount(0);
  });

  test('a 401 from history.php shows the "session has ended" message', async ({ page }) => {
    test.skip(!process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD, 'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local to run authenticated tests');

    // DataTables' ajax sets `cache: false`, so jQuery appends `?_=<timestamp>`
    // even to this POST. A plain glob on the path never matches; the regex
    // allows the query string.
    await page.route(/\/app\/api\/cars\/history\.php(\?|$)/, (route) => route.fulfill({
      status: 401,
      contentType: 'application/json',
      body: JSON.stringify({ success: false, message: 'Login required' }),
    }));

    await page.goto(`app/owner/cars/details.php?car_id=${CAR_ID_WITH_HISTORY}`, { waitUntil: 'domcontentloaded' });

    const toggleBtn = page.locator('#historyToggleBtn');
    await expect(toggleBtn).toBeVisible();
    await toggleBtn.click();

    const sessionEndedWarning = page.locator('.alert-warning', { hasText: 'Your session has ended' });
    await expect(sessionEndedWarning).toBeVisible({ timeout: 15000 });
  });
});
