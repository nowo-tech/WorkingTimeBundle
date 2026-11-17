import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { login, waitForWebProfiler } from './helpers';

/** README screenshots (PNG, converted to WebP by scripts/to-webp.mjs). Run after demo.spec.ts: the register must be on. */
const outDir = process.env.SCREENSHOT_DIR ? resolve(process.env.SCREENSHOT_DIR) : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('Working Time screenshots', () => {
  test.use({ viewport: { width: 1280, height: 1000 } });

  test('working-time-today and working-time-team', async ({ page }) => {
    await login(page, 'ana');
    await page.goto('/working-time');
    await expect(page.getByTestId('wt-clock')).toBeVisible();
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'working-time-today.png'), fullPage: true });

    await page.getByTestId('demo-logout').click();
    await login(page, 'mia');
    await page.goto('/working-time/team');
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'working-time-team.png'), fullPage: true });
  });
});
