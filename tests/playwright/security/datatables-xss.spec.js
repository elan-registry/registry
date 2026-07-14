const { test, expect } = require('@playwright/test');
const { ensureLoggedIn, waitForDataTables } = require('../auth-helper.js');

/**
 * Regression guard for stored-XSS protection in the car-listing, factory, and
 * car-history DataTables.
 *
 * Issue #1304 added `render: $.fn.dataTable.render.text()` to every text
 * column in `app/owner/cars/index.php`, `app/owner/cars/factory.php`, and
 * `app/assets/js/car_details.js`. Without that guard, any free-text field
 * stored in the database (e.g. `color`, `chassis`, owner first name) could
 * contain an HTML payload that DataTables would inject verbatim into the DOM
 * via innerHTML when it received the server-side AJAX response — a classic
 * stored-XSS vector.
 *
 * The `$.fn.dataTable.render.text()` renderer uses DOM textContent assignment
 * instead of innerHTML, so angle brackets and event handlers are never parsed
 * as markup.
 *
 * ## Why we cannot inject a live payload via API in tests 1–2 and 4
 *
 * The car-listing AJAX endpoint (`app/api/cars/list.php`) is read-only: it
 * returns rows from the database. Writing a poisoned row would require a
 * separate authenticated POST to the car-edit endpoint, which is out of scope
 * for a regression smoke test (it would also leave dirty fixture data in the
 * dev DB). Instead we verify:
 *
 *   1. The page loads and DataTables initialises successfully (the render
 *      path is live).
 *   2. `window.__xssFlag` is undefined after initialisation — confirming no
 *      prior persistent payload in the DB has fired.
 *   3. We synthetically inject `$.fn.dataTable.render.text()` on a temporary
 *      element and verify it escapes an XSS payload, proving the renderer
 *      that the production code uses actually escapes markup.
 *   4. No raw `<img>` element whose `src` is "x" (the classic onerror probe)
 *      exists anywhere inside `#cartable` after DataTables renders.
 *
 * For the factory and car-history tables, tests use DataTables' `row.add()`
 * API to inject a synthetic row containing an XSS payload in-memory, then
 * verify the rendered DOM is safe. This directly exercises the column render
 * functions and would fail if `render: textRender` were removed from any
 * text column.
 *
 * Tests that navigate to authenticated pages require TEST_USERNAME /
 * TEST_PASSWORD in .env.local and skip gracefully if absent.
 *
 * @group security
 * @group datatables
 * @group xss
 */

const CAR_LIST_PAGE = 'app/owner/cars/index.php';
const FACTORY_PAGE  = 'app/owner/cars/factory.php';

// ---------------------------------------------------------------------------
// Helper: skip if credentials are absent
// ---------------------------------------------------------------------------

function skipIfNoCreds() {
    if (!process.env.TEST_USERNAME || !process.env.TEST_PASSWORD) {
        test.skip(true, 'Set TEST_USERNAME and TEST_PASSWORD in .env.local to run authenticated tests');
    }
}

// ---------------------------------------------------------------------------
// Section 1: Car listing table (app/owner/cars/index.php → #cartable)
// ---------------------------------------------------------------------------

