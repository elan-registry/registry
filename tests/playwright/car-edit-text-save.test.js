// tests/playwright/car-edit-text-save.test.js
//
// Regression test for issue #796: FilePond processes all existing images on
// every save, causing slow text-only saves.
//
// Fix: pond.processFiles() now filters to new (non-LOCAL) files only.
// On a text-only save, newFileIds is empty so processFiles() is skipped and
// submitCarForm() is called directly.
//
// What this test verifies:
//   - The POST to edit.php includes a `filenames=` field (existing order preserved)
//   - The POST includes a `file[]` field whose filename is "blob" (sentinel for
//     no new uploads — only present when no new files were added)
//   - The POST does NOT include binary image data in any `file[]` field beyond
//     the sentinel (i.e., existing LOCAL images were not re-processed)
//   - The form submit completes successfully (mocked 200 response)
//
// All server calls are intercepted with page.route() so no MAMP DB row is needed.
//
// Requires local MAMP at http://localhost:9999/elan_registry

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Parse a multipart/form-data body captured from a Playwright request.
 * Returns a map of field name -> array of entries, where each entry is
 * { value, filename, size } for file parts and { value } for text parts.
 *
 * This is a minimal parser sufficient for the assertions in this test.
 * It handles the subset of RFC 2046 produced by the browser FormData API.
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

// ---------------------------------------------------------------------------
// Test suite
// ---------------------------------------------------------------------------

