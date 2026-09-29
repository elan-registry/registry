const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

/**
 * Verifies EmailTemplate::createButtonRow()'s responsive stacking actually
 * works in a real browser engine.
 *
 * PHPUnit already asserts the returned HTML string contains an `@media` rule
 * (see tests/unit/ for EmailTemplate coverage) — that only proves the text is
 * present, not that it does anything. This test renders a full email via
 * EmailTemplate::render() (not createButtonRow() alone — the .btn-row-cell
 * responsive rule lives in the base template's head-level <style> block, not
 * in createButtonRow()'s own returned fragment, since some mail clients strip
 * <style> tags outside <head>) in a real Chromium layout engine and asserts
 * on actual bounding boxes: buttons must sit side-by-side at a desktop
 * viewport and stack vertically once the viewport crosses the
 * `@media (max-width: 600px)` breakpoint.
 *
 * No live app dependency: the full HTML document is generated once via a
 * standalone PHP fixture script (fixtures/generate-button-row.php) and loaded
 * directly into an isolated page via page.setContent().
 *
 * @group email
 * @group responsive
 */

let buttonRowHtml;

test.beforeAll(() => {
  const fixturePath = path.join(__dirname, 'fixtures', 'generate-button-row.php');
  buttonRowHtml = execFileSync('php', [fixturePath], { encoding: 'utf8' });

  // Fail fast with a clear message if the fixture didn't produce what we expect,
  // rather than letting every test below fail with confusing selector timeouts.
  expect(buttonRowHtml).toContain('btn-row-cell');
  expect(buttonRowHtml).toContain('@media only screen and (max-width: 600px)');
});

test.describe('EmailTemplate::createButtonRow() responsive stacking', () => {
  test('buttons sit side by side at desktop width', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.setContent(buttonRowHtml);

    const links = page.locator('.btn-row-cell a');
    await expect(links).toHaveCount(2);

    const firstBox = await links.nth(0).boundingBox();
    const secondBox = await links.nth(1).boundingBox();

    expect(firstBox).not.toBeNull();
    expect(secondBox).not.toBeNull();

    // Side by side: same row (equal top), different horizontal position.
    expect(Math.abs(firstBox.y - secondBox.y)).toBeLessThan(2);
    expect(secondBox.x).toBeGreaterThan(firstBox.x + firstBox.width - 2);
  });

  test('buttons stack vertically below the 600px breakpoint', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 800 });
    await page.setContent(buttonRowHtml);

    const links = page.locator('.btn-row-cell a');
    await expect(links).toHaveCount(2);

    const firstLinkBox = await links.nth(0).boundingBox();
    const secondLinkBox = await links.nth(1).boundingBox();

    expect(firstLinkBox).not.toBeNull();
    expect(secondLinkBox).not.toBeNull();

    // Stacked: second button starts at/after the bottom of the first.
    expect(secondLinkBox.y).toBeGreaterThanOrEqual(firstLinkBox.y + firstLinkBox.height - 2);

    // Column alignment is asserted on the <td> cells rather than the <a>
    // links: each cell becomes display: block; width: 100% below the
    // breakpoint, so both cells share an identical left edge/width — but the
    // <a> inside stays display: inline-block with text-align: center on the
    // table, so its x position naturally shifts with label length ("Verify"
    // vs "Report Sold") even though the layout is correctly stacked.
    const cells = page.locator('td.btn-row-cell');
    const firstCellBox = await cells.nth(0).boundingBox();
    const secondCellBox = await cells.nth(1).boundingBox();

    expect(firstCellBox).not.toBeNull();
    expect(secondCellBox).not.toBeNull();
    expect(Math.abs(firstCellBox.x - secondCellBox.x)).toBeLessThan(2);
    expect(Math.abs(firstCellBox.width - secondCellBox.width)).toBeLessThan(2);
  });

  test('cells are full-width block elements below the breakpoint', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 800 });
    await page.setContent(buttonRowHtml);

    const cells = page.locator('td.btn-row-cell');
    await expect(cells).toHaveCount(2);

    for (const cell of await cells.all()) {
      const display = await cell.evaluate((el) => getComputedStyle(el).display);
      expect(display).toBe('block');
    }
  });

  test('cells remain inline table cells above the breakpoint', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.setContent(buttonRowHtml);

    const firstCell = page.locator('td.btn-row-cell').first();
    const display = await firstCell.evaluate((el) => getComputedStyle(el).display);
    expect(display).not.toBe('block');
  });
});
