import { afterAll, beforeAll, expect, test } from 'vitest';
import { chromium, type Browser, type Page } from 'playwright';
import { verifySoundfreshRelease } from './verify-release.js';

let browser: Browser;
beforeAll(async () => { browser = await chromium.launch({ headless: true }); });
afterAll(async () => { await browser?.close(); });
const codes = { upc: '1234567890123', isrcs: ['IDABC2612345', 'IDABC2612346'] };

async function fixture(page: Page, options: { tracks?: number; reverse?: boolean; reject?: boolean; rollback?: boolean; legacy?: boolean } = {}) {
    let verified = false;
    let saved: Record<string, string> = {};
    const submissions: URLSearchParams[] = [];
    const tracks = options.tracks ?? 1;
    await page.route('https://cms-verify.test/**', async route => {
        if (route.request().method() === 'POST') {
            const body = new URLSearchParams(route.request().postData() ?? '');
            submissions.push(body);
            saved = Object.fromEntries(body);
            verified = !options.reject && !options.rollback;
            await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ verified: !options.reject }) });
            return;
        }
        const order = Array.from({ length: tracks }, (_, index) => index + 1);
        if (options.reverse) order.reverse();
        const fields = order.map(number => `<div><div>Track ${number} ISRC - My Precious Little Star ${number}</div><input name="isrcs[${900 + number}]"></div>`).join('');
        await route.fulfill({ contentType: 'text/html', body: `
            <nav><a>Published</a></nav><input id="search" placeholder="Search UPC"><input type="hidden" name="upc" value="old-hidden-code">
            <a href="#" id="verify-action" onclick="setTimeout(()=>document.querySelector('#publish').style.display='block',100);return false">✓ Verify Release</a>
            <h1>My Precious Little Star</h1><span id="status">${verified ? 'Verified' : 'Upload DSP'}</span>
            <div id="identifiers">${verified ? Object.values(saved).join(' ') : 'UPC — ISRC —'}</div>
            <div id="publish" style="display:none" ${options.legacy ? 'role="dialog" class="modal"' : ''}>
                <header><h2>${options.legacy ? 'Verify Release' : 'Publish Release'}</h2><button type="button">×</button></header>
                <p>Before you can verify this release, please make sure that the release UPC and tracks ISRCs are correct.</p>
                <form id="verification"><div><div>UPC</div><input name="upc"></div>${fields}
                    <button type="button">Cancel</button><button type="submit">✓ Verify Release</button>
                </form>
            </div>
            <script>
                document.querySelector('#verification').onsubmit=async event=>{
                    event.preventDefault();
                    const data=new URLSearchParams(new FormData(event.target));
                    const result=await (await fetch('/verify',{method:'POST',body:data})).json();
                    document.querySelector('#publish').style.display='none';
                    if(result.verified){document.querySelector('#status').textContent='Verified';document.querySelector('#identifiers').textContent=[...data.values()].join(' ');}
                };
            </script>` });
    });
    await page.goto('https://cms-verify.test/admin/releases/3834');
    return submissions;
}

test('fills the current Publish Release modal, persists UPC/ISRC, and does not submit twice', async () => {
    const page = await browser.newPage();
    try {
        const submissions = await fixture(page);
        const single = { upc: codes.upc, isrcs: [codes.isrcs[0]!] };
        await verifySoundfreshRelease(page, single, { timeoutMs: 2000 });
        expect(submissions).toHaveLength(1);
        expect(submissions[0]!.get('upc')).toBe(codes.upc);
        expect(submissions[0]!.get('isrcs[901]')).toBe(codes.isrcs[0]);
        expect(await page.locator('#status').innerText()).toBe('Verified');
        expect(await page.locator('#search').inputValue()).toBe('');
        await verifySoundfreshRelease(page, single);
        expect(submissions).toHaveLength(1);
    } finally { await page.close(); }
});

test('maps every album ISRC by track label even when the modal DOM order differs', async () => {
    const page = await browser.newPage();
    try {
        const submissions = await fixture(page, { tracks: 2, reverse: true, legacy: true });
        await verifySoundfreshRelease(page, codes, { timeoutMs: 2000 });
        expect(submissions[0]!.get('isrcs[901]')).toBe(codes.isrcs[0]);
        expect(submissions[0]!.get('isrcs[902]')).toBe(codes.isrcs[1]);
    } finally { await page.close(); }
});

test('does not submit a partial album when ISRC count differs from the modal', async () => {
    const page = await browser.newPage();
    try {
        const submissions = await fixture(page, { tracks: 2 });
        await expect(verifySoundfreshRelease(page, { upc: codes.upc, isrcs: [codes.isrcs[0]!] }, { timeoutMs: 1000 }))
            .rejects.toThrow('SOUNDFRESH_VERIFY_TRACK_COUNT_MISMATCH');
        expect(submissions).toHaveLength(0);
    } finally { await page.close(); }
});

for (const rollback of [false, true]) {
    test(`does not report success when ${rollback ? 'verification does not persist after reload' : 'the modal closes without a status change'}`, async () => {
        const page = await browser.newPage();
        try {
            await fixture(page, rollback ? { rollback: true } : { reject: true });
            await expect(verifySoundfreshRelease(page, { upc: codes.upc, isrcs: [codes.isrcs[0]!] }, { timeoutMs: 500 }))
                .rejects.toThrow('SOUNDFRESH_VERIFY_NOT_CONFIRMED');
        } finally { await page.close(); }
    });
}

