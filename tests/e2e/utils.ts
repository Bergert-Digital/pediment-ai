import { Page, FrameLocator } from '@playwright/test';

/**
 * Returns a locator scope for the editor canvas — the iframe in WP 6.5+ block themes,
 * or the page itself in classic / non-iframed setups.
 */
export async function canvas(page: Page): Promise<FrameLocator | Page> {
  return (await page.locator('iframe[name="editor-canvas"]').count())
    ? page.frameLocator('iframe[name="editor-canvas"]')
    : page;
}

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
  // Give the editor a beat to mount its iframe before we look for the canvas.
  await page.locator('iframe[name="editor-canvas"], .editor-post-title__input').first().waitFor({ timeout: 20_000 });
  const scope = await canvas(page);
  const titleField = scope.locator('.editor-post-title__input, [aria-label*="Add title" i], [placeholder*="Add title" i]').first();
  await titleField.waitFor({ state: 'visible', timeout: 20_000 });
  await titleField.fill(title);
}

export async function openDocumentSidebar(page: Page) {
  const btn = page.getByRole('button', { name: /document sidebar|settings/i });
  if (await btn.count()) { await btn.first().click(); }
}
