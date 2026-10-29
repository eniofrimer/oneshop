import { chromium } from 'playwright';
import AxeBuilder from '@axe-core/playwright';
import { mkdir, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
process.umask(0o077);
import assert from 'node:assert/strict';
if (!process.env.ONESHOP_BROWSER_URL) throw new Error('Set ONESHOP_BROWSER_URL to the root URL of an initialized OneShop demo instance.');
const target = new URL(process.env.ONESHOP_BROWSER_URL);
assert.ok(['http:', 'https:'].includes(target.protocol) && ['/', ''].includes(target.pathname) && !target.username && !target.password && !target.search && !target.hash);
const base = target.origin;
const output = resolve(process.env.ONESHOP_BROWSER_OUTPUT ?? 'storage/app/private/browser-qa');
await mkdir(output, { recursive: true, mode: 0o700 });
const browser = await chromium.launch({ executablePath: process.env.ONESHOP_BROWSER_EXECUTABLE || undefined, headless: true,
    args: ['--disable-background-networking', '--disable-component-update', '--no-first-run'] });
const reports = [];
try {
    for (const mode of ['desktop', 'mobile', 'no-js']) {
        const mobile = mode === 'mobile';
        const context = await browser.newContext({ viewport: mobile ? { width: 390, height: 844 } : { width: 1400, height: 1000 },
            locale: 'hr-HR', javaScriptEnabled: mode !== 'no-js', isMobile: mobile, hasTouch: mobile, serviceWorkers: 'block' });
        const externalRequests = [];
        await context.route('**/*', route => {
            if (new URL(route.request().url()).origin === base) return route.continue();
            externalRequests.push(new URL(route.request().url()).origin);
            return route.abort();
        });
        const page = await context.newPage();
        const errors = [];
        let accessibilityChecks = 0;
        const audit = async stage => {
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth), false, `${mode}/${stage}: horizontal overflow`);
            if (mode === 'no-js') return;
            const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
            const summarize = items => items.map(item => ({ id: item.id, impact: item.impact, targets: item.nodes.map(node => node.target) }));
            const report = { stage, violations: summarize(result.violations), incomplete: summarize(result.incomplete) };
            await writeFile(`${output}/${mode}-${stage}-accessibility.json`, JSON.stringify(report, null, 2) + '\n', { mode: 0o600 });
            assert.deepEqual(report.violations, [], `${mode}/${stage}: accessibility violations`);
            accessibilityChecks++;
        };
        page.on('pageerror', error => errors.push(error.message));
        page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
        await page.goto(base, { waitUntil: 'networkidle' });
        await page.getByText('Razvojna instanca', { exact: false }).waitFor();
        if (process.env.ONESHOP_BROWSER_THEME) {
            assert.equal(await page.locator('body').evaluate((node, theme) => node.classList.contains(`theme-${theme}`), process.env.ONESHOP_BROWSER_THEME), true);
        }
        await page.keyboard.press('Tab');
        assert.equal(await page.getByRole('link', { name: 'Preskoči na sadržaj' }).evaluate(node => node === document.activeElement), true);
        await page.keyboard.press('Enter');
        assert.equal(await page.locator('main').evaluate(node => node === document.activeElement), true);
        await audit('home');
        assert.equal(await page.locator('.product-card').count(), 3);
        await page.screenshot({ path: `${output}/${mode}-home.png`, fullPage: true });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth), false);
        if (mode !== 'no-js') {
            await page.evaluate(() => { window.__oneshopQaMarker = true; });
            await page.locator('[name="q"]').fill('majica');
            await page.waitForFunction(() => document.querySelectorAll('.product-card').length === 1);
            assert.equal(await page.evaluate(() => window.__oneshopQaMarker), true);
            await page.locator('[name="q"]').fill('zz-no-match');
            await page.getByRole('heading', { name: 'Još nema podudaranja.' }).waitFor();
            await page.locator('[name="q"]').fill('');
            await page.waitForFunction(() => document.querySelectorAll('.product-card').length === 3);
        }
        await page.locator('.product-meta h3 a').filter({ hasText: mode === 'no-js' ? 'Šalica Jutro' : 'Majica Osnova' }).click();
        await page.locator('.product-copy h1').waitFor();
        await audit('product');
        if (mode !== 'no-js') {
            await page.locator('[data-option-controls]').waitFor({ state: 'visible' });
            const choose = async (name, value) => {
                const dropdown = page.getByRole('combobox', { name, exact: true });
                if (await dropdown.count()) await dropdown.selectOption({ label: value });
                else await page.getByRole('group', { name, exact: true }).getByRole('radio', { name: value, exact: true }).check();
            };
            await choose('Boja', mobile ? 'Pijesak' : 'Maslinasta');
            await choose('Veličina', mobile ? 'L' : 'M');
            await page.waitForFunction(() => !document.querySelector('[data-add-button]').disabled);
            assert.match(await page.locator('[data-selected-price]').innerText(), /25,00/);
        } else {
            await page.locator('[data-variant-fallback]').waitFor({ state: 'visible' });
        }
        await page.screenshot({ path: `${output}/${mode}-product.png`, fullPage: true });
        await page.getByRole('button', { name: /Dodaj u košaricu/ }).click();
        await page.waitForURL('**/kosarica');
        if (mode !== 'no-js') assert.equal(await page.locator('.cart-option').innerText(), mobile ? 'Pijesak / L' : 'Maslinasta / M');
        await audit('cart');
        if (mode === 'desktop') {
            await page.getByRole('spinbutton').fill('2');
            await page.getByRole('button', { name: 'Ažuriraj', exact: true }).click();
            await page.waitForURL('**/kosarica');
            assert.equal(await page.getByRole('spinbutton').inputValue(), '2');
        }
        await page.getByRole('link', { name: /Na završetak kupnje/ }).click();
        await page.waitForURL('**/checkout');
        await audit('address');
        for (const [name, value] of Object.entries({ first_name: 'Browser', last_name: `Demo ${mode}`, contact_email: `${mode}@example.test`,
            contact_phone: '+385910000000', line_one: 'Testna ulica 1', postcode: '10000', city: 'Zagreb' })) {
            await page.locator(`[name="${name}"]`).fill(value);
        }
        if (mode === 'desktop') {
            await page.locator('[name="contact_email"]').fill('invalid-email');
            // Reach server validation instead of stopping at the native email check.
            await page.locator('.checkout-form').evaluate(form => { form.noValidate = true; });
            await page.getByRole('button', { name: /Pregledaj narudžbu/ }).click();
            await page.locator('#contact_email-error').waitFor();
            assert.match(await page.getByRole('alert').innerText(), /valjanu adresu e-pošte/);
            assert.equal(await page.locator('[name="contact_email"]').getAttribute('aria-invalid'), 'true');
            assert.equal(await page.locator('[name="first_name"]').inputValue(), 'Browser');
            await page.waitForFunction(() => document.activeElement.hasAttribute('data-validation-summary'));
            await audit('address-error');
            await page.screenshot({ path: `${output}/${mode}-address-error.png`, fullPage: true });
            await page.locator('[name="contact_email"]').fill(`${mode}@example.test`);
        }
        await page.getByRole('button', { name: /Pregledaj narudžbu/ }).click();
        await page.waitForURL('**/checkout/pregled');
        if (mode !== 'no-js') assert.equal(await page.locator('.review-option').innerText(), mobile ? 'Pijesak / L' : 'Maslinasta / M');
        await audit('review');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth), false);
        const expected = mode === 'desktop' ? '55,00' : mode === 'mobile' ? '30,00' : '20,00';
        assert.match(await page.locator('.summary-total').innerText(), new RegExp(expected));
        await page.screenshot({ path: `${output}/${mode}-checkout.png`, fullPage: true });
        await page.locator('[name="demo_acknowledged"]').check();
        await page.getByRole('button', { name: /Predaj testnu narudžbu/ }).click();
        await page.waitForURL('**/checkout/test/*');
        await audit('payment');
        if (mode === 'desktop') {
            await page.getByRole('button', { name: 'Simuliraj odbijanje', exact: true }).click();
            await page.getByRole('heading', { name: 'Plaćanje je testno odbijeno.' }).waitFor();
        }
        await page.getByRole('button', { name: /Simuliraj uspješno plaćanje/ }).click();
        await page.getByRole('heading', { name: 'Testna kupnja je uspjela.' }).waitFor();
        await audit('success');
        await page.screenshot({ path: `${output}/${mode}-success.png`, fullPage: true });
        assert.deepEqual(errors, []);
        assert.deepEqual(externalRequests, []);
        reports.push({ mode, result: 'paid simulation', total: expected + ' EUR', overflow: false, javascriptErrors: errors.length, accessibilityChecks });
        await context.close();
    }
    await writeFile(`${output}/report.json`, JSON.stringify(reports, null, 2) + '\n', { mode: 0o600 });
    console.log(JSON.stringify(reports));
} finally { await browser.close(); }
