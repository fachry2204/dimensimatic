import { advanceDraftPage } from "./publish-release.js";
import type { Locator, Page, Request } from "playwright";

export type SoundOnReleaseStatus = "under_review" | "delivery" | "approved" | "not_approved" | "live";
export type ReleaseStatusLookup = { key: string; title: string; artist: string };
export type LastUpdatedLookup = { key: string; title: string; artist: string };
export type ReleaseStatusMatch = {
    found: true;
    status: SoundOnReleaseStatus;
    release_url: string;
    upc: string | null;
    isrcs: string[];
    reason: string | null;
};

const statuses: Array<{ value: SoundOnReleaseStatus; label: RegExp }> = [
    // Check the negative form before Approved so "Not Approved" is never
    // misclassified by the shorter positive expression.
    { value: "not_approved", label: /not\s*approved|rejected/i },
    { value: "under_review", label: /(?:under|in)\s*review|reviewing/i },
    { value: "delivery", label: /delivery|delivered/i },
    // Accept the misspelling defensively; the value stored by this app is canonical.
    { value: "approved", label: /approved|aproved/i },
    { value: "live", label: /\blive\b/i },
];

export const detectReleaseStatus = (text: string): SoundOnReleaseStatus | null =>
    statuses.find((status) => status.label.test(text))?.value ?? null;

