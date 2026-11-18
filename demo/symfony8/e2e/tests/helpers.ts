import { expect, type Page } from '@playwright/test';

/** Wait until the Symfony Web Debug Toolbar has finished loading (optional outside dev). */
export async function waitForWebProfiler(page: Page): Promise<void> {
  await page.waitForSelector('.sf-toolbar', { state: 'visible', timeout: 20000 }).catch(() => undefined);
  await page
    .waitForFunction(
      () => {
        const bar = document.querySelector('.sf-toolbar') as HTMLElement | null;
        return !bar || bar.offsetParent === null || !!document.querySelector('.sf-toolbar-block');
      },
      { timeout: 25000, polling: 250 },
    )
    .catch(() => undefined);
}

/** Signs in through the form login (all three demo users have the password "demo"). */
export async function login(page: Page, user: 'ana' | 'mia' | 'admin'): Promise<void> {
  await page.goto('/login');
  await page.locator('input[name="_username"]').fill(user);
  await page.locator('input[name="_password"]').fill('demo');
  await page.getByTestId('login-form').locator('button[type="submit"]').click();
  await expect(page.getByTestId('demo-user')).toHaveText(user);
}

/** Signs out through the masthead link. */
export async function logout(page: Page): Promise<void> {
  await page.getByTestId('demo-logout').click();
  await expect(page.getByTestId('demo-nav-login')).toBeVisible();
}

/** Today's date as the tenant sees it (the demo tenant runs in UTC). */
export function today(): string {
  return new Date().toISOString().slice(0, 10);
}

/**
 * A start one hour before the given clock-in, as HH:MM in the tenant's time zone (UTC), never before midnight of the
 * same day: an amended start that always comes before the clocked segment's end, whatever time the suite runs.
 */
export function earlierStart(clockIn: Date): string {
  const earlier = new Date(clockIn.getTime() - 60 * 60 * 1000);
  if (earlier.toISOString().slice(0, 10) !== clockIn.toISOString().slice(0, 10)) {
    return '00:00';
  }

  return earlier.toISOString().slice(11, 16);
}

/** The current month as YYYY-MM (UTC). */
export function thisMonth(): string {
  return today().slice(0, 7);
}

/** No horizontal scroll: the document is not wider than the viewport. */
export async function expectNoHorizontalScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => (document.scrollingElement?.scrollWidth ?? 0) - window.innerWidth);
  expect(overflow, 'horizontal overflow in px').toBeLessThanOrEqual(0);
}
