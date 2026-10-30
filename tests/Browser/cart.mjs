import { engineName, launchBrowser, mobileContext } from './engine.mjs';
import AxeBuilder from '@axe-core/playwright';
import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';

process.umask(0o077);
assert.ok(process.env.ONESHOP_BROWSER_URL, 'Provide the root URL of an isolated demo installation.');
const target = new URL(process.env.ONESHOP_BROWSER_URL);
assert.ok(['http:', 'https:'].includes(target.protocol) && ['/', ''].includes(target.pathname) && !target.username && !target.password && !target.search && !target.hash);
const base = target.origin;
const output = resolve(process.env.ONESHOP_BROWSER_OUTPUT ?? 'storage/app/private/browser-qa');
await mkdir(output, { recursive: true, mode: 0o700 });
const browser = await launchBrowser();
const reports = [];
try {
    for (const mode of ['desktop', 'mobile', 'no-js']) {
        const mobile = mode === 'mobile';
        const context = await browser.newContext({ viewport: mobile ? { width: 390, height: 844 } : { width: 1400, height: 1000 },
            locale: 'hr-HR', javaScriptEnabled: mode !== 'no-js', ...mobileContext(mobile), serviceWorkers: 'block' });
        const errors = [], external = [];
        await context.route('**/*', route => {
            if (new URL(route.request().url()).origin === base) return route.continue();
            external.push(new URL(route.request().url()).origin);
            return route.abort();
        });
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
        let accessibilityChecks = 0;
        const capture = async stage => {
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, `${mode}/${stage}: overflow`);
            if (mode !== 'no-js') {
                const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
                const summarize = entries => entries.map(entry => ({ id: entry.id, impact: entry.impact, targets: entry.nodes.map(node => node.target) }));
                const report = { violations: summarize(result.violations), incomplete: summarize(result.incomplete) };
                await writeFile(`${output}/cart-${mode}-${stage}-accessibility.json`, JSON.stringify(report, null, 2) + '\n');
                assert.deepEqual(report.violations, [], `${mode}/${stage}: accessibility violations`);
                accessibilityChecks++;
            }
            await page.screenshot({ path: `${output}/cart-${mode}-${stage}.png`, fullPage: true });
        };
        await page.goto(base, { waitUntil: 'networkidle' });
        await page.getByText('Razvojna instanca', { exact: false }).waitFor();
        const productUrl = await page.locator('.product-meta h3 a').filter({ hasText: 'Majica Osnova' }).getAttribute('href');
        assert.ok(productUrl);
        const add = async (color, size, quantity) => {
            await page.goto(productUrl, { waitUntil: 'networkidle' });
            if (mode === 'no-js') {
                const select = page.locator('[data-variant-fallback] select');
                const value = await select.locator('option').filter({ hasText: `${color} / ${size}` }).getAttribute('value');
                assert.ok(value);
                await select.selectOption(value);
            } else {
                await page.getByRole('group', { name: 'Boja', exact: true }).getByRole('radio', { name: color, exact: true }).check();
                await page.getByRole('combobox', { name: 'Veličina', exact: true }).selectOption({ label: size });
            }
            await page.getByRole('spinbutton', { name: 'Količina', exact: true }).fill(String(quantity));
            await page.getByRole('button', { name: /Dodaj u košaricu/ }).click();
            await page.waitForURL('**/kosarica');
        };
        await add('Maslinasta', 'M', 1);
        await add('Pijesak', 'L', 2);
        assert.equal(await page.locator('.cart-line').count(), 2);
        assert.equal(await page.locator('.cart-thumbnail').count(), 2);
        await page.locator('.cart-thumbnail').last().scrollIntoViewIfNeeded();
        await page.waitForFunction(() => [...document.querySelectorAll('.cart-thumbnail')].every(image => image.complete && image.naturalWidth > 0), null, { timeout: 15000 });
        const first = page.getByRole('article', { name: 'Majica Osnova Maslinasta / M', exact: true });
        const second = page.getByRole('article', { name: 'Majica Osnova Pijesak / L', exact: true });
        const firstQuantity = first.getByRole('spinbutton', { name: 'Količina za Majica Osnova — Maslinasta / M', exact: true });
        const secondQuantity = second.getByRole('spinbutton', { name: 'Količina za Majica Osnova — Pijesak / L', exact: true });
        assert.equal(await firstQuantity.inputValue(), '1');
        assert.equal(await secondQuantity.inputValue(), '2');
        assert.equal(await page.locator('.order-summary dd').first().innerText(), '75,00 €');
        await capture('two-variants');
        await firstQuantity.fill('3');
        await first.getByRole('button', { name: 'Ažuriraj količinu za Majica Osnova — Maslinasta / M', exact: true }).click();
        await first.locator(':scope > strong').filter({ hasText: '75,00' }).waitFor();
        assert.equal(await firstQuantity.inputValue(), '3');
        assert.equal(await secondQuantity.inputValue(), '2');
        assert.equal(await page.locator('.order-summary dd').first().innerText(), '125,00 €');
        await capture('updated-one');
        await second.getByRole('button', { name: 'Ukloni Majica Osnova — Pijesak / L iz košarice', exact: true }).click();
        await second.waitFor({ state: 'hidden' });
        assert.equal(await page.locator('.cart-line').count(), 1);
        assert.equal(await firstQuantity.inputValue(), '3');
        assert.equal(await page.locator('.order-summary dd').first().innerText(), '75,00 €');
        await capture('removed-one');
        await first.getByRole('button', { name: 'Ukloni Majica Osnova — Maslinasta / M iz košarice', exact: true }).click();
        await page.getByRole('heading', { name: 'Ovdje još nema favorita.', exact: true }).waitFor();
        assert.equal(await page.locator('.cart-line').count(), 0);
        await capture('empty');
        assert.deepEqual(errors, []);
        assert.deepEqual(external, []);
        reports.push({ mode, engine: engineName, result: 'passed', independentVariantControls: true, finalCartLines: 0, ordersPlaced: false, accessibilityChecks, javascriptErrors: errors.length });
        await context.close();
    }
    await writeFile(`${output}/cart-report.json`, JSON.stringify(reports, null, 2) + '\n');
    console.log(JSON.stringify(reports));
} finally { await browser.close(); }
