// Car edit form save payloads. Most tests mock the server with page.route(),
// so no DB row is needed. #796: a text-only save must not re-process
// existing (LOCAL) FilePond images; it sends only a sentinel `blob` file.

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');
const { CAR_ID_STANDARD, CAR_ID_WITH_SPECIAL_CHARS, CAR_ID_WITH_HISTORY } = require('./fixtures.js');

/**
 * Minimal multipart/form-data parser for browser FormData bodies.
 *
 * @param {Buffer} body - Raw request body
 * @param {string} boundary - Boundary string from Content-Type header
 * @returns {Map<string, Array<{value: string|Buffer, filename?: string, size?: number}>>}
 */
function parseMultipart(body, boundary) {
    const fields = new Map();
    const delimiter = Buffer.from('--' + boundary);
    const parts = [];

    let start = 0;
    while (start < body.length) {
        const delimPos = body.indexOf(delimiter, start);
        if (delimPos === -1) {
            break;
        }
        const afterDelim = delimPos + delimiter.length;
        // "--boundary--" signals the final boundary
        if (body[afterDelim] === 0x2d && body[afterDelim + 1] === 0x2d) {
            break;
        }
        // Skip CRLF after boundary
        const partStart = afterDelim + 2;
        const nextDelim = body.indexOf(delimiter, partStart);
        if (nextDelim === -1) {
            break;
        }
        // Part body ends before the CRLF that precedes the next boundary
        const partEnd = nextDelim - 2;
        parts.push(body.slice(partStart, partEnd));
        start = nextDelim;
    }

    for (const part of parts) {
        // Split headers from body at the blank line (\r\n\r\n)
        const headerEnd = part.indexOf('\r\n\r\n');
        if (headerEnd === -1) {
            continue;
        }
        const headerSection = part.slice(0, headerEnd).toString('latin1');
        const bodySection = part.slice(headerEnd + 4);

        // Extract field name
        const nameMatch = headerSection.match(/name="([^"]+)"/);
        if (!nameMatch) {
            continue;
        }
        const name = nameMatch[1];

        // Extract optional filename
        const filenameMatch = headerSection.match(/filename="([^"]*)"/);
        const filename = filenameMatch ? filenameMatch[1] : undefined;

        const entry = filename !== undefined
            ? { value: bodySection, filename, size: bodySection.length }
            : { value: bodySection.toString('utf8') };

        if (!fields.has(name)) {
            fields.set(name, []);
        }
        fields.get(name).push(entry);
    }

    return fields;
}

