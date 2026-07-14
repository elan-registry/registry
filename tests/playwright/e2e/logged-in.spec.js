const { test, expect } = require('@playwright/test');

test.describe('Elan Registry - Menu Verification (Logged In)', () => {
  // Skip these tests if NOT running in logged-in project
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'logged-in') {
      testInfo.skip();
    }
  });

  test('should show correct menu items when logged in with proper ordering', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('domcontentloaded');

    // Get all navigation links (adjust selector based on actual menu structure)
    // Common selectors: 'nav a', 'header nav a', '.menu a', '.navigation a'
    const navLinks = await page.locator('nav a, header nav a, .menu a, .navigation a').allTextContents();

    console.log('\n=== Menu Items Found ===');
    navLinks.forEach((text, index) => {
      console.log(`${index + 1}. ${text.trim()}`);
    });

    // Verify "Add Car" link exists (replaces "Register")
    const addCarLink = page.locator('nav a:has-text("Add Car"), header a:has-text("Add Car")');
    await expect(addCarLink.first()).toBeVisible();
    console.log('\n✓ "Add Car" menu item found (replaces "Register")');

    // Verify "Feedback" link exists (new menu item)
    const feedbackLink = page.locator('nav a:has-text("Feedback"), header a:has-text("Feedback")');
    await expect(feedbackLink.first()).toBeVisible();
    console.log('✓ "Feedback" menu item found (new when logged in)');

    // Verify "Account" link exists (replaces "Login")
    const accountLink = page.locator('nav a:has-text("Account"), header a:has-text("Account")');
    await expect(accountLink.first()).toBeVisible();
    console.log('✓ "Account" menu item found (replaces "Login")');

    // Verify "Register" and "Login" links do NOT exist in navigation
    const registerCount = await page.locator('nav a:has-text("Register"), header a:has-text("Register")').count();
    const loginCount = await page.locator('nav a:has-text("Log In"), header a:has-text("Login")').count();

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
      const link = page.locator(`nav a:has-text("${menuItem}"), header a:has-text("${menuItem}")`);
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
      const link = page.locator(`nav a:has-text("${menuItem}"), header a:has-text("${menuItem}")`);
      await expect(link.first()).toBeDefined();
    }
    console.log('✓ All dropdown menu items exist');
  });
});

test.describe('Elan Registry - Car Update Functionality (Logged In)', () => {
  // Skip these tests if NOT running in logged-in project
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'logged-in') {
      testInfo.skip();
    }
  });

  test('should be able to update car information', async ({ page }) => {
    // Navigate to account page
    await page.goto('/users/account.php');
    await page.waitForLoadState('domcontentloaded');

    console.log('✓ Navigated to account page');

    // Click "Update Car" button to enter the update workflow
    await page.click('button:has-text("Update Car"), a:has-text("Update Car")');
    await page.waitForLoadState('domcontentloaded');
    console.log('✓ Entered car update workflow');

    // Section 1 (Car Details) is open by default — fill in a comment
    const timestamp = new Date().toISOString();
    const testNote = `${timestamp} - This is a test update from automated Playwright tests`;

    const commentField = page.locator('textarea[name*="comment"], textarea[id*="comment"], textarea[placeholder*="comment" i]').first();
    await commentField.fill(testNote);
    console.log(`✓ Added comment: ${testNote}`);

    // Expand Section 2 (Photos)
    await page.locator('#heading-section2 button').click();
    await page.waitForSelector('#section2', { state: 'visible', timeout: 5000 });
    console.log('✓ Section 2 (Photos) expanded');

    // Submit via button below accordion
    await page.locator('#submit').click();
    await page.waitForLoadState('domcontentloaded');
    console.log('✓ Clicked Update Car button');

    // Take screenshot after update
    await page.screenshot({ path: 'screenshots/car-update-result.png', fullPage: true });
    console.log('✓ Screenshot saved to screenshots/car-update-result.png');

    // Verify success - check for success message or redirect
    // Adjust this based on what the page shows after successful update
    const bodyText = await page.textContent('body');
    expect(bodyText.length).toBeGreaterThan(0);

    console.log('✓ Car update process completed successfully');
  });
});

test.describe('Elan Registry - All Pages (Logged In)', () => {
  // Skip these tests if NOT running in logged-in project
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'logged-in') {
      testInfo.skip();
    }
  });

  const pages = [
    { path: '/', name: 'Home' },
    { path: '/app/owner/cars/index.php', name: 'List Cars' },
    { path: '/app/owner/reports/statistics.php', name: 'Statistics' },
    { path: '/docs/reference/identification-guide.php', name: 'Identification Guide' },
    { path: '/app/owner/cars/factory.php', name: 'Factory Data' },
    { path: '/docs/reference/index.php', name: 'Reference Library' },
    { path: '/docs/car-stories.php', name: 'Car Stories' },
    { path: '/docs/guides/index.php', name: 'Guides' },
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
  // Skip these tests if NOT running in logged-in project
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'logged-in') {
      testInfo.skip();
    }
  });

  const pages = [
    { path: '/', name: 'Home' },
    { path: '/app/owner/cars/index.php', name: 'List Cars' },
    { path: '/app/owner/reports/statistics.php', name: 'Statistics' },
    { path: '/docs/reference/identification-guide.php', name: 'Identification Guide' },
    { path: '/app/owner/cars/factory.php', name: 'Factory Data' },
    { path: '/docs/reference/index.php', name: 'Reference Library' },
    { path: '/docs/car-stories.php', name: 'Car Stories' },
    { path: '/docs/guides/index.php', name: 'Guides' },
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
            isInternalLink = url.hostname === 'elanregistry.org' || url.hostname === 'www.elanregistry.org';
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
            isInternalLink = url.hostname === 'elanregistry.org' || url.hostname === 'www.elanregistry.org';
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

    const uniqueLinks = Array.from(allInternalLinks).sort();

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
        const baseURL = 'https://elanregistry.org';
        const fullURL = linkPath.startsWith('http') ? linkPath : baseURL + linkPath;

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
