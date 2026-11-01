import { engineName, launchBrowser } from './engine.mjs';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { once } from 'node:events';
import { mkdir, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';

process.umask(0o077);
assert.ok(process.env.ONESHOP_BROWSER_URL, 'Provide an initialized disposable OneShop URL.');
const target = new URL(process.env.ONESHOP_BROWSER_URL);
assert.ok(['http:', 'https:'].includes(target.protocol) && ['/', ''].includes(target.pathname)
    && !target.username && !target.password && !target.search && !target.hash);
const base = target.origin;
const output = resolve(process.env.ONESHOP_BROWSER_OUTPUT ?? 'storage/app/private/framing-browser-qa');
await mkdir(output, { recursive: true, mode: 0o700 });
const escapeAttribute = value => value.replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;');
const html = (src, nested = false) => {
    const child = `<iframe id="subject" title="Test frame" src="${src}" width="1200" height="900"></iframe>`;
    return `<!doctype html><html lang="hr"><title>Isolated framing fixture</title>${nested
        ? `<iframe id="middle" title="Nested test frame" srcdoc="${escapeAttribute(child)}" width="1250" height="950"></iframe>`
        : child}</html>`;
};
const allowedPaths = ['/', '/admin/login'];
// A second, real loopback origin. The fixture does not proxy any app request.
const fixture = createServer((request, response) => {
    const url = new URL(request.url, 'http://127.0.0.1');
    const path = url.searchParams.get('target');
    if (request.method !== 'GET' || !allowedPaths.includes(path)) { response.writeHead(400); response.end(); return; }
    response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
    response.end(html(base + path, url.searchParams.get('nested') === '1'));
});
fixture.listen(0, '127.0.0.1');
await once(fixture, 'listening');
const fixtureBase = 'http://127.0.0.1:' + fixture.address().port;
const reports = [];
let browser;
try {
    browser = await launchBrowser();
    for (const javascript of [true, false]) {
        const context = await browser.newContext({ viewport: { width: 1400, height: 1000 }, javaScriptEnabled: javascript, serviceWorkers: 'block' });
        const external = [], blockedMutations = [], actualFixtureRequests = [], requestedPaths = [];
        await context.route('**/*', async route => {
            const request = route.request(), url = new URL(request.url());
            if (!['GET', 'HEAD'].includes(request.method())) { blockedMutations.push(request.method()); return route.abort(); }
            if (url.origin === base && allowedPaths.includes(url.pathname)) requestedPaths.push(url.pathname);
            if (url.origin === fixtureBase) actualFixtureRequests.push(url.pathname);
            else if (url.origin !== base) { external.push(url.origin); return route.abort(); }
            return route.continue();
        });
        const page = await context.newPage();
        page.setDefaultTimeout(15_000);
        page.setDefaultNavigationTimeout(20_000);
        for (const path of allowedPaths) {
            const selector = path === '/' ? '.store-hero h1' : 'input[type="email"]';
            const response = await page.goto(base + path, { waitUntil: 'networkidle' });
            assert.equal(response.status(), 200);
            assert.equal(await response.headerValue('x-frame-options'), 'SAMEORIGIN');
            assert.match(await response.headerValue('content-security-policy'), /frame-ancestors 'self'/);
            assert.equal(await page.locator(selector).count(), 1, 'Direct access must work.');
            for (const nested of [false, true]) {
                if (nested) await page.goto(base + path, { waitUntil: 'networkidle' });
                // Preserve the real response origin/address space. An intercepted
                // fake HTTP page can have different local-network permissions.
                await page.setContent(html(base + path, nested), { waitUntil: 'networkidle' });
                const subject = nested ? page.frameLocator('#middle').frameLocator('#subject') : page.frameLocator('#subject');
                await subject.locator(selector).waitFor();
                assert.equal(await subject.locator(selector).count(), 1, 'Same-origin embedding must work.');
                const previousRequests = requestedPaths.filter(value => value === path).length;
                await page.goto(fixtureBase + '/?target=' + encodeURIComponent(path) + (nested ? '&nested=1' : ''), { waitUntil: 'networkidle' });
                assert.equal(requestedPaths.filter(value => value === path).length, previousRequests + 1, 'The protected document must actually be requested.');
                // A denied document never commits. Do not wait for a child frame
                // locator: some engines leave no document after rejecting it.
                // WebKit can retain the requested URL on an empty denied frame.
                // Inspect only frames that exist, with a bounded wait; Firefox
                // can leave no child frame at all after enforcing the header.
                for (const frame of page.frames().filter(frame => frame.url() === base + path)) {
                    let timeout;
                    try {
                        const count = await Promise.race([
                            frame.locator(selector).count(),
                            new Promise((_, reject) => { timeout = setTimeout(() => reject(new Error('Denied frame inspection timed out.')), 3000); }),
                        ]);
                        assert.equal(count, 0, 'A different origin anywhere in the ancestor chain must be blocked.');
                    } finally { clearTimeout(timeout); }
                }
                reports.push({ engine: engineName, javascript, path, nested, direct: 'passed', same_origin: 'passed', cross_origin: 'blocked' });
            }
        }
        assert.ok(actualFixtureRequests.length >= 4, 'The second loopback origin must actually be visited.');
        assert.deepEqual(external, []);
        await writeFile(`${output}/${javascript ? 'enhanced' : 'no-js'}-requests.json`, JSON.stringify({ blocked_mutations: blockedMutations.length, unexpected_origins: external.length, fixture_requests: actualFixtureRequests.length, allowed_mutations: 0 }));
        await context.close();
    }
    await writeFile(`${output}/framing-summary.json`, JSON.stringify({ engine: engineName, checks: reports, orders_placed: 0, scope: 'Real second loopback origin and native srcdoc nesting; same-origin positive fixture replaces only the loaded document body. No synthetic HTTP response, application test route, login or saved data.' }, null, 2));
    console.log(JSON.stringify({ engine: engineName, checks: reports.length, cross_origin: 'blocked', same_origin: 'passed', orders_placed: 0 }));
} finally {
    if (browser) await browser.close();
    fixture.closeAllConnections();
    await new Promise((resolve, reject) => fixture.close(error => error ? reject(error) : resolve()));
}
