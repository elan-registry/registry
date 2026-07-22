const { test, expect } = require('@playwright/test');

test.describe('Elan Registry - All Pages (Not Logged In)', () => {
  // Skip these tests if running in logged-in project
  test.beforeEach(async ({ }, testInfo) => {
    if (testInfo.project.name !== 'not-logged-in') {
      testInfo.skip();
    }
  });
  const pages = [
    {
      path: '/',
      name: 'Home',
      selector: 'h1',
      expectedText: 'Lotus Elan Registry',
    },
    {
      path: '/app/owner/cars/index.php',
      name: 'List Cars',
      selector: 'h2',
      expectedText: 'Registry Cars',
    },
    {
      path: '/users/join.php',
      name: 'Register',
      selector: 'h1.h3.text-primary',
      expectedText: 'Join the Lotus Elan Registry',
    },
    {
      path: '/app/owner/reports/statistics.php',
      name: 'Statistics',
      selector: 'h1',
      expectedText: 'Registry Analytics & Statistics',
    },
    {
      path: '/docs/reference/identification-guide.php',
      name: 'Identification Guide',
      selector: 'h1',
      expectedText: 'Lotus Elan Identification Guide',
    },
    {
      path: '/app/owner/cars/factory.php',
      name: 'Factory Data',
      selector: 'h2',
      expectedText: 'Elan Factory Information',
    },
    {
      path: '/docs/',
      name: 'Docs Index',
      selector: 'h1',
      expectedText: 'Documentation',
    },
    {
      path: '/docs/reference/index.php',
      name: 'Reference Index',
      selector: 'h1',
      expectedText: 'Technical Reference',
    },
    {
      path: '/docs/reference/chassis-validation.php',
      name: 'Chassis Validation',
      selector: 'h1',
      expectedText: 'Chassis Validation Rules',
    },
    {
      path: '/docs/reference/paint-colors.php',
      name: 'Paint Colors',
      selector: 'h1',
      expectedText: 'Lotus Elan',
    },
    {
      path: '/docs/reference/technical-articles.php',
      name: 'Technical Articles',
      selector: 'h1',
      expectedText: 'Technical Articles',
    },
    {
      path: '/docs/reference/workshop.php',
      name: 'Workshop & Parts',
      selector: 'h1',
      expectedText: 'Workshop',
    },
    {
      path: '/docs/car-stories.php',
      name: 'Car Stories',
      selector: 'h1',
      expectedText: 'Car Stories',
    },
    {
      path: '/docs/stories/brian_walton/index.php',
      name: 'Brian Walton Story',
      selector: 'h1',
      expectedText: 'Elan Experimental Rally Car',
    },
    {
      path: '/docs/stories/SGO_2F/index.php',
      name: 'SGO 2F Story',
      selector: 'h1',
      expectedText: 'SGO 2F',
    },
    {
      path: '/docs/stories/type26register.php',
      name: 'Type 26 Register',
      selector: 'h2',
      expectedText: 'type26register.com',
    },
    {
      path: '/docs/guides/index.php',
      name: 'Owner Guides',
      selector: 'h1',
      expectedText: 'Owner Guides',
    },
    {
      path: '/docs/guides/car-transfer-faq.php',
      name: 'Car Transfer FAQ',
      selector: 'h1',
      expectedText: 'Car Transfer FAQ',
    },
    {
      path: '/docs/pdf-viewer.php',
      name: 'PDF Viewer',
      selector: 'h1',
      expectedText: 'Document Viewer',
    },
    {
      path: 'usersc/login.php',
      name: 'Log In',
      selector: '.modal-header',
      expectedText: 'Please Log In',
      isLoginPage: true,
    },
    {
      path: '/users/forgot_password.php',
      name: 'Forgot Password',
      selector: 'h2',
      expectedText: 'Reset Password',
    },
  ];

  pages.forEach(({ path, name, selector, expectedText, isLoginPage }) => {
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

      console.log(`✓ Successfully reached: ${name} (${path})`);
    });
  });
});

