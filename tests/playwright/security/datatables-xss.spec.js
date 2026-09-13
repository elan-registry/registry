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
 * For the car-history table, tests use DataTables' `row.add()` API to inject
 * a synthetic row containing an XSS payload in-memory, then verify the
 * rendered DOM is safe — this table (car_details.js) has no `serverSide`
 * config, so `row.add()`-created rows render normally.
 *
 * The car-listing (id column) and factory (color column) tables instead call
 * their column render functions directly — via `column().init().render()`
 * or `$.fn.dataTable.render.text()` — rather than going through
 * DataTables' row/draw machinery. Both of those tables' `#cartable`
 * instances use `serverSide: true`, under which DataTables' rendering is
 * driven entirely by the AJAX response; a `row.add()`-created row never
 * produces a renderable DOM node regardless of page/search/sort state (see
 * #1853). Calling the render function directly is a faithful invocation of
 * the exact function production wires to each column and would fail if
 * `render: textRender` (or the equivalent custom function) were removed.
 *
 * Tests that navigate to authenticated pages require E2E_DEV_ADMIN_USERNAME /
 * E2E_DEV_ADMIN_PASSWORD in .env.local and skip gracefully if absent.
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
    if (!process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD) {
        test.skip(true, 'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local to run authenticated tests');
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

    test('parseInt guard prevents HTML injection via id column in car listing', async ({ page }) => {
        skipIfNoCreds();
        await ensureLoggedIn(page);
        await page.goto(CAR_LIST_PAGE, { waitUntil: 'domcontentloaded' });
        await waitForDataTables(page, 15000);

        const result = await page.evaluate(() => {
            window.__idXssFlag = undefined;
            const table = $('#cartable').DataTable();
            const xssPayload = '<img src=x onerror="window.__idXssFlag=1">';

            // #cartable uses serverSide: true (car-list.js) — table.row.add()
            // cannot be used to test render functions here: DataTables' own
            // rendering for server-side tables is driven entirely by the
            // AJAX response (see recordsDisplay() in dataTables.js), so a
            // locally-added row's node() is always null regardless of
            // page/search/sort state — confirmed empirically against both
            // an empty and a 1,590-row live table. Instead, call the id
            // column's actual render function directly via the public
            // column().init() API (returns the original column config
            // object passed to DataTable(), including its render function)
            // — this exercises the exact same function car-list.js uses,
            // with no dependency on DataTables' row/draw/server machinery.
            const idColumnConfig = table.column(0).init();
            // 4th arg (meta) intentionally omitted as {} — car-list.js's id
            // renderer never reads it, only (data, type, row).
            const renderedHtml = idColumnConfig.render(xssPayload, 'display', {});

            // Inject the rendered output into a detached DOM element to
            // check whether the browser would execute it as markup — same
            // pattern as the render.text() escaping test above.
            const probe = document.createElement('td');
            probe.innerHTML = renderedHtml;
            const hasImg  = probe.querySelector('img[src="x"]') !== null;
            const hasLink = probe.querySelector('a[href*="car_id="]') !== null;

            const xssFired = typeof window.__idXssFlag !== 'undefined';
            return { xssFired, renderedHtml, hasLink, hasImg };
        });

        expect(result.xssFired, 'XSS onerror fired via non-numeric id value in car listing table').toBe(false);
        expect(result.hasLink,  `Non-numeric id produced a car details link in car listing table: ${result.renderedHtml}`).toBe(false);
        expect(result.hasImg,   `<img src="x"> appeared in id column of car listing table: ${result.renderedHtml}`).toBe(false);
    });
});

