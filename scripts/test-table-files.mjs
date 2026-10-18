import fs from 'node:fs';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';

const helper = fs.readFileSync('resources/js/table-files.js', 'utf8');
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jf1EAAAAASUVORK5CYII=', 'base64');
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    await page.route('http://fixture.test/**', async (route) => {
        const path = new URL(route.request().url()).pathname;
        if (path === '/helper.js') return route.fulfill({contentType: 'text/javascript', body: helper});
        if (path === '/image.png') return route.fulfill({contentType: 'image/png', body: png});
        if (path === '/missing.png') return route.fulfill({status: 404, body: 'missing'});
        return route.fulfill({contentType: 'text/html', body: '<html><body><h2>Image column</h2><div id="cell"></div></body></html>'});
    });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('http://fixture.test/');
    await page.evaluate(async () => {
        const { fileCell } = await import('/helper.js');
        document.querySelector('#cell').append(fileCell({files: [
            {name: 'Signature.png', url: '/image.png', is_image: true},
            {name: '<img src=x onerror=alert(1)>.pdf', url: '/report.pdf', is_image: false},
            {name: 'Unsafe', url: 'javascript:alert(1)', is_image: true},
        ]}));
    });
    await page.waitForFunction(() => document.querySelector('#cell img')?.naturalWidth > 0);
    assert.equal(await page.locator('#cell img').count(), 1);
    assert.equal(await page.locator('#cell a').count(), 2);
    assert.equal(await page.locator('#cell a').nth(1).textContent(), '<img src=x onerror=alert(1)>.pdf');
    await page.evaluate(async () => {
        const { fileCell } = await import('/helper.js');
        document.querySelector('#cell').append(fileCell({files: [{name: 'Missing.png', url: '/missing.png', is_image: true}]}));
    });
    await page.waitForFunction(() => document.querySelector('#cell').textContent.includes('Image unavailable: Missing.png'));
    assert.deepEqual(errors, []);
    console.log('PASS: thumbnail decoded, attachment text escaped, executable URL rejected, broken-image fallback visible; no browser errors.');
} finally {
    await browser.close();
}
