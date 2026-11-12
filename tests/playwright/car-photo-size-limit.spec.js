// Photo size limits (#2627): FilePond accepts a photo up to 20 MB, shrinks
// it to 2048 px before save, and save.php answers an oversized body with a
// clear message (#2647): 413 on test and prod; 200 on Docker, see
// docs/development/ENVIRONMENT.md. The form tests need an owner login and
// mock the server side, so no data is written.

const { test, expect } = require('@playwright/test');
const { ensureLoggedIn } = require('./auth-helper.js');
const { CAR_ID_STANDARD } = require('./fixtures.js');

const SAVE_URL = 'app/api/cars/save.php';
const MIB = 1024 * 1024;
const SAVED_PHOTO_MAX_BYTES = 5 * MIB;
const SAVED_PHOTO_MAX_SIDE = 2048;
const TOO_LARGE_MESSAGE = 'The photos are too large to save together. The maximum is 64 MB for one save. Remove some photos, then save again.';

/**
 * Build a real JPEG in the browser. Plain gradient content keeps the file
 * small, so a test sets the file size by padding the bytes.
 *
 * @param {import('@playwright/test').Page} page
 * @param {number} width
 * @param {number} height
 * @returns {Promise<Buffer>}
 */
async function makeJpeg(page, width, height) {
    const base64 = await page.evaluate(async ([w, h]) => {
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, w, h);
        gradient.addColorStop(0, '#1d4e89');
        gradient.addColorStop(1, '#f6c85f');
        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, w, h);
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(w / 4, h / 4, w / 2, h / 2);
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.85));
        const bytes = new Uint8Array(await blob.arrayBuffer());
        let binary = '';
        for (let i = 0; i < bytes.length; i += 0x8000) {
            binary += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
        }
        return btoa(binary);
    }, [width, height]);
    return Buffer.from(base64, 'base64');
}

/**
 * Append bytes after the JPEG end-of-image marker. Decoders ignore them, so
 * the file stays a valid photo of the new size.
 *
 * @param {Buffer} jpeg
 * @param {number} totalBytes
 * @returns {Buffer}
 */
function padJpeg(jpeg, totalBytes) {
    expect(jpeg.length, 'base JPEG must be smaller than the padded size').toBeLessThan(totalBytes);
    return Buffer.concat([jpeg, Buffer.alloc(totalBytes - jpeg.length)]);
}

/**
 * Read the pixel size from the JPEG start-of-frame marker.
 *
 * @param {Buffer} buf
 * @returns {{width: number, height: number}}
 */
function jpegSize(buf) {
    expect(buf.subarray(0, 2).toString('hex'), 'posted photo must be a JPEG').toBe('ffd8');
    let pos = 2;
    while (pos + 9 < buf.length) {
        expect(buf[pos], `JPEG marker expected at byte ${pos}`).toBe(0xff);
        const marker = buf[pos + 1];
        const length = buf.readUInt16BE(pos + 2);
        // SOF0-SOF15, except DHT (C4), JPG (C8) and DAC (CC)
        if (marker >= 0xc0 && marker <= 0xcf && ![0xc4, 0xc8, 0xcc].includes(marker)) {
            return { height: buf.readUInt16BE(pos + 5), width: buf.readUInt16BE(pos + 7) };
        }
        pos += 2 + length;
    }
    throw new Error('JPEG has no start-of-frame marker');
}

/**
 * Pull the first file[] part with a real file name out of a multipart body.
 *
 * @param {Buffer} body
 * @param {string} contentType
 * @returns {{filename: string, data: Buffer}}
 */
function firstUploadedFile(body, contentType) {
    const boundary = contentType.match(/boundary=([^\s;]+)/)[1];
    const delimiter = Buffer.from('--' + boundary);
    let pos = body.indexOf(delimiter);
    while (pos !== -1) {
        const next = body.indexOf(delimiter, pos + delimiter.length);
        if (next === -1) {
            break;
        }
        const part = body.subarray(pos + delimiter.length + 2, next - 2);
        const headerEnd = part.indexOf('\r\n\r\n');
        const headers = part.subarray(0, headerEnd).toString('latin1');
        const filename = headers.match(/name="file\[\]"; filename="([^"]*)"/);
        if (filename && filename[1] !== 'blob') {
            return { filename: filename[1], data: part.subarray(headerEnd + 4) };
        }
        pos = next;
    }
    throw new Error('POST holds no file[] part with a photo');
}

