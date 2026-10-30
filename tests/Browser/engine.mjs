import { chromium, firefox, webkit } from 'playwright';

export const engineName = process.env.ONESHOP_BROWSER_ENGINE ?? 'chromium';
const engines = { chromium, firefox, webkit };
if (!Object.hasOwn(engines, engineName)) throw new Error('ONESHOP_BROWSER_ENGINE must be chromium, firefox or webkit.');

export function launchBrowser() {
    return engines[engineName].launch({
        headless: true,
        executablePath: process.env.ONESHOP_BROWSER_EXECUTABLE || undefined,
        ...(engineName === 'chromium' ? { args: ['--disable-background-networking', '--disable-component-update', '--no-first-run'] } : {}),
    });
}

export function mobileContext(mobile) {
    // Firefox exercises the narrow viewport and touch input; it has no isMobile emulation.
    return { ...(engineName === 'firefox' ? {} : { isMobile: mobile }), hasTouch: mobile };
}