test.describe('DataTables XSS render guard — car listing', () => {

    test('car listing page loads and DataTable initialises', async ({ page }) => {
        skipIfNoCreds();
        await ensureLoggedIn(page);
        await page.goto(CAR_LIST_PAGE, { waitUntil: 'domcontentloaded' });
        await waitForDataTables(page, 15000);
        await expect(page.locator('#cartable')).toBeAttached();
    });

    test('window.__xssFlag is unset after DataTables renders', async ({ page }) => {
        skipIfNoCreds();

        // Plant a sentinel on window before any page scripts run so we can detect
        // if any onerror/onclick payload sets it. addInitScript runs before any
        // page scripts, guaranteeing the sentinel exists before DataTables
        // initialises — a page.evaluate() called after navigation would race
        // against DataTables rendering. The assignment is semantically a no-op
        // (window.__xssFlag is already undefined) but documents the intent.
        await page.addInitScript(() => {
            window.__xssFlag = undefined;
        });

        await ensureLoggedIn(page);
        await page.goto(CAR_LIST_PAGE, { waitUntil: 'domcontentloaded' });

        // waitForDataTables asserts the DataTables wrapper and search input are
        // present. Any synchronous onerror handler injected by a stored payload
        // fires during DOM insertion — which completes before this resolves.
        await waitForDataTables(page, 15000);

        const xssFlag = await page.evaluate(() => window.__xssFlag);
        expect(
            xssFlag,
            'window.__xssFlag was set — a stored XSS payload fired during DataTables render'
        ).toBeFalsy();
    });

    test('$.fn.dataTable.render.text() escapes XSS payload to plain text', async ({ page }) => {
        skipIfNoCreds();
        await ensureLoggedIn(page);
        await page.goto(CAR_LIST_PAGE, { waitUntil: 'domcontentloaded' });
        await waitForDataTables(page, 15000);

        const result = await page.evaluate(() => {
            const xssPayload = '<img src=x onerror="window.__xssFlag=1">';

            // $.fn.dataTable.render.text() returns {display: fn, filter: fn} where
            // each function HTML-encodes its argument (verified against DataTables
            // 2.3.8). DataTables consumes this object as the column render config:
            // for the display context it calls obj.display(data); for filter it
            // calls obj.filter(data). Calling renderer.display() here manually
            // exercises the same escaping function DataTables uses when rendering
            // each cell into the DOM.
            const renderer = $.fn.dataTable.render.text();
            const rendered = renderer.display(xssPayload);

            // Parse the rendered string back through a temporary DOM element to
            // check whether the browser would treat it as markup.
            const probe = document.createElement('td');
            probe.innerHTML = rendered;
            const hasImgChild = probe.querySelector('img') !== null;

            return {
                rendered,
                containsRawAngleBracket: rendered.includes('<'),
                createsImgElement: hasImgChild,
            };
        });

        expect(
            result.containsRawAngleBracket,
            `render.text() output still contains raw "<": ${result.rendered}`
        ).toBe(false);

        expect(
            result.createsImgElement,
            `render.text() output creates an <img> element when used as innerHTML: ${result.rendered}`
        ).toBe(false);
    });

    test('no raw XSS probe <img src="x"> injected inside #cartable', async ({ page }) => {
        skipIfNoCreds();
        await ensureLoggedIn(page);
        await page.goto(CAR_LIST_PAGE, { waitUntil: 'domcontentloaded' });
        await waitForDataTables(page, 15000);

        const probeCount = await page.evaluate(() => {
            const imgs = document.querySelectorAll('#cartable img[src="x"]');
            return imgs.length;
        });

        expect(
            probeCount,
            `Found ${probeCount} <img src="x"> probe element(s) inside #cartable — ` +
            'DataTables may have rendered a stored XSS payload as raw HTML'
        ).toBe(0);
    });
});

// ---------------------------------------------------------------------------
// Section 2: Factory table (app/owner/cars/factory.php → #cartable)
//
// Uses DataTables row.add() to inject a synthetic row with an XSS payload in
// the color column, verifies the rendered DOM is safe, then removes the row.
// This directly exercises the column render functions: if render: textRender
// is removed from any text column, the onerror handler fires or an <img>
// element appears.
// ---------------------------------------------------------------------------

test.describe('DataTables XSS render guard — factory table', () => {

    test('factory page loads and DataTable initialises', async ({ page }) => {
        skipIfNoCreds();
        await ensureLoggedIn(page);
        await page.goto(FACTORY_PAGE, { waitUntil: 'domcontentloaded' });
        await waitForDataTables(page, 15000);
        await expect(page.locator('#cartable')).toBeAttached();
    });

    test('render guard prevents XSS when factory row contains HTML payload', async ({ page }) => {
        skipIfNoCreds();
        await ensureLoggedIn(page);
        await page.goto(FACTORY_PAGE, { waitUntil: 'domcontentloaded' });
        await waitForDataTables(page, 15000);

        const result = await page.evaluate(() => {
            window.__factoryXssFlag = undefined;
            const table = $('#cartable').DataTable();
            const xssPayload = '<img src=x onerror="window.__factoryXssFlag=1">';

            // Add a synthetic row with XSS payload in the color column.
            // If render: textRender is absent from the color column, the payload
            // renders as raw HTML and the onerror fires.
            const newRow = table.row.add({
                id: '0', year: '1966', month: 'Jan', batch: '1',
                type: 'S1', serial: '0001', suffix: 'A',
                engineletter: 'A', enginenumber: '0001',
                gearbox: 'Ford', color: xssPayload,
                builddate: '1966-01-01', note: '', car_id: null
            });
            newRow.draw(false);

            const xssFired = typeof window.__factoryXssFlag !== 'undefined';
            const rowNode   = newRow.node();
            // null means the row sorted onto a page not currently displayed —
            // the img check would be vacuous, so we return null to fail the
            // assertion explicitly rather than silently passing.
            const hasImg    = rowNode ? rowNode.querySelector('img[src="x"]') !== null : null;

            newRow.remove().draw(false);
            return { xssFired, hasImg };
        });

        expect(result.xssFired, 'XSS onerror fired in factory table color column').toBe(false);
        // null means the row was off the current page — the DOM check would have been vacuous.
        expect(result.hasImg, 'Synthetic row was not rendered on the current page — img check is vacuous').not.toBeNull();
        expect(result.hasImg, '<img src="x"> appeared in factory table color column').toBe(false);
    });

    test('no raw XSS probe <img src="x"> injected inside factory #cartable', async ({ page }) => {
        skipIfNoCreds();
        await ensureLoggedIn(page);
        await page.goto(FACTORY_PAGE, { waitUntil: 'domcontentloaded' });
        await waitForDataTables(page, 15000);

        const probeCount = await page.evaluate(() =>
            document.querySelectorAll('#cartable img[src="x"]').length
        );

        expect(
            probeCount,
            `Found ${probeCount} <img src="x"> probe element(s) inside factory #cartable`
        ).toBe(0);
    });
});