test.describe('Car edit form — text-only save (regression #796)', () => {

    test.beforeEach(async ({ page }) => {
        // Without credentials login() uses a placeholder account; skip on the real cause.
        test.skip(
            !process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD,
            'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local to run authenticated tests'
        );
        await ensureLoggedIn(page);
    });

    test('text-only save sends sentinel blob and no binary image data', async ({ page }) => {
        await page.route(
            '**/app/api/cars/save.php',
            async (route, request) => {
                const method = request.method();
                const postData = method === 'POST' ? request.postData() || '' : '';

                // fetchImages: ElanRegistryAPI sends action in POST body (multipart), never the query string
                if (postData.includes('action=fetchImages')) {
                    await route.fulfill({
                        status: 200,
                        contentType: 'application/json',
                        body: JSON.stringify({
                            success: true,
                            images: [
                                {
                                    path: 'usersc/uploads/cars/1/existing-photo.jpg',
                                    basename: 'existing-photo.jpg'
                                }
                            ]
                        })
                    });
                    return;
                }

                // The later submit-capture route runs first (LIFO).
                await route.fallback();
            }
        );

        await page.goto(`app/owner/cars/edit.php?car_id=${CAR_ID_STANDARD}`, { waitUntil: 'domcontentloaded' });

        // beforeEach has established an authenticated session, so edit.php must
        // render the form rather than bouncing to the login page.
        expect(page.url(), 'edit.php must render for an authenticated session, not redirect to login').not.toContain('login');

        // Wait for FilePond to initialise (it registers itself on DOMContentLoaded)
        await page.waitForFunction(
            () => typeof window.FilePond !== 'undefined' && document.querySelector('.filepond--root') !== null,
            { timeout: 15000 }
        );

        // fetchImages fires only in update mode, which a GET never sets
        // (#1846), so the pond starts empty. The sentinel assertions hold
        // either way.

        // Registered after the fetchImages route so it runs first (LIFO).
        let capturedRequest = null;

        await page.route('**/app/api/cars/save.php', async (route, request) => {
            if (request.method() === 'POST') {
                const postDataBuffer = request.postDataBuffer();
                const postDataText = request.postData() || '';

                // Only capture the form submission — skip fetchImages/removeImages
                if (!postDataText.includes('action=fetchImages') &&
                    !postDataText.includes('action=removeImages')) {
                    capturedRequest = {
                        buffer: postDataBuffer,
                        contentType: request.headers()['content-type'] || ''
                    };

                    // Return a successful mock response so submitCarForm() resolves
                    await route.fulfill({
                        status: 200,
                        contentType: 'application/json',
                        body: JSON.stringify({
                            success: true,
                            cardetails: { id: 1 }
                        })
                    });
                    return;
                }
            }
            await route.fallback();
        });

        const submitBtn = page.locator('#submit');
        await expect(submitBtn, 'edit.php must render a #submit button').toBeVisible();

        await submitBtn.click();

        // Poll for the Node-side capturedRequest to be populated (up to 8 seconds).
        const deadline = Date.now() + 8000;
        while (capturedRequest === null && Date.now() < deadline) {
            await page.waitForTimeout(100);
        }

        expect(capturedRequest, 'Form submit POST was not captured — did the submit button fire?').not.toBeNull();

        const contentType = capturedRequest.contentType;
        expect(contentType).toContain('multipart/form-data');

        // Extract boundary from Content-Type header
        const boundaryMatch = contentType.match(/boundary=([^\s;]+)/);
        expect(boundaryMatch, 'multipart boundary not found in Content-Type').not.toBeNull();
        const boundary = boundaryMatch[1];

        const body = capturedRequest.buffer;
        expect(body, 'Request body buffer must not be null').not.toBeNull();

        const fields = parseMultipart(body, boundary);

        // --- Assertion A: filenames field is present ---
        // submitCarForm() always appends filenames=, even when it is empty.
        expect(
            fields.has('filenames'),
            'POST must include a "filenames" field — existing file order must be preserved'
        ).toBe(true);

        // --- Assertion B: file[] field is present ---
        expect(
            fields.has('file[]'),
            'POST must include a "file[]" field'
        ).toBe(true);

        const fileEntries = fields.get('file[]');

        // submitCarForm() appends an empty 'blob' when there are no new files.
        const sentinelEntry = fileEntries.find(e => e.filename === 'blob');
        expect(
            sentinelEntry,
            'POST must include a file[] entry with filename="blob" (the no-new-images sentinel)'
        ).toBeDefined();

        // The sentinel is an empty Blob — its body should be zero bytes
        expect(
            sentinelEntry.size,
            'Sentinel blob must be empty (0 bytes) — it is a marker, not image data'
        ).toBe(0);

        // Before the fix, LOCAL files were re-processed into non-empty file[] entries.
        const nonSentinelFileEntries = fileEntries.filter(e => e.filename !== 'blob');
        expect(
            nonSentinelFileEntries.length,
            'POST must NOT contain binary image data in file[] for a text-only save ' +
            '(existing LOCAL images must not be re-processed — regression #796)'
        ).toBe(0);
    });

    // Guard: proves the sentinel check is not always true.
    test('sentinel blob absent when new file is queued for upload', async ({ page }) => {
        // Mock fetchImages to return no existing images (clean pond)
        await page.route('**/app/api/cars/save.php', async (route, request) => {
            const postData = request.postData() || '';
            if (postData.includes('action=fetchImages')) {
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({ success: true, images: [] })
                });
                return;
            }
            await route.fallback();
        });

        await page.goto(`app/owner/cars/edit.php?car_id=${CAR_ID_STANDARD}`, { waitUntil: 'domcontentloaded' });

        expect(page.url(), 'edit.php must render for an authenticated session, not redirect to login').not.toContain('login');

        await page.waitForFunction(
            () => typeof window.FilePond !== 'undefined' && document.querySelector('.filepond--root') !== null,
            { timeout: 15000 }
        );

        // Capture the submit POST
        let capturedRequest = null;
        await page.route('**/app/api/cars/save.php', async (route, request) => {
            if (request.method() === 'POST') {
                const postData = request.postData() || '';
                if (!postData.includes('action=fetchImages') && !postData.includes('action=removeImages')) {
                    capturedRequest = {
                        buffer: request.postDataBuffer(),
                        contentType: request.headers()['content-type'] || ''
                    };
                    await route.fulfill({
                        status: 200,
                        contentType: 'application/json',
                        body: JSON.stringify({ success: true, cardetails: { id: 1 } })
                    });
                    return;
                }
            }
            await route.fallback();
        });

        // Add a synthetic file to FilePond via evaluate() to simulate a new upload
        // without involving the file picker dialog.
        const fileAdded = await page.evaluate(() => {
            const root = document.querySelector('.filepond--root');
            if (!root) { return 'no .filepond--root element'; }
            const instance = window.FilePond && window.FilePond.find(root);
            if (!instance) { return 'window.FilePond.find() returned no instance for .filepond--root'; }

            // Create a minimal 1x1 JPEG blob to act as a new (non-LOCAL) file
            const jpegBytes = new Uint8Array([
                0xFF, 0xD8, 0xFF, 0xE0, 0x00, 0x10, 0x4A, 0x46,
                0x49, 0x46, 0x00, 0x01, 0x01, 0x00, 0x00, 0x01,
                0x00, 0x01, 0x00, 0x00, 0xFF, 0xDB, 0x00, 0x43,
                0x00, 0x08, 0x06, 0x06, 0x07, 0x06, 0x05, 0x08,
                0x07, 0x07, 0x07, 0x09, 0x09, 0x08, 0x0A, 0x0C,
                0x14, 0x0D, 0x0C, 0x0B, 0x0B, 0x0C, 0x19, 0x12,
                0x13, 0x0F, 0x14, 0x1D, 0x1A, 0x1F, 0x1E, 0x1D,
                0x1A, 0x1C, 0x1C, 0x20, 0x24, 0x2E, 0x27, 0x20,
                0x22, 0x2C, 0x23, 0x1C, 0x1C, 0x28, 0x37, 0x29,
                0x2C, 0x30, 0x31, 0x34, 0x34, 0x34, 0x1F, 0x27,
                0x39, 0x3D, 0x38, 0x32, 0x3C, 0x2E, 0x33, 0x34,
                0x32, 0xFF, 0xC0, 0x00, 0x0B, 0x08, 0x00, 0x01,
                0x00, 0x01, 0x01, 0x01, 0x11, 0x00, 0xFF, 0xC4,
                0x00, 0x1F, 0x00, 0x00, 0x01, 0x05, 0x01, 0x01,
                0x01, 0x01, 0x01, 0x01, 0x00, 0x00, 0x00, 0x00,
                0x00, 0x00, 0x00, 0x00, 0x01, 0x02, 0x03, 0x04,
                0x05, 0x06, 0x07, 0x08, 0x09, 0x0A, 0x0B, 0xFF,
                0xC4, 0x00, 0xB5, 0x10, 0x00, 0x02, 0x01, 0x03,
                0x03, 0x02, 0x04, 0x03, 0x05, 0x05, 0x04, 0x04,
                0x00, 0x00, 0x01, 0x7D, 0x01, 0x02, 0x03, 0x00,
                0x04, 0x11, 0x05, 0x12, 0x21, 0x31, 0x41, 0x06,
                0x13, 0x51, 0x61, 0x07, 0x22, 0x71, 0x14, 0x32,
                0x81, 0x91, 0xA1, 0x08, 0x23, 0x42, 0xB1, 0xC1,
                0x15, 0x52, 0xD1, 0xF0, 0x24, 0x33, 0x62, 0x72,
                0x82, 0x09, 0x0A, 0x16, 0x17, 0x18, 0x19, 0x1A,
                0x25, 0x26, 0x27, 0x28, 0x29, 0x2A, 0x34, 0x35,
                0x36, 0x37, 0x38, 0x39, 0x3A, 0x43, 0x44, 0x45,
                0x46, 0x47, 0x48, 0x49, 0x4A, 0x53, 0x54, 0x55,
                0x56, 0x57, 0x58, 0x59, 0x5A, 0x63, 0x64, 0x65,
                0x66, 0x67, 0x68, 0x69, 0x6A, 0x73, 0x74, 0x75,
                0x76, 0x77, 0x78, 0x79, 0x7A, 0x83, 0x84, 0x85,
                0x86, 0x87, 0x88, 0x89, 0x8A, 0x92, 0x93, 0x94,
                0x95, 0x96, 0x97, 0x98, 0x99, 0x9A, 0xA2, 0xA3,
                0xA4, 0xA5, 0xA6, 0xA7, 0xA8, 0xA9, 0xAA, 0xB2,
                0xB3, 0xB4, 0xB5, 0xB6, 0xB7, 0xB8, 0xB9, 0xBA,
                0xC2, 0xC3, 0xC4, 0xC5, 0xC6, 0xC7, 0xC8, 0xC9,
                0xCA, 0xD2, 0xD3, 0xD4, 0xD5, 0xD6, 0xD7, 0xD8,
                0xD9, 0xDA, 0xE1, 0xE2, 0xE3, 0xE4, 0xE5, 0xE6,
                0xE7, 0xE8, 0xE9, 0xEA, 0xF1, 0xF2, 0xF3, 0xF4,
                0xF5, 0xF6, 0xF7, 0xF8, 0xF9, 0xFA, 0xFF, 0xDA,
                0x00, 0x08, 0x01, 0x01, 0x00, 0x00, 0x3F, 0x00,
                0xFB, 0xD3, 0xFF, 0xD9
            ]);
            const blob = new Blob([jpegBytes], { type: 'image/jpeg' });
            const file = new File([blob], 'new-upload.jpg', { type: 'image/jpeg' });

            instance.addFile(file);
            return true;
        });

        expect(fileAdded, 'Could not add a synthetic file to FilePond').toBe(true);

        // Must succeed: a rejected file would make the sentinel assertion vacuous.
        await page.waitForFunction(
            () => {
                const root = document.querySelector('.filepond--root');
                const instance = window.FilePond && window.FilePond.find(root);
                return instance && instance.getFiles().length > 0;
            },
            { timeout: 5000 }
        );

        // Verify the file is present and is NOT a LOCAL file (it has no metadata.serverFilename)
        const fileOrigin = await page.evaluate(() => {
            const root = document.querySelector('.filepond--root');
            const instance = window.FilePond.find(root);
            return instance.getFiles()[0].origin;
        });

        // FileOrigin.INPUT === 1 (user-added), FileOrigin.LOCAL === 3 (already on server)
        const FILEPOND_FILE_ORIGIN_LOCAL = 3;
        expect(fileOrigin).not.toBe(FILEPOND_FILE_ORIGIN_LOCAL);

        // Click submit — FilePond will process the new file via the mock server.process
        // handler (which just calls load() immediately), then submitCarForm() fires.
        const submitBtn = page.locator('#submit');
        await expect(submitBtn, 'edit.php must render a #submit button').toBeVisible();

        await submitBtn.click();

        // Poll for the Node-side capturedRequest (processFiles may take a moment for new files)
        const deadline2 = Date.now() + 8000;
        while (capturedRequest === null && Date.now() < deadline2) {
            await page.waitForTimeout(100);
        }

        // The payload is the whole point of this test — the "no sentinel"
        // assertion below is vacuously true without it, so require it.
        expect(
            capturedRequest,
            'Form submit POST was not captured — processFiles() did not resolve for the synthetic file'
        ).not.toBeNull();

        const contentType = capturedRequest.contentType;
        expect(contentType).toContain('multipart/form-data');

        const boundaryMatch = contentType.match(/boundary=([^\s;]+)/);
        expect(boundaryMatch).not.toBeNull();
        const boundary = boundaryMatch[1];

        const fields = parseMultipart(capturedRequest.buffer, boundary);

        // When new files are present the sentinel blob must NOT be appended,
        // because hasNewFiles is true in submitCarForm().
        const fileEntries = fields.get('file[]') || [];
        const sentinelEntry = fileEntries.find(e => e.filename === 'blob' && e.size === 0);

        expect(
            sentinelEntry,
            'Sentinel blob must NOT be present when new files are uploaded'
        ).toBeUndefined();
    });

    // #838: comments were double-encoded on save (\Input::get()). Checks the
    // POST sends raw Unicode and the textarea shows it unencoded.
    test('comments with special characters save and reload as plain text', async ({ page }) => {
        const SPECIAL_CHARS_INPUT = "it´s original registration — é & ñ";

        await page.route('**/app/api/cars/save.php', async (route, request) => {
            const postData = request.postData() || '';
            if (postData.includes('action=fetchImages')) {
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({ success: true, images: [] })
                });
                return;
            }
            await route.fallback();
        });

        await page.goto(`app/owner/cars/edit.php?car_id=${CAR_ID_WITH_SPECIAL_CHARS}`, { waitUntil: 'domcontentloaded' });

        expect(page.url(), 'edit.php must render for an authenticated session, not redirect to login').not.toContain('login');

        // Wait for FilePond to initialise (confirms the full form JS has loaded)
        await page.waitForFunction(
            () => typeof window.FilePond !== 'undefined' && document.querySelector('.filepond--root') !== null,
            { timeout: 15000 }
        );

        // Verify the comments textarea rendered in the DOM
        const commentsTextarea = page.locator('#comments');
        await expect(commentsTextarea, 'edit.php must render a #comments textarea').toBeVisible();

        // A higher-priority route (LIFO) captures the POST and echoes the value back.
        await commentsTextarea.fill(SPECIAL_CHARS_INPUT);

        let capturedComments = null;

        await page.route('**/app/api/cars/save.php', async (route, request) => {
            if (request.method() === 'POST') {
                const postData = request.postData() || '';
                if (!postData.includes('action=fetchImages') && !postData.includes('action=removeImages')) {
                    // Fail loudly here; a silent null shows as a misleading 8s timeout below.
                    const contentType = request.headers()['content-type'] || '';
                    const boundaryMatch = contentType.match(/boundary=([^\s;]+)/);
                    if (!boundaryMatch) {
                        throw new Error(
                            `[car-edit-text-save #838] Expected multipart/form-data but got: "${contentType}". ` +
                            'Did the JS submit mechanism change?'
                        );
                    }
                    const bodyBuffer = request.postDataBuffer();
                    if (!bodyBuffer) {
                        throw new Error(
                            '[car-edit-text-save #838] request.postDataBuffer() returned null — ' +
                            'Playwright could not retain the POST body.'
                        );
                    }
                    const fields = parseMultipart(bodyBuffer, boundaryMatch[1]);
                    const commentsEntries = fields.get('comments');
                    if (commentsEntries && commentsEntries.length > 0) {
                        capturedComments = commentsEntries[0].value;
                    }

                    // Return a mocked success response that echoes the unencoded
                    // plain-text comments back as the server would after the fix.
                    await route.fulfill({
                        status: 200,
                        contentType: 'application/json',
                        body: JSON.stringify({
                            success: true,
                            cardetails: {
                                id: 650,
                                comments: SPECIAL_CHARS_INPUT
                            }
                        })
                    });
                    return;
                }
            }
            await route.fallback();
        });

        const submitBtn = page.locator('#submit');
        await expect(submitBtn, 'edit.php must render a #submit button').toBeVisible();

        await submitBtn.click();

        // Poll for the captured POST body (up to 8 seconds)
        const deadline = Date.now() + 8000;
        while (capturedComments === null && Date.now() < deadline) {
            await page.waitForTimeout(100);
        }

        // Assertion A: the client does not pre-encode the value.
        expect(
            capturedComments,
            'POST body must contain the comments field — was the form submitted?'
        ).not.toBeNull();

        expect(
            capturedComments,
            'POST comments must not contain HTML entity &amp; — value should be raw Unicode'
        ).not.toContain('&amp;');

        expect(
            capturedComments,
            'POST comments must not contain HTML entity &#039; — value should be raw Unicode'
        ).not.toContain('&#039;');

        expect(
            capturedComments,
            'POST comments must not contain HTML entity &eacute; — value should be raw Unicode'
        ).not.toContain('&eacute;');

        expect(
            capturedComments,
            'POST comments must not contain HTML entity &ntilde; — value should be raw Unicode'
        ).not.toContain('&ntilde;');

        // The exact raw Unicode string must be present
        expect(
            capturedComments,
            'POST comments must equal the raw Unicode input exactly (regression #838)'
        ).toBe(SPECIAL_CHARS_INPUT);

        // Assertion B: a double-encoded value would show entity text in the textarea.
        await page.waitForFunction(
            (expected) => {
                const el = document.getElementById('comments');
                return el && el.value === expected;
            },
            SPECIAL_CHARS_INPUT,
            { timeout: 5000 }
        ).catch((err) => {
            // Only swallow TimeoutError — the assertion below produces the clear failure message.
            // Any other error (navigation, crashed page) should propagate immediately.
            if (err.constructor.name !== 'TimeoutError') { throw err; }
        });

        const textareaValue = await commentsTextarea.inputValue();

        expect(
            textareaValue,
            'Textarea must NOT display HTML entities after reload ' +
            '(regression #838: double-encoding would show &amp;#180;s instead of ´s)'
        ).not.toContain('&amp;');

        expect(
            textareaValue,
            'Textarea must NOT display the HTML entity &#039; after reload (regression #838)'
        ).not.toContain('&#039;');

        expect(
            textareaValue,
            'Textarea must display the raw Unicode string after mock server response (regression #838)'
        ).toBe(SPECIAL_CHARS_INPUT);
    });

    // Existing LOCAL image plus one new upload: only the new file is in
    // file[], the existing name is in filenames=, and no sentinel is sent.
    test('mixed save: existing image preserved in filenames, only new file in file[]', async ({ page }) => {
        // Needs real update mode, which only a POST with action=updateCar sets
        // (#1846). The admin account bypasses the ownership check.
        await page.goto('app/owner/cars/edit.php', { waitUntil: 'domcontentloaded' });

        expect(page.url(), 'edit.php must render for an authenticated session, not redirect to login').not.toContain('login');

        const csrfToken = await page.locator('#csrf').inputValue();
        expect(csrfToken, 'edit.php must render a #csrf hidden field to obtain a token from').toBeTruthy();

        // Do not mock fetchImages: a mock can lose the race with the POST reload.
        await Promise.all([
            page.waitForLoadState('domcontentloaded'),
            page.evaluate(({ csrf, carId }) => {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = window.location.pathname;
                const fields = { csrf, action: 'updateCar', car_id: String(carId) };
                for (const [name, value] of Object.entries(fields)) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = value;
                    form.appendChild(input);
                }
                document.body.appendChild(form);
                form.submit();
            }, { csrf: csrfToken, carId: CAR_ID_WITH_HISTORY }),
        ]);

        await page.waitForFunction(
            () => typeof window.FilePond !== 'undefined' && document.querySelector('.filepond--root') !== null,
            { timeout: 15000 }
        );

        const isUpdateMode = await page.evaluate(() => window.editCarConfig?.isUpdate === true);
        expect(
            isUpdateMode,
            'POST with action=updateCar must render edit.php in real update mode — if this fails, verify E2E_DEV_ADMIN_USERNAME still has admin/editor access and CAR_ID_WITH_HISTORY still exists'
        ).toBe(true);

        // Wait for the real existing image(s) to hydrate
        await page.waitForFunction(
            () => {
                const root = document.querySelector('.filepond--root');
                const instance = window.FilePond && window.FilePond.find(root);
                return instance && instance.getFiles().length > 0;
            },
            { timeout: 10000 }
        );

        // Capture the real hydrated filename(s) so the later assertion checks
        // against actual DB-backed data instead of an assumed mock value.
        const hydratedFilenames = await page.evaluate(() => {
            const root = document.querySelector('.filepond--root');
            const instance = window.FilePond && window.FilePond.find(root);
            return instance.getFiles()
                .filter(f => f.origin === window.FilePond.FileOrigin.LOCAL)
                .map(f => f.getMetadata('serverFilename'));
        });
        expect(hydratedFilenames.length, 'CAR_ID_WITH_HISTORY must have at least one existing photo to hydrate').toBeGreaterThan(0);

        // Capture submit POST
        let capturedRequest = null;
        await page.route('**/app/api/cars/save.php', async (route, request) => {
            if (request.method() === 'POST') {
                const postData = request.postData() || '';
                if (!postData.includes('action=fetchImages') && !postData.includes('action=removeImages')) {
                    capturedRequest = {
                        buffer: request.postDataBuffer(),
                        contentType: request.headers()['content-type'] || ''
                    };
                    await route.fulfill({
                        status: 200,
                        contentType: 'application/json',
                        body: JSON.stringify({ success: true, cardetails: { id: 1 } })
                    });
                    return;
                }
            }
            await route.fallback();
        });

        // Add a synthetic new file alongside the existing LOCAL image
        const fileAdded = await page.evaluate(() => {
            const root = document.querySelector('.filepond--root');
            const instance = window.FilePond && window.FilePond.find(root);
            if (!instance) { return 'window.FilePond.find() returned no instance for .filepond--root'; }
            const jpegBytes = new Uint8Array([0xFF, 0xD8, 0xFF, 0xE0, 0x00, 0x10, 0x4A, 0x46, 0x49, 0x46, 0x00, 0x01, 0x01, 0x00, 0x00, 0x01, 0x00, 0x01, 0x00, 0x00, 0xFF, 0xD9]);
            const file = new File([new Blob([jpegBytes], { type: 'image/jpeg' })], 'new-photo.jpg', { type: 'image/jpeg' });
            instance.addFile(file);
            return true;
        });

        expect(fileAdded, 'Could not add a synthetic file to FilePond').toBe(true);

        // Must succeed: otherwise the "exactly one new upload" assertion fails confusingly.
        await page.waitForFunction(
            () => {
                const root = document.querySelector('.filepond--root');
                const instance = window.FilePond && window.FilePond.find(root);
                return instance && instance.getFiles().length >= 2;
            },
            { timeout: 5000 }
        );

        const submitBtn = page.locator('#submit');
        await expect(submitBtn, 'edit.php must render a #submit button').toBeVisible();

        await submitBtn.click();

        const deadline3 = Date.now() + 8000;
        while (capturedRequest === null && Date.now() < deadline3) {
            await page.waitForTimeout(100);
        }

        // Every assertion below reads this payload — without it they are vacuous.
        expect(
            capturedRequest,
            'Form submit POST was not captured — processFiles() did not resolve for the synthetic file'
        ).not.toBeNull();

        const contentType = capturedRequest.contentType;
        expect(contentType).toContain('multipart/form-data');

        const boundaryMatch = contentType.match(/boundary=([^\s;]+)/);
        expect(boundaryMatch).not.toBeNull();
        const fields = parseMultipart(capturedRequest.buffer, boundaryMatch[1]);

        // filenames= must contain every real existing image (LOCAL file order preserved)
        const filenamesField = (fields.get('filenames') || [])[0];
        expect(filenamesField, 'filenames= field must be present').toBeDefined();
        for (const hydratedName of hydratedFilenames) {
            expect(
                filenamesField.value,
                `filenames= must include the existing LOCAL image basename "${hydratedName}"`
            ).toContain(hydratedName);
        }

        // No sentinel blob — hasNewFiles is true
        const fileEntries = fields.get('file[]') || [];
        const sentinelEntry = fileEntries.find(e => e.filename === 'blob' && e.size === 0);
        expect(sentinelEntry, 'Sentinel blob must NOT be present when new files are uploaded').toBeUndefined();

        // Exactly one non-sentinel file[] entry (the new upload only, not the LOCAL image)
        const realUploads = fileEntries.filter(e => !(e.filename === 'blob' && e.size === 0));
        expect(
            realUploads.length,
            'Only the new file should appear in file[] — the LOCAL image must not be re-uploaded'
        ).toBe(1);
        expect(realUploads[0].filename).toBe('new-photo.jpg');
    });

    // #2295: a slow models.php left #model empty in update mode. Delays
    // models.php by 1.5s and checks #submit stays disabled until #model is set.
    test('update mode: #submit stays disabled until the model dropdown finishes loading', async ({ page }) => {
        // Reproduce the real "Update Car" POST, as in the mixed-save test above —
        // this is the only way to reach real update mode (window.editCarConfig.isUpdate).
        await page.goto('app/owner/cars/edit.php', { waitUntil: 'domcontentloaded' });

        expect(page.url(), 'edit.php must render for an authenticated session, not redirect to login').not.toContain('login');

        const csrfToken = await page.locator('#csrf').inputValue();
        expect(csrfToken, 'edit.php must render a #csrf hidden field to obtain a token from').toBeTruthy();

        // Set the delay before the POST, so it is in effect when the ready handler runs.
        await page.route('**/app/api/cars/models.php', async (route) => {
            await new Promise((resolve) => setTimeout(resolve, 1500));
            await route.fallback();
        });

        const navigationStart = Date.now();

        await Promise.all([
            page.waitForLoadState('domcontentloaded'),
            page.evaluate(({ csrf, carId }) => {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = window.location.pathname;
                const fields = { csrf, action: 'updateCar', car_id: String(carId) };
                for (const [name, value] of Object.entries(fields)) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = value;
                    form.appendChild(input);
                }
                document.body.appendChild(form);
                form.submit();
            }, { csrf: csrfToken, carId: CAR_ID_WITH_HISTORY }),
        ]);

        // A bare evaluate() right after the POST can hit "Execution context was destroyed".
        await page.waitForFunction(() => typeof window.editCarConfig !== 'undefined', { timeout: 15000 });

        const isUpdateMode = await page.evaluate(() => window.editCarConfig?.isUpdate === true);
        expect(
            isUpdateMode,
            'POST with action=updateCar must render edit.php in real update mode — if this fails, verify E2E_DEV_ADMIN_USERNAME still has admin/editor access and CAR_ID_WITH_HISTORY still exists'
        ).toBe(true);

        const submitBtn = page.locator('#submit');
        await expect(submitBtn, 'edit.php must render a #submit button').toBeVisible();

        // --- Assertion A: #submit must still be disabled while models.php is held ---
        const elapsedBeforeCheck = Date.now() - navigationStart;
        expect(
            elapsedBeforeCheck,
            'Check ran too late to be inside the 1.5s models.php delay — the assertion below would be meaningless'
        ).toBeLessThan(1500);

        await expect(
            submitBtn,
            '#submit must be disabled while models.php is still loading (blockSubmit(\'models\') — regression #2295)'
        ).toBeDisabled();

        // --- Assertion B: once models.php resolves, #model is populated and #submit re-enables ---
        const expectedModel = await page.evaluate(() => window.editCarConfig?.model);
        expect(
            expectedModel,
            'window.editCarConfig.model must be a non-empty saved value for CAR_ID_WITH_HISTORY'
        ).toBeTruthy();

        await expect(
            submitBtn,
            '#submit must re-enable once the model dropdown finishes loading (unblockSubmit(\'models\'))'
        ).toBeEnabled({ timeout: 5000 });

        const modelValue = await page.locator('#model').inputValue();
        expect(
            modelValue,
            '#model must be populated with the saved model value after models.php resolves (regression #2295)'
        ).toBe(expectedModel);

        // --- Assertion C: the eventual save POST carries the correct, non-empty model ---
        let capturedModel = null;
        let resolveCapture;
        const capturePromise = new Promise((resolve) => { resolveCapture = resolve; });
        await page.route('**/app/api/cars/save.php', async (route, request) => {
            if (request.method() === 'POST') {
                const postData = request.postData() || '';
                if (!postData.includes('action=fetchImages') && !postData.includes('action=removeImages')) {
                    const contentType = request.headers()['content-type'] || '';
                    const boundaryMatch = contentType.match(/boundary=([^\s;]+)/);
                    if (boundaryMatch) {
                        const bodyBuffer = request.postDataBuffer();
                        if (bodyBuffer) {
                            const fields = parseMultipart(bodyBuffer, boundaryMatch[1]);
                            const modelEntries = fields.get('model');
                            if (modelEntries && modelEntries.length > 0) {
                                capturedModel = modelEntries[0].value;
                            }
                        }
                    }
                    resolveCapture();
                    await route.fulfill({
                        status: 200,
                        contentType: 'application/json',
                        body: JSON.stringify({ success: true, cardetails: { id: 1 } })
                    });
                    return;
                }
            }
            await route.fallback();
        });

        await submitBtn.click();

        // Wait for the route handler to resolve the capture, not a fixed poll —
        // the handler itself signals completion instead of being polled for it.
        await Promise.race([
            capturePromise,
            new Promise((resolve) => setTimeout(resolve, 8000)),
        ]);

        expect(
            capturedModel,
            'Form submit POST was not captured, or carried no "model" field'
        ).not.toBeNull();

        expect(
            capturedModel,
            'Saved model must not be blank in the save POST (regression #2295: a slow models.php previously left #model empty)'
        ).not.toBe('');

        expect(
            capturedModel,
            'Saved model in the save POST must match the car\'s saved model value'
        ).toBe(expectedModel);
    });

    // #2295 failure path: ModelLoader swallows a models.php error and returns
    // an empty list, so #submit must stay blocked. Waits for #message text,
    // not visibility: the pond 'addfile' handler can hide #message. A bare
    // toBeDisabled() would pass without the fix (#submit starts disabled).
    test('update mode: #submit stays disabled when the model dropdown fails to load', async ({ page }) => {
        await page.goto('app/owner/cars/edit.php', { waitUntil: 'domcontentloaded' });

        expect(page.url(), 'edit.php must render for an authenticated session, not redirect to login').not.toContain('login');

        const csrfToken = await page.locator('#csrf').inputValue();
        expect(csrfToken, 'edit.php must render a #csrf hidden field to obtain a token from').toBeTruthy();

        await page.route('**/app/api/cars/models.php', async (route) => {
            await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false }) });
        });

        await Promise.all([
            page.waitForLoadState('domcontentloaded'),
            page.evaluate(({ csrf, carId }) => {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = window.location.pathname;
                const fields = { csrf, action: 'updateCar', car_id: String(carId) };
                for (const [name, value] of Object.entries(fields)) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = value;
                    form.appendChild(input);
                }
                document.body.appendChild(form);
                form.submit();
            }, { csrf: csrfToken, carId: CAR_ID_WITH_HISTORY }),
        ]);

        await page.waitForFunction(() => typeof window.editCarConfig !== 'undefined', { timeout: 15000 });

        const isUpdateMode = await page.evaluate(() => window.editCarConfig?.isUpdate === true);
        expect(
            isUpdateMode,
            'POST with action=updateCar must render edit.php in real update mode'
        ).toBe(true);

        const submitBtn = page.locator('#submit');
        await expect(submitBtn, 'edit.php must render a #submit button').toBeVisible();

        // Wait for the failure to land; #submit is disabled before models.php answers.
        await expect(
            page.locator('#message'),
            'onYearChange() must settle into the models.php failure branch (regression #2295)'
        ).toContainText('model list could not be loaded', { timeout: 10000 });

        await expect(
            submitBtn,
            '#submit must stay disabled when models.php fails — a failed load must not be mistaken for a load that never started (regression #2295)'
        ).toBeDisabled();

        const modelValue = await page.locator('#model').inputValue();
        expect(
            modelValue,
            '#model must not silently hold the saved value when its own option list failed to load'
        ).toBe('');
    });

    // #2295: the list loads but excludes the saved model (real data case).
    // #submit must re-enable. The live models.php response is rewritten to
    // drop the saved model.
    test('update mode: #submit re-enables when the saved model is not valid for its year', async ({ page }) => {
        await page.goto('app/owner/cars/edit.php', { waitUntil: 'domcontentloaded' });

        expect(page.url(), 'edit.php must render for an authenticated session, not redirect to login').not.toContain('login');

        const csrfToken = await page.locator('#csrf').inputValue();
        expect(csrfToken, 'edit.php must render a #csrf hidden field to obtain a token from').toBeTruthy();

        await Promise.all([
            page.waitForLoadState('domcontentloaded'),
            page.evaluate(({ csrf, carId }) => {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = window.location.pathname;
                const fields = { csrf, action: 'updateCar', car_id: String(carId) };
                for (const [name, value] of Object.entries(fields)) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = value;
                    form.appendChild(input);
                }
                document.body.appendChild(form);
                form.submit();
            }, { csrf: csrfToken, carId: CAR_ID_WITH_HISTORY }),
        ]);

        await page.waitForFunction(() => typeof window.editCarConfig !== 'undefined', { timeout: 15000 });

        const isUpdateMode = await page.evaluate(() => window.editCarConfig?.isUpdate === true);
        expect(
            isUpdateMode,
            'POST with action=updateCar must render edit.php in real update mode'
        ).toBe(true);

        const savedModel = await page.evaluate(() => window.editCarConfig?.model);
        expect(savedModel, 'window.editCarConfig.model must be a non-empty saved value for CAR_ID_WITH_HISTORY').toBeTruthy();

        await page.route('**/app/api/cars/models.php', async (route) => {
            const response = await route.fetch();
            const body = await response.json();
            if (body && body.yearModels) {
                for (const year of Object.keys(body.yearModels)) {
                    body.yearModels[year] = body.yearModels[year].filter((m) => m.value !== savedModel);
                }
            }
            await route.fulfill({ response, json: body });
        });

        await Promise.all([
            page.waitForLoadState('domcontentloaded'),
            page.evaluate(({ csrf, carId }) => {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = window.location.pathname;
                const fields = { csrf, action: 'updateCar', car_id: String(carId) };
                for (const [name, value] of Object.entries(fields)) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = value;
                    form.appendChild(input);
                }
                document.body.appendChild(form);
                form.submit();
            }, { csrf: csrfToken, carId: CAR_ID_WITH_HISTORY }),
        ]);

        await page.waitForFunction(() => typeof window.editCarConfig !== 'undefined', { timeout: 15000 });

        const submitBtn = page.locator('#submit');
        const modelSelect = page.locator('#model');
        await expect(submitBtn, 'edit.php must render a #submit button').toBeVisible();

        // Proves "loaded without the saved value" is not treated as "failed to load".
        await expect(
            submitBtn,
            '#submit must not be permanently locked when the saved model is not in a successfully-loaded list (regression: over-broad error check)'
        ).toBeEnabled({ timeout: 10000 });

        const modelValue = await modelSelect.inputValue();
        expect(
            modelValue,
            '#model must not silently hold a value that is not one of its own options'
        ).toBe('');

        await expect(
            modelSelect.locator('option'),
            'the model list must have genuinely loaded (more than just the placeholder)'
        ).not.toHaveCount(1);
    });
});

