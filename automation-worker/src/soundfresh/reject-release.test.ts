import { afterAll, beforeAll, expect, test } from 'vitest';
import { chromium, type Browser, type Page } from 'playwright';
import { rejectSoundfreshRelease } from './reject-release.js';

let browser: Browser;
beforeAll(async () => { browser = await chromium.launch({ headless: true }); });
afterAll(async () => { await browser?.close(); });

async function fixture(page: Page, options: { legacy?: boolean; fail?: boolean; rollback?: boolean; already?: boolean } = {}) {
    let rejected = options.already ?? false;
    const reasons: string[] = [];
    await page.route('https://cms-reject.test/**', async route => {
        if (route.request().method() === 'POST') {
            reasons.push(new URLSearchParams(route.request().postData() ?? '').get('reason') ?? '');
            rejected = !options.fail && !options.rollback;
            await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ rejected: !options.fail }) });
            return;
        }
        await route.fulfill({ contentType: 'text/html', body: `
            <nav><a>Rejected</a></nav><h1>Love Grows Like Ivy</h1>
            <span id="status">${rejected ? 'Rejected' : 'Upload DSP'}</span>
            ${options.legacy ? '<button aria-label="Toggle Dropdown" onclick="document.querySelector(\'#reject-action\').hidden=false">Verify Release</button>' : ''}
            <button id="verify-action" onclick="window.verifyClicked=true">Verify Release</button>
            <button id="reject-action" ${options.legacy ? 'hidden' : ''} onclick="document.querySelector('#reject-modal').style.display='block'">Reject Release</button>
            <div id="reject-modal" role="dialog" style="display:none">
                <h2>Reject Release</h2><form id="rejection">
                <label>Reason<textarea name="reason"></textarea></label><button>Reject Release</button></form>
            </div>
            <script>document.querySelector('#rejection').onsubmit=async event=>{
                event.preventDefault();
                const result=await fetch('/reject',{method:'POST',body:new URLSearchParams(new FormData(event.target))}).then(r=>r.json());
                document.querySelector('#reject-modal').style.display='none';
                if(result.rejected) document.querySelector('#status').textContent='Rejected';
            };</script>` });
    });
    await page.goto('https://cms-reject.test/admin/releases/123');
    return reasons;
}

for (const legacy of [false, true]) test(`submits the exact SoundOn reason and verifies persistence (${legacy ? 'dropdown' : 'direct button'})`, async () => {
    const page = await browser.newPage();
    try {
        const reasons = await fixture(page, { legacy });
        const reason = 'Artwork: text is unreadable\nAudio: instrumental classification is incorrect';
        await rejectSoundfreshRelease(page, reason, 1500);
        expect(reasons).toEqual([reason]);
        expect(await page.locator('#status').innerText()).toBe('Rejected');
        expect(await page.evaluate(() => (window as any).verifyClicked)).toBeUndefined();
        await rejectSoundfreshRelease(page, reason, 1500);
        expect(reasons).toHaveLength(1);
    } finally { await page.close(); }
});

for (const rollback of [false, true]) test(`does not report success when CMS ${rollback ? 'rolls back after reload' : 'closes the dialog without saving'}`, async () => {
    const page = await browser.newPage();
    try {
        await fixture(page, rollback ? { rollback: true } : { fail: true });
        await expect(rejectSoundfreshRelease(page, 'Real SoundOn reason', 600)).rejects.toThrow('SOUNDFRESH_REJECT_NOT_CONFIRMED');
    } finally { await page.close(); }
});

test('does not submit an empty rejection reason', async () => {
    const page = await browser.newPage();
    try {
        const reasons = await fixture(page);
        await expect(rejectSoundfreshRelease(page, '   ')).rejects.toThrow('REJECTION_REASON_REQUIRED');
        expect(reasons).toEqual([]);
    } finally { await page.close(); }
});