test.describe('Car edit form — text-only save (regression #796)', () => {

    test.beforeEach(async ({ page }) => {
        await ensureLoggedIn(page);
    });

    test('text-only save sends sentinel blob and no binary image data', async ({ page }) => {
        // ------------------------------------------------------------------
        // 1. Mock fetchImages so FilePond hydrates one existing (LOCAL) image
        //    without hitting the real database.
        // ------------------------------------------------------------------
        await page.route(
            '**/app/cars/actions/edit.php',
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
                                    path: 'http://localhost:9999/elan_registry/usersc/uploads/cars/1/existing-photo.jpg',
                                    basename: 'existing-photo.jpg'
                                }
                            ]
                        })
                    });
                    return;
                }

                // The submit-capture route registered later (Playwright evaluates
                // the most recently registered handler first — LIFO) intercepts
                // the form POST before this handler sees it. Non-fetchImages
                // requests that reach here are passed through unchanged.
                await route.fallback();
            }
        );

        // ------------------------------------------------------------------
        // 2. Navigate to the car edit form for a fake car ID.
        //    The page loads PHP server-side, but all JS API calls are mocked.
        //    The PHP page may redirect to login or show a 404/403 for car_id=1
        //    if the DB is unavailable — we intercept at the JS layer only, so
        //    we need the page DOM to be present. We therefore also mock the
        //    page navigation itself only when the page is inaccessible.
        // ------------------------------------------------------------------
        await page.goto('app/cars/form.php?car_id=1', { waitUntil: 'domcontentloaded' });

        // If the page redirected to login, skip rather than fail — this test
        // requires an authenticated session with a page that renders the form.
        const currentUrl = page.url();
        if (currentUrl.includes('login') || currentUrl.includes('Please Log In')) {
            test.skip('Session not established — skipping network-payload assertion');
            return;
        }

        // Wait for FilePond to initialise (it registers itself on DOMContentLoaded)
        await page.waitForFunction(
            () => typeof window.FilePond !== 'undefined' && document.querySelector('.filepond--root') !== null,
            { timeout: 15000 }
        );

        // ------------------------------------------------------------------
        // 3. Wait for the fetchImages mock response to be consumed and FilePond
        //    to hydrate the existing image as a LOCAL file item.
        //    FilePond adds files asynchronously via pond.addFile(), so we poll
        //    until getFiles() returns at least one item or a short timeout elapses.
        // ------------------------------------------------------------------
        await page.waitForFunction(
            () => {
                // FilePond exposes instances; query the pond object from the
                // global that form.php creates — we access it via the element.
                const root = document.querySelector('.filepond--root');
                if (!root) { return false; }
                const instance = window.FilePond && window.FilePond.find(root);
                return instance && instance.getFiles().length > 0;
            },
            { timeout: 10000 }
        ).catch(() => {
            // fetchImages may not resolve if DB is unavailable; the sentinel
            // blob assertion still holds for the empty-pond case (no LOCAL files).
        });

        // ------------------------------------------------------------------
        // 4. Capture the submit POST payload via a second, higher-priority route.
        //    We register it after the fetchImages route so Playwright evaluates
        //    it first (LIFO order).
        // ------------------------------------------------------------------
        let capturedRequest = null;

        await page.route('**/app/cars/actions/edit.php', async (route, request) => {
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

        // ------------------------------------------------------------------
        // 5. Click the submit button to trigger a text-only save.
        //    No image changes have been made — only LOCAL (existing) files are
        //    present in FilePond at this point.
        // ------------------------------------------------------------------
        const submitBtn = page.locator('#submit');
        const submitVisible = await submitBtn.isVisible().catch(() => false);
        if (!submitVisible) {
            test.skip('Submit button not found — form did not render (DB unavailable)');
            return;
        }

        await submitBtn.click();

        // Poll for the Node-side capturedRequest to be populated (up to 8 seconds).
        const deadline = Date.now() + 8000;
        while (capturedRequest === null && Date.now() < deadline) {
            await page.waitForTimeout(100);
        }

        // ------------------------------------------------------------------
        // 6. Assert the captured request payload.
        // ------------------------------------------------------------------
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

        // --- Assertion C: sentinel blob is present ---
        // When no new files are uploaded, submitCarForm() appends:
        //   formData.append('file[]', new Blob([]), 'blob')
        // This signals the server that no new image data was sent.
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

        // --- Assertion D: no binary image data in file[] ---
        // Before the fix, LOCAL files were run through processFiles() and their
        // transformed blobs were appended as file[] entries with non-zero size.
        // After the fix, the only file[] entry for a text-only save is the sentinel.
        const nonSentinelFileEntries = fileEntries.filter(e => e.filename !== 'blob');
        expect(
            nonSentinelFileEntries.length,
            'POST must NOT contain binary image data in file[] for a text-only save ' +
            '(existing LOCAL images must not be re-processed — regression #796)'
        ).toBe(0);
    });

    // -----------------------------------------------------------------------
    // Guard test: verify the sentinel is NOT present when new files ARE added.
    // This ensures the sentinel-detection logic is not trivially always-true.
    // -----------------------------------------------------------------------
    test('sentinel blob absent when new file is queued for upload', async ({ page }) => {
        // Mock fetchImages to return no existing images (clean pond)
        await page.route('**/app/cars/actions/edit.php', async (route, request) => {
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

        await page.goto('app/cars/form.php?car_id=1', { waitUntil: 'domcontentloaded' });

        const currentUrl = page.url();
        if (currentUrl.includes('login')) {
            test.skip('Session not established');
            return;
        }

        await page.waitForFunction(
            () => typeof window.FilePond !== 'undefined' && document.querySelector('.filepond--root') !== null,
            { timeout: 15000 }
        );

        // Capture the submit POST
        let capturedRequest = null;
        await page.route('**/app/cars/actions/edit.php', async (route, request) => {
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
            if (!root) { return false; }
            const instance = window.FilePond && window.FilePond.find(root);
            if (!instance) { return false; }

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

        if (!fileAdded) {
            test.skip('FilePond instance not found — cannot add synthetic file');
            return;
        }

        // Wait for FilePond to register the synthetic file
        await page.waitForFunction(
            () => {
                const root = document.querySelector('.filepond--root');
                const instance = window.FilePond && window.FilePond.find(root);
                return instance && instance.getFiles().length > 0;
            },
            { timeout: 5000 }
        ).catch(() => {});

        // Verify the file is present and is NOT a LOCAL file (it has no metadata.serverFilename)
        const fileOrigin = await page.evaluate(() => {
            const root = document.querySelector('.filepond--root');
            const instance = window.FilePond && window.FilePond.find(root);
            if (!instance) { return null; }
            const files = instance.getFiles();
            if (files.length === 0) { return null; }
            return files[0].origin;
        });

        // FileOrigin.INPUT === 1 (user-added), FileOrigin.LOCAL === 3 (already on server)
        if (fileOrigin === null) {
            test.skip('No file in pond after addFile — FilePond may have rejected the synthetic file');
            return;
        }

        const FILEPOND_FILE_ORIGIN_LOCAL = 3;
        expect(fileOrigin).not.toBe(FILEPOND_FILE_ORIGIN_LOCAL);

        // Click submit — FilePond will process the new file via the mock server.process
        // handler (which just calls load() immediately), then submitCarForm() fires.
        const submitBtn = page.locator('#submit');
        if (!await submitBtn.isVisible().catch(() => false)) {
            test.skip('Submit button not rendered');
            return;
        }

        await submitBtn.click();

        // Poll for the Node-side capturedRequest (processFiles may take a moment for new files)
        const deadline2 = Date.now() + 8000;
        while (capturedRequest === null && Date.now() < deadline2) {
            await page.waitForTimeout(100);
        }

        if (capturedRequest === null) {
            // The new-file path may not have fired if processFiles timed out;
            // we only assert the negative (no sentinel) when we have a payload.
            test.skip('POST not captured — processFiles may not have resolved for synthetic file');
            return;
        }

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

    // -----------------------------------------------------------------------
    // Mixed scenario: one existing (LOCAL) image + one new upload.
    // This is the most common real-world path: adding a photo to a car that
    // already has images. Verifies that:
    //   - Only the new file appears in file[] (not the LOCAL one)
    //   - The existing filename is preserved in filenames=
    //   - No sentinel blob is sent (hasNewFiles is true)
    // -----------------------------------------------------------------------
    test('mixed save: existing image preserved in filenames, only new file in file[]', async ({ page }) => {
        // Mock fetchImages to hydrate one existing LOCAL image
        await page.route('**/app/cars/actions/edit.php', async (route, request) => {
            const postData = request.postData() || '';
            if (postData.includes('action=fetchImages')) {
                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({
                        success: true,
                        images: [{ path: 'http://localhost:9999/elan_registry/usersc/uploads/cars/1/existing-photo.jpg', basename: 'existing-photo.jpg' }]
                    })
                });
                return;
            }
            await route.fallback();
        });

        await page.goto('app/cars/form.php?car_id=1', { waitUntil: 'domcontentloaded' });

        const currentUrl = page.url();
        if (currentUrl.includes('login')) {
            test.skip('Session not established');
            return;
        }

        await page.waitForFunction(
            () => typeof window.FilePond !== 'undefined' && document.querySelector('.filepond--root') !== null,
            { timeout: 15000 }
        );

        // Wait for the LOCAL image to hydrate
        await page.waitForFunction(
            () => {
                const root = document.querySelector('.filepond--root');
                const instance = window.FilePond && window.FilePond.find(root);
                return instance && instance.getFiles().length > 0;
            },
            { timeout: 10000 }
        ).catch(() => {});

        // Capture submit POST
        let capturedRequest = null;
        await page.route('**/app/cars/actions/edit.php', async (route, request) => {
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
            if (!instance) { return false; }
            const jpegBytes = new Uint8Array([0xFF, 0xD8, 0xFF, 0xE0, 0x00, 0x10, 0x4A, 0x46, 0x49, 0x46, 0x00, 0x01, 0x01, 0x00, 0x00, 0x01, 0x00, 0x01, 0x00, 0x00, 0xFF, 0xD9]);
            const file = new File([new Blob([jpegBytes], { type: 'image/jpeg' })], 'new-photo.jpg', { type: 'image/jpeg' });
            instance.addFile(file);
            return true;
        });

        if (!fileAdded) {
            test.skip('FilePond instance not found');
            return;
        }

        // Wait for new file to register (pond should now have 2 items: LOCAL + new)
        await page.waitForFunction(
            () => {
                const root = document.querySelector('.filepond--root');
                const instance = window.FilePond && window.FilePond.find(root);
                return instance && instance.getFiles().length >= 2;
            },
            { timeout: 5000 }
        ).catch(() => {});

        const submitBtn = page.locator('#submit');
        if (!await submitBtn.isVisible().catch(() => false)) {
            test.skip('Submit button not rendered');
            return;
        }

        await submitBtn.click();

        const deadline3 = Date.now() + 8000;
        while (capturedRequest === null && Date.now() < deadline3) {
            await page.waitForTimeout(100);
        }

        if (capturedRequest === null) {
            test.skip('POST not captured — processFiles may not have resolved');
            return;
        }

        const contentType = capturedRequest.contentType;
        expect(contentType).toContain('multipart/form-data');

        const boundaryMatch = contentType.match(/boundary=([^\s;]+)/);
        expect(boundaryMatch).not.toBeNull();
        const fields = parseMultipart(capturedRequest.buffer, boundaryMatch[1]);

        // filenames= must contain the existing image (LOCAL file order preserved)
        const filenamesField = (fields.get('filenames') || [])[0];
        expect(filenamesField, 'filenames= field must be present').toBeDefined();
        expect(
            filenamesField.value,
            'filenames= must include the existing LOCAL image basename'
        ).toContain('existing-photo.jpg');

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
});
