import { Page } from '@playwright/test';

export async function login(page: Page) {
  await page.goto('/wp-login.php');
  await page.fill('input#user_login', 'admin');
  await page.fill('input#user_pass', 'password');
  await page.click('input#wp-submit');
  await page.waitForURL(/wp-admin/);
}

export async function openNewPage(page: Page, title: string) {
  await page.goto('/wp-admin/post-new.php?post_type=page');
  // Close any welcome / fullscreen modal that appears on first load.
  const closeBtn = page.getByRole('button', { name: /close dialog|close/i });
  if (await closeBtn.count()) { await closeBtn.first().click().catch(() => {}); }
  // The title field's accessible name varies across WP versions.
  const titleField = page.locator('.editor-post-title__input, [aria-label*="Add title" i], [placeholder*="Add title" i]').first();
  await titleField.waitFor({ state: 'visible', timeout: 20_000 });
  await titleField.fill(title);
}

export async function openDocumentSidebar(page: Page) {
  const btn = page.getByRole('button', { name: /document sidebar|settings/i });
  if (await btn.count()) { await btn.first().click(); }
}