test.describe('Photo size limit in the car edit form', () => {
    test.beforeEach(async ({ page }) => {
        test.skip(
            !process.env.E2E_DEV_ADMIN_USERNAME || !process.env.E2E_DEV_ADMIN_PASSWORD,
            'Set E2E_DEV_ADMIN_USERNAME and E2E_DEV_ADMIN_PASSWORD in .env.local to run authenticated tests'
        );
        await ensureLoggedIn(page);

        // An empty pond keeps the 6-photo limit from rejecting the test photo.
        await page.route('**/' + SAVE_URL, async (route, request) => {
            const postData = request.method() === 'POST' ? request.postData() || '' : '';
            if (postData.includes('fetchImages')) {
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
    });

    test('accepts a 4.6 MB photo', async ({ page }) => {
        const photo = padJpeg(await makeJpeg(page, 640, 480), 4.6 * 1000 * 1000);

        await page.locator('.filepond--browser').setInputFiles({
            name: 'padded-4-6mb.jpg',
            mimeType: 'image/jpeg',
            buffer: photo
        });

        await expect(page.locator('.filepond--item')).toHaveCount(1);
        await expect(page.locator('.filepond--item')).toHaveAttribute('data-filepond-item-state', 'idle');
        await expect(page.locator('.filepond--file-status-main')).not.toHaveText('Photo is too large');
        await expect(page.locator('.filepond--file-status-sub')).not.toContainText('Maximum size');
    });

    test('accepts a 4.6 MB photo on the add-car page', async ({ page }) => {
        await page.goto('app/owner/cars/edit.php', { waitUntil: 'domcontentloaded' });
        await page.waitForFunction(
            () => typeof window.FilePond !== 'undefined' && document.querySelector('.filepond--root') !== null,
            { timeout: 15000 }
        );
        const photo = padJpeg(await makeJpeg(page, 640, 480), 4.6 * 1000 * 1000);

        await page.locator('.filepond--browser').setInputFiles({
            name: 'padded-4-6mb.jpg',
            mimeType: 'image/jpeg',
            buffer: photo
        });

        await expect(page.locator('.filepond--item')).toHaveCount(1);
        await expect(page.locator('.filepond--item')).toHaveAttribute('data-filepond-item-state', 'idle');
    });

    test('rejects a 21 MB photo with the size labels', async ({ page }) => {
        const photo = padJpeg(await makeJpeg(page, 640, 480), 21 * MIB);

        await page.locator('.filepond--browser').setInputFiles({
            name: 'padded-21mb.jpg',
            mimeType: 'image/jpeg',
            buffer: photo
        });

        await expect(page.locator('.filepond--file-status-main')).toHaveText('Photo is too large');
        await expect(page.locator('.filepond--file-status-sub')).toHaveText('Maximum size is 20 MB');
    });

    /**
     * Add one photo, click Save, and return the size of the JPEG that the
     * form posts. The save request is aborted, so no car data is written.
     *
     * @param {import('@playwright/test').Page} page
     * @param {Buffer} photo
     * @param {string} name
     * @returns {Promise<{width: number, height: number, bytes: number}>}
     */
    async function postedPhoto(page, photo, name) {
        let posted = null;
        await page.route('**/' + SAVE_URL, async (route, request) => {
            const postData = request.method() === 'POST' ? request.postData() || '' : '';
            if (request.method() === 'POST' && !postData.includes('fetchImages') && !postData.includes('removeImages')) {
                posted = {
                    body: request.postDataBuffer(),
                    contentType: request.headers()['content-type'] || ''
                };
                await route.abort();
                return;
            }
            await route.fallback();
        });

        await page.locator('.filepond--browser').setInputFiles({ name, mimeType: 'image/jpeg', buffer: photo });
        await expect(page.locator('.filepond--item')).toHaveAttribute('data-filepond-item-state', 'idle');

        const submitBtn = page.locator('#submit');
        await expect(submitBtn).toBeEnabled({ timeout: 15000 });
        await submitBtn.click();

        await expect.poll(() => posted, { timeout: 20000 }).not.toBeNull();

        const upload = firstUploadedFile(posted.body, posted.contentType);
        return { ...jpegSize(upload.data), bytes: upload.data.length };
    }

    test('shrinks a 4032x3024 photo to 2048 px, keeps 4:3, and stays under 5 MiB', async ({ page }) => {
        const photo = await makeJpeg(page, 4032, 3024);
        expect(jpegSize(photo), 'test photo must start at 4032x3024').toEqual({ width: 4032, height: 3024 });

        const saved = await postedPhoto(page, photo, 'big-4032x3024.jpg');

        expect(saved, 'saved photo size').toMatchObject({ width: SAVED_PHOTO_MAX_SIDE, height: 1536 });
        expect(saved.bytes, 'saved photo bytes').toBeLessThanOrEqual(SAVED_PHOTO_MAX_BYTES);
    });

    test('bounds the longest side of a 3024x4032 portrait photo to 2048 px', async ({ page }) => {
        const photo = await makeJpeg(page, 3024, 4032);
        expect(jpegSize(photo), 'test photo must start at 3024x4032').toEqual({ width: 3024, height: 4032 });

        const saved = await postedPhoto(page, photo, 'portrait-3024x4032.jpg');

        expect(saved).toMatchObject({ width: 1536, height: 2048 });
    });

    test('does not enlarge a 640x480 photo', async ({ page }) => {
        const photo = await makeJpeg(page, 640, 480);
        expect(jpegSize(photo), 'test photo must start at 640x480').toEqual({ width: 640, height: 480 });

        const saved = await postedPhoto(page, photo, 'small-640x480.jpg');

        expect(saved).toMatchObject({ width: 640, height: 480 });
    });

    test('shows the 413 message in the form when the photos are too large', async ({ page }) => {
        let saveCalls = 0;
        await page.route('**/' + SAVE_URL, async (route, request) => {
            const postData = request.method() === 'POST' ? request.postData() || '' : '';
            if (request.method() === 'POST' && !postData.includes('fetchImages') && !postData.includes('removeImages')) {
                saveCalls += 1;
                await route.fulfill({
                    status: 413,
                    contentType: 'application/json',
                    body: JSON.stringify({ success: false, message: TOO_LARGE_MESSAGE })
                });
                return;
            }
            await route.fallback();
        });

        const submitBtn = page.locator('#submit');
        await expect(submitBtn).toBeEnabled({ timeout: 15000 });
        await submitBtn.click();

        await expect.poll(() => saveCalls, { timeout: 20000 }).toBe(1);
        const message = page.locator('#message');
        await expect(message).toBeVisible();
        await expect(message).toContainText('Submission failed.');
        await expect(message).toContainText(TOO_LARGE_MESSAGE);
    });
});

test.describe('save.php body limits (no login needed)', () => {
    test('answers 413 with a clear message for a body above post_max_size', async ({ request }) => {
        // 64 MB comes from post_max_size in docker/php.ini-overrides.ini.
        const response = await request.post(SAVE_URL, {
            multipart: {
                'file[]': {
                    name: 'huge.jpg',
                    mimeType: 'image/jpeg',
                    buffer: Buffer.alloc(65 * MIB)
                }
            }
        });

        // Test and prod answer 413 with a bare JSON body. Docker has
        // display_errors = On, so PHP prints the startup warning first, the
        // headers go out, and the status stays 200 (ENVIRONMENT.md, "Body
        // above post_max_size"). Accept either form; the message is the check.
        const text = await response.text();
        if (text.startsWith('{')) {
            expect(response.status()).toBe(413);
        } else {
            expect(text, 'Docker prefix must be the PHP startup warning').toContain('exceeds the limit');
        }
        const jsonStart = text.indexOf('{');
        expect(jsonStart, `response must hold a JSON object, got: ${text.slice(0, 200)}`).toBeGreaterThanOrEqual(0);
        const json = JSON.parse(text.slice(jsonStart));
        expect(json.success).toBe(false);
        expect(json.message).toBe(TOO_LARGE_MESSAGE);
    });

    test('answers 400 "No data received" for an empty POST', async ({ request }) => {
        const response = await request.post(SAVE_URL);

        expect(response.status()).toBe(400);
        const text = await response.text();
        const json = JSON.parse(text.slice(text.indexOf('{')));
        expect(json.message).toBe('No data received');
    });
});
