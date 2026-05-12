import { test, expect } from '@playwright/test';
import { login, openNewPage, openAIChatPanel, canvas } from './utils';

test('chat panel inserts a paragraph from mock fixture', async ({ page }) => {
  await login(page);
  await openNewPage(page, 'Chat E2E');

  const sidebar = await openAIChatPanel(page);

  // Send a message that triggers the insert-paragraph mock fixture.
  await sidebar.locator('textarea').fill('Add a paragraph that says hi');
  await sidebar.getByRole('button', { name: /^send$/i }).click();

  // Optimistic UI: the user's message appears immediately in the chat.
  await expect(sidebar.getByText('Add a paragraph that says hi')).toBeVisible({ timeout: 5_000 });

  // The mock's assistant prose streams in.
  await expect(sidebar.getByText(/adding a paragraph/i)).toBeVisible({ timeout: 15_000 });

  // The canvas receives the inserted paragraph.
  const editor = await canvas(page);
  await expect(editor.locator('p.wp-block-paragraph', { hasText: 'Hello from mock.' })).toBeVisible({ timeout: 10_000 });
});
