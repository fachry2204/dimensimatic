import { afterAll, beforeAll, expect, test } from 'vitest';
import { chromium, type Browser } from 'playwright';
import { resolveLabelName, setLabelRightsFields } from './draft-driver.js';

let browser: Browser;
beforeAll(async () => { browser = await chromium.launch({ headless: true }); });
afterAll(async () => { await browser?.close(); });

for (const active of [true, false, 'false', 'aktif', 'tidak aktif']) test(`fills all rights-holder fields for whitelabel=${active}`, async () => {
    const page = await browser.newPage();
    try {
        await page.setContent(`<main>
            <section><label class="soundon-formik-field-label">Record label</label><input value="Old label"></section>
            <section><label class="soundon-formik-field-label">P-line (Optional)</label><span id="p-year">2026</span><input placeholder="Enter the sound recording rights holder." value="Old P-line"></section>
            <section><label class="soundon-formik-field-label">C-line (Optional)</label><span id="c-year">2026</span><input placeholder="Enter the copyright owner." value="Old C-line"></section>
        </main>`);
        const metadata = { whitelabel_active: active, label_name: 'PT RUANG DIMENSI RECORDS', record_label: 'Other label', p_line: 'Ignored', c_line: 'Ignored' };
        await setLabelRightsFields(page, metadata);
        const expected = active === true || active === 'aktif' ? 'PT RUANG DIMENSI RECORDS' : 'Dimensi Suara';
        expect(await page.locator('input').evaluateAll(inputs => inputs.map(input => (input as HTMLInputElement).value))).toEqual([expected, expected, expected]);
        expect(await page.locator('#p-year').innerText()).toBe('2026');
        expect(await page.locator('#c-year').innerText()).toBe('2026');
    } finally { await page.close(); }
});

test('requires a label name for an active whitelabel and defaults old metadata to Dimensi Suara', () => {
    expect(() => resolveLabelName({ whitelabel_active: true, label_name: '' })).toThrow('METADATA_MISSING');
    expect(resolveLabelName({ record_label: 'Old label' })).toBe('Dimensi Suara');
    expect(resolveLabelName({ whitelabel_active: true, record_label: 'Whitelabel Records' })).toBe('Whitelabel Records');
});
