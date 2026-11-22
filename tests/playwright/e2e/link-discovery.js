const { expect } = require('@playwright/test');

// Links with these extensions get a HEAD request instead of a page visit.
const DOWNLOAD_EXTENSIONS = ['.pdf', '.zip', '.doc', '.docx', '.xls', '.xlsx', '.jpg', '.jpeg', '.png', '.gif', '.svg'];

/**
 * Return the site-relative path of an internal href, or null for an external
 * or unparsable href. An absolute URL keeps its pathname and query string. A
 * root-relative href is returned as written when it resolves to the page host.
 *
 * @param {string|null} href The raw href attribute
 * @param {string} pageUrl The URL of the page that holds the link
 * @returns {string|null}
 */
function toInternalPath(href, pageUrl) {
  if (!href) {
    return null;
  }
  if (href.startsWith('/')) {
    // Resolve the href, because a leading slash can still name another host:
    // the URL parser reads //host, /\host and /<tab>/host as a host.
    try {
      return new URL(href, pageUrl).host === new URL(pageUrl).host ? href : null;
    } catch (_e) {
      return null;
    }
  }
  if (!href.startsWith('http')) {
    return null;
  }
  // Parse the hostname. A substring match would accept evil.com/elanregistry.org.
  try {
    const url = new URL(href);
    const siteHost = new URL(pageUrl).hostname;
    return url.hostname === siteHost || url.hostname === `www.${siteHost}` ? url.pathname + url.search : null;
  } catch (_e) {
    return null;
  }
}

/**
 * Visit each seed page and collect the internal links in its content.
 * Links in a <header> or <nav> are not collected.
 *
 * @param {import('@playwright/test').Page} page
 * @param {Array<{path: string, name: string}>} seeds Pages to crawl
 * @returns {Promise<Map<string, string[]>>} Each link path, with the names of the seeds that link to it
 */
async function discoverInternalLinks(page, seeds) {
  const foundOn = new Map();

  console.log('\n=== Discovering Internal Links ===');
  for (const { path, name } of seeds) {
    await page.goto(path);
    await page.waitForLoadState('domcontentloaded');

    const contentLinks = await page.locator('a:not(header a, nav a)').all();
    for (const link of contentLinks) {
      const linkPath = toInternalPath(await link.getAttribute('href'), page.url());
      if (linkPath === null) {
        continue;
      }
      const names = foundOn.get(linkPath) ?? [];
      if (!names.includes(name)) {
        names.push(name);
      }
      foundOn.set(linkPath, names);
    }
  }

  return foundOn;
}

/**
 * Split link paths into pages to visit and files to check with HEAD.
 *
 * @param {string[]} links
 * @returns {{navigableLinks: string[], downloadableLinks: string[]}}
 */
function splitDownloadableLinks(links) {
  const navigableLinks = [];
  const downloadableLinks = [];
  for (const link of links) {
    // A link can keep its query string (toInternalPath), so match the path only.
    const linkFile = link.split(/[?#]/)[0].toLowerCase();
    const isDownloadable = DOWNLOAD_EXTENSIONS.some(ext => linkFile.endsWith(ext));
    (isDownloadable ? downloadableLinks : navigableLinks).push(link);
  }
  return { navigableLinks, downloadableLinks };
}

/**
 * Visit each page link and assert a status below 400. Fails when the list is
 * empty, because an empty loop checks nothing (#2189).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string[]} links
 * @param {(linkPath: string, page: import('@playwright/test').Page) => void} [onLoaded] Called after each successful visit
 * @returns {Promise<void>}
 */
async function checkNavigableLinks(page, links, onLoaded = () => {}) {
  expect(links.length, 'Link discovery found no navigable links, so the loop below would check nothing').toBeGreaterThan(0);

  console.log('=== Testing Navigable Pages ===\n');
  for (const linkPath of links) {
    const response = await page.goto(linkPath);
    const status = response.status();
    console.log(`${status < 400 ? '✓' : '✗'} ${linkPath} - Status: ${status}`);
    expect(status, `Status for ${linkPath}`).toBeLessThan(400);
    onLoaded(linkPath, page);
  }
}

/**
 * Send a HEAD request for each file link and assert a status below 400.
 *
 * @param {import('@playwright/test').Page} page The page whose origin resolves each link
 * @param {string[]} links
 * @returns {Promise<void>}
 */
async function checkDownloadableLinks(page, links) {
  console.log('\n=== Testing Downloadable Files ===\n');
  for (const linkPath of links) {
    // Resolve against the current page's origin, not a hard-coded prod host.
    // The scraped path already holds this environment's mount prefix.
    const fullURL = linkPath.startsWith('http') ? linkPath : new URL(linkPath, page.url()).toString();
    const response = await page.context().request.head(fullURL);
    const status = response.status();
    console.log(`${status < 400 ? '✓' : '✗'} ${linkPath} - Status: ${status}`);
    expect(status, `Status for ${linkPath}`).toBeLessThan(400);
  }
}

module.exports = {
  toInternalPath,
  discoverInternalLinks,
  splitDownloadableLinks,
  checkNavigableLinks,
  checkDownloadableLinks,
};
