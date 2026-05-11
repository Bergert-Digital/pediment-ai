import { test, expect } from '@playwright/test';

test('admin login screen reachable', async ({ page }) => {
  await page.goto('/wp-login.php');
  await expect(page.locator('#user_login')).toBeVisible();
});
