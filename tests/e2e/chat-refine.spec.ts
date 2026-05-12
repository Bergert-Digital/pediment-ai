import { test, expect } from '@playwright/test';
import { login, openNewPage, openBlockAIChatPanel, canvas } from './utils';

test('quick action shortens the selected paragraph via chat', async ({ page }) => {
  await login(page);
  await openNewPage(page, 'Chat Refine E2E');
  const editor = await canvas(page);

  // Move from the title into the body, then type a paragraph.
  await page.keyboard.press('Enter');
  await page.keyboard.type('A long paragraph that needs shortening, several sentences indeed.');

  // Select the paragraph (click in it).
  await editor.locator('p.wp-block-paragraph', { hasText: 'A long paragraph' }).click();

  // Selecting a block auto-switches the sidebar to the Block tab; expand the AI Chat panel there.
  const sidebar = await openBlockAIChatPanel(page);

  // Click the "Shorten" quick action.
  await sidebar.getByRole('button', { name: /^shorten$/i }).click();

  // The mock fixture replaces the paragraph's content with "Short."
  await expect(editor.locator('p.wp-block-paragraph', { hasText: 'Short.' })).toBeVisible({ timeout: 15_000 });
});
