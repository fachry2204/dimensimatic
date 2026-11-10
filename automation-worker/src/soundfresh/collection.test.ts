import { afterAll, beforeAll, expect, test } from 'vitest';
import { chromium, type Browser, type Page } from 'playwright';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

let browser: Browser;
beforeAll(async () => { browser = await chromium.launch({ headless: true }); });
afterAll(async () => { await browser?.close(); });

// Exercise existing route collection body without starting HTTP server or requiring credentials.
async function collect(page: Page) {
    const source = readFileSync(new URL('../server.ts', import.meta.url), 'utf8').replace(/\r\n/g, '\n');
    const bodySource = source.slice(source.indexOf('        const requestedMax ='), source.indexOf('        req.log.info({\n            tableId,\n            selectedPageLength:', source.indexOf('        const requestedMax =')));
    const code = ts.transpile(bodySource + '\nreturn { items, pagesRead };', { target: ts.ScriptTarget.ES2022 });
    const run = new Function('page', 'table', 'tableWrapper', 'tableId', 'body', 'requestedStatus', 'requestedStatusPattern', 'req', 'progress', `return (async () => { ${code} })()`);
    return run(page, page.locator('table'), page.locator('.dataTables_wrapper'), 'pending', { options: {} }, 'pending', /pending/i, { log: { info() {}, warn() {} } }, undefined);
}

async function fixture(page: Page, options: { total?: number; api?: boolean; stuck?: boolean; sameText?: boolean } = {}) {
    await page.route('https://fixture.test/**', route => route.fulfill({ contentType: 'text/html', body: '<div class="dataTables_wrapper"><table id="pending"><thead><tr><th>Album</th><th>Tracks</th><th>Status</th></tr></thead><tbody></tbody></table><button id="pending_next">Next</button><div class="dataTables_info"></div></div>' }));
    await page.goto('https://fixture.test/releases');
    await page.evaluate(({ total = 337, api = true, stuck = false, sameText = false }) => {
        let current = 0;
        const pages = Math.ceil(total / 100);
        const draw = () => {
            document.querySelector('.dataTables_info')!.textContent = `Showing ${current * 100 + 1} to ${Math.min(total, (current + 1) * 100)} of ${total} entries`;
            document.querySelector('tbody')!.innerHTML = Array.from({ length: Math.min(100, total - current * 100) }, (_, i) => {
                const id = current * 100 + i + 1;
                return `<tr data-id="${id}"><td><a href="/admin/releases/${id}">${sameText ? 'Same title' : `Release ${id}`}</a><div>Artist</div><span hidden>hidden metadata</span></td><td>1</td><td>Pending <a href="/admin/releases/${id}">View</a></td></tr>`;
            }).join('');
            (document.querySelector('button') as HTMLButtonElement).disabled = current >= pages - 1;
            document.querySelector('button')!.className = current >= pages - 1 ? 'disabled' : '';
        };
        const advance = (target: number) => { if (!stuck) setTimeout(() => { current = target; draw(); }, 180); };
        document.querySelector('button')!.onclick = () => advance(current + 1);
        if (api) {
            const pageApi = Object.assign((target: number) => ({ draw: () => advance(target) }), { info: () => ({ page: current, pages }) });
            (window as any).jQuery = Object.assign(() => ({ DataTable: () => ({ page: pageApi }) }), { fn: { DataTable: { isDataTable: () => true } } });
        }
        draw();
    }, options);
}

test('rejects stalled pagination instead of reporting partial success', async () => {
    const page = await browser.newPage();
    try {
        await fixture(page, { stuck: true });
        await expect(collect(page)).rejects.toThrow('SOUNDFRESH_PAGINATION_STALLED');
    } finally { await page.close(); }
}, 30_000);

test('collects all 337 rows across four delayed DataTables redraws despite innerText/textContent mismatch', async () => {
    const page = await browser.newPage();
    try {
        await fixture(page);
        const first = page.locator('tbody tr').first();
        expect(await first.innerText()).not.toBe(await first.textContent());
        const result = await collect(page);
        expect(result.items).toHaveLength(337);
        expect(new Set(result.items.map((item: any) => item.release_id)).size).toBe(337);
        expect(result.pagesRead).toBe(4);
    } finally { await page.close(); }
}, 30_000);
