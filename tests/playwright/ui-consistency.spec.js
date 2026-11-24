const { test, expect } = require('@playwright/test');
const { assertNoConsoleErrors } = require('./auth-helper.js');

test.describe('UI Consistency After Style Refactoring', () => {
  test('JavaScript files load without errors', async ({ page }) => {
    await assertNoConsoleErrors(page, 'app/owner/reports/statistics.php', 'statistics.php');
  });

  // Issue #1778 — dedicated console-error coverage for three static reference
  // pages that previously only had incidental page-title-loop coverage. See
  // assertNoConsoleErrors() in auth-helper.js for why status + full-render
  // assertions are needed together (chassis-validation.php has a history of
  // mid-render fatals: d8cb618d, 8b81ab57). Each test below also asserts a
  // row/card count so a silently truncated loop fails, not just a hard fatal.
  test('docs/car-stories.php has no console errors and renders all story cards', async ({ page }) => {
    await assertNoConsoleErrors(page, 'docs/car-stories.php', 'car-stories.php');
    // $storyCards has 3 entries — count proves every card rendered, not just the last.
    await expect(page.locator('.row.mt-4 .registry-card')).toHaveCount(3);
    await expect(page.getByRole('heading', { name: 'Shapecraft Elan Story' })).toBeVisible();
  });

  test('docs/reference/chassis-validation.php has no console errors and renders race-car formats', async ({ page }) => {
    await assertNoConsoleErrors(page, 'docs/reference/chassis-validation.php', 'chassis-validation.php');
    // $validationRules['race_cars'] has 3 entries (1963, 1964, 1965-1966) —
    // count proves every iteration ran. The "Validation Override" heading
    // renders only after the loop's endforeach, proving the loop exited too.
    await expect(page.locator('.card.border-left-dark')).toHaveCount(3);
    await expect(page.getByRole('heading', { name: 'Validation Override' })).toBeVisible();
  });

  test('docs/reference/paint-colors.php has no console errors and renders the full color chart', async ({ page }) => {
    await assertNoConsoleErrors(page, 'docs/reference/paint-colors.php', 'paint-colors.php');
    // $officialColors has 27 entries — count proves every row rendered, not
    // just that the loop reached "Laurel Green" (the true last entry).
    await expect(page.locator('.card:has(#official-colors) table tbody tr')).toHaveCount(27);
    await expect(page.getByText('Laurel Green')).toBeVisible();
  });
});
