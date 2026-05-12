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

/**
 * Opens the editor's general sidebar to a specific tab via the WordPress data API.
 * More reliable than clicking around UI affordances that move between WP versions.
 *   tab = 'edit-post/document' | 'edit-post/block'
 */
async function openSidebarTab(page: Page, tab: 'edit-post/document' | 'edit-post/block') {
  await page.evaluate((target) => {
    const wp = (window as any).wp;
    const dispatch = wp?.data?.dispatch?.('core/edit-post') ?? wp?.data?.dispatch?.('core/editor');
    dispatch?.openGeneralSidebar?.(target);
  }, tab);
}

/**
 * Opens the Document sidebar, ensures the "AI Chat" PluginDocumentSettingPanel is expanded,
 * and returns the chat panel locator (`.starter-ai-chat`) for further interactions.
 */
export async function openAIChatPanel(page: Page) {
  await openSidebarTab(page, 'edit-post/document');
  const toggle = page.getByRole('button', { name: /^AI Chat$/i }).first();
  await toggle.waitFor({ state: 'visible', timeout: 10_000 });
  if ((await toggle.getAttribute('aria-expanded')) === 'false') {
    await toggle.click();
  }
  const panel = page.locator('.starter-ai-chat').first();
  await panel.waitFor({ state: 'visible', timeout: 10_000 });
  return panel;
}

/**
 * Variant for the block-inspector AI Chat PanelBody (rendered by BlockChatPanel.tsx
 * when a block is selected). Use this after selecting a block in the canvas.
 */
export async function openBlockAIChatPanel(page: Page) {
  await openSidebarTab(page, 'edit-post/block');
  const toggle = page.getByRole('button', { name: /^AI Chat$/i }).first();
  await toggle.waitFor({ state: 'visible', timeout: 10_000 });
  if ((await toggle.getAttribute('aria-expanded')) === 'false') {
    await toggle.click();
  }
  const panel = page.locator('.starter-ai-chat').first();
  await panel.waitFor({ state: 'visible', timeout: 10_000 });
  return panel;
}