// ---------------------------------------------------------------------------
// Section 2: Factory table (app/owner/cars/factory.php → #cartable)
//
// This table uses serverSide: true, so table.row.add() cannot be used to
// test render output (see #1853) — instead calls the color column's render
// function (textRender = $.fn.dataTable.render.text()) directly and checks
// the returned HTML string for injected markup. Fails if render: textRender
// is removed from the color column.
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
            const xssPayload = '<img src=x onerror="window.__factoryXssFlag=1">';

            // #cartable here uses serverSide: true (factory-list.js) — same
            // constraint as the car-listing id-column test above:
            // table.row.add() cannot render a client-side-only row on a
            // server-side DataTable regardless of page/search/sort state
            // (DataTables' rendering for serverSide tables is driven
            // entirely by the AJAX response). factory-list.js's color
            // column uses render: textRender, which is exactly
            // $.fn.dataTable.render.text() (see factory-list.js:4) — the
            // same escaping helper the "$.fn.dataTable.render.text()
            // escapes XSS payload" test above already exercises directly.
            // Do the same here instead of going through DataTables' row
            // machinery.
            const renderer = $.fn.dataTable.render.text();
            const renderedHtml = renderer.display(xssPayload);

            const probe = document.createElement('td');
            probe.innerHTML = renderedHtml;
            const hasImg = probe.querySelector('img[src="x"]') !== null;

            const xssFired = typeof window.__factoryXssFlag !== 'undefined';

            return { xssFired, renderedHtml, hasImg };
        });

        expect(result.xssFired, 'XSS onerror fired in factory table color column').toBe(false);
        expect(result.hasImg, `<img src="x"> appeared in factory table color column: ${result.renderedHtml}`).toBe(false);
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
// verify the rendered DOM is safe. A beforeAll creates a disposable car
// fixture (rather than assuming one already exists in the test DB — see
// issue #1732) so the section passes deterministically regardless of
// ambient DB state; an afterAll cleans it up via the admin delete form,
// the only delete path the app exposes (there is no owner-facing delete
// endpoint). This only works because the shared test account
// (E2E_DEV_ADMIN_USERNAME/E2E_DEV_ADMIN_PASSWORD) is an admin — a fixture needing a
// non-admin-owned car (e.g. #1789) would need a second test identity.
// ---------------------------------------------------------------------------

const ADD_CAR_ENDPOINT    = 'app/api/cars/save.php';
const ADMIN_DELETE_ENDPOINT = 'app/admin/index.php';
const CAR_EDIT_FORM_PAGE  = 'app/owner/cars/edit.php';

async function getCsrfFromOwnerForm(page) {
    await page.goto(CAR_EDIT_FORM_PAGE, { waitUntil: 'domcontentloaded' });
    try {
        return (await page.inputValue('#csrf', { timeout: 3000 })) || null;
    } catch (err) {
        console.error(`getCsrfFromOwnerForm: could not read #csrf on ${CAR_EDIT_FORM_PAGE}: ${err.message}`);
        return null;
    }
}

async function getCsrfFromAdminDeleteForm(page) {
    await page.goto(ADMIN_DELETE_ENDPOINT, { waitUntil: 'domcontentloaded' });
    try {
        return (await page.locator('.delete-form input[name="csrf"]').inputValue({ timeout: 3000 })) || null;
    } catch (err) {
        console.error(`getCsrfFromAdminDeleteForm: could not read delete-form csrf on ${ADMIN_DELETE_ENDPOINT}: ${err.message}`);
        return null;
    }
}

// Navigates to the details page and expands the history table. #historyDetails
// is a Bootstrap collapse, hidden by default on every car (ambient or fixture)
// — the table wrapper only becomes visible once #historyToggleBtn is clicked.
async function openCarHistoryTable(page, carId) {
    await page.goto(`app/owner/cars/details.php?car_id=${carId}`, { waitUntil: 'domcontentloaded' });
    await page.locator('#historyToggleBtn').click();
    await page.waitForSelector('#carHistoryTable_wrapper', { timeout: 15000 });
}

