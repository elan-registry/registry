const { test, expect } = require('@playwright/test');
const { ensureLoggedIn, waitForDataTables } = require('../auth-helper.js');

/**
 * Stored-XSS guard for the car-listing, factory, and car-history DataTables
 * (#1304: text columns use `$.fn.dataTable.render.text()`).
 *
 * The listing and factory tables use serverSide: true, where a row.add() row
 * never renders (#1853), so those tests call the column render function
 * directly. The history table is client-side, so it uses row.add().
 *
 * @group security
 * @group datatables
 * @group xss
 */

const CAR_LIST_PAGE = 'app/owner/cars/index.php';
const FACTORY_PAGE  = 'app/owner/cars/factory.php';

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

        // addInitScript runs before page scripts; a later evaluate() would race DataTables.
        await page.addInitScript(() => {
            window.__xssFlag = undefined;
        });

        await ensureLoggedIn(page);
        await page.goto(CAR_LIST_PAGE, { waitUntil: 'domcontentloaded' });

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

            // render.text() returns {display, filter}; display() is the cell escaping function.
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

            // serverSide table: row.add() never renders (#1853), so call the
            // id column's render function from column().init() directly.
            const idColumnConfig = table.column(0).init();
            // 4th arg (meta) intentionally omitted as {} — car-list.js's id
            // renderer never reads it, only (data, type, row).
            const renderedHtml = idColumnConfig.render(xssPayload, 'display', {});

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

// Section 2: Factory table (app/owner/cars/factory.php → #cartable)
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

            // serverSide table (#1853): call textRender (= render.text()) directly.
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

// Section 3: Car history table (app/owner/cars/details.php → #carHistoryTable)
// A disposable car fixture is created (#1732) and removed through the admin
// delete form. This needs the shared test account to be an admin.
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

// #historyDetails is a collapsed Bootstrap panel until #historyToggleBtn is clicked.
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
            // app/admin/index.php returns 200 on failure and renders the error as
            // userSpiceMessage("<message>",'danger'). The quoted-literal form
            // differs from the helper definition present on every page.
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

            // Without render: textRender on the color column, the onerror fires.
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
            // null (row on another page) fails the assertion instead of passing vacuously.
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