const normalize = (value: string): string => value.normalize("NFKC").replace(/[\u200B-\u200D\uFEFF]/g, "").trim().replace(/\s+/g, " ").toLocaleLowerCase();
const comparable = (value: string): string => normalize(value).replace(/[^\p{L}\p{N}]+/gu, " ").trim();
const unique = (values: string[]): string[] => [...new Set(values)];
const identifiers = (text: string): { upc: string | null; isrcs: string[] } => ({
    upc: text.match(/\b(?:UPC|EAN)\s*[:#]?\s*(\d{12,13})\b/i)?.[1] ?? null,
    isrcs: unique([...text.matchAll(/\b[A-Z]{2}[A-Z0-9]{3}\d{7}\b/gi)].map((match) => match[0].toUpperCase())),
});

export const parseRejectionReason = (text: string): string | null => {
    const lines = text.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
    const explanationIndex = lines.findIndex((line) => /(?:was|is)\s+not\s+approved\s+due\s+to\s+(?:the\s+)?following\s+reason/i.test(line));
    if (explanationIndex >= 0) {
        const reasons: string[] = [];
        for (const line of lines.slice(explanationIndex + 1)) {
            const isBullet = /^[•·*\-–—]/.test(line);
            if (reasons.length && !isBullet) break;
            const cleaned = line.replace(/^[\s•·*\-–—]+/, "").trim();
            if (cleaned) reasons.push(cleaned);
        }
        if (reasons.length) return reasons.join("\n");
    }
    for (let index = 0; index < lines.length; index += 1) {
        const line = lines[index]!;
        const inline = line.match(/^(?:rejection\s+reason|reason(?:\s+for\s+rejection)?|alasan(?:\s+penolakan)?)\s*[:\-]?\s*(.+)$/i)?.[1]?.trim();
        if (inline && !/^(?:not\s*approved|rejected)$/i.test(inline)) return inline;
        if (/^(?:rejection\s+reason|reason(?:\s+for\s+rejection)?|alasan(?:\s+penolakan)?)\s*[:\-]?$/i.test(line)) {
            const next = lines[index + 1]?.trim();
            if (next) return next;
        }
    }
    return null;
};

async function rejectionReasonFrom(page: Page): Promise<string | null> {
    const heading = page.getByText(/the\s+track\s+.+\s+was\s+not\s+approved\s+due\s+to\s+(?:the\s+)?following\s+reason/i, { exact: false })
        .filter({ visible: true })
        .first();
    await heading.waitFor({ state: "visible", timeout: 8_000 }).catch(() => undefined);
    if (await heading.count()) {
        let scope = heading;
        for (let level = 0; level <= 6; level += 1) {
            const text = await scope.innerText().catch(() => "");
            const reason = parseRejectionReason(text);
            if (reason) return reason;
            scope = scope.locator("xpath=..");
        }
    }

    const notices = page.locator([
        '[role="alert"]',
        '[class*="alert" i]',
        '[class*="warning" i]',
    ].join(",")).filter({ hasText: /the\s+track\s+.+\s+was\s+not\s+approved\s+due\s+to\s+(?:the\s+)?following\s+reason/i });
    let selected: { reason: string; length: number } | null = null;
    for (let index = 0; index < await notices.count(); index += 1) {
        const text = await notices.nth(index).innerText().catch(() => "");
        const reason = parseRejectionReason(text);
        if (reason && (!selected || text.length < selected.length)) selected = { reason, length: text.length };
    }
    return selected?.reason ?? null;
}

async function releaseUrl(page: Page, candidate: Locator): Promise<string> {
    const links = candidate.locator("a[href]");
    for (let index = 0; index < await links.count(); index += 1) {
        const href = await links.nth(index).getAttribute("href");
        if (href && /release|library|detail/i.test(href)) return new URL(href, page.url()).toString();
    }
    return page.url();
}

async function inspectDetails(page: Page, candidate: Locator, url: string, rejected = false): Promise<{ upc: string | null; isrcs: string[]; reason: string | null }> {
    const valuesFrom = async (scope: Locator, readRejection = false): Promise<{ upc: string | null; isrcs: string[]; reason: string | null }> => {
        const text = await scope.innerText().catch(() => "");
        const inputs = await scope.locator("input, textarea").evaluateAll((elements) =>
            elements.map((element) => (element as HTMLInputElement).value || element.getAttribute("value") || "").join("\n"),
        ).catch(() => "");
        const combined = `${text}\n${inputs}`;
        const scopedReason = readRejection ? await rejectionReasonFrom(scope.page()) : null;
        return {
            ...identifiers(combined),
            reason: readRejection ? scopedReason ?? parseRejectionReason(combined) : null,
        };
    };
    const rowValues = await valuesFrom(candidate);
    if (rowValues.upc && rowValues.isrcs.length) return rowValues;
    if (url === page.url()) return rowValues;

    const detail = await page.context().newPage();
    try {
        await detail.goto(url, { waitUntil: "domcontentloaded" });
        await detail.waitForLoadState("networkidle", { timeout: 10_000 }).catch(() => undefined);
        if (rejected) await detail.getByText(/was\s+not\s+approved\s+due\s+to/i, { exact: false })
            .filter({ visible: true }).first()
            .waitFor({ state: "visible", timeout: 8_000 }).catch(() => undefined);
        const detailValues = await valuesFrom(detail.locator("body"), rejected);
        return { upc: rowValues.upc ?? detailValues.upc, isrcs: unique([...rowValues.isrcs, ...detailValues.isrcs]), reason: detailValues.reason ?? rowValues.reason };
    } finally {
        await detail.close();
    }
}

async function selectStatusDropdown(page: Page, label: RegExp): Promise<boolean> {
    const combos = page.getByRole("combobox").filter({ visible: true });
    for (const combo of await combos.all()) {
        const text = (await combo.innerText()).trim();
        if (!/^(?:All status|Under Review|Not Approved|Approved|Delivered|Live|Taken Down)$/i.test(text)) continue;
        if (label.test(text)) return true;
        await combo.click();
        const option = page.getByRole("option", { name: label }).filter({ visible: true })
            .or(page.locator('[class*="select-option"], [role="listbox"] [role="option"]').filter({ hasText: label, visible: true }));
        const target = await option.count() ? option.first() : page.getByText(label, { exact: true }).filter({ visible: true }).last();
        if (!await target.count()) { await combo.press("Escape"); return false; }
        await target.click();
        await combo.press("Escape").catch(() => undefined);
        await page.waitForTimeout(300);
        await waitForList(page);
        return true;
    }
    return false;
}

async function selectStatus(page: Page, label: RegExp): Promise<boolean> {
    // Approved must never select the Not Approved tab by substring.
    label = new RegExp(`^(?:${label.source})(?:\\s*\\(?\\d+\\)?)?$`, label.flags);
    const tab = page.getByRole("tab", { name: label }).filter({ visible: true }).first();
    const button = page.getByRole("button", { name: label }).filter({ visible: true }).first();
    const target = await tab.count() ? tab : await button.count() ? button : page.getByText(label).filter({ visible: true }).first();
    if (!await target.count()) return selectStatusDropdown(page, label);
    await target.click({ timeout: 5_000 });
    await page.waitForTimeout(1_000);
    return true;
}

async function waitForList(page: Page): Promise<void> {
    if (/login|sign-in/i.test(page.url())) throw new Error("AUTH_SESSION_EXPIRED");
    await page.waitForFunction(() => !Array.from(document.querySelectorAll('[aria-busy="true"], [role="progressbar"], .ant-spin-spinning, .arco-spin-loading, .semi-spin:not(.semi-spin-hidden)')).some(node => {
        const element = node as HTMLElement;
        return element.getClientRects().length > 0 && getComputedStyle(element).visibility !== 'hidden';
    }), {}, { timeout: 12_000 });
    if (/login|sign-in/i.test(page.url())) throw new Error("AUTH_SESSION_EXPIRED");
}

async function search(page: Page, title: string): Promise<void> {
    let input = page.getByPlaceholder(/find release|search|cari/i).filter({ visible: true }).first();
    if (!await input.count()) input = page.getByRole("textbox").filter({ visible: true }).first();
    if (!await input.count()) return;
    // SoundOn can retain a previous page even after the query changes.
    const firstPage = page.locator('li[aria-label="Page 1"], [class*="pagination"] [aria-label="Page 1"]').filter({ visible: true }).first();
    if (await firstPage.count() && await firstPage.getAttribute('aria-current') !== 'page') {
        await firstPage.click();
        await page.waitForTimeout(300);
        await waitForList(page);
    }
    const currentUrl = new URL(page.url());
    if (Number(currentUrl.searchParams.get('current')) > 1 && !await firstPage.count()) {
        currentUrl.searchParams.set('current', '1');
        await page.goto(currentUrl.href, { waitUntil: 'domcontentloaded' });
        await input.waitFor({state:'visible', timeout:12_000});
    }
    let pending: Promise<void> | undefined;
    let expectedIds: string[] | undefined;
    const observe = (request: Request) => {
        const url = new URL(request.url());
        if (url.origin !== new URL(page.url()).origin || url.pathname !== '/api/search/entity') return;
        let body: any;
        try { body = request.postDataJSON(); } catch { return; }
        const queries = Array.isArray(body?.queries) ? body.queries : [];
        if (title ? !queries.some((query: any) => query.queryValue === title) : queries.some((query: any) => query.queryValue)) return;
        pending = request.response().then(async response => {
            if (!response || !response.ok()) throw new Error('SOUNDON_SEARCH_REQUEST_FAILED: Permintaan pencarian SoundOn gagal.');
            const failure = await response.finished();
            if (failure) throw failure;
            const result = await response.json();
            if (result.baseResp?.errorCode) throw new Error('SOUNDON_SEARCH_REQUEST_FAILED: SoundOn menolak pencarian.');
            if (Array.isArray(result.ids)) expectedIds = result.ids.map(String);
        });
        // Keep a handler attached while the keyboard/debounce operation runs.
        pending.catch(() => undefined);
    };
    page.on('request', observe);
    try {
        await input.fill("");
        if (title) await input.pressSequentially(title);
        else await input.press("Backspace");
        await input.press("Enter");
        await page.waitForTimeout(800);
        if (pending) {
            let timer: ReturnType<typeof setTimeout> | undefined;
            try {
                await Promise.race([pending, new Promise<never>((_, reject) => {
                    timer = setTimeout(() => reject(new Error('SOUNDON_SEARCH_TIMEOUT: Hasil pencarian belum selesai dimuat.')), 12_000);
                })]);
            } finally { if (timer) clearTimeout(timer); }
        }
    } finally { page.off('request', observe); }
    // Do not wait for unrelated analytics to become network-idle on every song.
    await waitForList(page);
    if (expectedIds) {
        await page.waitForFunction(ids => {
            if (!ids.length) return /no result|no releases|no data/i.test(document.body.innerText);
            const rendered = Array.from(document.querySelectorAll('tbody tr[data-row-key]')).filter(row => (row as HTMLElement).getClientRects().length).map(row => row.getAttribute('data-row-key'));
            return ids.length === rendered.length && ids.every(id => rendered.includes(id));
        }, expectedIds, {timeout:12_000});
    }
    if (title && expectedIds === undefined) {
        await page.waitForFunction((query) => {
            const normalize = (value: string) => value.normalize("NFKC").replace(/[^\p{L}\p{N}]+/gu, " ").trim().toLowerCase();
            return normalize(document.body.innerText).includes(normalize(query)) || /no result|no releases|no data/i.test(document.body.innerText);
        }, title, { timeout: 6_000 }).catch(() => undefined);
        await waitForList(page);
    }
}

const releaseRows = 'tbody tr, [role="rowgroup"] [role="row"], [data-testid="release-card"], [data-testid="draft-card"]';
async function nextPage(page: Page, currentPage: number): Promise<boolean> {
    await waitForList(page);
    const moved = await advanceDraftPage(page, currentPage, 12_000, releaseRows);
    if (!moved) {
        const text = await page.locator('body').innerText();
        const summary = text.match(/showing\s+[\d,]+\s+to\s+([\d,]+)\s+of\s+([\d,]+)/i);
        if (summary && Number(summary[1]!.replaceAll(',', '')) < Number(summary[2]!.replaceAll(',', ''))) {
            throw new Error('SOUNDON_STATUS_LIST_INCOMPLETE: Masih ada halaman SoundOn yang belum terbaca; kontrol halaman berikutnya belum siap atau belum dikenali.');
        }
    }
    return moved;
}

async function candidateFor(page: Page, title: string, artist: string, strictArtist = false, requireUnique = false): Promise<Locator | null> {
    const wantedTitle = comparable(title);
    const wantedArtist = comparable(artist);
    // Navigation <li> elements must not hide the release cards below them.
    let rows = page.locator('tr:visible, [role="row"]:visible, article:visible, [data-testid="release-card"]:visible, [class*="card" i]:visible');
    if (!await rows.count()) rows = page.locator("div:visible");
    const index = await rows.evaluateAll((elements, wanted) => {
        const __name = (window as any).__name || ((fn: any) => fn);
        const normalizeBrowser = (value: string) => value.normalize("NFKC").replace(/[\u200B-\u200D\uFEFF]/g, "").trim().replace(/\s+/g, " ").toLocaleLowerCase();
        const comparableBrowser = (value: string) => normalizeBrowser(value).replace(/[^\p{L}\p{N}]+/gu, " ").trim();
        const limit = Math.min(elements.length, 500);
        const titleMatches: number[] = [];
        const exactMatches: number[] = [];
        const exactTitles: number[] = [];
        for (let rowIndex = 0; rowIndex < limit; rowIndex += 1) {
            const element = elements[rowIndex] as HTMLElement;
            if (!element || !(element.offsetWidth || element.offsetHeight || element.getClientRects().length)) continue;
            const raw = element.innerText || element.textContent || "";
            const lines = raw.split(/\r?\n/).map(comparableBrowser).filter(Boolean);
            // In some SoundOn tables each cell is separated by tabs/spaces in
            // innerText rather than newlines. Match the title as a bounded
            // phrase across the row text as well as as a standalone line.
            const rowText = ` ${comparableBrowser(raw)} `;
            const values = [...raw.split(/\r?\n|\t/), ...Array.from(element.querySelectorAll('td,span,a,p,button,[title],[aria-label]')).flatMap(el => {
                if (!(el as HTMLElement).getClientRects().length || getComputedStyle(el).visibility === 'hidden') return [];
                return [(el as HTMLElement).innerText ?? '', el.getAttribute('title') ?? '', el.getAttribute('aria-label') ?? ''];
            })].map(comparableBrowser);
            if (!values.includes(wanted.title)) continue;
            titleMatches.push(rowIndex);
            const artistMatches = lines.some((line) =>
                line === wanted.artist || line.replace(/^primary artists?\s*/, "") === wanted.artist,
            ) || (wanted.artist !== "" && rowText.includes(` ${wanted.artist} `));
            if(wanted.requireUnique) {
                const values=[...raw.split(/\r?\n|\t/),...Array.from(element.querySelectorAll('td,span,a,p,button,[title],[aria-label]')).flatMap(el=>[el.textContent??'',el.getAttribute('title')??'',el.getAttribute('aria-label')??''])].map(comparableBrowser);
                if(values.includes(wanted.title)) exactTitles.push(rowIndex);
                if(values.includes(wanted.title) && values.includes(wanted.artist)) exactMatches.push(rowIndex);
            } else if (!wanted.artist || artistMatches) exactMatches.push(rowIndex);
        }
        // Soundfresh and SoundOn often format artist credits differently.
        // An exact title is safe when the filtered result contains only one row.
        if(wanted.requireUnique) {
            const titles=exactTitles.filter(index=>!exactTitles.some(other=>other!==index && elements[index]!.contains(elements[other]!)));
            if(titles.length===1) return titles[0]!;
            const smallest=exactMatches.filter(index=>!exactMatches.some(other=>other!==index && elements[index]!.contains(elements[other]!)));
            return smallest.length>1 ? -2 : smallest[0] ?? -1;
        }
        const smallest = (indexes: number[]) => indexes.filter(index => !indexes.some(other => other !== index && elements[index]!.contains(elements[other]!)));
        const exact = smallest(exactMatches);
        if (exact.length === 1) return exact[0]!;
        if (exact.length > 1) return -2;
        const titles = smallest(titleMatches);
        return !wanted.strictArtist && titles.length === 1 ? titles[0]! : -1;
    }, { title: wantedTitle, artist: wantedArtist, strictArtist, requireUnique });
    if(index===-2) throw new Error('SOUNDON_SELECTED_DRAFT_AMBIGUOUS: Judul dan artis memiliki beberapa pasangan di library SoundOn.');
    return index >= 0 ? rows.nth(index) : null;
}

export async function findReleaseStatuses(page: Page, lookups: ReleaseStatusLookup[], strictArtist = false, requireDetectedStatus = false, requireUnique = false): Promise<Record<string, ReleaseStatusMatch>> {
    const matches: Record<string, ReleaseStatusMatch> = {};
    await selectAllReleasesForConfirmation(page);
    await search(page, "");

    // The duplicate gate only needs to know whether an exact title + artist
    // exists. Scanning every SoundOn page once for every Pending release made
    // a batch of 49 take hours. A library search filters globally, so use one
    // bounded search per release and do not open details or paginate here.
    if (strictArtist) {
        for (const lookup of lookups) {
            if (!normalize(lookup.title) || !normalize(lookup.artist)) continue;
            await search(page, lookup.title);
            const candidate = await candidateFor(page, lookup.title, lookup.artist, true, requireUnique);
            if (!candidate) continue;
            const raw = await candidate.innerText().catch(() => "");
            const detectedStatus = detectReleaseStatus(raw);
            if (requireDetectedStatus && !detectedStatus) continue;
            const status = detectedStatus ?? "under_review";
            matches[lookup.key] = {
                found: true,
                status,
                release_url: await releaseUrl(page, candidate),
                upc: null,
                isrcs: [],
                reason: null,
            };
        }
        return matches;
    }

    const inspectCandidate = async (lookup: ReleaseStatusLookup): Promise<boolean> => {
        const candidate = await candidateFor(page, lookup.title, lookup.artist, strictArtist);
        if (!candidate) return false;
        const raw = await candidate.innerText().catch(() => "");
        const detectedValue = detectReleaseStatus(raw);
        const detected = detectedValue ? statuses.find((status) => status.value === detectedValue) : undefined;
        if (!detected) return false;
        const url = await releaseUrl(page, candidate);
        const values = ["delivery", "approved", "live", "not_approved"].includes(detected.value)
            ? await inspectDetails(page, candidate, url, detected.value === "not_approved")
            : { upc: null, isrcs: [], reason: null };
        matches[lookup.key] = { found: true, status: detected.value, release_url: url, ...values };
        return true;
    };

    const inspectAcrossPages = async (lookup: ReleaseStatusLookup, knownStatus?: SoundOnReleaseStatus): Promise<boolean> => {
        for (let pageNumber = 1; pageNumber <= 100; pageNumber += 1) {
            const candidate = await candidateFor(page, lookup.title, lookup.artist, strictArtist);
            if (candidate) {
                const raw = await candidate.innerText().catch(() => "");
                const detectedValue = knownStatus ?? detectReleaseStatus(raw);
                const detected = detectedValue ? statuses.find((item) => item.value === detectedValue) : undefined;
                if (detected) {
                    const url = await releaseUrl(page, candidate);
                    const values = ["delivery", "approved", "live", "not_approved"].includes(detected.value)
                        ? await inspectDetails(page, candidate, url, detected.value === "not_approved")
                        : { upc: null, isrcs: [], reason: null };
                    matches[lookup.key] = { found: true, status: detected.value, release_url: url, ...values };
                    return true;
                }
            }
            if (!await nextPage(page, pageNumber)) return false;
        }
        throw new Error("SOUNDON_STATUS_SCAN_LIMIT: Pencarian belum mencapai akhir daftar SoundOn.");
    };

    const searchTerms = (lookup: ReleaseStatusLookup): string[] => unique([
        comparable(lookup.title).split(" ").filter(word => word.length >= 4 && !/^(?:the|with|from|among|under|over|into|that|this|your|love)$/.test(word)).sort((a, b) => b.length - a.length)[0] ?? lookup.title.trim(),
        lookup.title.trim(),
        comparable(lookup.title),
    ].filter((value, index, values) => value && values.findIndex(other => normalize(other) === normalize(value)) === index));

    // Title search is the fastest path. Inspect the initial visible rows first,
    // then paginate each filtered query before trying a broader library scan.
    for (const lookup of lookups) {
        if (normalize(lookup.title)) await inspectCandidate(lookup);
    }
    const unresolved = () => lookups.filter(lookup => !matches[lookup.key] && normalize(lookup.title));
    for (const lookup of unresolved()) {
        for (const term of searchTerms(lookup)) {
            await search(page, term);
            if (await inspectAcrossPages(lookup)) break;
        }
    }
    // Some SoundOn searches omit an existing title. Clear the filter and scan
    // the library once for all unresolved releases, instead of once per song.
    if (unresolved().length) {
        await selectAllReleasesForConfirmation(page);
        await search(page, "");
        for (let pageNumber = 1; pageNumber <= 1000; pageNumber++) {
            for (const lookup of unresolved()) await inspectCandidate(lookup);
            if (!unresolved().length || !await nextPage(page, pageNumber)) break;
            if (pageNumber === 1000) throw new Error("SOUNDON_STATUS_SCAN_LIMIT: Akhir daftar belum tercapai.");
        }
    }

    for (const status of statuses) {
        if (lookups.every(lookup => matches[lookup.key] || !normalize(lookup.title))) break;
        await search(page, "");
        if (!await selectStatus(page, status.label)) continue;
        for (const lookup of lookups) {
            if (matches[lookup.key] || !normalize(lookup.title)) continue;
            for (const term of searchTerms(lookup)) {
                await search(page, term);
                if (await inspectAcrossPages(lookup, status.value)) break;
            }
        }
    }
    return matches;
}

/** Find SoundOn's Last Updated Date for matching releases from Soundfresh. */
export async function findLastUpdatedDates(page: Page, lookups: LastUpdatedLookup[]): Promise<Record<string, string>> {
    const result: Record<string, string> = {};
    await selectStatus(page, /under\s*review/i);

    for (const item of lookups) {
        if (!normalize(item.title) || !normalize(item.artist)) continue;
        await search(page, item.title);
        let candidate = await candidateFor(page, item.title, item.artist, true);
        if (!candidate) {
            await search(page, item.title + " " + item.artist);
            candidate = await candidateFor(page, item.title, item.artist, true);
        }
        // Artist labels can be rendered differently between Soundfresh and
        // SoundOn. A unique exact-title match is a safe fallback; ambiguous
        // same-title releases remain unmatched rather than guessing.
        if (!candidate) candidate = await candidateFor(page, item.title, item.artist, false);
        if (!candidate) continue;

        const updated = await candidate.evaluate((element) => {
            const row = element.closest("tr, [role=row]") ?? element;
            const table = row.closest("table");
            const headers = table
                ? Array.from(table.querySelectorAll("thead th")).map((header) => (header.textContent || "").replace(/\s+/g, " ").trim().toLowerCase())
                : [];
            const index = headers.findIndex((header) => /last\s*updated\s*date|last\s*updated|updated\s*date/.test(header));
            const cells = Array.from(row.querySelectorAll(":scope > td, :scope > [role=cell]"));
            if (index >= 0 && cells[index]) return (cells[index]?.textContent || "").replace(/\s+/g, " ").trim();

            const lines = ((row as HTMLElement).innerText || row.textContent || "").split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
            for (let position = 0; position < lines.length; position += 1) {
                const match = lines[position]?.match(/^last\s*updated(?:\s*date)?\s*:?\s*(.*)$/i);
                if (!match) continue;
                return (match[1] || lines[position + 1] || "").replace(/\s+/g, " ").trim();
            }
            return "";
        }).catch(() => "");
        if (updated) result[item.key] = updated;
    }

    return result;
}


/** Confirm the exact draft already opened and identity-checked before Submit. */
export async function publishedStatusByDraftId(page: Page, draftId: string): Promise<ReleaseStatusMatch | null> {
    const links=page.locator('a[href*="/library/"]');
    for(const link of await links.all()) {
        const href=await link.getAttribute('href');
        if(!href) continue;
        const url=new URL(href,page.url());
        const id=url.searchParams.get('id') ?? url.searchParams.get('release_id') ?? url.pathname.match(/(?:release|draft)\/([^/]+)$/i)?.[1];
        if(url.origin!==new URL(page.url()).origin || id!==draftId) continue;
        let row=link.locator('xpath=..');
        for(let depth=0;depth<6;depth++,row=row.locator('xpath=..')) {
            const tag=await row.evaluate(node=>node.tagName);
            if(['BODY','HTML','MAIN'].includes(tag)) break;
            const otherIds=await row.locator('a[href*="/library/"]').evaluateAll(nodes=>nodes.map(node=>(node as HTMLAnchorElement).href));
            if(otherIds.some(value=>value!==url.href)) break;
            const status=detectReleaseStatus(await row.innerText());
            if(status && ['under_review','approved','delivery','live'].includes(status))
                return {found:true,status,release_url:url.href,upc:null,isrcs:[],reason:null};
        }
    }
    return null;
}


export async function selectAllReleasesForConfirmation(page:Page):Promise<void> {
    const label=/^All\s*releases(?:\s*\(?\d+\)?)?$/i;
    const roleTabs=page.getByRole('tab',{name:label}).filter({visible:true});
    const buttons=page.getByRole('button',{name:label}).filter({visible:true});
    const tabs=await roleTabs.count() ? roleTabs : await buttons.count() ? buttons : page.getByText(label,{exact:true}).filter({visible:true});
    if(!await tabs.count()) { await selectStatusDropdown(page, /^All status$/i); return; }
    const tab=tabs.first();
    if(await tab.getAttribute('aria-selected')!=='true') { await tab.click(); await page.waitForTimeout(400); }
    await selectStatusDropdown(page, /^All status$/i);
}


export async function readVisiblePublishedStatus(page:Page,title:string,artist:string):Promise<ReleaseStatusMatch|null> {
    const candidate=await candidateFor(page,title,artist,true,true);
    if(!candidate)return null;
    const status=detectReleaseStatus(await candidate.innerText());
    if(!status || !['under_review','approved','delivery','live'].includes(status))return null;
    return {found:true,status,release_url:await releaseUrl(page,candidate),upc:null,isrcs:[],reason:null};
}
