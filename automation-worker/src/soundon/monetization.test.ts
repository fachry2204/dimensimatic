import { describe, expect, it, vi } from 'vitest';
import { chromium } from 'playwright';
import { enableMonetizationOption, enableStandardMonetization } from './draft-driver.js';

describe('SoundOn monetization eligibility', () => {
    it('does not click a genre-ineligible store or force dependent pre-release fields', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        const warning = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
        try {
            const page = await browser.newPage();
            await page.setContent(`<section><h3>YouTube Content ID</h3>
                <button role="switch" aria-checked="false" aria-disabled="true" aria-describedby="reason" onclick="document.body.dataset.clicked='yes'">Toggle</button>
                <p id="reason">Genre Devotional/Inspirational is not accepted.</p></section>`);
            await enableStandardMonetization(page, '2026-12-01');
            expect(await page.locator('body').getAttribute('data-clicked')).toBeNull();
            expect(await page.getByRole('switch').isChecked()).toBe(false);
            expect(warning).toHaveBeenCalledWith(expect.stringContaining('monetization_option_unavailable'));
            expect(warning).toHaveBeenCalledWith(expect.stringContaining('Devotional/Inspirational'));
        } finally { warning.mockRestore(); await browser.close(); }
    }, 10000);

    it('skips a natively disabled store without a synthetic DOM click', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent('<button disabled role="switch" aria-checked="false" onclick="this.setAttribute(\'aria-checked\',\'true\')">YouTube</button>');
            expect(await enableMonetizationOption(page, page.getByRole('switch'), 'YouTube Content ID')).toBe(false);
            expect(await page.getByRole('switch').isChecked()).toBe(false);
        } finally { await browser.close(); }
    }, 10000);

    it('enables an eligible store and preserves an already enabled option', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent('<button role="switch" aria-checked="false" onclick="this.setAttribute(\'aria-checked\',\'true\');document.body.dataset.clicks=String(Number(document.body.dataset.clicks||0)+1)">YouTube</button>');
            expect(await enableMonetizationOption(page, page.getByRole('switch'), 'YouTube Content ID')).toBe(true);
            expect(await enableMonetizationOption(page, page.getByRole('switch'), 'YouTube Content ID')).toBe(true);
            expect(await page.locator('body').getAttribute('data-clicks')).toBe('1');
        } finally { await browser.close(); }
    }, 10000);

    it('does not silently skip an enabled control which fails to save', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent('<button role="switch" aria-checked="false">YouTube</button>');
            await expect(enableMonetizationOption(page, page.getByRole('switch'), 'YouTube Content ID')).rejects.toThrow('SOUNDON_MONETIZATION_NOT_ENABLED:YouTube Content ID');
        } finally { await browser.close(); }
    }, 10000);
});
