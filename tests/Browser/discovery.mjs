import { engineName, launchBrowser, mobileContext } from './engine.mjs';
import AxeBuilder from '@axe-core/playwright';
import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';

process.umask(0o077);
assert.ok(process.env.ONESHOP_BROWSER_URL, 'Provide an initialized disposable three-product demo URL.');
const target = new URL(process.env.ONESHOP_BROWSER_URL);
assert.ok(['http:', 'https:'].includes(target.protocol) && ['/', ''].includes(target.pathname)
    && !target.username && !target.password && !target.search && !target.hash);
const base = target.origin;
const output = resolve(process.env.ONESHOP_BROWSER_OUTPUT ?? 'storage/app/private/discovery-browser-qa');
await mkdir(output, { recursive: true, mode: 0o700 });
const browser = await launchBrowser();
const reports = [];
try {
    for (const mode of ['desktop', 'mobile', 'small', 'no-js']) {
        const mobile = mode === 'mobile' || mode === 'small', javascript = mode !== 'no-js';
        const viewport = mode === 'small' ? { width: 320, height: 844 } : mobile ? { width: 390, height: 844 } : { width: 1400, height: 1000 };
        const context = await browser.newContext({ viewport, javaScriptEnabled: javascript, locale: 'hr-HR', serviceWorkers: 'block', ...mobileContext(mobile) });
        const external = [], mutations = [], errors = [], failed = [];
        await context.route('**/*', route => {
            const request = route.request();
            if (new URL(request.url()).origin !== base) { external.push(new URL(request.url()).origin); return route.abort(); }
            if (!['GET', 'HEAD'].includes(request.method())) { mutations.push(request.method()); return route.abort(); }
            return route.continue();
        });
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        page.on('response', response => { if (response.status() >= 400) failed.push(new URL(response.url()).pathname); });
        let accessibilityChecks = 0;
        const audit = async stage => {
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, `${mode}/${stage}: horizontal overflow`);
            if (!javascript) return;
            const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
            const summarize = items => items.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }));
            await writeFile(`${output}/${mode}-${stage}-axe.json`, JSON.stringify({ violations: summarize(result.violations), incomplete: summarize(result.incomplete) }, null, 2));
            assert.deepEqual(result.violations.map(item => item.id), [], `${mode}/${stage}: accessibility violations`);
            accessibilityChecks++;
        };
        assert.equal((await page.goto(base, { waitUntil: 'networkidle' })).status(), 200);
        assert.equal(await page.locator('.product-card').count(), 3);
        await page.locator('.product-meta h3 a').filter({ hasText: 'Majica Osnova' }).click();
        const shirtUrl = page.url();
        const breadcrumb = page.getByRole('navigation', { name: 'Putanja proizvoda', exact: true });
        assert.equal(await breadcrumb.locator('[aria-current="page"]').innerText(), 'Majica Osnova');
        const schema = JSON.parse(await page.locator('script[type="application/ld+json"]').textContent());
        assert.equal(schema['@type'], 'BreadcrumbList');
        assert.deepEqual(schema.itemListElement.map(item => item.name), ['Kolekcija', 'Majica Osnova']);
        assert.deepEqual(schema.itemListElement.map(item => item.item), [base + '/', shirtUrl]);
        const related = page.locator('[data-related-products]');
        await related.scrollIntoViewIfNeeded();
        assert.equal(await related.locator('.product-card').count(), 2);
        assert.equal(await related.locator(`a[href="${shirtUrl}"]`).count(), 0);
        await page.waitForFunction(() => [...document.querySelectorAll('[data-related-products] img')].every(image => image.complete && image.naturalWidth > 0));
        await audit('related-products');
        await related.screenshot({ path: `${output}/${mode}-related.png` });
        const cupLink = related.locator('.product-meta h3 a').filter({ hasText: 'Šalica Jutro' });
        await cupLink.focus();
        await page.keyboard.press('Enter');
        await page.getByRole('heading', { name: 'Šalica Jutro', exact: true }).waitFor();
        const cupUrl = page.url();
        assert.equal(await breadcrumb.locator('[aria-current="page"]').innerText(), 'Šalica Jutro');
        assert.equal(await related.locator(`a[href="${cupUrl}"]`).count(), 0);
        assert.equal(await related.locator('.product-meta h3 a').filter({ hasText: 'Majica Osnova' }).count(), 1);
        await audit('next-product');
        await page.goBack({ waitUntil: 'networkidle' });
        await page.getByRole('heading', { name: 'Majica Osnova', exact: true }).waitFor();
        if (mode === 'small') {
            // Synthetic DOM labels only: do not modify any saved product.
            await page.evaluate(() => {
                document.querySelector('.product-copy h1').textContent = 'Ž'.repeat(255);
                document.querySelector('.product-breadcrumb [aria-current="page"]').textContent = 'Ž'.repeat(255);
                document.querySelectorAll('[data-related-products] .product-meta h3 a').forEach(link => { link.textContent = 'Č'.repeat(255); });
            });
            await audit('long-labels');
            await related.screenshot({ path: `${output}/${mode}-long-labels.png` });
        }
        await breadcrumb.getByRole('link', { name: 'Kolekcija', exact: true }).focus();
        await page.keyboard.press('Enter');
        await page.waitForURL(url => url.pathname === '/' && url.hash === '#kolekcija');
        assert.equal(await page.locator('.product-card').count(), 3);
        await audit('collection-return');
        assert.deepEqual(errors, []); assert.deepEqual(external, []); assert.deepEqual(mutations, []); assert.deepEqual(failed, []);
        reports.push({ mode, accessibilityChecks, boundedPublicCards: 2, breadcrumbAndRelatedKeyboardNavigation: true,
            browserBack: true, scriptDataMatchesVisiblePath: true, longLabelsInDomOnly: mode === 'small', ordersPlaced: 0 });
        await context.close();
    }
    await writeFile(`${output}/report.json`, JSON.stringify({ engine: engineName, reports, ordersPlaced: 0 }, null, 2));
    console.log(JSON.stringify({ engine: engineName, reports, ordersPlaced: 0 }));
} finally {
    await browser.close();
}
