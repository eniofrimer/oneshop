import { engineName, launchBrowser } from './engine.mjs';
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
    for (const mode of ['enhanced', 'no-js']) {
        const context = await browser.newContext({ viewport: { width: 1400, height: 1000 }, locale: 'hr-HR', javaScriptEnabled: mode !== 'no-js', serviceWorkers: 'block' });
        const errors = [], external = [], orderRequests = [];
        await context.route('**/*', route => {
            const request = route.request(), url = new URL(request.url());
            if (url.origin !== base) { external.push(url.origin); return route.abort(); }
            if (request.method() === 'POST' && (url.pathname === '/checkout/narudzba' || url.pathname.startsWith('/checkout/test/'))) {
                orderRequests.push(url.pathname);
                return route.abort();
            }
            return route.continue();
        });
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
        let tabSteps = 0, reverseTabSteps = 0;
        const focused = locator => locator.evaluate(node => node === document.activeElement);
        const tabTo = async (locator, reverse = false) => {
            assert.equal(await locator.count(), 1, 'Keyboard target must be unambiguous.');
            for (let attempts = 0; attempts < 50; attempts++) {
                if (await focused(locator)) return;
                await page.keyboard.press(reverse ? 'Shift+Tab' : 'Tab');
                tabSteps++;
                if (reverse) reverseTabSteps++;
            }
            assert.fail(`Target could not be reached with Tab: ${await locator.evaluate(node => node.outerHTML)}`);
        };
        const activate = async (locator, reverse = false) => { await tabTo(locator, reverse); await page.keyboard.press('Enter'); };
        const type = async (name, value) => {
            await tabTo(page.locator(`[name="${name}"]`));
            await page.keyboard.press('ControlOrMeta+A');
            await page.keyboard.type(value);
        };
        const capture = async stage => {
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, `${mode}/${stage}: overflow`);
            await page.screenshot({ path: `${output}/keyboard-${mode}-${stage}.png`, fullPage: true });
        };
        await page.goto(base, { waitUntil: 'networkidle' });
        await page.getByText('Razvojna instanca', { exact: false }).waitFor();
        await activate(page.getByRole('link', { name: 'Preskoči na sadržaj', exact: true }));
        assert.equal(await focused(page.locator('main')), true);
        await activate(page.locator('.product-meta h3 a').filter({ hasText: 'Majica Osnova' }));
        await page.waitForURL('**/proizvodi/*');
        if (mode === 'enhanced') {
            const green = page.getByRole('radio', { name: 'Maslinasta', exact: true });
            const sand = page.getByRole('radio', { name: 'Pijesak', exact: true });
            await tabTo(green);
            await page.keyboard.press('Space');
            assert.equal(await green.isChecked(), true);
            await page.keyboard.press('ArrowRight');
            assert.equal(await sand.isChecked(), true);
            await page.keyboard.press('ArrowLeft');
            assert.equal(await green.isChecked(), true);
        }
        const variantSelect = mode === 'enhanced' ? page.getByRole('combobox', { name: 'Veličina', exact: true }) : page.locator('[data-variant-fallback] select');
        await tabTo(variantSelect);
        await page.keyboard.press('Home');
        // PostgreSQL can return native SKU choices in a different order after stock updates.
        // Choose the visible combination with the keyboard instead of assuming the first row.
        const expectedOption = mode === 'enhanced' ? /^M$/ : /^Maslinasta \/ M ·/;
        for (let attempts = 0; attempts < await variantSelect.locator('option').count(); attempts++) {
            if (expectedOption.test(await variantSelect.locator('option:checked').innerText())) break;
            await page.keyboard.press('ArrowDown');
        }
        assert.match(await variantSelect.locator('option:checked').innerText(), expectedOption);
        await page.keyboard.press('Enter');
        await tabTo(page.getByRole('spinbutton', { name: 'Količina', exact: true }));
        await page.keyboard.press('ControlOrMeta+A');
        await page.keyboard.type('1');
        await activate(page.getByRole('button', { name: /Dodaj u košaricu/ }));
        await page.waitForURL('**/kosarica');
        assert.equal(await page.locator('.cart-option').innerText(), 'Maslinasta / M');
        await capture('cart');
        await activate(page.getByRole('link', { name: /Na završetak kupnje/ }));
        await page.waitForURL('**/checkout');
        const values = { first_name: 'Tipkovnica', last_name: 'Probni Kupac', contact_email: 'neispravna-adresa', contact_phone: '+385910000000',
            line_one: 'Izmišljena ulica 12', line_two: 'Kat 3, stan 4', postcode: '10000', city: 'Zagreb' };
        for (const [name, value] of Object.entries(values)) await type(name, value);
        const country = page.getByRole('combobox', { name: 'Država', exact: true });
        await tabTo(country);
        assert.equal(await country.locator('option:checked').innerText(), 'Hrvatska');
        const submit = page.getByRole('button', { name: /Pregledaj narudžbu/ });
        await activate(submit);
        await page.waitForFunction(() => document.activeElement?.name === 'contact_email');
        assert.equal(new URL(page.url()).pathname, '/checkout');
        assert.equal(await page.locator('[name="contact_email"]').evaluate(input => input.validity.typeMismatch), true);
        values.contact_email = `keyboard-${mode}@example.test`;
        await type('contact_email', values.contact_email);
        await activate(submit);
        await page.waitForURL('**/checkout/pregled');
        assert.match(await page.locator('.summary-total').innerText(), /30,00/);
        const address = page.locator('address');
        for (const value of Object.values(values)) assert.ok((await address.innerText()).includes(value));
        await capture('review');
        await activate(page.getByRole('link', { name: /Promijeni adresu/ }));
        await page.waitForURL('**/checkout');
        for (const [name, value] of Object.entries(values)) assert.equal(await page.locator(`[name="${name}"]`).inputValue(), value);
        await capture('restored-address');
        values.line_two = 'Kat 2, stan 8';
        await type('line_two', values.line_two);
        await activate(submit);
        await page.waitForURL('**/checkout/pregled');
        assert.ok((await address.innerText()).includes(values.line_two));
        assert.ok(!(await address.innerText()).includes('Kat 3, stan 4'));
        const shipping = page.locator('[name="shipping"]');
        assert.equal(await shipping.count(), 1);
        await tabTo(shipping);
        await page.keyboard.press('Space');
        assert.equal(await shipping.isChecked(), true);
        await activate(page.getByRole('button', { name: 'Primijeni odabranu dostavu', exact: true }));
        await page.waitForURL('**/checkout/pregled');
        assert.match(await page.locator('.summary-total').innerText(), /30,00/);
        const acknowledge = page.getByRole('checkbox', { name: /Razumijem da je ovo testna narudžba/ });
        await tabTo(acknowledge);
        await page.keyboard.press('Space');
        assert.equal(await acknowledge.isChecked(), true);
        await page.keyboard.press('Space');
        assert.equal(await acknowledge.isChecked(), false);
        // Stop before placing an order; the purchase suite covers settlement separately.
        await activate(page.getByRole('link', { name: 'Uredi košaricu', exact: true }), true);
        await page.waitForURL('**/kosarica');
        await activate(page.getByRole('button', { name: /^Ukloni Majica Osnova/ }));
        await page.getByRole('heading', { name: 'Ovdje još nema favorita.', exact: true }).waitFor();
        await capture('empty');
        assert.deepEqual(errors, []);
        assert.deepEqual(external, []);
        assert.deepEqual(orderRequests, []);
        reports.push({ mode, engine: engineName, result: 'passed', keyboardOnly: true, nativeEmailValidation: true, restoredAddress: true,
            updatedAddress: true, shippingControl: true, acknowledgementControl: true, tabSteps, reverseTabSteps, ordersPlaced: false, javascriptErrors: errors.length });
        await context.close();
    }
    await writeFile(`${output}/keyboard-report.json`, JSON.stringify(reports, null, 2) + '\n');
    console.log(JSON.stringify(reports));
} finally { await browser.close(); }
