import { engineName, launchBrowser, mobileContext } from './engine.mjs';
import AxeBuilder from '@axe-core/playwright';
import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';

process.umask(0o077);
assert.equal(process.env.ONESHOP_BROWSER_ALLOW_WRITES, 'true', 'Acknowledge test purchases in a disposable demo installation.');
assert.ok(process.env.ONESHOP_BROWSER_URL);
const target = new URL(process.env.ONESHOP_BROWSER_URL);
assert.ok(['http:', 'https:'].includes(target.protocol) && ['/', ''].includes(target.pathname) && !target.username && !target.password && !target.search && !target.hash);
const base = target.origin;
const output = resolve(process.env.ONESHOP_BROWSER_OUTPUT ?? 'storage/app/private/orders-browser-qa');
await mkdir(output, { recursive: true, mode: 0o700 });
const browser = await launchBrowser();
const reports = [];
try {
    for (const mode of ['desktop', 'mobile', 'no-js']) {
        const mobile = mode === 'mobile', errors = [], external = [], stages = [];
        const context = await browser.newContext({ viewport: mobile ? { width: 390, height: 844 } : { width: 1400, height: 1000 },
            locale: 'hr-HR', javaScriptEnabled: mode !== 'no-js', ...mobileContext(mobile), serviceWorkers: 'block' });
        await context.route('**/*', route => {
            if (new URL(route.request().url()).origin === base) return route.continue();
            external.push(new URL(route.request().url()).origin);
            return route.abort();
        });
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        const audit = async stage => {
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, mode + '/' + stage + ' overflow');
            if (mode !== 'no-js') {
                const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
                const summary = entries => entries.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }));
                await writeFile(output + '/' + mode + '-' + stage + '-axe.json', JSON.stringify({ violations: summary(result.violations), incomplete: summary(result.incomplete) }));
                assert.deepEqual(summary(result.violations), [], mode + '/' + stage + ' accessibility');
            }
            await page.screenshot({ path: output + '/' + mode + '-' + stage + '.png', fullPage: true });
            stages.push({ stage, axe: mode !== 'no-js', overflow: false });
        };
        await page.goto(base + '/moje-narudzbe', { waitUntil: 'networkidle' });
        await page.getByRole('heading', { name: 'Prvi favorit tek te čeka.' }).waitFor();
        await audit('empty');
        await page.goto(base, { waitUntil: 'networkidle' });
        await page.locator('.product-meta h3 a').filter({ hasText: 'Šalica Jutro' }).click();
        await page.locator('[name=quantity]').fill('2');
        await page.getByRole('button', { name: /Dodaj u košaricu/ }).click();
        await page.waitForURL('**/kosarica');
        assert.equal(await page.locator('[data-cart-count]').innerText(), '2');
        await page.getByRole('link', { name: /Na završetak kupnje/ }).click();
        for (const [field, value] of Object.entries({ first_name: 'Browser', last_name: 'Orders ' + mode,
            contact_email: 'orders-' + mode + '@example.test', contact_phone: '+385910000000', line_one: 'QA ulica 1', city: 'Zagreb', postcode: '10000' })) {
            await page.locator('[name=' + field + ']').fill(value);
        }
        await page.getByRole('button', { name: /Pregledaj narudžbu/ }).click();
        await page.waitForURL('**/checkout/pregled');
        await page.locator('[name=demo_acknowledged]').check();
        await page.getByRole('button', { name: /Predaj testnu narudžbu/ }).click();
        await page.waitForURL('**/checkout/test/*');
        const paymentUrl = page.url();
        const reference = await page.locator('.payment-reference strong').innerText();
        await page.goto(base + '/moje-narudzbe', { waitUntil: 'networkidle' });
        await page.getByText('Čeka testnu potvrdu', { exact: true }).waitFor();
        await audit('pending');
        await page.locator('.guest-order a').click();
        assert.equal(page.url(), paymentUrl);
        await page.getByRole('button', { name: 'Simuliraj odbijanje', exact: true }).click();
        await page.waitForLoadState('networkidle');
        await page.goto(base + '/moje-narudzbe', { waitUntil: 'networkidle' });
        await page.getByText('Testno odbijena', { exact: true }).waitFor();
        await audit('declined');
        await page.locator('.guest-order a').click();
        await page.getByRole('button', { name: /Simuliraj uspješno plaćanje/ }).click();
        await page.waitForLoadState('networkidle');
        await page.getByRole('heading', { name: /Testna kupnja je uspjela/ }).waitFor();
        await page.goto(base, { waitUntil: 'networkidle' });
        assert.equal(await page.locator('[data-cart-count]').innerText(), '0');
        await page.locator('.product-meta h3 a').filter({ hasText: 'Šalica Jutro' }).click();
        await page.getByRole('button', { name: /Dodaj u košaricu/ }).click();
        await page.waitForURL('**/kosarica');
        assert.equal(await page.locator('[data-cart-count]').innerText(), '1');
        await page.goto(base + '/moje-narudzbe', { waitUntil: 'networkidle' });
        await page.getByText('Testno potvrđena', { exact: true }).waitFor();
        await page.getByRole('link', { name: /Otvori potvrdu/ }).waitFor();
        assert.ok((await page.locator('.guest-orders').innerText()).includes(reference));
        await audit('paid-new-cart');

        const outsider = await browser.newContext({ javaScriptEnabled: mode !== 'no-js', serviceWorkers: 'block' });
        await outsider.route('**/*', route => {
            if (new URL(route.request().url()).origin === base) return route.continue();
            external.push(new URL(route.request().url()).origin);
            return route.abort();
        });
        const otherPage = await outsider.newPage();
        await otherPage.goto(base + '/moje-narudzbe?order=' + encodeURIComponent(reference));
        await otherPage.getByRole('heading', { name: 'Prvi favorit tek te čeka.' }).waitFor();
        assert.equal((await otherPage.goto(paymentUrl)).status(), 404);
        await outsider.close();
        assert.deepEqual(errors, []);
        assert.deepEqual(external, []);
        reports.push({ mode, result: 'passed', reference, stages, otherSessionRefused: true, retainedAfterNewCart: true, javascriptErrors: 0, externalOrigins: external });
        await context.close();
    }
    await writeFile(output + '/report.json', JSON.stringify({ engine: engineName, reports }, null, 2));
    console.log(JSON.stringify({ engine: engineName, result: 'passed', purchases: reports.length, reports: reports.length, otherSessionRefused: true }));
} finally { await browser.close(); }