test('rejects malformed identifiers before opening or submitting the CMS dialog', async () => {
    const page = await browser.newPage();
    try {
        const submissions = await fixture(page);
        await expect(verifySoundfreshRelease(page, { upc: 'invalid-upc', isrcs: ['invalid-isrc'] }))
            .rejects.toThrow('UPC_ISRC_VALIDATION_FAILED');
        expect(submissions).toHaveLength(0);
        expect(await page.locator('#publish').isVisible()).toBe(false);
    } finally { await page.close(); }
});

for (const wrapped of [false, true]) {
    test(`recognizes unassociated labels without name/id in ${wrapped ? 'nested' : 'shared'} CMS field containers`, async () => {
        const page = await browser.newPage();
        try {
            const submissions = await fixture(page, { tracks: 2 });
            await page.locator('#verification').evaluate((form, wrapped) => {
                const fields = [...form.querySelectorAll('input')];
                const container = document.createElement('div');
                for (const [index, input] of fields.entries()) {
                    const label = document.createElement('label');
                    label.textContent = index === 0 ? 'UPC' : `Track ${index} ISRC - Album Track`;
                    input.dataset.postName = input.name;
                    input.removeAttribute('name');
                    const group = wrapped ? document.createElement('div') : container;
                    group.append(label);
                    if (wrapped) {
                        const wrapper = document.createElement('div');
                        wrapper.append(input);
                        group.append(wrapper);
                        container.append(group);
                    } else group.append(input);
                }
                form.prepend(container);
                form.addEventListener('submit', () => {
                    for (const input of fields) input.name = input.dataset.postName!;
                }, { capture: true });
            }, wrapped);
            await verifySoundfreshRelease(page, codes, { timeoutMs: 1500 });
            expect(submissions).toHaveLength(1);
            expect(submissions[0]!.get('upc')).toBe(codes.upc);
            expect(submissions[0]!.get('isrcs[901]')).toBe(codes.isrcs[0]);
            expect(submissions[0]!.get('isrcs[902]')).toBe(codes.isrcs[1]);
        } finally { await page.close(); }
    });
}

test('does not guess between two visible UPC fields', async () => {
    const page = await browser.newPage();
    try {
        const submissions = await fixture(page);
        await page.locator('#verification').evaluate(form => {
            const duplicate = document.createElement('input');
            duplicate.name = 'another_upc';
            form.prepend(duplicate);
        });
        await expect(verifySoundfreshRelease(page, { upc: codes.upc, isrcs: [codes.isrcs[0]!] }, { timeoutMs: 1500 }))
            .rejects.toThrow('verify_upc_input:matched=2');
        expect(submissions).toHaveLength(0);
    } finally { await page.close(); }
});

test('ignores hidden header inputs and waits for delayed modal fields', async () => {
    const page = await browser.newPage();
    try {
        const submissions = await fixture(page);
        await page.locator('#publish').evaluate(panel => {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = '_token';
            panel.querySelector('header')!.append(hidden);
            const form = panel.querySelector('form')!;
            form.style.display = 'none';
            document.querySelector('#verify-action')!.addEventListener('click', () => {
                setTimeout(() => { form.style.display = 'block'; }, 450);
            });
        });
        await verifySoundfreshRelease(page, { upc: codes.upc, isrcs: [codes.isrcs[0]!] }, { timeoutMs: 1800 });
        expect(submissions).toHaveLength(1);
        expect(submissions[0]!.get('upc')).toBe(codes.upc);
        expect(submissions[0]!.get('isrcs[901]')).toBe(codes.isrcs[0]);
    } finally { await page.close(); }
});

test('waits for fields when a visible modal initially contains only hidden inputs', async () => {
    const page = await browser.newPage();
    try {
        const submissions = await fixture(page, { legacy: true });
        await page.locator('#publish').evaluate(panel => {
            const form = panel.querySelector('form')!;
            form.style.display = 'none';
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            panel.append(hidden);
            document.querySelector('#verify-action')!.addEventListener('click', () => {
                setTimeout(() => { form.style.display = 'block'; }, 450);
            });
        });
        await verifySoundfreshRelease(page, { upc: codes.upc, isrcs: [codes.isrcs[0]!] }, { timeoutMs: 1800 });
        expect(submissions).toHaveLength(1);
    } finally { await page.close(); }
});

test('does not use outer page inputs when the verification form never becomes visible', async () => {
    const page = await browser.newPage();
    try {
        const submissions = await fixture(page);
        await page.evaluate(() => {
            const main = document.createElement('main');
            while (document.body.firstChild) main.append(document.body.firstChild);
            document.body.append(main);
            document.querySelector<HTMLFormElement>('#verification')!.style.display = 'none';
            const other = document.createElement('input');
            other.placeholder = 'Search artist';
            main.prepend(other);
        });
        await expect(verifySoundfreshRelease(page, { upc: codes.upc, isrcs: [codes.isrcs[0]!] }, { timeoutMs: 700 }))
            .rejects.toThrow('verify_dialog_not_ready');
        expect(submissions).toHaveLength(0);
        expect(await page.locator('#search').inputValue()).toBe('');
    } finally { await page.close(); }
});
