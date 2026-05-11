import { test, expect } from '@playwright/test';
import { login, openNewPage } from './utils';

test('compose with AI inserts blocks from mock fixture', async ({ page }) => {
  await login(page);
  await openNewPage(page, 'AI Compose E2E');

  await page.getByRole('button', { name: /compose with ai/i }).click();
  await page.getByRole('textbox', { name: /prompt/i }).fill('A landing page for an agency');
  await page.getByRole('button', { name: /^compose$/i }).click();

  await expect(page.locator('.wp-block-starter-hero')).toBeVisible({ timeout: 15_000 });
});
