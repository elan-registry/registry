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
      path: '/app/cars/index.php',
      name: 'List Cars',
      selector: 'h2',
      expectedText: 'List Cars',
    },
    {
      path: '/users/join.php',
      name: 'Register',
      selector: 'h1.h3.text-primary',
      expectedText: 'Join the Lotus Elan Registry',
    },
    {
      path: '/app/reports/statistics.php',
      name: 'Statistics',
      selector: 'h1',
      expectedText: 'Registry Analytics & Statistics',
    },
    {
      path: '/app/cars/identify.php',
      name: 'Identification Guide',
      isRedirect: true,
      expectedRedirectPattern: /docs\/view\.php/,
      selector: 'h1, h2',
      expectedText: '',
    },
    {
      path: '/app/cars/factory.php',
      name: 'Factory Data',
      selector: 'h2',
      expectedText: 'Elan Factory Information',
    },
    {
      path: '/docs/reference-library.php',
      name: 'Reference Library',
      selector: 'h2',
      expectedText: 'Reference Library',
    },
    {
      path: '/docs/car-stories.php',
      name: 'Car Stories',
      selector: 'h2',
      expectedText: 'Car Stories',
    },
    {
      path: '/docs/faq/index.php',
      name: 'FAQ',
      selector: 'h1',
      expectedText: 'FAQ & User Guides',
    },
    {
      path: 'usersc/login.php',
      name: 'Log In',
      selector: '.modal-header',
      expectedText: 'Please Log In',
      isLoginPage: true,
    },
  ];

  pages.forEach(({ path, name, selector, expectedText, isRedirect, expectedRedirectPattern, isLoginPage }) => {
    test(`should be able to reach ${name} page`, async ({ page }) => {
      // Navigate to the page
      const response = await page.goto(path);

      // Layer 1: Check that we got a successful response
      expect(response.status()).toBeLessThan(400);

      // Wait for the page DOM to load (don't wait for all images/resources)
      await page.waitForLoadState('domcontentloaded');

      // Layer 2: CRITICAL - Verify we're NOT on the login page (not redirected to login)
      // Skip this check for the login page itself, since it should contain login.php in URL
      if (!isLoginPage) {
        expect(page.url()).not.toContain('login.php');
      }

      // Layer 3: CRITICAL - Verify actual page content (not just body length)
      if (isRedirect && expectedRedirectPattern) {
        // For pages that redirect, verify the redirect URL matches expected pattern
        expect(page.url()).toMatch(expectedRedirectPattern);
      }

      if (selector && expectedText) {
        // Verify the specific content is visible on the page
        await expect(page.locator(selector)).toContainText(expectedText);
      }

      console.log(`✓ Successfully reached: ${name} (${path})`);
    });
  });
});

test.describe('Internal Links Discovery and Testing (Not Logged In)', () => {
  const pages = [
    { path: '/', name: 'Home' },
    { path: '/app/cars/index.php', name: 'List Cars' },
    { path: '/users/join.php', name: 'Register' },
    { path: '/app/reports/statistics.php', name: 'Statistics' },
    { path: '/app/cars/identify.php', name: 'Identification Guide' },
    { path: '/app/cars/factory.php', name: 'Factory Data' },
    { path: '/docs/reference-library.php', name: 'Reference Library' },
    { path: '/docs/car-stories.php', name: 'Car Stories' },
    { path: '/docs/faq/index.php', name: 'FAQ' },
    { path: 'usersc/login.php', name: 'Log In' },
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
          } catch (e) {
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
    const allInternalLinks = new Map(); // Changed to Map to track which page each link was found on
    const publicPageNames = ['Home', 'List Cars', 'Register', 'Statistics', 'Identification Guide', 'Factory Data', 'Reference Library', 'Car Stories', 'FAQ', 'Log In'];

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
          } catch (e) {
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
        // Exclude /app/cars/details.php links except for car_id=1 (to avoid testing many individual car pages)
        if (link.includes('/app/cars/details.php') && !link.includes('car_id=1')) {
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
