import { launchBrowser, engineName, mobileContext } from './engine.mjs';
import AxeBuilder from '@axe-core/playwright';
import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';

process.umask(0o077);
assert.ok(process.env.ONESHOP_BROWSER_URL, 'Provide an initialized disposable demo URL.');
const target = new URL(process.env.ONESHOP_BROWSER_URL);
assert.ok(['http:', 'https:'].includes(target.protocol) && ['/', ''].includes(target.pathname)
    && !target.username && !target.password && !target.search && !target.hash);
const base = target.origin;
const output = resolve(process.env.ONESHOP_BROWSER_OUTPUT ?? 'storage/app/private/gallery-browser-qa');
await mkdir(output, { recursive: true, mode: 0o700 });
const browser = await launchBrowser();
const reports = [];
try {
    for (const mode of ['desktop', 'mobile', 'small', 'no-js']) {
        const mobile = mode === 'mobile' || mode === 'small';
        const viewport = mode === 'small' ? { width: 320, height: 568 } : mobile ? { width: 390, height: 844 } : { width: 1400, height: 1000 };
        const context = await browser.newContext({ viewport,
            javaScriptEnabled: mode !== 'no-js', locale: 'hr-HR', serviceWorkers: 'block', ...mobileContext(mobile) });
        const blocked = [], external = [], errors = [];
        await context.route('**/*', route => {
            const request = route.request();
            if (new URL(request.url()).origin !== base) { external.push(new URL(request.url()).origin); return route.abort(); }
            if (!['GET', 'HEAD'].includes(request.method())) { blocked.push(request.method()); return route.abort(); }
            return route.continue();
        });
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(base, { waitUntil: 'networkidle' });
        await page.locator('.product-meta h3 a').filter({ hasText: 'Majica Osnova' }).click();
        const productUrl = page.url();
        const button = page.getByRole('button', { name: 'Povećaj fotografiju' });
        if (mode === 'no-js') {
            assert.equal(await page.locator('[data-gallery-zoom]').isVisible(), false);
            const original = await page.locator('[data-gallery-original]').getAttribute('href');
            const image = await context.request.get(original);
            assert.equal(image.status(), 200);
            assert.match(image.headers()['content-type'], /^image\//);
            const popup = page.waitForEvent('popup');
            await page.locator('[data-gallery-original]').click();
            const opened = await popup;
            await opened.waitForLoadState('load');
            assert.equal(opened.url(), original);
            await opened.close();
            assert.equal(page.url(), productUrl);
            reports.push({ mode, originalWithoutJavascript: true, mutations: blocked.length });
        } else {
            const dialog = page.getByRole('dialog', { name: 'Fotografija proizvoda', exact: true });
            const close = page.getByRole('button', { name: 'Zatvori', exact: true });
            let audits = 0;
            const audit = async stage => {
                const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
                const summarize = items => items.map(item => ({ id: item.id, impact: item.impact, targets: item.nodes.map(node => node.target) }));
                const report = { stage, violations: summarize(result.violations), incomplete: summarize(result.incomplete) };
                await writeFile(`${output}/${mode}-${stage}-axe.json`, JSON.stringify(report, null, 2)+'\n', { mode: 0o600 });
                assert.deepEqual(report.violations, []);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
                assert.equal(await dialog.evaluate(node => {
                    const r = node.getBoundingClientRect();
                    return r.left >= 0 && r.top >= 0 && r.right <= innerWidth + 1 && r.bottom <= innerHeight + 1
                        && Math.abs((r.left + r.right) / 2 - innerWidth / 2) <= 1;
                }), true, 'The viewer stays centered and inside the viewport.');
                audits++;
            };
            await button.focus();
            await page.keyboard.press('Enter');
            await dialog.waitFor();
            assert.equal(await close.evaluate(node => node === document.activeElement), true);
            await dialog.locator('[data-photo-full]').evaluate(image => image.decode());
            assert.match(await dialog.locator('img').getAttribute('alt'), /maslinastoj boji/);
            for (let step = 0; step < 5; step++) {
                await page.keyboard.press('Tab');
                assert.equal(await dialog.evaluate(node => node.contains(document.activeElement)), true, 'Focus remains inside the modal.');
            }
            await close.focus();
            await page.keyboard.press('Shift+Tab');
            assert.equal(await dialog.locator('[data-photo-open]').evaluate(node => node === document.activeElement), true);
            await audit('olive-open');
            await page.screenshot({ path: `${output}/${mode}-photo.png`, fullPage: true });
            await page.keyboard.press('Escape');
            await dialog.waitFor({ state: 'hidden' });
            assert.equal(await button.evaluate(node => node === document.activeElement), true);
            assert.equal(await page.locator('[data-photo-full]').getAttribute('src'), null);

            const colour = page.getByRole('combobox', { name: 'Boja', exact: true });
            if (await colour.count()) await colour.selectOption({ label: 'Pijesak' });
            else await page.getByRole('group', { name: 'Boja', exact: true }).getByRole('radio', { name: 'Pijesak', exact: true }).check();
            assert.equal(await page.locator('[data-add-button]').isDisabled(), true);
            await button.click();
            await dialog.waitFor();
            await dialog.locator('[data-photo-full]').evaluate(image => image.decode());
            assert.match(await dialog.locator('img').getAttribute('src'), /shirt-sand-1200\.webp$/);
            assert.match(await dialog.locator('img').getAttribute('alt'), /boji pijeska/);
            await audit('sand-open');
            await close.click();
            await dialog.waitFor({ state: 'hidden' });
            assert.equal(await button.evaluate(node => node === document.activeElement), true);

            const original = await page.locator('[data-gallery-original]').getAttribute('href');
            // A unique local URL bypasses the browser's decoded-image memory cache.
            // This is a browser-only network fault, with no server fixture or write.
            const failedUrl = new URL(original);
            failedUrl.searchParams.set('qa_image_failure', mode);
            await page.locator('[data-gallery-original]').evaluate((link, url) => { link.href = url; }, failedUrl.href);
            await context.route(failedUrl.href, route => route.abort());
            await button.click();
            await page.getByText('Fotografija se trenutačno ne može učitati.', { exact: false }).waitFor();
            assert.equal(await dialog.locator('[data-photo-full]').isVisible(), false);
            await audit('image-error');
            await close.click();
            await dialog.waitFor({ state: 'hidden' });
            await context.unroute(failedUrl.href);
            await page.locator('[data-gallery-original]').evaluate((link, url) => { link.href = url; }, original);
            assert.equal(page.url(), productUrl);
            reports.push({ mode, keyboardOpenAndEscape: true, focusContainedAndRestored: true, variantPhoto: true,
                failedImageRecoverable: true, accessibilityChecks: audits, mutations: blocked.length });
        }
        assert.deepEqual(blocked, []);
        assert.deepEqual(external, []);
        assert.deepEqual(errors, []);
        await context.close();
    }
    await writeFile(`${output}/report.json`, JSON.stringify({ engine: engineName, reports, ordersPlaced: 0 }, null, 2)+'\n', { mode: 0o600 });
    console.log(JSON.stringify({ engine: engineName, modes: reports.length, accessibilityChecks: reports.reduce((n,r) => n+(r.accessibilityChecks??0),0), ordersPlaced: 0 }));
} finally {
    await browser.close();
}
