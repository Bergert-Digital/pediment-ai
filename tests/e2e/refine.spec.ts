import { test, expect } from '@playwright/test';
import { login, openNewPage } from './utils';

test('refine updates a single block', async ({ page }) => {
  await login(page);
  await openNewPage(page, 'AI Refine E2E');

  await page.locator('.editor-styles-wrapper').click();
  await page.keyboard.type('/hero');
  await page.keyboard.press('Enter');

  await page.locator('.wp-block-starter-hero').first().click();

  await page.getByRole('button', { name: /^ai refine$/i }).click();
  await page.getByRole('textbox', { name: /custom instruction/i }).fill('Make it punchier');
  await page.getByRole('button', { name: /^refine$/i }).click();

  await expect(page.locator('.wp-block-starter-hero h1')).toContainText(/punchier/i, { timeout: 10_000 });
});
