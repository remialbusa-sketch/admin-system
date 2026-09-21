/**
 * Manual smoke test: the floating selection bar must reappear after using any
 * bar action and selecting another row (including selecting immediately, while
 * the post-action refresh is still in flight).
 *
 * Covers the reported bug where the bar did not come back after an action.
 * The fix preserves the selection by row id across replaceData() and derives
 * the count from Tabulator's selection module (see managed-table.js).
 *
 * Usage:
 *   php artisan serve --host=127.0.0.1 --port=8000
 *   node scripts/e2e-reselect.mjs
 */
import { chromium } from 'playwright';

const BASE = 'http://127.0.0.1:8000';
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
page.on('dialog', (d) => d.accept());

await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
await page.fill('input[name="email"]', 'test@example.com');
await page.fill('input[name="password"]', 'Password!123');
await Promise.all([
    page.waitForURL('**/dashboard', { timeout: 30000 }).catch(() => null),
    page.click('button[type="submit"]'),
]);

async function state() {
    return page.evaluate(() => {
        const el = document.querySelector('[data-managed-table-grid]')?.closest('[x-data]');
        const d = el?._x_dataStack?.[0];
        const bar = document.querySelector('.admin-selection-bar');
        return {
            count: d?.selectedCount,
            tab: d?.table ? d.table.getSelectedRows().length : -1,
            checked: document.querySelectorAll('[data-managed-table-grid] .tabulator-row input[type="checkbox"]:checked').length,
            display: bar ? getComputedStyle(bar).display : 'missing',
        };
    });
}

async function selectRow(index) {
    await page.locator('[data-managed-table-grid] .tabulator-row input[type="checkbox"]').nth(index).click();
    await page.waitForTimeout(500);
}

async function runAction(title, settleMs = 1400) {
    const wait = page.waitForResponse((r) => r.url().includes('/livewire/update'), { timeout: 20000 }).catch(() => null);
    await page.click(`.admin-selection-bar button[title="${title}"]`);
    await wait;
    if (settleMs > 0) {
        await page.waitForTimeout(settleMs);
    }
}

async function scenario(name, title, settleMs = 1400) {
    await page.goto(`${BASE}/service-requests`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[data-managed-table-grid] .tabulator-row', { timeout: 30000 });
    await page.waitForTimeout(600);
    await selectRow(0);
    await runAction(title, settleMs);
    const afterAction = await state();
    await selectRow(1);
    await page.waitForTimeout(1200);
    const afterReselect = await state();
    console.log(`${name}: after action ${JSON.stringify(afterAction)} -> after reselect ${JSON.stringify(afterReselect)}`);
}

await scenario('Archive       ', 'Archive selected rows');
await scenario('Archive(fast) ', 'Archive selected rows', 0);
await scenario('Export        ', 'Export selected rows to Excel');
await scenario('Export(fast)  ', 'Export selected rows to Excel', 0);
await scenario('Delete        ', 'Delete selected rows');
await scenario('Delete(fast)  ', 'Delete selected rows', 0);

// Archive view: restore then select another
await page.goto(`${BASE}/service-requests`, { waitUntil: 'domcontentloaded' });
await page.waitForSelector('[data-managed-table-grid] .tabulator-row', { timeout: 30000 });
await page.waitForTimeout(500);
await page.click('button:has-text("Archive box")').catch(() => {});
await page.waitForTimeout(1000);
const archivedRows = await page.locator('[data-managed-table-grid] .tabulator-row').count();
if (archivedRows > 0) {
    await selectRow(0);
    await runAction('Restore selected rows to the active listing');
    const afterRestore = await state();
    const remaining = await page.locator('[data-managed-table-grid] .tabulator-row').count();
    if (remaining > 0) {
        await selectRow(0);
    }
    console.log(`Restore: after action ${JSON.stringify(afterRestore)} -> after reselect ${JSON.stringify(await state())} (archived rows were ${archivedRows})`);
} else {
    console.log('Restore: no archived rows to test');
}

await browser.close();
