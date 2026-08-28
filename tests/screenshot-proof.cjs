// @ts-check
// Screenshot proof of the redesigned admin-system UI (md5-distinct views).
const { chromium } = require('playwright');
const { createHash } = require('crypto');
const fs = require('fs');
const path = require('path');

const BASE = 'http://127.0.0.1:8000';
const OUT = 'C:/Users/USER/Documents/MONDAY.COM/Web Side Project/Admin Web App/admin-system/test-results/screenshots';
const u = 'test@example.com';
const p = 'Password!123';

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();

  async function login(page) {
    await page.goto(BASE + '/login');
    await page.fill('input[name="email"]', u);
    await page.fill('input[name="password"]', p);
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard', { timeout: 15000 });
  }

  async function shot(page, url, width, height, file) {
    await page.setViewportSize({ width, height });
    await page.goto(url, { waitUntil: 'networkidle' }).catch(() => {});
    await page.waitForTimeout(900);
    const fp = path.join(OUT, file);
    await page.screenshot({ path: fp, fullPage: true });
    const md5 = createHash('md5').update(fs.readFileSync(fp)).digest('hex');
    console.log(file, md5, fs.statSync(fp).size + ' bytes');
  }

  // Session 1: dashboard desktop + phone
  let ctx = await browser.newContext();
  let page = await ctx.newPage();
  await login(page);
  await shot(page, BASE + '/dashboard', 1366, 900, 'dash-desktop.png');
  await shot(page, BASE + '/dashboard', 390, 844, 'dash-phone.png');
  // Managed grid (one of the tables) desktop + phone
  await shot(page, BASE + '/installed-products', 1366, 900, 'grid-desktop.png');
  await shot(page, BASE + '/installed-products', 390, 844, 'grid-phone.png');
  await ctx.close();
  console.log('done');
  await browser.close();
})();
