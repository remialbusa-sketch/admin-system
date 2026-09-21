/**
 * Manual smoke test for the grid's floating selection bar.
 *
 * Regression coverage for the callWire routing bugs (see
 * ManagedTableSelectionActionsTest): every bar action must send the correct
 * Livewire call and receive a proper Livewire 3 response (a `components`
 * array), with no page errors. A malformed response would crash the client
 * request pool ("Cannot read properties of undefined (reading 'shift')").
 *
 * Usage:
 *   php artisan serve --host=127.0.0.1 --port=8000
 *   node scripts/e2e-bar.mjs
 *
 * Requires the seeded superadmin (test@example.com / Password!123) and at
 * least one service request row in the local database.
 */
import { chromium } from 'playwright';

const BASE = 'http://127.0.0.1:8000';
const events = [];

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();

page.on('console', (msg) => {
    if (msg.type() === 'error') events.push(`[console.error] ${msg.text().slice(0, 200)}`);
});
page.on('pageerror', (err) => events.push(`[pageerror] ${err.message.slice(0, 200)}`));
page.on('dialog', (dialog) => dialog.accept());

page.on('request', (req) => {
    if (!req.url().includes('/livewire/update')) return;
    try {
        const payload = JSON.parse(req.postData() || '{}');
        const parts = (payload.components || []).flatMap((c) => (c.calls || []).map((call) => call.method));
        events.push(`[wire call] ${parts.join(',')}`);
    } catch { /* ignore */ }
});

// login
await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
await page.fill('input[name="email"]', 'test@example.com');
await page.fill('input[name="password"]', 'Password!123');
await Promise.all([
    page.waitForURL('**/dashboard', { timeout: 30000 }).catch(() => null),
    page.click('button[type="submit"]'),
]);

await page.goto(`${BASE}/service-requests`, { waitUntil: 'domcontentloaded' });
await page.waitForSelector('[data-managed-table-grid] .tabulator-row', { timeout: 30000 });
await page.waitForTimeout(600);

async function ensureSelected() {
    if (await page.isVisible('.admin-selection-bar')) {
        return;
    }
    await page.click('[data-managed-table-grid] .tabulator-row input[type="checkbox"]');
    await page.waitForTimeout(300);
}

async function callBarButton(title, label) {
    await ensureSelected();
    const wait = page.waitForResponse((r) => r.url().includes('/livewire/update'), { timeout: 20000 }).catch(() => null);
    await page.click(`.admin-selection-bar button[title="${title}"]`);
    const resp = await wait;
    if (!resp) {
        console.log(`${label}: NO request fired`);
        return;
    }
    const body = await resp.text();
    let effects = '';
    try {
        const json = JSON.parse(body);
        effects = Object.keys(json.effects || {}).join(',');
    } catch { /* ignore */ }
    console.log(`${label}: HTTP ${resp.status()} components=${body.includes('"components"')} effects=[${effects}]`);
    await page.waitForTimeout(800);
}

await callBarButton('Duplicate selected rows', 'Duplicate');
await callBarButton('Archive selected rows', 'Archive');

// switch to the archive view and restore
await page.click('button:has-text("Archive box")').catch(() => {});
await page.waitForTimeout(800);
await callBarButton('Restore selected rows to the active listing', 'Restore');

// back to active, then export + delete
await page.click('button:has-text("Back to active records")').catch(() => {});
await page.waitForTimeout(800);
await callBarButton('Export selected rows to Excel', 'Export');
await callBarButton('Delete selected rows', 'Delete');

console.log('--- events ---');
console.log(events.join('\n') || '(none)');

await browser.close();
