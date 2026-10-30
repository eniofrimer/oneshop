import assert from 'node:assert/strict';
import AxeBuilder from '@axe-core/playwright';
import { randomBytes } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { launchBrowser } from './engine.mjs';

process.umask(0o077);
assert.equal(process.env.ONESHOP_ADMIN_BROWSER_ALLOW_WRITES, 'true', 'Acknowledge writes to a disposable demo installation.');
assert.ok(process.env.ONESHOP_BROWSER_URL && process.env.ONESHOP_ADMIN_BROWSER_EMAIL && process.env.ONESHOP_ADMIN_BROWSER_PASSWORD, 'Provide a demo URL and an explicitly provisioned test administrator.');
const target = new URL(process.env.ONESHOP_BROWSER_URL);
assert.ok(['http:', 'https:'].includes(target.protocol) && ['/', ''].includes(target.pathname) && !target.username && !target.password && !target.search && !target.hash);
const base = target.origin;
const output = resolve(process.env.ONESHOP_BROWSER_OUTPUT ?? 'storage/app/private/admin-catalog-browser-qa');
await mkdir(output, { recursive: true, mode: 0o700 });
const suffix = randomBytes(5).toString('hex').toUpperCase();
const group = `QA Grupa ${suffix}`, rootName = `QA Korijen ${suffix}`, childName = `QA Podkategorija ${suffix}`;
const productName = `QA Razvrstan proizvod ${suffix}`, shippingName = `QA Dostava ${suffix}`;
const browser = await launchBrowser();
const errors = [], external = [], stages = [];
let page, authenticated = false;
try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, locale: 'hr-HR', serviceWorkers: 'block' });
    await context.route('**/*', route => {
        if (new URL(route.request().url()).origin === base) return route.continue();
        external.push(new URL(route.request().url()).origin);
        return route.abort();
    });
    context.on('page', tab => {
        tab.on('pageerror', error => errors.push(error.message));
        tab.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    });
    page = await context.newPage();
    const capture = async stage => {
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, `${stage}: horizontal overflow`);
        assert.deepEqual((await page.locator('body').ariaSnapshot()).match(/filament[\w-]*::[\w.\/-]+/g) ?? [], [], `${stage}: unresolved translations`);
        await page.screenshot({ path: `${output}/${stage}.png`, fullPage: true });
        stages.push(stage);
    };
    await page.goto(base, { waitUntil: 'networkidle' });
    await page.getByText('Razvojna instanca', { exact: false }).waitFor();
    await page.goto(base + '/admin/login', { waitUntil: 'networkidle' });
    await page.locator('input[type=email]').fill(process.env.ONESHOP_ADMIN_BROWSER_EMAIL);
    await page.locator('input[type=password]').fill(process.env.ONESHOP_ADMIN_BROWSER_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.waitForURL(base + '/admin');
    authenticated = true;

    await page.goto(base + '/admin/categories/create', { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Dodaj opciju', exact: true }).click();
    const groupDialog = page.getByRole('dialog');
    await groupDialog.getByRole('textbox', { name: 'Naziv grupe*', exact: true }).fill(group);
    await groupDialog.getByRole('textbox', { name: 'Oznaka grupe*', exact: true }).fill('qa-grupa-' + suffix.toLowerCase());
    await groupDialog.getByRole('button', { name: 'Napravi', exact: true }).click();
    await groupDialog.waitFor({ state: 'hidden' });
    await page.getByRole('combobox', { name: 'Grupa kategorija*', exact: true }).selectOption({ label: group });
    await page.getByRole('textbox', { name: 'Naziv*', exact: true }).fill(rootName);
    await page.getByRole('textbox', { name: 'Opis', exact: true }).fill('Sintetički korijen ručne grupe kategorija.');
    await page.getByRole('button', { name: 'Napravi', exact: true }).first().click();
    await page.waitForURL(/\/admin\/categories\/\d+\/edit$/);
    const rootUrl = page.url();
    await capture('root-category');

    await page.goto(base + '/admin/categories/create', { waitUntil: 'networkidle' });
    await page.getByRole('combobox', { name: 'Grupa kategorija*', exact: true }).selectOption({ label: group });
    await page.getByRole('button', { name: 'Nadređena kategorija', exact: true }).click();
    await page.getByRole('option', { name: rootName, exact: true }).click();
    await page.getByRole('textbox', { name: 'Naziv*', exact: true }).fill(childName);
    await page.getByRole('button', { name: 'Napravi', exact: true }).first().click();
    await page.waitForURL(/\/admin\/categories\/\d+\/edit$/);
    const childUrl = page.url();
    await capture('child-category');
    await page.goto(rootUrl, { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Nadređena kategorija', exact: true }).click();
    await page.getByRole('option', { name: childName, exact: true }).click();
    await page.getByRole('button', { name: 'Spremi promjene', exact: true }).click();
    await page.getByText('Odaberite kategoriju iz iste grupe koja nije ova kategorija ili njezin potomak.', { exact: true }).waitFor();
    await capture('category-cycle-refused');
    await page.goto(childUrl, { waitUntil: 'networkidle' });
    assert.match(await page.getByRole('button', { name: 'Nadređena kategorija', exact: true }).innerText(), new RegExp(rootName));

    await page.goto(base + '/admin/products/create', { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Vrsta kataloga*', exact: true }).click();
    await page.getByRole('option', { name: 'Demo kolekcija', exact: true }).click();
    await page.getByRole('button', { name: 'Kategorije', exact: true }).click();
    await page.getByRole('option', { name: group + ' / ' + childName, exact: true }).click();
    await page.keyboard.press('Escape');
    await page.getByRole('textbox', { name: 'Naziv*', exact: true }).fill(productName);
    await page.getByRole('button', { name: 'Napravi', exact: true }).first().click();
    await page.waitForURL(/\/admin\/products\/\d+\/edit$/);
    await page.reload({ waitUntil: 'networkidle' });
    assert.match(await page.getByRole('button', { name: 'Kategorije', exact: true }).innerText(), new RegExp(childName));
    await page.goto(base + '/admin/categories', { waitUntil: 'networkidle' });
    const childRow = page.getByRole('row').filter({ has: page.getByRole('cell', { name: childName, exact: true }) });
    await childRow.getByRole('cell', { name: rootName, exact: true }).waitFor();
    await childRow.getByRole('cell', { name: '1', exact: true }).waitFor();
    await capture('assigned-category');

    await page.goto(base + '/admin/shipping-methods/create', { waitUntil: 'networkidle' });
    await page.getByRole('textbox', { name: 'Naziv dostave*', exact: true }).fill(shippingName);
    await page.getByRole('textbox', { name: 'Opis za kupca', exact: true }).fill('Isključena sintetička tarifa za provjeru administracije.');
    await page.getByRole('button', { name: 'Države dostave*', exact: true }).click();
    await page.getByRole('combobox', { name: /^Države dostave/ }).fill('Hrvatska');
    await page.getByRole('option', { name: 'Hrvatska', exact: true }).click();
    await page.keyboard.press('Escape');
    await page.getByRole('combobox', { name: 'Porezna klasa*', exact: true }).selectOption({ label: 'DEMO — testni porez 25%' });
    await page.getByRole('textbox', { name: 'Cijena dostave (EUR)*', exact: true }).fill('6,40');
    assert.equal(await page.getByRole('switch', { name: 'Dostupno na checkoutu', exact: true }).getAttribute('aria-checked'), 'false');
    await page.getByRole('button', { name: 'Napravi', exact: true }).first().click();
    await page.waitForURL(/\/admin\/shipping-methods\/\d+\/edit$/);
    const shippingUrl = page.url();
    await capture('disabled-shipping-method');
    const newer = await context.newPage();
    await newer.goto(shippingUrl, { waitUntil: 'networkidle' });
    await newer.getByRole('textbox', { name: 'Cijena dostave (EUR)*', exact: true }).fill('8,00');
    await newer.getByRole('textbox', { name: 'Opis za kupca', exact: true }).fill('Noviji spremljeni opis tarife.');
    await newer.getByRole('button', { name: 'Spremi promjene', exact: true }).click();
    await newer.getByText('Spremljeno', { exact: true }).waitFor();
    await newer.close();
    await page.getByRole('textbox', { name: 'Cijena dostave (EUR)*', exact: true }).fill('1,00');
    await page.getByRole('textbox', { name: 'Naziv dostave*', exact: true }).fill('QA Zastarjeli unos');
    await page.getByRole('button', { name: 'Spremi promjene', exact: true }).click();
    await page.getByText('Dostava je u međuvremenu promijenjena. Ponovno otvorite zapis.', { exact: true }).waitFor();
    await capture('stale-shipping-refused');
    await page.reload({ waitUntil: 'networkidle' });
    assert.equal(await page.getByRole('textbox', { name: 'Naziv dostave*', exact: true }).inputValue(), shippingName);
    assert.match(await page.getByRole('textbox', { name: 'Cijena dostave (EUR)*', exact: true }).inputValue(), /^8[.,]00$/);
    assert.equal(await page.getByRole('textbox', { name: 'Opis za kupca', exact: true }).inputValue(), 'Noviji spremljeni opis tarife.');
    assert.equal(await page.getByRole('switch', { name: 'Dostupno na checkoutu', exact: true }).getAttribute('aria-checked'), 'false');
    assert.match(await page.getByRole('button', { name: 'Države dostave*', exact: true }).innerText(), /Hrvatska/);
    await capture('preserved-shipping-revision');

    // Drafts without photos must not create empty clickable image cells.
    await page.goto(base + '/admin/products', { waitUntil: 'networkidle' });
    const unpicturedRow = page.getByRole('row').filter({ has: page.getByRole('cell', { name: productName, exact: true }) });
    await unpicturedRow.getByRole('link', { name: productName + ' — fotografija nije dodana', exact: true }).waitFor();
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
        const summary = values => values.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }));
        await writeFile(`${output}/products-${width}-accessibility.json`, JSON.stringify({ violations: summary(result.violations), incomplete: summary(result.incomplete) }));
        assert.deepEqual(summary(result.violations), [], `products-${width}: accessibility`);
        await capture('product-list-' + width);
    }
    assert.deepEqual(errors, []);
    assert.deepEqual(external, []);
    const report = { result: 'passed', stages, categoryCycleRefused: true, productCategoryPreserved: true,
        staleShippingRefused: true, preservedShippingPrice: '8,00 EUR', shippingMethodEnabled: false,
        namedMissingPhotoLinks: true, productListAccessibilityChecks: 2, javascriptErrors: errors.length };
    await writeFile(`${output}/report.json`, JSON.stringify(report, null, 2) + '\n', { mode: 0o600 });
    console.log(JSON.stringify(report));
} catch (error) {
    if (page && authenticated) await page.screenshot({ path: `${output}/failure.png`, fullPage: true }).catch(() => {});
    throw error;
} finally {
    await browser.close();
}
