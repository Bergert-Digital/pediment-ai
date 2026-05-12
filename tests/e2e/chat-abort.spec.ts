import { test, expect } from '@playwright/test';
import { login, openNewPage, openAIChatPanel } from './utils';

test('stop button aborts an in-flight turn', async ({ page }) => {
  await login(page);
  await openNewPage(page, 'Chat Abort E2E');

  const sidebar = await openAIChatPanel(page);

  await sidebar.locator('textarea').fill('Add a paragraph that says hi');
  await sidebar.getByRole('button', { name: /^send$/i }).click();

  // Stop button appears while streaming — click it immediately.
  // NOTE: against the synthetic Mock provider, events arrive instantly so this race
  // is fragile. The intent is to verify the Stop button exists and the abort wiring
  // tears the streaming bubble down. A slowed-down mock would make it deterministic.
  await sidebar.getByRole('button', { name: /^stop$/i }).click();

  // After stop, the Stop button disappears (we're back to idle / Send).
  await expect(sidebar.getByRole('button', { name: /^stop$/i })).not.toBeVisible({ timeout: 5_000 });
});
