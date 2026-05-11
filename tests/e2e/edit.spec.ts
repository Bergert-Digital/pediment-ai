import { test, expect } from '@playwright/test';
import { login, openNewPage } from './utils';

test('edit with AI replaces page content', async ({ page }) => {
  await login(page);
  await openNewPage(page, 'AI Edit E2E');

  await page.locator('.editor-styles-wrapper').click();
  await page.keyboard.type('/hero');
  await page.keyboard.press('Enter');

  await page.getByRole('button', { name: /edit with ai/i }).click();
  await page.getByRole('textbox', { name: /instruction/i }).fill('add an faq');
  await page.getByRole('button', { name: /^edit$/i }).click();

  await expect(page.locator('.wp-block-starter-faq')).toBeVisible({ timeout: 15_000 });
});
