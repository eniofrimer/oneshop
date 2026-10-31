import { chromium } from 'playwright';
import AxeBuilder from '@axe-core/playwright';
import assert from 'node:assert/strict';
import { chooseUploadFile } from './upload.mjs';
import { randomBytes } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';

process.umask(0o077);
assert.equal(process.env.ONESHOP_ADMIN_BROWSER_ALLOW_WRITES, 'true', 'Acknowledge writes to a disposable demo installation.');
assert.ok(process.env.ONESHOP_BROWSER_URL && process.env.ONESHOP_ADMIN_BROWSER_EMAIL && process.env.ONESHOP_ADMIN_BROWSER_PASSWORD, 'Provide a demo URL and explicitly provisioned test administrator.');
const target = new URL(process.env.ONESHOP_BROWSER_URL);
assert.ok(['http:', 'https:'].includes(target.protocol) && ['/', ''].includes(target.pathname) && !target.username && !target.password && !target.search && !target.hash);
const base = target.origin;
const output = resolve(process.env.ONESHOP_BROWSER_OUTPUT ?? 'storage/app/private/admin-browser-qa');
await mkdir(output, { recursive: true, mode: 0o700 });
const suffix = randomBytes(5).toString('hex').toUpperCase();
const name = `Browser QA Bilježnica ${suffix}`;
const sku = `QA-NOTEBOOK-${suffix}`;
const photoName = `QA fotografija ${suffix}`;
const optionName = `QA Površina ${suffix}`;
const alt = 'Zelena bilježnica na svijetloj podlozi';
const detailName = photoName + ' — detalj';
const detailAlt = 'Otvorena bilježnica na toploj podlozi';
const errors = [], external = [], stages = [];
const browser = await chromium.launch({ executablePath: process.env.ONESHOP_BROWSER_EXECUTABLE || undefined, headless: true });
let page, authenticated = false;
const context = async (options = {}) => {
    const instance = await browser.newContext({ viewport: { width: 1440, height: 1000 }, locale: 'hr-HR', serviceWorkers: 'block', ...options });
    await instance.route('**/*', route => {
        if (new URL(route.request().url()).origin === base) return route.continue();
        external.push(new URL(route.request().url()).origin);
        return route.abort();
    });
    instance.on('page', tab => {
        tab.on('pageerror', error => errors.push(error.message));
        tab.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    });
    return instance;
};
const capture = async (stage, targetPage = page) => {
    assert.equal(await targetPage.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, `${stage}: horizontal overflow`);
    const snapshot = await targetPage.locator('body').ariaSnapshot();
    assert.deepEqual(snapshot.match(/filament[\w-]*::[\w.\/-]+/g) ?? [], [], `${stage}: unresolved administrator translations`);
    await targetPage.screenshot({ path: `${output}/${stage}.png`, fullPage: true });
    stages.push(stage);
};
const skuPanel = async (targetPage = page) => {
    await targetPage.getByRole('tab', { name: 'SKU-ovi, cijene i zaliha', exact: true }).click();
    const panel = targetPage.getByRole('tabpanel', { name: 'SKU-ovi, cijene i zaliha', exact: true });
    await panel.scrollIntoViewIfNeeded();
    await panel.getByRole('button', { name: 'Dodaj SKU', exact: true }).waitFor();
    return panel;
};
try {
    const admin = await context();
    page = await admin.newPage();
    await page.goto(base, { waitUntil: 'networkidle' });
    await page.getByText('Razvojna instanca', { exact: false }).waitFor();
    await page.goto(base + '/admin/login', { waitUntil: 'networkidle' });
    await page.locator('input[type=email]').fill(process.env.ONESHOP_ADMIN_BROWSER_EMAIL);
    await page.locator('input[type=password]').fill(process.env.ONESHOP_ADMIN_BROWSER_PASSWORD);
    await page.locator('button[type=submit]').click();
    await page.waitForURL(base + '/admin');
    authenticated = true;
    await capture('dashboard');

    await page.goto(base + '/admin/options/create', { waitUntil: 'networkidle' });
    await page.getByRole('textbox', { name: 'Naziv*', exact: true }).fill(optionName);
    await page.getByRole('combobox', { name: 'Preferirani odabir u trgovini*', exact: true }).selectOption('radio');
    await page.getByRole('button', { name: 'Napravi', exact: true }).click();
    await page.waitForURL(/\/admin\/options\/\d+\/edit$/);
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    for (const [position, value] of ['Mat', 'Sjaj'].entries()) {
        await page.getByRole('button', { name: 'Dodaj vrijednost', exact: true }).click();
        const valueDialog = page.getByRole('dialog');
        await valueDialog.getByRole('textbox', { name: 'Naziv*', exact: true }).fill(value);
        await valueDialog.getByRole('spinbutton', { name: 'Redoslijed*', exact: true }).fill(String(position));
        await valueDialog.getByRole('button', { name: 'Napravi', exact: true }).click();
        await page.getByRole('cell', { name: value, exact: true }).waitFor();
    }
    await capture('reusable-option');

    await page.goto(base + '/admin/products/create', { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Vrsta kataloga*', exact: true }).click();
    await page.getByRole('option', { name: 'Demo kolekcija', exact: true }).click();
    await page.getByRole('button', { name: 'Opcije varijanti', exact: true }).click();
    await page.getByRole('option', { name: optionName, exact: true }).click();
    await page.keyboard.press('Escape');
    await page.getByRole('textbox', { name: 'Naziv*', exact: true }).fill(name);
    await page.getByRole('textbox', { name: 'Opis', exact: true }).fill('Sintetički proizvod stvoren kroz administratorski preglednik.');
    await page.getByRole('button', { name: 'Napravi', exact: true }).click();
    await page.waitForURL(/\/admin\/products\/\d+\/edit$/);
    const editUrl = page.url();
    const productId = editUrl.match(/products\/(\d+)\/edit/)[1];
    await capture('draft-product');
    await (await skuPanel()).getByRole('button', { name: 'Dodaj SKU', exact: true }).click();
    let dialog = page.getByRole('dialog');
    await dialog.getByRole('combobox', { name: optionName + '*', exact: true }).selectOption({ label: 'Mat' });
    await dialog.getByRole('textbox', { name: 'SKU*', exact: true }).fill(sku.toLowerCase());
    await dialog.getByRole('textbox', { name: 'Cijena bez poreza*', exact: true }).fill('8,00');
    await dialog.getByRole('combobox', { name: 'Porezna kategorija*', exact: true }).selectOption({ label: 'DEMO — testni porez 25%' });
    await dialog.getByRole('spinbutton', { name: 'Zaliha*', exact: true }).fill('10');
    await dialog.getByRole('button', { name: 'Napravi', exact: true }).click();
    await page.getByRole('cell', { name: sku, exact: true }).waitFor();
    await page.getByRole('button', { name: 'Dodaj SKU', exact: true }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByRole('combobox', { name: optionName + '*', exact: true }).selectOption({ label: 'Sjaj' });
    await dialog.getByRole('textbox', { name: 'SKU*', exact: true }).fill(sku + '-GLOSS');
    await dialog.getByRole('textbox', { name: 'Cijena bez poreza*', exact: true }).fill('12,00');
    await dialog.getByRole('combobox', { name: 'Porezna kategorija*', exact: true }).selectOption({ label: 'DEMO — testni porez 25%' });
    await dialog.getByRole('spinbutton', { name: 'Zaliha*', exact: true }).fill('5');
    await dialog.getByRole('button', { name: 'Napravi', exact: true }).click();
    await page.getByRole('cell', { name: sku + '-GLOSS', exact: true }).waitFor();
    await page.getByRole('row').filter({ has: page.getByRole('cell', { name: sku, exact: true }) }).getByRole('cell', { name: '8,00 EUR', exact: true }).waitFor();
    await page.getByRole('row').filter({ has: page.getByRole('cell', { name: sku + '-GLOSS', exact: true }) }).getByRole('cell', { name: '12,00 EUR', exact: true }).waitFor();
    await capture('saved-sku');

    // Readiness uses saved data; this explicit action retains the seeded default audience.
    await page.getByRole('button', { name: 'Objava u katalogu', exact: true }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByText('Podaci su spremni za objavu u katalogu.', { exact: true }).waitFor();
    await dialog.getByRole('button', { name: 'Kanali*', exact: true }).getByText('Web trgovina', { exact: true }).waitFor();
    await dialog.getByRole('button', { name: 'Grupe kupaca*', exact: true }).getByText('Maloprodaja', { exact: true }).waitFor();
    await dialog.getByRole('button', { name: 'Pošalji', exact: true }).click();
    await page.getByText('Proizvod je objavljen u katalogu', { exact: true }).waitFor();

    await page.getByRole('tab', { name: 'Fotografije', exact: true }).click();
    await page.getByRole('tabpanel', { name: 'Fotografije', exact: true }).scrollIntoViewIfNeeded();
    await page.getByRole('button', { name: 'Dodaj fotografiju', exact: true }).click();
    dialog = page.getByRole('dialog');
    await chooseUploadFile(page, dialog, resolve('tests/Browser/fixtures/product.png'));
    await dialog.getByRole('textbox', { name: 'Naziv fotografije*', exact: true }).fill(photoName);
    await dialog.getByRole('textbox', { name: 'Alternativni tekst', exact: true }).fill(alt);
    await dialog.getByText('Prijenos završen', { exact: true }).waitFor();
    await dialog.getByRole('button', { name: 'Pošalji', exact: true }).click();
    await page.getByRole('cell', { name: photoName, exact: true }).waitFor();
    await capture('saved-photo');
    await page.getByRole('button', { name: 'Dodaj fotografiju', exact: true }).click();
    dialog = page.getByRole('dialog');
    await chooseUploadFile(page, dialog, resolve('tests/Browser/fixtures/product-detail.png'));
    await dialog.getByRole('textbox', { name: 'Naziv fotografije*', exact: true }).fill(detailName);
    await dialog.getByRole('textbox', { name: 'Alternativni tekst', exact: true }).fill(detailAlt);
    await dialog.getByText('Prijenos završen', { exact: true }).waitFor();
    await dialog.getByRole('button', { name: 'Pošalji', exact: true }).click();
    await page.getByRole('cell', { name: detailName, exact: true }).waitFor();
    await page.getByRole('button', { name: 'Mijenjanje redoslijeda zapisa', exact: true }).click();
    const firstPhoto = page.getByRole('row').filter({ has: page.getByRole('cell', { name: photoName, exact: true }) });
    const secondPhoto = page.getByRole('row').filter({ has: page.getByRole('cell', { name: detailName, exact: true }) });
    await secondPhoto.locator('.fi-ta-reorder-handle').dragTo(firstPhoto.locator('.fi-ta-reorder-handle'));
    await page.getByRole('tabpanel', { name: 'Fotografije', exact: true }).locator('tbody > tr').first().getByRole('cell', { name: detailName, exact: true }).waitFor();
    await page.getByRole('button', { name: 'Završi mijenjanje redoslijeda zapisa', exact: true }).click();
    await page.reload({ waitUntil: 'networkidle' });
    await page.getByRole('tab', { name: 'Fotografije', exact: true }).click();
    await page.getByRole('tabpanel', { name: 'Fotografije', exact: true }).scrollIntoViewIfNeeded();
    await page.getByRole('cell', { name: detailName, exact: true }).waitFor();
    await page.getByRole('row').filter({ has: page.getByRole('cell', { name: detailName, exact: true }) }).getByRole('cell', { name: 'Da', exact: true }).waitFor();
    await capture('reordered-gallery');

    const photoPanel = await skuPanel();
    for (const [photoSku, title] of [[sku, photoName], [sku + '-GLOSS', detailName]]) {
        const row = photoPanel.getByRole('row').filter({ has: page.getByRole('cell', { name: photoSku, exact: true }) });
        await row.getByRole('button', { name: 'Fotografija SKU-a', exact: true }).click();
        const photoDialog = page.getByRole('dialog');
        await photoDialog.getByRole('combobox', { name: 'Fotografija varijante', exact: true }).selectOption({ label: title });
        await photoDialog.getByRole('button', { name: 'Pošalji', exact: true }).click();
        await photoDialog.getByRole('button', { name: 'Pošalji', exact: true }).waitFor({ state: 'hidden' });
        await page.getByText('Fotografija SKU-a je spremljena.', { exact: true }).first().waitFor();
        const skuImage = row.getByRole('img', { name: 'Fotografija SKU-a ' + photoSku, exact: true });
        await skuImage.evaluate(img => img.decode());
        assert.ok(!(await skuImage.getAttribute('src')).endsWith('/images/product-placeholder.svg'));
    }
    // Dismiss saved notices before measuring the editor, including its named image links.
    await page.locator('.fi-no-notification-close-btn').evaluateAll(buttons => buttons.forEach(button => button.click()));
    await page.locator('.fi-no-notification:visible').first().waitFor({ state: 'hidden' });
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
        const summarize = values => values.map(value => ({ id: value.id, targets: value.nodes.map(node => node.target) }));
        const proof = { violations: summarize(result.violations), incomplete: summarize(result.incomplete) };
        await writeFile(`${output}/variant-photos-${width}-accessibility.json`, JSON.stringify(proof));
        assert.deepEqual(proof.violations, [], 'Variant photo editor accessibility');
        await capture('variant-photos-' + width);
    }
    await page.setViewportSize({ width: 1440, height: 1000 });

    // Keep the original stock version open while a separate guest completes a purchase.
    const panel = await skuPanel();
    await panel.getByRole('row').filter({ has: page.getByRole('cell', { name: sku, exact: true }) }).getByRole('button', { name: 'Uredi', exact: true }).click();
    dialog = page.getByRole('dialog');
    assert.equal(await dialog.getByRole('spinbutton', { name: 'Zaliha*', exact: true }).inputValue(), '10');
    const guest = await context();
    const shopper = await guest.newPage();
    await shopper.goto(base + '/?q=' + sku, { waitUntil: 'networkidle' });
    const cardImage = shopper.locator('.product-card').getByRole('img', { name: detailAlt, exact: true });
    await cardImage.waitFor();
    // Conversions are asynchronous: refresh until the real background worker has finished.
    const conversionDeadline = Date.now() + 30000;
    let cardSources = await cardImage.getAttribute('srcset');
    while (!['500w', '800w'].every(width => (cardSources ?? '').includes(width))) {
        assert.ok(Date.now() < conversionDeadline, 'Background image conversions did not finish.');
        await new Promise(resolve => setTimeout(resolve, 1000));
        await shopper.reload({ waitUntil: 'networkidle' });
        cardSources = await cardImage.getAttribute('srcset');
    }
    assert.match(await cardImage.getAttribute('srcset'), /500w/);
    await shopper.waitForFunction(() => [...document.querySelectorAll('.product-card img')].some(img => img.complete && img.naturalWidth > 0));
    await shopper.screenshot({ path: `${output}/published-card.png`, fullPage: true });
    await shopper.goto(base + '/proizvodi/' + productId, { waitUntil: 'networkidle' });
    await shopper.getByRole('heading', { name, exact: true }).waitFor();
    const image = shopper.locator('[data-gallery-main]');
    await image.waitFor();
    assert.equal(await image.getAttribute('alt'), detailAlt);
    const coverSource = await image.getAttribute('src');
    await shopper.getByRole('group', { name: optionName, exact: true }).getByRole('radio', { name: 'Sjaj', exact: true }).check();
    await shopper.waitForFunction(() => document.querySelector('[data-selected-price]').textContent.includes('15,00'));
    assert.equal(await image.getAttribute('alt'), detailAlt);
    await shopper.getByRole('group', { name: optionName, exact: true }).getByRole('radio', { name: 'Mat', exact: true }).check();
    await shopper.waitForFunction(() => document.querySelector('[data-selected-price]').textContent.includes('10,00'));
    assert.equal(await image.getAttribute('alt'), alt);
    assert.notEqual(await image.getAttribute('src'), coverSource);
    assert.match(await image.getAttribute('srcset'), /500w.*800w/);
    assert.match(await image.getAttribute('src'), /-medium\./);
    await shopper.waitForFunction(() => document.querySelector('[data-gallery-main]').complete && document.querySelector('[data-gallery-main]').naturalWidth > 0);
    assert.equal(await shopper.locator('[data-gallery-thumbnail]').count(), 2);
    await shopper.getByRole('link', { name: 'Prikaži fotografiju 1', exact: true }).focus();
    await shopper.keyboard.press('Enter');
    await shopper.waitForFunction(expected => document.querySelector('[data-gallery-main]').alt === expected, detailAlt);
    const originalSource = await image.getAttribute('src');
    await shopper.getByRole('link', { name: 'Prikaži fotografiju 2', exact: true }).focus();
    await shopper.keyboard.press('Enter');
    await shopper.waitForFunction(expected => document.querySelector('[data-gallery-main]').alt === expected, alt);
    assert.notEqual(await image.getAttribute('src'), originalSource);
    assert.match(await image.getAttribute('srcset'), /500w.*800w/);
    assert.match(await image.getAttribute('src'), /-medium\./);
    await image.evaluate(img => img.decode());
    assert.match(await image.evaluate(img => img.currentSrc), /-(medium|large)\./);
    assert.equal(await shopper.locator('[data-gallery-original]').getAttribute('href'), await shopper.getByRole('link', { name: 'Prikaži fotografiju 2', exact: true }).getAttribute('href'));
    assert.equal(await shopper.getByRole('link', { name: 'Prikaži fotografiju 2', exact: true }).getAttribute('aria-current'), 'true');
    const selectedThumbnailSource = await shopper.getByRole('link', { name: 'Prikaži fotografiju 2', exact: true }).locator('img').getAttribute('src');
    await shopper.getByRole('button', { name: 'Poništi odabir', exact: true }).click();
    assert.equal(await image.getAttribute('alt'), detailAlt);
    assert.equal(await shopper.getByRole('button', { name: /Dodaj u košaricu/ }).isDisabled(), true);
    await shopper.getByRole('group', { name: optionName, exact: true }).getByRole('radio', { name: 'Mat', exact: true }).check();
    assert.equal(await image.getAttribute('alt'), alt);
    assert.match(await shopper.locator('.detail-price').innerText(), /10,00/);
    await shopper.screenshot({ path: `${output}/published-product.png`, fullPage: true });
    const nativeGallery = await context({ javaScriptEnabled: false });
    const nativePhotoPage = await nativeGallery.newPage();
    await nativePhotoPage.goto(base + '/proizvodi/' + productId, { waitUntil: 'networkidle' });
    assert.match(await nativePhotoPage.locator('[data-gallery-main]').getAttribute('srcset'), /500w.*800w/);
    const nativeThumbnail = nativePhotoPage.getByRole('link', { name: 'Prikaži fotografiju 2', exact: true });
    const originalUrl = await nativeThumbnail.getAttribute('href');
    const originalResponse = nativePhotoPage.waitForResponse(originalUrl);
    await nativeThumbnail.click();
    assert.match((await originalResponse).headers()['content-type'], /^image\//);
    await nativePhotoPage.waitForURL(originalUrl);
    await nativePhotoPage.goto(base + '/proizvodi/' + productId, { waitUntil: 'networkidle' });
    await nativePhotoPage.locator('select[name=variant_id]').selectOption(await shopper.locator('select[name=variant_id]').inputValue());
    await nativePhotoPage.getByRole('button', { name: /Dodaj u košaricu/ }).click();
    await nativePhotoPage.waitForURL('**/kosarica');
    assert.equal(await nativePhotoPage.locator('.cart-thumbnail').getAttribute('src'), selectedThumbnailSource);
    await nativeGallery.close();
    await shopper.getByRole('button', { name: /Dodaj u košaricu/ }).click();
    await shopper.waitForURL('**/kosarica');
    const cartPhoto = shopper.locator('.cart-thumbnail');
    await cartPhoto.waitFor();
    assert.match(await cartPhoto.getAttribute('src'), /-small\./);
    assert.equal(await cartPhoto.getAttribute('src'), selectedThumbnailSource);
    await cartPhoto.evaluate(img => img.decode());
    await shopper.getByRole('link', { name: /Na završetak kupnje/ }).click();
    await shopper.waitForURL('**/checkout');
    for (const [field, value] of Object.entries({ first_name: 'Browser', last_name: 'Admin QA', contact_email: 'admin-journey@example.test',
        contact_phone: '+385910000000', line_one: 'Testna ulica 1', postcode: '10000', city: 'Zagreb' })) {
        await shopper.locator(`[name="${field}"]`).fill(value);
    }
    await shopper.getByRole('button', { name: /Pregledaj narudžbu/ }).click();
    await shopper.waitForURL('**/checkout/pregled');
    assert.match(await shopper.locator('.summary-total').innerText(), /15,00/);
    await shopper.locator('[name="demo_acknowledged"]').check();
    await shopper.getByRole('button', { name: /Predaj testnu narudžbu/ }).click();
    await shopper.waitForURL('**/checkout/test/*');
    const paymentCapability = new URL(shopper.url()).pathname.split('/').at(-1);
    const reference = await shopper.locator('.payment-reference strong').innerText();
    await shopper.getByText('Praćena zaliha čuva se rezervacijom tijekom testa.', { exact: false }).waitFor();
    const inventoryPage = await admin.newPage();
    await inventoryPage.goto(editUrl, { waitUntil: 'networkidle' });
    const heldPanel = await skuPanel(inventoryPage);
    const heldRow = heldPanel.getByRole('row').filter({ has: inventoryPage.getByRole('cell', { name: sku, exact: true }) });
    const heldCell = heldRow.getByRole('cell').filter({ has: inventoryPage.getByText('Rezervirano: 1 · Raspoloživo: 9', { exact: true }) });
    await heldCell.getByText('10', { exact: true }).waitFor();
    await capture('pending-reservation', inventoryPage);
    await inventoryPage.goto(base + '/admin/inventory', { waitUntil: 'networkidle' });
    const inventoryQuantities = async expected => {
        const search = inventoryPage.getByPlaceholder('SKU ili naziv proizvoda', { exact: true });
        if (await search.inputValue() !== sku) {
            const response = inventoryPage.waitForResponse(response => response.request().method() === 'POST'
                && new URL(response.url()).pathname.startsWith('/livewire') && response.status() === 200);
            await search.fill(sku);
            await response;
        }
        const row = inventoryPage.getByRole('row').filter({ has: inventoryPage.getByRole('cell', { name: sku, exact: true }) });
        await row.waitFor();
        for (const [label, quantity] of Object.entries(expected)) {
            const index = await inventoryPage.locator('thead th').evaluateAll((headers, label) => headers.findIndex(header => header.textContent.includes(label)), label);
            assert.ok(index >= 0, 'Missing inventory quantity header');
            assert.equal((await row.locator('td').nth(index).innerText()).trim(), quantity);
        }
    };
    await inventoryQuantities({ Zaliha: '10', Rezervirano: '1', Raspoloživo: '9' });
    for (const width of [1440, 390]) {
        await inventoryPage.setViewportSize({ width, height: 1000 });
        const result = await new AxeBuilder({ page: inventoryPage }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
        const summarize = values => values.map(value => ({ id: value.id, targets: value.nodes.map(node => node.target) }));
        const proof = { violations: summarize(result.violations), incomplete: summarize(result.incomplete) };
        await writeFile(`${output}/inventory-held-${width}-accessibility.json`, JSON.stringify(proof));
        assert.deepEqual(proof.violations, [], 'Inventory overview accessibility');
        await capture('inventory-held-' + width, inventoryPage);
    }
    await inventoryPage.setViewportSize({ width: 1440, height: 1000 });
    await dialog.getByRole('spinbutton', { name: 'Zaliha*', exact: true }).fill('0');
    await dialog.getByRole('textbox', { name: 'Cijena bez poreza*', exact: true }).fill('9,00');
    await dialog.getByRole('button', { name: 'Spremi promjene', exact: true }).click();
    await dialog.getByText('Zaliha ne može biti manja od rezervirane količine (1).', { exact: true }).waitFor();
    await capture('held-stock-refused');
    await shopper.getByRole('button', { name: /Simuliraj uspješno plaćanje/ }).click();
    await shopper.getByRole('heading', { name: 'Testna kupnja je uspjela.' }).waitFor();
    await inventoryPage.reload({ waitUntil: 'networkidle' });
    await inventoryQuantities({ Zaliha: '9', Rezervirano: '0', Raspoloživo: '9' });
    await capture('inventory-consumed', inventoryPage);
    await inventoryPage.close();
    assert.match(await shopper.locator('.payment-totals .summary-total').innerText(), /15,00/);
    await shopper.screenshot({ path: `${output}/guest-confirmation.png`, fullPage: true });
    await guest.close();

    await dialog.getByRole('spinbutton', { name: 'Zaliha*', exact: true }).fill('11');
    await dialog.getByRole('textbox', { name: 'Cijena bez poreza*', exact: true }).fill('9,00');
    await dialog.getByRole('button', { name: 'Spremi promjene', exact: true }).click();
    await dialog.getByText('Zaliha je u međuvremenu promijenjena. Ponovno otvorite varijantu.', { exact: true }).waitFor();
    await capture('stale-stock-refused');
    await page.goto(editUrl, { waitUntil: 'networkidle' });
    const refreshedPanel = await skuPanel();
    const row = refreshedPanel.getByRole('row').filter({ has: page.getByRole('cell', { name: sku, exact: true }) });
    const stockCell = row.getByRole('cell').filter({ has: page.getByText('Rezervirano: 0 · Raspoloživo: 9', { exact: true }) });
    await stockCell.getByText('9', { exact: true }).waitFor();
    await row.getByRole('button', { name: 'Uredi', exact: true }).click();
    assert.match(await page.getByRole('dialog').getByRole('textbox', { name: 'Cijena bez poreza*', exact: true }).inputValue(), /^8[.,]00$/);

    await page.goto(base + '/admin/orders', { waitUntil: 'networkidle' });
    const orderRow = page.getByRole('row').filter({ has: page.getByRole('cell', { name: reference, exact: true }) });
    await orderRow.getByRole('cell', { name: /15,00/ }).waitFor();
    await orderRow.getByRole('link', { name: 'Pregledaj', exact: true }).click();
    await page.waitForURL(/\/admin\/orders\/\d+$/);
    const orderId = page.url().match(/orders\/(\d+)$/)[1];
    await page.getByText('Testna narudžba — bez stvarne naplate i dostave', { exact: true }).waitFor();
    await capture('placed-order');
    const downloadEvent = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Preuzmi sažetak (PDF)', exact: true }).click();
    const download = await downloadEvent;
    assert.equal(download.suggestedFilename(), `order-summary-${orderId}.pdf`);
    const pdfPath = `${output}/order-summary.pdf`;
    await download.saveAs(pdfPath);
    const pdf = await readFile(pdfPath);
    assert.equal(pdf.subarray(0, 5).toString(), '%PDF-');
    assert.ok(pdf.length > 10000);

    await page.goto(base + '/admin/payments', { waitUntil: 'networkidle' });
    const paymentRow = page.getByRole('row').filter({ has: page.getByRole('cell', { name: reference, exact: true }) });
    await paymentRow.getByRole('cell', { name: 'Testna simulacija — bez novca', exact: true }).waitFor();
    await paymentRow.getByRole('cell', { name: 'Testno potvrđeno', exact: true }).waitFor();
    await paymentRow.getByRole('cell', { name: /15,00/ }).waitFor();
    await capture('simulated-payment');
    await paymentRow.getByRole('link', { name: 'Pregledaj', exact: true }).click();
    await page.waitForURL(/\/admin\/payments\/\d+$/);
    await page.getByText('Testno potvrđeno', { exact: true }).waitFor();
    await page.getByRole('link', { name: reference, exact: true }).click();
    await page.waitForURL(base + '/admin/orders/' + orderId);
    await capture('payment-order-history');
    await page.goto(base + '/admin/products', { waitUntil: 'networkidle' });
    await page.getByPlaceholder('Naziv proizvoda ili aktivni SKU').fill(sku);
    await page.waitForFunction(() => document.querySelectorAll('.fi-ta-table tbody > tr').length === 1);
    const productRow = page.getByRole('row').filter({ has: page.getByRole('cell', { name, exact: true }) });
    const adminCover = productRow.getByRole('img', { name: detailAlt, exact: true });
    await adminCover.waitFor();
    assert.match(await adminCover.getAttribute('src'), /-small\./);
    await adminCover.evaluate(img => img.decode());
    assert.ok(await adminCover.evaluate(img => img.naturalWidth > 0));
    await capture('product-list-cover');

    const globalSearch = page.getByRole('searchbox', { name: 'Globalno pretraživanje', exact: true });
    await globalSearch.fill(sku);
    const searchResult = page.locator('.fi-global-search-result-link').filter({ hasText: name });
    await searchResult.waitFor();
    assert.equal(await searchResult.getAttribute('href'), editUrl);
    await capture('global-product-search');
    await globalSearch.press('ArrowDown');
    // Filament moves focus inside Alpine's next tick; wait for that native
    // transition rather than racing the immediate post-keydown DOM state.
    await page.waitForFunction(() => document.activeElement?.matches('.fi-global-search-result-link'));
    assert.equal(await searchResult.evaluate(link => link === document.activeElement), true);
    await page.keyboard.press('Enter');
    await page.waitForURL(editUrl);

    await page.goto(base + '/admin/activities', { waitUntil: 'networkidle' });
    const consumedActivity = page.getByRole('row').filter({ hasText: 'Rezervirana zaliha potrošena' })
        .filter({ has: page.getByRole('link', { name: reference, exact: true }) });
    await consumedActivity.waitFor();
    assert.equal((await page.content()).includes(paymentCapability), false, 'Activity screens must not expose checkout capabilities.');
    await capture('safe-activity-history');
    await consumedActivity.getByRole('link', { name: reference, exact: true }).click();
    await page.waitForURL(base + '/admin/orders/' + orderId);
    assert.deepEqual(errors, []);
    assert.deepEqual(external, []);
    const report = { result: 'passed', productId, sku, reference, stages, pdfBytes: pdf.length, total: '15,00 EUR',
        staleStockRefused: true, pendingReservationVisible: true, heldStockEditRefused: true, reservationConsumedOnce: true,
        reusableOptionCreated: true, variableSkus: 2, basePriceColumn: true, publicPhotos: 2, reorderedCover: true, galleryKeyboardSelection: true, responsiveConversions: true, responsiveDetailPhotos: true, galleryOriginalWithoutJavascript: true, adminListCover: true, globalSkuSearch: true, globalSearchKeyboardNavigation: true, safeActivityOrderLink: true, paymentOrderHistoryLink: true, cartPhoto: true, variantPhotosAssigned: true, variantPhotoFollowsSelection: true, resetReturnsToCover: true, nativeVariantCartPhoto: true, variantPhotoEditorAccessibilityChecks: 2, inventoryHeldAndConsumed: true, inventoryAccessibilityChecks: 2, preservedUnitPrice: '8,00 EUR', remainingStock: 9, javascriptErrors: errors.length, externalOrigins: external };
    await writeFile(`${output}/report.json`, JSON.stringify(report, null, 2) + '\n', { mode: 0o600 });
    console.log(JSON.stringify(report));
} catch (error) {
    if (page && authenticated) await page.screenshot({ path: `${output}/failure.png`, fullPage: true }).catch(() => {});
    throw error;
} finally {
    await browser.close();
}