test.describe('Internal Links Discovery and Testing (Not Logged In)', () => {
  const pages = [
    { path: '/', name: 'Home' },
    { path: '/app/owner/cars/index.php', name: 'List Cars' },
    { path: '/users/join.php', name: 'Register' },
    { path: '/app/owner/reports/statistics.php', name: 'Statistics' },
    { path: '/docs/reference/identification-guide.php', name: 'Identification Guide' },
    { path: '/app/owner/cars/factory.php', name: 'Factory Data' },
    { path: '/docs/reference/index.php', name: 'Reference Index' },
    { path: '/docs/car-stories.php', name: 'Car Stories' },
    { path: '/docs/guides/index.php', name: 'Owner Guides' },
    { path: 'usersc/login.php', name: 'Log In' },
    { path: '/users/forgot_password.php', name: 'Forgot Password' },
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
            isInternalLink = url.hostname === 'elanregistry.org' || url.hostname === 'www.elanregistry.org';
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
            isInternalLink = url.hostname === 'elanregistry.org' || url.hostname === 'www.elanregistry.org';
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
        // Exclude /app/owner/cars/details.php links except for car_id=1 (to avoid testing many individual car pages)
        if (link.includes('/app/owner/cars/details.php') && !link.includes('car_id=1')) {
          // Skip this link - it's a car details page other than car_id=1
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
        const baseURL = 'https://elanregistry.org';
        const fullURL = linkPath.startsWith('http') ? linkPath : baseURL + linkPath;

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
  });

  const BASE = 'https://elanregistry.org';

  const redirects = [
    {
      from: '/docs/embed.php?doc=Elan_26_36_Workshop_Manual.pdf',
      to: '/docs/pdf-viewer.php?subdir=reference/assets&doc=Elan_26_36_Workshop_Manual.pdf',
      label: 'embed.php soft 404 → pdf-viewer.php with subdir',
    },
    {
      from: '/docs/pdf-viewer.php?doc=Elan_26_36_Workshop_Manual.pdf',
      to: '/docs/pdf-viewer.php?subdir=reference/assets&doc=Elan_26_36_Workshop_Manual.pdf',
      label: 'pdf-viewer.php single-param → two-param format',
    },
    {
      from: '/stories/type26register.com/index.html',
      to: '/docs/stories/type26register.com/index.html',
      label: 'stories/* migration',
    },
    // Legacy doc viewer removed in #911
    {
      from: '/docs/view.php?doc=IDENTIFICATION_GUIDE.md',
      to: '/docs/reference/identification-guide.php',
      label: 'docs/view.php IDENTIFICATION_GUIDE.md',
    },
    {
      from: '/docs/view.php?doc=CAR_TRANSFER_FAQ.md',
      to: '/docs/guides/car-transfer-faq.php',
      label: 'docs/view.php CAR_TRANSFER_FAQ.md',
    },
    {
      from: '/docs/guide-viewer.php?doc=CAR_TRANSFER_FAQ.md',
      to: '/docs/guides/car-transfer-faq.php',
      label: 'docs/guide-viewer.php CAR_TRANSFER_FAQ.md',
    },
    {
      from: '/docs/guide-viewer.php?doc=CAR_TRANSFER_USER_GUIDE.md',
      to: '/docs/guides/',
      label: 'docs/guide-viewer.php CAR_TRANSFER_USER_GUIDE.md',
    },
    {
      from: '/app/car_details.php?car_id=100',
      to: '/app/owner/cars/details.php?car_id=100',
      label: '/app/car_details.php preserves car_id query string',
    },
    {
      from: '/app/identification.php',
      to: '/docs/reference/identification-guide.php',
      label: '/app/identification.php legacy path',
    },
    {
      from: '/app/list_cars.php',
      to: '/app/owner/cars/index.php',
      label: '/app/list_cars.php legacy path',
    },
    {
      from: '/list_cars.php',
      to: '/app/owner/cars/index.php',
      label: '/list_cars.php root-level legacy path',
    },
    {
      from: '/guide.php',
      to: '/docs/',
      label: '/guide.php legacy guide index',
    },
    // Duplicate PDF path: /docs/assets/ renamed to /docs/reference/assets/ in #715
    {
      from: '/docs/assets/Elan_26_36_Workshop_Manual.pdf',
      to: '/docs/reference/assets/Elan_26_36_Workshop_Manual.pdf',
      label: '/docs/assets/ → /docs/reference/assets/ (duplicate PDF path)',
    },
  ];

  redirects.forEach(({ from, to, label }) => {
    test(`301: ${label}`, async ({ request }) => {
      const response = await request.get(`${BASE}${from}`, { maxRedirects: 0 });
      expect(response.status(), `Expected 301 for ${from}`).toBe(301);
      const location = response.headers()['location'] ?? '';
      // Normalize absolute Location headers to path + query for comparison
      const locationPath = location.startsWith('http')
        ? new URL(location).pathname + new URL(location).search
        : location;
      expect(locationPath, `Expected Location: ${to} for ${from}`).toBe(to);
    });
  });
});
