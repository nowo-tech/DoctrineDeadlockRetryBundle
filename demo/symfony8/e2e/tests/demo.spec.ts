import { test, expect } from '@playwright/test';

/** REQ-DEMO-013 — the overview persists a note through DeadlockRetryService::flush() and lists it. */
test.describe('Doctrine Deadlock Retry demo', () => {
  test('overview flushes with retry and lists the recent notes', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.ok()).toBeTruthy();
    await expect(page.locator('[data-testid="demo-platform"]')).toBeVisible();
    await expect(page.getByRole('heading', { level: 1, name: 'Doctrine Deadlock Retry Bundle' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Flush with retry' })).toBeVisible();
    await expect(page.locator('main table').first()).toContainText('Latest saved');
    await expect(page.locator('main table tbody tr').nth(2)).toBeVisible();
  });
});
