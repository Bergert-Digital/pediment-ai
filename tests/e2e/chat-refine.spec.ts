import { test, expect } from '@playwright/test';
import { login, openNewPage, canvas } from './utils';

// TODO: rewrite for the post-a6593d6 chat UI — chat moved from PluginSidebar to
// a PluginDocumentSettingPanel + block-inspector PanelBody, so the "Open AI Chat"
// button no longer exists. Also '.block-editor-default-block-appender__content'
// no longer matches inside WP 6.5's iframed canvas.
test.skip('quick action shortens the selected paragraph via chat', async ({ page }) => {
  await login(page);
  await openNewPage(page, 'Chat Refine E2E');
  const editor = await canvas(page);

  // Insert a paragraph manually.
  await page.keyboard.press('Tab'); // focus the canvas
  await editor.locator('.block-editor-default-block-appender__content').click();
  await page.keyboard.type('A long paragraph that needs shortening, several sentences indeed.');

  // Select the paragraph block (click in it).
  await editor.locator('p.wp-block-paragraph', { hasText: 'A long paragraph' }).click();

  // Open the chat sidebar.
  await page.getByRole('button', { name: /open ai chat/i }).click();
  const sidebar = page.locator('.starter-ai-chat');
  await sidebar.waitFor({ state: 'visible' });

  // Selection chip should appear; click the "Shorten" quick action.
  await expect(sidebar.locator('.starter-ai-chat__chip')).toBeVisible();
  await sidebar.getByRole('button', { name: /^shorten$/i }).click();

  // The mock fixture replaces content with "Short."
  await expect(editor.locator('p.wp-block-paragraph', { hasText: 'Short.' })).toBeVisible({ timeout: 15_000 });
});