test.describe('DataTables XSS render guard — car history table', () => {
    let carId = null;

    test.beforeAll(async ({ browser }) => {
        if (!process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD) return;
        const context = await browser.newContext();
        const page    = await context.newPage();
        await ensureLoggedIn(page);

        const csrf = await getCsrfFromOwnerForm(page);
        if (!csrf) {
            await context.close();
            throw new Error(`Car history XSS tests require a disposable car fixture; could not read #csrf on ${CAR_EDIT_FORM_PAGE}`);
        }

        const response = await page.request.post(ADD_CAR_ENDPOINT, {
            form: {
                action: 'addCar',
                year: '1966',
                model: 'S3|FHC|36',
                chassis: `TEST-${Date.now()}`,
                chassis_override: '1',
                csrf,
            },
        });
        let failureDetail = `HTTP ${response.status()}: ${await response.text().catch(() => '<unreadable body>')}`;
        if (response.status() === 200) {
            const body = await response.json().catch(() => null);
            carId = body?.cardetails?.id ? parseInt(body.cardetails.id, 10) : null;
            if (!carId) {
                failureDetail = `response had no cardetails.id: ${JSON.stringify(body)}`;
            }
        }
        await context.close();
        if (!carId) {
            throw new Error(`Car history XSS tests require a disposable car fixture; addCar failed (${failureDetail})`);
        }
    });

    test.afterAll(async ({ browser }) => {
        if (!carId) return;
        const context = await browser.newContext();
        const page    = await context.newPage();
        await ensureLoggedIn(page);

        const csrf = await getCsrfFromAdminDeleteForm(page);
        if (!csrf) {
            console.error(
                `[datatables-xss.spec.js] Could not fetch CSRF token to delete fixture car ${carId} — ` +
                'it was NOT cleaned up and remains in the database. Delete manually if needed.'
            );
        } else {
            const response = await page.request.post(ADMIN_DELETE_ENDPOINT, {
                form: {
                    command: 'delete',
                    car_id: String(carId),
                    confirmation: 'DELETE',
                    reason: 'Playwright test fixture cleanup (#1732)',
                    csrf,
                },
            });
            // app/admin/index.php never changes HTTP status on failure — errors
            // (bad confirmation text, car not found, permission denial, DB
            // errors) are queued as a session flash and rendered inline in this
            // same 200 response as userSpiceMessage("<message>",'danger') —
            // a quoted string literal, distinct from the static boilerplate
            // `userSpiceMessage(msg,'danger')` helper definition that's present
            // on every page load regardless of outcome (verified: the literal
            // identifier `msg` never appears quoted, only a real error message
            // does). A status check alone cannot detect these; check the body
            // for the quoted-literal form specifically.
            const body = await response.text().catch(() => '');
            const hasErrorToast = /userSpiceMessage\(\s*"[^"]*"\s*,\s*'danger'\s*\)/.test(body);
            if (response.status() !== 200 || hasErrorToast) {
                console.error(
                    `[datatables-xss.spec.js] Delete request for fixture car ${carId} did not ` +
                    `confirm success (HTTP ${response.status()}${hasErrorToast ? ', error toast present in response' : ''}) — cleanup may have failed; verify manually.`
                );
            }
        }
        await context.close();
    });

    test('car history DataTable initialises on details page', async ({ page }) => {
        skipIfNoCreds();

        await ensureLoggedIn(page);
        await openCarHistoryTable(page, carId);
        await expect(page.locator('#carHistoryTable')).toBeAttached();
    });

    test('render guard prevents XSS when history row contains HTML payload', async ({ page }) => {
        skipIfNoCreds();

        await ensureLoggedIn(page);
        await openCarHistoryTable(page, carId);

        const result = await page.evaluate(() => {
            window.__historyXssFlag = undefined;
            const table = $('#carHistoryTable').DataTable();
            const xssPayload = '<img src=x onerror="window.__historyXssFlag=1">';

            // Add a synthetic row with XSS payload in the color column.
            // If render: textRender is absent from the color column in car_details.js,
            // the payload renders as raw HTML and the onerror fires.
            const newRow = table.row.add({
                operation: 'UPDATE', mtime: '2099-12-31 23:59:59',
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

        await ensureLoggedIn(page);
        await openCarHistoryTable(page, carId);

        const probeCount = await page.evaluate(() =>
            document.querySelectorAll('#carHistoryTable img[src="x"]').length
        );

        expect(
            probeCount,
            `Found ${probeCount} <img src="x"> probe element(s) inside #carHistoryTable`
        ).toBe(0);
    });

    test('render guard prevents XSS in chassis column of history table', async ({ page }) => {
        skipIfNoCreds();

        await ensureLoggedIn(page);
        await openCarHistoryTable(page, carId);

        const result = await page.evaluate(() => {
            window.__chassisXssFlag = undefined;
            const table = $('#carHistoryTable').DataTable();
            const xssPayload = '<img src=x onerror="window.__chassisXssFlag=1">';

            const newRow = table.row.add({
                operation: 'UPDATE', mtime: '2099-12-31 23:59:59',
                year: '1966', type: 'S1', chassis: xssPayload, series: 'S1',
                variant: 'Standard', color: 'Red', engine: '',
                purchasedate: '', solddate: '', comments: '',
                image: null, fname: 'Test', city: '', state: '', country: '',
                car_id: 0
            });
            newRow.draw(false);

            const xssFired = typeof window.__chassisXssFlag !== 'undefined';
            const rowNode   = newRow.node();
            const hasImg    = rowNode ? rowNode.querySelector('img[src="x"]') !== null : null;

            newRow.remove().draw(false);
            return { xssFired, hasImg };
        });

        expect(result.xssFired, 'XSS onerror fired in car history table chassis column').toBe(false);
        expect(result.hasImg, 'Synthetic row was not rendered on the current page — img check is vacuous').not.toBeNull();
        expect(result.hasImg, '<img src="x"> appeared in car history table chassis column').toBe(false);
    });
});
