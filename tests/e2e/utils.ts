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
  // Give the editor a beat to mount its iframe before we look for the canvas.
  await page.locator('iframe[name="editor-canvas"], .editor-post-title__input').first().waitFor({ timeout: 20_000 });
  // The "Welcome to the editor" guide mounts asynchronously and its modal overlay
  // intercepts pointer events. Disable it via the preferences store (reactive —
  // closes it if already open) rather than racing to click its Close button.
  await disableWelcomeGuide(page);
  // WP 6.9 + pattern-providing themes open a "Choose a pattern" dialog *after* the editor mounts.
  await dismissEditorOverlays(page);
  const scope = await canvas(page);
  const titleField = scope.locator('.editor-post-title__input, [aria-label*="Add title" i], [placeholder*="Add title" i]').first();
  await titleField.waitFor({ state: 'visible', timeout: 20_000 });
  await titleField.fill(title);
}

/**
 * Turns off the editor welcome guide via the preferences store. This is reactive,
 * so it dismisses the guide whether it has already mounted or is about to. Covers
 * both the modern `core/preferences` store and the legacy edit-post feature flag.
 */
async function disableWelcomeGuide(page: Page) {
  await page
    .waitForFunction(() => !!(window as any).wp?.data?.dispatch?.('core/preferences'), null, { timeout: 20_000 })
    .catch(() => {});
  await page.evaluate(() => {
    const wp = (window as any).wp;
    wp?.data?.dispatch?.('core/preferences')?.set?.('core', 'welcomeGuide', false);
    const editPost = wp?.data?.select?.('core/edit-post');
    if (editPost?.isFeatureActive?.('welcomeGuide')) {
      wp.data.dispatch('core/edit-post').toggleFeature('welcomeGuide');
    }
  });
}

/**
 * Dismisses any modal/dialog that the post editor opens on a fresh page.
 * Handles WP's welcome guide, fullscreen prompt, and (WP 6.9+) the
 * "Choose a pattern" picker that appears when the active theme provides patterns.
 */
async function dismissEditorOverlays(page: Page) {
  const patterns = [
    /^Choose a pattern$/i,
    /^Welcome to/i,
    /Welcome to the block editor/i,
  ];
  for (const name of patterns) {
    const dialog = page.getByRole('dialog', { name });
    if (await dialog.first().isVisible().catch(() => false)) {
      await dialog.first().getByRole('button', { name: /^close/i }).click({ timeout: 2_000 }).catch(() => {});
    }
  }
  // Generic fallback: any visible "Close dialog" button (covers older WP modal labels).
  const closeDialog = page.getByRole('button', { name: /^close dialog$/i });
  if (await closeDialog.first().isVisible().catch(() => false)) {
    await closeDialog.first().click({ timeout: 2_000 }).catch(() => {});
  }
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
 * and returns the chat panel locator (`.pediment-ai-chat`) for further interactions.
 */
export async function openAIChatPanel(page: Page) {
  await openSidebarTab(page, 'edit-post/document');
  const toggle = page.getByRole('button', { name: /^AI Chat$/i }).first();
  await toggle.waitFor({ state: 'visible', timeout: 10_000 });
  if ((await toggle.getAttribute('aria-expanded')) === 'false') {
    await toggle.click();
  }
  const panel = page.locator('.pediment-ai-chat').first();
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
  const panel = page.locator('.pediment-ai-chat').first();
  await panel.waitFor({ state: 'visible', timeout: 10_000 });
  return panel;
}
