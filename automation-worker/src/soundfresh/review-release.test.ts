import { afterAll, beforeAll, expect, test } from 'vitest';
import { chromium, type Browser, type Page } from 'playwright';
import { moveSoundfreshReleaseToUnderReview } from './review-release.js';

let browser: Browser;
beforeAll(async () => { browser = await chromium.launch({ headless: true }); });
afterAll(async () => { await browser?.close(); });

async function fixture(page: Page, options: { legacy?: boolean; reject?: boolean; missingSoundOn?: boolean; noAction?: boolean; rollback?: boolean } = {}) {
    let reviewed = false;
    const submissions: URLSearchParams[] = [];
    await page.route('https://cms-review.test/**', async route => {
        if (route.request().method() === 'POST') {
            const body = new URLSearchParams(route.request().postData() ?? '');
            submissions.push(body);
            reviewed = !options.reject && !options.rollback && body.get('aggregator') === 'soundon-42';
            await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ reviewed: !options.reject }) });
            return;
        }
        await route.fulfill({ contentType: 'text/html', body: `
            <nav><a href="/all">Under Review</a></nav>
            ${options.noAction ? '' : '<a id="review-action" href="#" onclick="setTimeout(openReview, 150);return false"><span>✓</span> Review Release</a>'}
            <h1>The Sweetness of a Rose</h1><span id="release-status">${reviewed ? 'Under Review' : 'Pending'}</span>
            <select aria-label="Genre"><option>Rock</option></select>
            <div id="modal" style="display:none" ${options.legacy ? 'role="dialog"' : ''}>
                <h2>Review Release</h2><form id="review-form">
                    <p>Please select an aggregator to review this release:</p>
                    ${options.legacy ? '<label for="aggregator">Aggregator</label>' : ''}
                    <select id="aggregator" name="aggregator"><option value="">-- Unassigned --</option>
                        ${options.missingSoundOn ? '<option value="other">Other</option>' : '<option value="soundon-42">SoundOn</option>'}
                    </select>
                    ${options.legacy ? '<input name="draft_reference">' : ''}
                    <button type="button" onclick="document.querySelector(\'#modal\').style.display=\'none\'">Cancel</button>
                    <button type="submit"><span>✓</span> Review Release</button>
                </form>
            </div>
            <script>
                function openReview() { document.querySelector('#modal').style.display='block'; }
                document.querySelector('#review-form').onsubmit=async event => {
                    event.preventDefault();
                    const response=await fetch('/review', {method:'POST',body:new URLSearchParams(new FormData(event.target))});
                    const result=await response.json();
                    document.querySelector('#modal').style.display='none';
                    if(result.reviewed) document.querySelector('#release-status').textContent='Under Review';
                };
            </script>` });
    });
    await page.goto('https://cms-review.test/admin/releases/3833');
    return submissions;
}

for (const legacy of [false, true]) {
    test(`selects SoundOn and verifies persisted review with ${legacy ? 'legacy dialog' : 'current unlabelled modal and icon link'}`, async () => {
        const page = await browser.newPage();
        try {
            const submissions = await fixture(page, { legacy });
            await moveSoundfreshReleaseToUnderReview(page, 'SoundOn', '7705073981900032001', { timeoutMs: 2000 });
            expect(submissions).toHaveLength(1);
            expect(submissions[0]!.get('aggregator')).toBe('soundon-42');
            if (legacy) expect(submissions[0]!.get('draft_reference')).toBe('7705073981900032001');
            expect(await page.locator('#release-status').innerText()).toBe('Under Review');
            // The original action remains available; an already reviewed release is a no-op.
            await moveSoundfreshReleaseToUnderReview(page, 'SoundOn', '7705073981900032001');
            expect(submissions).toHaveLength(1);
        } finally { await page.close(); }
    });
}

test('a closed modal with status still Pending does not count as success', async () => {
    const page = await browser.newPage();
    try {
        await fixture(page, { reject: true });
        await expect(moveSoundfreshReleaseToUnderReview(page, 'SoundOn', 'saved-draft', { timeoutMs: 500 }))
            .rejects.toThrow('SOUNDFRESH_REVIEW_NOT_CONFIRMED');
        expect(await page.locator('#release-status').innerText()).toBe('Pending');
    } finally { await page.close(); }
});

test('does not submit an unassigned or missing SoundOn aggregator', async () => {
    const page = await browser.newPage();
    try {
        const submissions = await fixture(page, { missingSoundOn: true });
        await expect(moveSoundfreshReleaseToUnderReview(page, 'SoundOn', 'saved-draft', { timeoutMs: 500 }))
            .rejects.toThrow('SOUNDFRESH_REVIEW_AGGREGATOR_NOT_FOUND');
        expect(submissions).toHaveLength(0);
    } finally { await page.close(); }
});

test('an optimistic status that reverts after reload does not count as persisted review', async () => {
    const page = await browser.newPage();
    try {
        await fixture(page, { rollback: true });
        await expect(moveSoundfreshReleaseToUnderReview(page, 'SoundOn', 'saved-draft', { timeoutMs: 500 }))
            .rejects.toThrow('status did not persist after reload');
    } finally { await page.close(); }
});

test('an Under Review navigation link does not mask a missing release action', async () => {
    const page = await browser.newPage();
    try {
        await fixture(page, { noAction: true });
        await expect(moveSoundfreshReleaseToUnderReview(page, 'SoundOn', 'saved-draft', { timeoutMs: 300 }))
            .rejects.toThrow('SOUNDFRESH_UI_CHANGED:review_release_button');
    } finally { await page.close(); }
});
