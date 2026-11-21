import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { login } from './helpers';
import { frameShot } from './frame';

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
    await frameShot(page, resolve(outDir, 'working-time-today.png'));

    await page.goto('/logout');
    await login(page, 'mia');
    await page.goto('/working-time/team');
    await frameShot(page, resolve(outDir, 'working-time-team.png'));
  });
});