// Encode-at-output regression (#844). Tests 2 and 3 need CAR_ID_WITH_SPECIAL_CHARS
// in the local DB with special characters in comments.

test.describe('encode-at-output regression — special chars in car text fields (#844)', () => {
    const SPECIAL_CHARS = "O'Brien & Co <é> \"test\"";

    test.beforeEach(async ({ page }) => {
        test.skip(
            !process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD,
            'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local to run authenticated tests'
        );
        await ensureLoggedIn(page);
    });

    test('form submits special chars as plain text (not entity-encoded)', async ({ page }) => {
        // 1. Capture POST body sent to edit.php
        let capturedPostBody = null;

        await page.route('**/app/api/cars/save.php', async (route, request) => {
            if (request.method() === 'POST') {
                const body = request.postData();
                // Only capture non-null, non-empty bodies — a null or empty postData()
                // means no body was sent, which would defeat the polling sentinel below.
                if (body !== null && body !== '') {
                    capturedPostBody = body;
                }
            }
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ success: true, message: 'Car updated successfully.' }),
            });
        });

        // 2. Navigate to edit form
        await page.goto(`app/owner/cars/edit.php?car_id=${CAR_ID_STANDARD}`, { waitUntil: 'domcontentloaded' });

        expect(page.url(), 'edit.php must render for an authenticated session, not redirect to login').not.toContain('login');

        // 3. Fill text fields with special characters
        const commentsField = page.locator('#comments');
        const engineField   = page.locator('#engine');
        const colorField    = page.locator('#color');

        await expect(commentsField, 'edit.php must render a #comments textarea').toBeVisible();

        await commentsField.fill(SPECIAL_CHARS);
        await engineField.fill(SPECIAL_CHARS);
        await colorField.fill(SPECIAL_CHARS);

        // 4. Submit the form
        const submitBtn = page.locator('#submit');
        await submitBtn.click();

        // 5. Poll for the POST to be captured (up to 8 seconds, matching the pattern used
        //    elsewhere in this file — see the #796 and #838 test blocks above).
        const deadline = Date.now() + 8000;
        while (capturedPostBody === null && Date.now() < deadline) {
            await page.waitForTimeout(100);
        }

        // 6. Assert POST body contains plain text — not entity-encoded strings
        expect(capturedPostBody, 'Form submit POST was not captured').not.toBeNull();

        const body = capturedPostBody;
        expect(body, 'POST body must not contain &amp; (HTML entity for &)').not.toContain('&amp;');
        expect(body, "POST body must not contain &#039; (HTML entity for ')").not.toContain('&#039;');
        expect(body, 'POST body must not contain &lt; (HTML entity for <)').not.toContain('&lt;');
        expect(body, 'POST body must not contain &quot; (HTML entity for ")').not.toContain('&quot;');
        expect(body, 'POST body must contain the raw text value').toContain('Brien');
    });

    test('details page renders special chars as readable text', async ({ page }) => {
        // Navigate to the details page for a car with known special chars
        await page.goto(`app/owner/cars/details.php?car_id=${CAR_ID_WITH_SPECIAL_CHARS}`, {
            waitUntil: 'domcontentloaded',
        });

        expect(page.url(), 'details.php must render for an authenticated session, not redirect to login').not.toContain('login');

        // A missing fixture row would make the entity assertions vacuous.
        const bodyText = await page.locator('body').textContent();
        expect(
            bodyText,
            `Car ${CAR_ID_WITH_SPECIAL_CHARS} must exist in the local DB with migrated special-character text — see fixtures.js`
        ).not.toMatch(/not found|does not exist/i);

        // Assert no visible HTML entity strings in any text content
        expect(bodyText, 'Details page must not render literal &amp; entity strings').not.toContain('&amp;');
        expect(bodyText, "Details page must not render literal &#039; entity strings").not.toContain('&#039;');
        expect(bodyText, 'Details page must not render literal &lt; entity strings').not.toContain('&lt;');
    });

    test('edit form textarea pre-fills with plain readable text', async ({ page }) => {
        // Navigate to the edit form for a car with known special chars
        await page.goto(`app/owner/cars/edit.php?car_id=${CAR_ID_WITH_SPECIAL_CHARS}`, {
            waitUntil: 'domcontentloaded',
        });

        expect(page.url(), 'edit.php must render for an authenticated session, not redirect to login').not.toContain('login');

        const commentsField = page.locator('#comments');
        await expect(
            commentsField,
            `edit.php must render a #comments textarea for car ${CAR_ID_WITH_SPECIAL_CHARS} — see fixtures.js`
        ).toBeVisible();

        const textareaValue = await commentsField.inputValue();

        // Assert no HTML entity strings in the textarea value
        expect(textareaValue, 'Textarea must not pre-fill with &amp; entity strings').not.toContain('&amp;');
        expect(textareaValue, "Textarea must not pre-fill with &#039; entity strings").not.toContain('&#039;');
        expect(textareaValue, 'Textarea must not pre-fill with &lt; entity strings').not.toContain('&lt;');
    });
});