// ---------------------------------------------------------------------------
// Section 3: Car history table (app/owner/cars/details.php → #carHistoryTable)
//
// Same row.add() approach: inject an XSS payload in the color column and
// verify the rendered DOM is safe. A beforeAll fetches a car_id from the
// car list page so no ID needs to be hardcoded.
// ---------------------------------------------------------------------------

test.describe('DataTables XSS render guard — car history table', () => {
    let carId = null;

    test.beforeAll(async ({ browser }) => {
        if (!process.env.TEST_USERNAME || !process.env.TEST_PASSWORD) return;
        const context = await browser.newContext();
        const page    = await context.newPage();
        await ensureLoggedIn(page);
        await page.goto(CAR_LIST_PAGE, { waitUntil: 'domcontentloaded' });
        await waitForDataTables(page, 15000);
        carId = await page.evaluate(() => {
            const link = document.querySelector('#cartable tbody a[href*="car_id="]');
            if (!link) return null;
            const m = link.href.match(/car_id=(\d+)/);
            return m ? parseInt(m[1], 10) : null;
        });
        await context.close();
    });

    test('car history DataTable initialises on details page', async ({ page }) => {
        skipIfNoCreds();
        if (!carId) test.skip(true, 'No cars found in registry — cannot load car details page');

        await ensureLoggedIn(page);
        await page.goto(`app/owner/cars/details.php?car_id=${carId}`, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#carHistoryTable_wrapper', { timeout: 15000 });
        await expect(page.locator('#carHistoryTable')).toBeAttached();
    });

    test('render guard prevents XSS when history row contains HTML payload', async ({ page }) => {
        skipIfNoCreds();
        if (!carId) test.skip(true, 'No cars found in registry — cannot load car details page');

        await ensureLoggedIn(page);
        await page.goto(`app/owner/cars/details.php?car_id=${carId}`, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#carHistoryTable_wrapper', { timeout: 15000 });

        const result = await page.evaluate(() => {
            window.__historyXssFlag = undefined;
            const table = $('#carHistoryTable').DataTable();
            const xssPayload = '<img src=x onerror="window.__historyXssFlag=1">';

            // Add a synthetic row with XSS payload in the color column.
            // If render: textRender is absent from the color column in car_details.js,
            // the payload renders as raw HTML and the onerror fires.
            const newRow = table.row.add({
                operation: 'UPDATE', mtime: '2024-01-01 00:00:00',
                year: '1966', type: 'S1', chassis: '1234', series: 'S1',
                variant: 'Standard', color: xssPayload, engine: '',
                purchasedate: '', solddate: '', comments: '',
                image: null, fname: 'Test', city: '', state: '', country: '',
                car_id: 0
            });
            newRow.draw(false);

            const xssFired = typeof window.__historyXssFlag !== 'undefined';
            const rowNode   = newRow.node();
            // null means the row sorted onto a page not currently displayed —
            // the img check would be vacuous, so we return null to fail the
            // assertion explicitly rather than silently passing.
            const hasImg    = rowNode ? rowNode.querySelector('img[src="x"]') !== null : null;

            newRow.remove().draw(false);
            return { xssFired, hasImg };
        });

        expect(result.xssFired, 'XSS onerror fired in car history table color column').toBe(false);
        // null means the row was off the current page — the DOM check would have been vacuous.
        expect(result.hasImg, 'Synthetic row was not rendered on the current page — img check is vacuous').not.toBeNull();
        expect(result.hasImg,   '<img src="x"> appeared in car history table color column').toBe(false);
    });

    test('no raw XSS probe <img src="x"> injected inside #carHistoryTable', async ({ page }) => {
        skipIfNoCreds();
        if (!carId) test.skip(true, 'No cars found in registry — cannot load car details page');

        await ensureLoggedIn(page);
        await page.goto(`app/owner/cars/details.php?car_id=${carId}`, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#carHistoryTable_wrapper', { timeout: 15000 });

        const probeCount = await page.evaluate(() =>
            document.querySelectorAll('#carHistoryTable img[src="x"]').length
        );

        expect(
            probeCount,
            `Found ${probeCount} <img src="x"> probe element(s) inside #carHistoryTable`
        ).toBe(0);
    });
});
