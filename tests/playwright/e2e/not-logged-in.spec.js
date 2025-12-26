const { test, expect } = require('@playwright/test');

test.describe('Elan Registry - All Pages (Not Logged In)', () => {
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
    { path: '/users/login.php', name: 'Log In' },
  ];

  pages.forEach(({ path, name }) => {
    test(`should be able to reach ${name} page`, async ({ page }) => {
      // Navigate to the page
      const response = await page.goto(path);

      // Check that we got a successful response
      expect(response.status()).toBeLessThan(400);

      // Wait for the page to load
      await page.waitForLoadState('networkidle');

      // Verify the page has content
      const bodyText = await page.textContent('body');
      expect(bodyText.length).toBeGreaterThan(0);

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
    { path: '/users/login.php', name: 'Log In' },
  ];

  test('find all internal links across all pages (excluding header)', async ({ page }) => {
    const allInternalLinks = new Map(); // Use Map to track unique links with their source pages

    for (const { path, name } of pages) {
      await page.goto(path);
      await page.waitForLoadState('networkidle');

      // Get all links NOT in the header/nav
      const contentLinks = await page.locator('a:not(header a, nav a)').all();

      for (const link of contentLinks) {
        const href = await link.getAttribute('href');
        const text = await link.textContent();

        // Filter for internal links (elanregistry.org or relative paths)
        if (href && (href.includes('elanregistry.org') || href.startsWith('/'))) {
          // Convert to relative path if it's a full URL
          const relativePath = href.includes('elanregistry.org')
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
    const allInternalLinks = new Set();

    console.log('\n=== Discovering Internal Links ===');
    for (const { path, name } of pages) {
      await page.goto(path);
      await page.waitForLoadState('networkidle');

      const contentLinks = await page.locator('a:not(header a, nav a)').all();

      for (const link of contentLinks) {
        const href = await link.getAttribute('href');

        if (href && (href.includes('elanregistry.org') || href.startsWith('/'))) {
          // Convert to relative path if it's a full URL
          const relativePath = href.includes('elanregistry.org')
            ? new URL(href).pathname
            : href;
          allInternalLinks.add(relativePath);
        }
      }
    }

    const uniqueLinks = Array.from(allInternalLinks).sort();

    // Separate links into navigable pages and downloadable files
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

    console.log(`\n=== Testing Links ===`);
    console.log(`Navigable pages: ${navigableLinks.length}`);
    console.log(`Downloadable files: ${downloadableLinks.length}`);
    console.log(`Total unique links: ${uniqueLinks.length}\n`);

    let successCount = 0;
    let failCount = 0;

    // Test navigable pages
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
