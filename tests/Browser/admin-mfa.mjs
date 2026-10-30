import { chromium } from 'playwright';
import AxeBuilder from '@axe-core/playwright';
import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { invalidTotp, totp } from './totp.mjs';

process.umask(0o077);
assert.equal(process.env.ONESHOP_ADMIN_BROWSER_ALLOW_WRITES, 'true', 'Acknowledge enrollment on a disposable installation.');
assert.ok(process.env.ONESHOP_BROWSER_URL && process.env.ONESHOP_MFA_BROWSER_EMAIL && process.env.ONESHOP_MFA_BROWSER_PASSWORD);
const target = new URL(process.env.ONESHOP_BROWSER_URL);
assert.ok(['http:', 'https:'].includes(target.protocol) && ['/', ''].includes(target.pathname) && !target.username && !target.password && !target.search && !target.hash);
const base = target.origin;
const password = process.env.ONESHOP_MFA_BROWSER_PASSWORD;
const secrets = new Set([password]);
const sensitive = value => { secrets.add(value); return value; };
const sanitized = text => [...secrets].filter(Boolean).sort((a, b) => b.length - a.length)
    .reduce((text, secret) => text.replaceAll(secret, '[REDACTED]'), text)
    .replace(/otpauth:\/\/[^\s"'<>]+/g, '[REDACTED AUTH URI]')
    .replace(/\b[A-Z2-7]{16,}\b/g, '[REDACTED KEY]')
    .replace(/[A-Za-z0-9]{10}-[A-Za-z0-9]{10}/g, '[REDACTED RECOVERY CODE]')
    .replace(/\b\d{6}\b/g, '[REDACTED OTP]');
const output = resolve(process.env.ONESHOP_BROWSER_OUTPUT ?? 'storage/app/private/admin-mfa-browser-qa');
await mkdir(output, { recursive: true, mode: 0o700 });
const browser = await chromium.launch({ executablePath: process.env.ONESHOP_BROWSER_EXECUTABLE || undefined, headless: true });
const errors = [], external = [], checks = [];
try {
    const context = await browser.newContext({ viewport: { width: 1280, height: 1000 }, locale: 'hr-HR', serviceWorkers: 'block' });
    await context.route('**/*', route => {
        if (new URL(route.request().url()).origin === base) return route.continue();
        external.push(new URL(route.request().url()).origin);
        return route.abort();
    });
    const page = await context.newPage();
    page.setDefaultTimeout(30000);
    page.setDefaultNavigationTimeout(30000);
    page.on('pageerror', error => errors.push(error.message));
    const audit = async stage => {
        const text = await page.locator('body').innerText();
        assert.ok(![...secrets].some(secret => secret && secret.length >= 8 && text.includes(secret)), 'Hide enrollment and recovery credentials before artifacts.');
        assert.ok(await page.locator('.fi-one-time-code-input-digit:visible, input[type=password]:visible').evaluateAll(inputs => inputs.every(input => !input.value)), 'Clear sensitive inputs before artifacts.');
        assert.equal(await page.getByRole('img', { name: 'QR kod za aplikaciju za autentifikaciju', exact: true }).isVisible(), false, 'Hide enrollment QR before artifacts.');
        const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
        const summarize = values => values.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }));
        const violations = summarize(result.violations);
        await writeFile(`${output}/${stage}-accessibility.json`, JSON.stringify({ violations, incomplete: summarize(result.incomplete) }));
        assert.deepEqual(violations, [], stage + ': accessibility');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, stage + ': overflow');
        assert.deepEqual((await page.locator('body').ariaSnapshot()).match(/filament[\w-]*::[\w.\/-]+/g) ?? [], [], stage + ': unresolved translations');
        // Only called on screens without enrollment keys, recovery codes or filled OTPs.
        await page.screenshot({ path: `${output}/${stage}.png`, fullPage: true });
        checks.push(stage);
    };
    const login = async () => {
        await page.goto(base + '/admin/login', { waitUntil: 'networkidle' });
        await page.locator('input[type=email]').fill(process.env.ONESHOP_MFA_BROWSER_EMAIL);
        await page.locator('input[type=password]').fill(password);
        await page.getByRole('button', { name: 'Prijavi se', exact: true }).click();
    };
    const challenge = async () => {
        await page.getByRole('heading', { name: 'Potvrdi svoj identitet', exact: true }).waitFor();
        return page.getByRole('group', { name: 'Šesteroznamenkasti kod iz aplikacije', exact: true });
    };
    const approved = async () => {
        await page.waitForURL(url => url.origin === base && ['/admin', '/admin/products'].includes(url.pathname));
        assert.equal((await context.request.get(base + '/admin/products', { maxRedirects: 0 })).status(), 200);
        await page.goto(base + '/admin', { waitUntil: 'networkidle' });
    };
    const logout = async () => {
        const menu = page.locator('.fi-user-menu');
        await menu.getByRole('button', { name: 'Korisnički izbornik', exact: true }).click();
        await page.getByRole('menuitem', { name: 'Odjava', exact: true }).click();
        await page.waitForURL(base + '/admin/login');
    };
    const clearCode = async group => {
        for (const digit of await group.locator('input').all()) await digit.fill('');
    };
    const cooldown = async () => {
        console.log(JSON.stringify({ stage: 'waiting-for-native-login-rate-window', seconds: 62 }));
        await delay(31000);
        await delay(31000);
    };
    const commitRecovery = async input => {
        await input.press('Tab');
        await page.waitForFunction(() => document.querySelector('.fi-one-time-code-input-digit')?.required === false);
    };

    await login();
    await page.getByRole('heading', { name: 'Postavi dvofaktorsku provjeru', exact: true }).waitFor();
    const setupUrl = page.url();
    const blockedSetup = await context.request.get(base + '/admin/products', { maxRedirects: 0 });
    assert.equal(blockedSetup.status(), 302);
    assert.equal(blockedSetup.headers().location, setupUrl);
    await audit('enrollment-required');
    await page.getByRole('button', { name: 'Postavi', exact: true }).click();
    const modal = page.getByRole('dialog');
    const qr = modal.getByRole('img', { name: 'QR kod za aplikaciju za autentifikaciju', exact: true });
    await qr.evaluate(img => img.decode());
    assert.ok(await qr.evaluate(img => img.naturalWidth > 0), 'Authenticator QR must render locally.');
    const keyText = await modal.getByText(/\b[A-Z2-7]{16,64}\b/).innerText();
    const secret = sensitive(keyText.match(/\b[A-Z2-7]{16,64}\b/)?.[0]);
    assert.ok(typeof secret === 'string' && /^[A-Z2-7]{16,64}$/.test(secret), 'Unexpected ephemeral authenticator key format.');
    await modal.getByRole('group', { name: 'Šesteroznamenkasti kod iz aplikacije', exact: true }).locator('input').first().fill(sensitive(totp(secret).code));
    await modal.getByLabel('Trenutačna lozinka').fill(password);
    await modal.getByRole('button', { name: 'Naprijed', exact: true }).click();
    await modal.getByRole('listitem').first().waitFor();
    const recoveryCodes = (await modal.getByRole('listitem').allTextContents()).map(code => sensitive(code.match(/[A-Za-z0-9]{10}-[A-Za-z0-9]{10}/)?.[0]));
    assert.equal(recoveryCodes.length, 8);
    assert.ok(recoveryCodes.every(code => typeof code === 'string' && code.length === 21), 'Unexpected recovery code format.');
    await modal.getByRole('button', { name: 'Omogući aplikaciju za autentifikaciju', exact: true }).click();
    await modal.getByRole('button', { name: 'Omogući aplikaciju za autentifikaciju', exact: true }).waitFor({ state: 'hidden' });
    await page.getByText('Aplikacija za autentifikaciju je omogućena', { exact: true }).waitFor();
    await audit('enrollment-complete');
    await page.getByRole('button', { name: 'Nastavi', exact: true }).click();
    await approved();
    await logout();
    // Keep the shipped limits intact; the next five submissions include a real replay.
    await cooldown();
    await login();
    let group = await challenge();
    const pending = await context.request.get(base + '/admin/products', { maxRedirects: 0 });
    assert.equal(pending.status(), 302);
    assert.equal(pending.headers().location, base + '/admin/login');
    await audit('challenge-required');
    await group.locator('input').first().fill(sensitive(invalidTotp(secret)));
    await page.getByText('Uneseni kod nije valjan.', { exact: true }).waitFor();
    await clearCode(group);
    await audit('incorrect-code-refused');
    const accepted = totp(secret);
    await group.locator('input').first().fill(sensitive(accepted.code));
    await approved();
    await audit('otp-approved');
    await logout();
    await login();
    group = await challenge();
    assert.ok(Math.floor(Date.now() / 30000) - accepted.counter <= 1, 'Replay must be tested while its time window is still valid.');
    await group.locator('input').first().fill(accepted.code);
    await page.getByText('Uneseni kod nije valjan.', { exact: true }).waitFor();
    await clearCode(group);
    await audit('replay-refused');
    await cooldown();

    await login();
    await challenge();
    await page.getByRole('button', { name: 'Upotrijebi rezervni kod', exact: true }).click();
    await page.getByLabel('Rezervni kod', { exact: true }).fill(recoveryCodes[0]);
    // The native on-blur update removes the empty OTP field's HTML requirement.
    await commitRecovery(page.getByLabel('Rezervni kod', { exact: true }));
    await page.getByRole('button', { name: 'Potvrdi prijavu', exact: true }).click();
    await approved();
    await logout();
    await login();
    group = await challenge();
    await page.getByRole('button', { name: 'Upotrijebi rezervni kod', exact: true }).click();
    const recovery = page.getByLabel('Rezervni kod', { exact: true });
    await recovery.fill(recoveryCodes[0]);
    await commitRecovery(recovery);
    await page.getByRole('button', { name: 'Potvrdi prijavu', exact: true }).click();
    await page.getByText('Uneseni rezervni kod nije valjan.', { exact: true }).waitFor();
    await recovery.fill('');
    await recovery.press('Tab');
    await page.waitForLoadState('networkidle');
    await audit('recovery-reuse-refused');
    await group.locator('input').first().fill(sensitive(totp(secret).code));
    await approved();
    await audit('fresh-otp-approved');
    await page.goto(base + '/admin/profile', { waitUntil: 'networkidle' });
    await page.getByText('Dvofaktorska provjera (2FA)', { exact: true }).waitFor();
    await audit('profile-mfa-settings');
    assert.deepEqual(errors, []);
    assert.deepEqual(external, []);
    const report = { result: 'passed', stages: checks, enrollmentRequired: true, passwordAloneRefused: true,
        localQrRendered: true, incorrectCodeRefused: true, validTotpAccepted: true, replayRefusedWithinValidWindow: true,
        recoveryCodeAcceptedOnce: true, recoveryReuseRefused: true, freshTotpAccepted: true,
        nativeRateLimitsPreserved: true, secretsOmittedFromArtifacts: true, accessibilityChecks: checks.length,
        javascriptErrors: errors.length, externalOrigins: external };
    await writeFile(`${output}/report.json`, JSON.stringify(report, null, 2) + '\n');
    console.log(JSON.stringify(report));
} catch (error) {
    // Never capture enrollment/recovery screens or print input values on failure.
    console.error(sanitized(String(error.stack ?? error)));
    process.exitCode = 1;
} finally { await browser.close(); }
