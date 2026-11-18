import { test, expect } from '@playwright/test';
import { earlierStart, expectNoHorizontalScroll, login, logout, thisMonth, today } from './helpers';

/**
 * The register end to end against the running demo (fresh stack: ana clocks out once per day, so recreate the
 * stack with `down -v` to run it twice on the same day). One serial flow: admin enables the register, ana clocks and
 * asks for a correction, mia approves it and exports a CSV.
 */
test.describe.configure({ mode: 'serial' });

test.describe('Working Time demo', () => {
  test('the home page answers 200 for an anonymous visitor', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.status()).toBe(200);
    await expect(page.getByTestId('demo-people')).toContainText('Ana Worker');
  });

  test('admin turns the register on in the settings', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/working-time/settings');
    const enabled = page.locator('input[name="wt_settings[enabled]"]');
    await enabled.check();
    await page.locator('form:has(input[name="wt_settings[enabled]"]) button[type="submit"]').click();
    // The flash is shown as the translation key today (see the report); accept the translated text too.
    await expect(page.getByRole('alert').filter({ hasText: /The settings were saved\./ })).toBeVisible();
    await expect(page.locator('input[name="wt_settings[enabled]"]')).toBeChecked();
    await logout(page);
  });

  test('anonymous visitors are sent to the login page', async ({ request }) => {
    const response = await request.get('/working-time', { maxRedirects: 0 });
    expect(response.status()).toBe(302);
    expect(response.headers()['location']).toContain('/login');
  });

  test('a worker is refused the team page (403)', async ({ page }) => {
    await login(page, 'ana');
    const response = await page.goto('/working-time/team');
    expect(response?.status()).toBe(403);
    await page.goto('/');
    await logout(page);
  });

  test('a worker clocks in, takes a break, clocks out and asks for a correction', async ({ page }) => {
    await login(page, 'ana');
    await page.goto('/working-time');
    const clock = page.getByTestId('wt-clock');
    await expect(clock).toHaveAttribute('data-status', 'idle');
    await expect(page.getByTestId('wt-header-clock')).toBeVisible();

    // The amended start is relative to the clock-in, so the run does not depend on the time of day.
    const earlier = earlierStart(new Date());
    await page.locator('form[data-testid="wt-clock-clock-in"] button[type="submit"]').click();
    await expect(page.getByTestId('wt-clock')).toHaveAttribute('data-status', 'working');
    await page.locator('form[data-testid="wt-clock-break-start"] button[type="submit"]').click();
    await expect(page.getByTestId('wt-clock')).toHaveAttribute('data-status', 'on_break');
    await page.locator('form[data-testid="wt-clock-break-end"] button[type="submit"]').click();
    await expect(page.getByTestId('wt-clock')).toHaveAttribute('data-status', 'working');
    await page.locator('form[data-testid="wt-clock-clock-out"] button[type="submit"]').click();
    await expect(page.getByTestId('wt-clock')).toHaveAttribute('data-status', 'closed');

    await page.goto(`/working-time/date/${today()}`);
    await expect(page.getByTestId('wt-segment').first()).toBeVisible();
    expect(await page.getByTestId('wt-segment').count()).toBeGreaterThanOrEqual(2);

    const form = page.locator('[data-testid="wt-correction-form"] form');
    await form.locator('select[name="wt_correction[operation]"]').selectOption('amend');
    await form.locator('select[name="wt_correction[segment]"]').selectOption({ index: 1 });
    await form.locator('input[name="wt_correction[start]"]').fill(earlier);
    await form.locator('textarea[name="wt_correction[reason]"]').fill('Came in earlier than the clock shows');
    await form.locator('button[type="submit"]').click();
    await expect(page).toHaveURL(new RegExp(`/working-time/date/${today()}`));
    await expect(page.getByRole('alert').or(page.getByRole('status')).first()).toBeVisible();
    await logout(page);
  });

  test('a manager approves the request and exports a CSV', async ({ page }) => {
    await login(page, 'mia');
    await page.goto('/working-time/team');
    const pending = page.locator('[data-testid^="wt-pending-"]:not([data-testid="wt-pending-corrections"])');
    await expect(pending).toHaveCount(1);
    await pending.first().getByTestId('wt-approve').click();
    await expect(pending).toHaveCount(0);

    await page.goto('/working-time/exports');
    const form = page.locator('[data-testid="wt-export-form"] form');
    await form.locator('select[name="wt_export[scope]"]').selectOption('own');
    await form.locator('input[name="wt_export[from]"]').fill(thisMonth());
    await form.locator('input[name="wt_export[to]"]').fill(thisMonth());
    await form.locator('select[name="wt_export[format]"]').selectOption('csv');
    await form.locator('button[type="submit"]').click();

    const download = page.getByTestId('wt-export-download').first();
    await expect(download).toBeVisible();
    const href = await download.getAttribute('href');
    expect(href).not.toBeNull();
    const file = await page.request.get(href!);
    expect(file.status()).toBe(200);
    expect(file.headers()['content-disposition']).toContain('attachment');
    await logout(page);
  });
});

test.describe('Holidays demo (stand-alone component)', () => {
  test('admin adds a holiday and the agenda reads it without the register', async ({ page }) => {
    await login(page, 'admin');
    await page.goto('/holidays');
    const year = new Date().getUTCFullYear();
    const calendar = page.getByTestId('wt-holiday-calendar-form');
    await calendar.locator('input[name="wt_holiday_calendar[name]"]').fill(`Demo ${Date.now()}`);
    await calendar.locator('input[name="wt_holiday_calendar[year]"]').fill(String(year));
    await calendar.locator('button[type="submit"]').click();
    const day = page.getByTestId('wt-holiday-day-form');
    await day.locator('input[name="wt_holiday_day[date]"]').fill(`${year}-12-25`);
    await day.locator('input[name="wt_holiday_day[name]"]').fill('Christmas Day');
    await day.locator('button[type="submit"]').click();
    await expect(page.getByRole('alert').filter({ hasText: /Holiday added\./ })).toBeVisible();
    await page.goto('/agenda');
    await expect(page.getByTestId('demo-agenda')).toContainText('Christmas Day');
    await logout(page);
  });

  test('a worker is refused the holiday pages (403)', async ({ page }) => {
    await login(page, 'ana');
    const response = await page.goto('/holidays');
    expect(response?.status()).toBe(403);
    await page.goto('/');
    await logout(page);
  });
});

test.describe('Working Time demo, phone width', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('the pages do not scroll horizontally', async ({ page }) => {
    await page.goto('/');
    await expectNoHorizontalScroll(page);
    await login(page, 'admin');
    for (const path of ['/working-time', `/working-time/month/${thisMonth()}`, `/working-time/date/${today()}`, '/working-time/team', '/working-time/schedules', '/working-time/exports', '/working-time/preferences', '/working-time/settings', '/holidays', '/agenda', '/working-time/settings/leave']) {
      const response = await page.goto(path);
      expect(response?.status(), path).toBe(200);
      await expectNoHorizontalScroll(page);
    }
    await logout(page);
  });
});
