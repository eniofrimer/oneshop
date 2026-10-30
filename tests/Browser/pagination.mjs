import { engineName, launchBrowser, mobileContext } from './engine.mjs';
import AxeBuilder from '@axe-core/playwright';
import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';

process.umask(0o077);
assert.ok(process.env.ONESHOP_BROWSER_URL, 'Provide a disposable instance with the pagination fixture.');
const target = new URL(process.env.ONESHOP_BROWSER_URL);
assert.ok(['http:', 'https:'].includes(target.protocol) && ['/', ''].includes(target.pathname) && !target.username && !target.password && !target.search && !target.hash);
const base = target.origin;
const output = resolve(process.env.ONESHOP_BROWSER_OUTPUT ?? 'storage/app/private/browser-qa');
await mkdir(output, { recursive: true, mode: 0o700 });
const browser = await launchBrowser();
const reports = [];
try {
    for (const mode of ['desktop', 'mobile', 'no-js']) {
        const mobile = mode === 'mobile', javascript = mode !== 'no-js';
        const context = await browser.newContext({ viewport: mobile ? { width: 390, height: 844 } : { width: 1400, height: 1000 },
            javaScriptEnabled: javascript, locale: 'hr-HR', ...mobileContext(mobile), serviceWorkers: 'block' });
        const external = [], errors = [];
        await context.route('**/*', route => {
            if (new URL(route.request().url()).origin === base) return route.continue();
            external.push(new URL(route.request().url()).origin);
            return route.abort();
        });
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
        let accessibilityChecks = 0;
        const audit = async stage => {
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, `${mode}/${stage}: horizontal overflow`);
            if (!javascript) return;
            const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
            const summarize = items => items.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }));
            await writeFile(`${output}/paging-${mode}-${stage}-accessibility.json`, JSON.stringify({ violations: summarize(result.violations), incomplete: summarize(result.incomplete) }, null, 2));
            assert.deepEqual(result.violations.map(item => item.id), [], `${mode}/${stage}: accessibility violations`);
            accessibilityChecks++;
        };
        const query = '/?q=QA%20Stranica&available=1';
        assert.equal((await page.goto(base + query, { waitUntil: 'networkidle' })).status(), 200);
        assert.equal(await page.locator('.product-card').count(), 12);
        await page.locator('.product-meta h3').getByRole('link', { name: 'QA Stranica 01', exact: true }).waitFor();
        assert.equal(await page.locator('.product-meta h3').getByRole('link', { name: 'QA Stranica 13', exact: true }).count(), 0);
        await audit('first');
        await page.locator('.store-pagination').screenshot({ path: `${output}/paging-${mode}-controls.png` });
        if (javascript) await page.evaluate(() => { window.__oneshopPagingMarker = true; });
        const next = page.locator('.pagination a[href*="page=2"]:visible').first();
        if (mobile) await next.tap();
        else { await next.focus(); await page.keyboard.press('Enter'); }
        await page.waitForURL(url => url.searchParams.get('page') === '2');
        await page.locator('.product-meta h3').getByRole('link', { name: 'QA Stranica 13', exact: true }).waitFor();
        assert.equal(await page.locator('.product-card').count(), 1);
        assert.equal(await page.locator('.product-meta h3').getByRole('link', { name: 'QA Stranica 01', exact: true }).count(), 0);
        assert.equal(new URL(page.url()).searchParams.get('q'), 'QA Stranica');
        assert.equal(new URL(page.url()).searchParams.get('available'), '1');
        if (javascript) {
            assert.equal(await page.evaluate(() => window.__oneshopPagingMarker), true);
            assert.equal(await page.locator('[data-catalog-results] h2').evaluate(node => node === document.activeElement), true);
        }
        await audit('second');
        await page.screenshot({ path: `${output}/paging-${mode}-second.png`, fullPage: true });

        assert.equal((await page.goto(base + query + '&page=3', { waitUntil: 'networkidle' })).status(), 200);
        await page.getByRole('heading', { name: 'Ova stranica kolekcije je prazna.', exact: true }).waitFor();
        assert.equal(await page.locator('.product-card').count(), 0);
        if (javascript) await page.evaluate(() => { window.__oneshopEmptyPage = true; });
        await audit('empty');
        const firstPage = page.getByRole('link', { name: 'Vrati se na prvu stranicu', exact: true });
        if (mobile) await firstPage.tap();
        else { await firstPage.focus(); await page.keyboard.press('Enter'); }
        await page.waitForURL(url => url.searchParams.get('page') === '1');
        await page.locator('.product-meta h3').getByRole('link', { name: 'QA Stranica 01', exact: true }).waitFor();
        assert.equal(await page.locator('.product-card').count(), 12);
        assert.equal(await page.locator('[name=q]').inputValue(), 'QA Stranica');
        assert.equal(await page.locator('[name=available]').isChecked(), true);
        if (javascript) assert.equal(await page.evaluate(() => window.__oneshopEmptyPage), true);
        await audit('recovered');
        assert.deepEqual(errors, []);
        assert.deepEqual(external, []);
        reports.push({ mode, engine: engineName, pages: [12, 1, 0, 12], preservedFilters: true, javascriptErrors: 0, accessibilityChecks });
        await context.close();
    }
    await writeFile(`${output}/pagination-report.json`, JSON.stringify(reports, null, 2) + '\n');
    console.log(JSON.stringify(reports));
} finally {
    await browser.close();
}
