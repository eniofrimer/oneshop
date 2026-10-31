import assert from 'node:assert/strict';

// Call before any screenshot or accessibility artifact on authentication screens.
// Assertions never include the credential value or raw DOM in their messages.
export async function assertPrivateArtifactsSafe(page, secrets = []) {
    const text = await page.locator('body').innerText();
    assert.ok(![...secrets].some(secret => secret && secret.length >= 8 && text.includes(secret)), 'Hide enrollment and recovery credentials before artifacts.');
    assert.ok(await page.locator('.fi-one-time-code-input-digit:visible, input[autocomplete="one-time-code"]:visible, input[type=password]:visible')
        .evaluateAll(inputs => inputs.every(input => !input.value)), 'Clear sensitive inputs before artifacts.');
    assert.equal(await page.getByRole('img', { name: 'QR kod za aplikaciju za autentifikaciju', exact: true }).isVisible(), false, 'Hide enrollment QR before artifacts.');
}
