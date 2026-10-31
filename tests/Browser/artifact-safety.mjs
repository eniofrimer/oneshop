import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { assertPrivateArtifactsSafe } from './private-artifacts.mjs';

process.umask(0o077);
const output = resolve(process.env.ONESHOP_ARTIFACT_SAFETY_OUTPUT ?? 'storage/app/private/artifact-safety-qa');
await mkdir(output, { recursive: true, mode: 0o700 });
const browser = await chromium.launch({ executablePath: process.env.ONESHOP_BROWSER_EXECUTABLE || undefined, headless: true });
try {
    const context = await browser.newContext({ serviceWorkers: 'block' });
    let requests = 0;
    context.on('request', () => requests++);
    await context.route('**/*', route => route.abort());
    const page = await context.newPage();
    const secret = randomBytes(24).toString('hex');
    const cases = [];
    const refused = async name => {
        await assert.rejects(() => assertPrivateArtifactsSafe(page, [secret]), assert.AssertionError);
        cases.push(name);
    };
    await page.setContent('<p id="key"></p>');
    await page.locator('#key').evaluate((node, value) => node.textContent = value, secret);
    await refused('visible-key-refused');
    // A synthetic box with the actual accessible QR label, never an enrollment QR.
    await page.setContent('<svg role="img" aria-label="QR kod za aplikaciju za autentifikaciju" width="20" height="20"><rect width="20" height="20"/></svg>');
    await refused('visible-qr-refused');
    await page.setContent('<input class="fi-one-time-code-input-digit" autocomplete="one-time-code">');
    await page.locator('input').fill(String(randomBytes(4).readUInt32BE() % 1000000).padStart(6, '0'));
    await refused('filled-otp-refused');
    await page.setContent('<input type="password">');
    await page.locator('input').fill(secret);
    await refused('filled-password-refused');
    await page.setContent('<p id="key" hidden></p><svg role="img" aria-label="QR kod za aplikaciju za autentifikaciju" style="display:none"></svg><input type="password"><input class="fi-one-time-code-input-digit">');
    await page.locator('#key').evaluate((node, value) => node.textContent = value, secret);
    await assertPrivateArtifactsSafe(page, [secret]);
    cases.push('cleared-and-hidden-content-accepted');
    assert.equal(requests, 0);
    const report = { result: 'passed', cases, applicationRequests: requests, screenshotsCreated: 0, credentialValuesPersisted: false };
    await writeFile(`${output}/report.json`, JSON.stringify(report, null, 2) + '\n');
    console.log(JSON.stringify(report));
} finally { await browser.close(); }
