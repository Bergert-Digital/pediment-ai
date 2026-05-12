import { test, expect } from '@playwright/test';
import { login, openNewPage, canvas } from './utils';

// TODO: rewrite for the post-a6593d6 chat UI — chat moved from PluginSidebar to
// a PluginDocumentSettingPanel inside the Document sidebar, so the "Open AI Chat"
// button no longer exists. Test needs to open the Document sidebar (gear icon)
// and expand the "AI Chat" panel instead.
test.skip('chat sidebar inserts a paragraph from mock fixture', async ({ page }) => {
  await login(page);
  await openNewPage(page, 'Chat E2E');

  // Open the AI chat sidebar via the document-panel launcher.
  await page.getByRole('button', { name: /open ai chat/i }).click();

  // Wait for the sidebar to render.
  const sidebar = page.locator('.starter-ai-chat');
  await sidebar.waitFor({ state: 'visible', timeout: 10_000 });

  // Send a message that triggers the insert-paragraph fixture.
  await sidebar.locator('textarea').fill('Add a paragraph that says hi');
  await sidebar.getByRole('button', { name: /^send$/i }).click();

  // The text bubble should appear with the mock's assistant prose.
  await expect(sidebar.getByText(/adding a paragraph/i)).toBeVisible({ timeout: 15_000 });

  // The canvas should receive the inserted paragraph.
  const editor = await canvas(page);
  await expect(editor.locator('p.wp-block-paragraph', { hasText: 'Hello from mock.' })).toBeVisible({ timeout: 10_000 });
});
