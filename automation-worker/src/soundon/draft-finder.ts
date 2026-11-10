import type { Page } from "playwright";

export type DraftMatch = {
    found: true;
    draft_id: string;
    draft_url: string;
};
export type DraftLookup = { key: string; title: string; artist: string };

const normalize = (value: string): string =>
    value.normalize("NFKC").trim().replace(/[’‘`]/g, "'").replace(/\s+/g, " ").toLocaleLowerCase();

const isSameArtistLine = (line: string, artist: string): boolean => {
    const normalized = normalize(line);
    return normalized === artist
        || normalized.replace(/^primary artists?\s*:?\s*/, "") === artist;
};

const syntheticDraftId = (title: string, artist: string): string => {
    const input = `${title}\u0000${artist}`;
    let hash = 2166136261;
    for (let index = 0; index < input.length; index += 1) {
        hash ^= input.charCodeAt(index);
        hash = Math.imul(hash, 16777619);
    }
    return `existing-${(hash >>> 0).toString(16)}`;
};

export const draftLookupReference = (title: string, artist: string): string => syntheticDraftId(normalize(title), normalize(artist));

const draftIdFromUrl = (value: string): string | null => {
    const url = new URL(value);
    if (!url.pathname.match(/(?:draft|release|publish\/single)/i) && url.searchParams.get("source") !== "draft") return null;
    return url.searchParams.get("id")
        ?? url.pathname.match(/(?:draft|release)\/([^/]+)$/i)?.[1]
        ?? null;
};

async function searchDraftList(page: Page, title: string): Promise<boolean> {
    let search = page
        .getByPlaceholder(/search|cari/i)
        .filter({ visible: true })
        .first();
    if (!(await search.count())) {
        search = page.getByRole("textbox").filter({ visible: true }).first();
    }
    if (!(await search.count())) return false;

    await search.fill(title);
    await search.press("Enter").catch(() => undefined);
    // The list is debounced, but a fixed two-second wait for every pending
    // release made duplicate checks appear stuck. The exact-title lookup
    // below still guards the result after this short debounce.
    await page.waitForTimeout(350);
    return true;
}

async function ensureDraftsView(page: Page): Promise<void> {
    const draftsTab = page.getByRole("tab", { name: /^drafts?\b/i }).filter({ visible: true }).first();
    const draftsText = page.getByText(/^drafts?(?:\s+\d+)?$/i, { exact: true }).filter({ visible: true }).first();
    const target = await draftsTab.count() ? draftsTab : draftsText;
    if (!await target.count()) return;

    const selected = await target.getAttribute("aria-selected").catch(() => null);
    const className = await target.getAttribute("class").catch(() => "");
    if (selected === "true" || /active|selected/i.test(className || "")) return;
    await target.click();
    await page.waitForTimeout(2_000);
}

async function referenceForMatchedCandidate(
    page: Page,
    candidate: ReturnType<Page["locator"]>,
    title: string,
    artist: string,
): Promise<DraftMatch> {
    const links = candidate.locator("a[href]");
    for (let linkIndex = 0; linkIndex < await links.count(); linkIndex += 1) {
        const href = await links.nth(linkIndex).getAttribute("href");
        if (!href) continue;
        const absolute = new URL(href, page.url()).toString();
        const draftId = draftIdFromUrl(absolute);
        if (draftId) return { found: true, draft_id: draftId, draft_url: absolute };
    }

    // SoundOn sometimes renders the draft row with JavaScript click handlers
    // and no usable href. A title+artist match is still sufficient to block a
    // duplicate. Store a stable local reference and point operators to the
    // draft list instead of creating the same release again.
    return {
        found: true,
        draft_id: syntheticDraftId(title, artist),
        draft_url: page.url(),
    };
}

export async function findDraft(
    page: Page,
    title: string,
    artist: string,
): Promise<DraftMatch | null> {
    const wantedTitle = normalize(title);
    const wantedArtist = normalize(artist);
    if (!wantedTitle || !wantedArtist) throw new Error("DRAFT_LOOKUP_METADATA_REQUIRED");

    await ensureDraftsView(page);

    // SoundOn's current pagination arrows have no accessible "Next" name.
    // Its draft search is therefore the authoritative and fastest way to
    // inspect every page before creating another release.
    const searched = await searchDraftList(page, title);

    for (let pageNumber = 0; pageNumber < (searched ? 1 : 20); pageNumber += 1) {
        await page.waitForLoadState("domcontentloaded");
        let candidates = page.locator(
            'tr, [role="row"], article, li, [data-testid*="draft" i], [class*="card" i]',
        );
        if (!(await candidates.count())) candidates = page.locator("div");
        const count = Math.min(await candidates.count(), 500);

        for (let index = 0; index < count; index += 1) {
            const candidate = candidates.nth(index);
            if (!await candidate.isVisible().catch(() => false)) continue;
            const rawText = await candidate.innerText().catch(() => "");
            const lines = rawText.split(/\r?\n|\t/).map(normalize).filter(Boolean);
            const titleLinks = candidate.locator("a");
            const linkTexts = await titleLinks.allInnerTexts().catch(() => []);
            const hasExactTitle = lines.includes(wantedTitle)
                || linkTexts.some((value) => normalize(value) === wantedTitle);
            // Artist chips and responsive tables can add labels on the same
            // line (for example "Artist Erik Anis"). Title remains exact,
            // while artist may appear anywhere inside the same release row.
            if (!hasExactTitle || !lines.some((line) => isSameArtistLine(line, wantedArtist))) continue;

            return referenceForMatchedCandidate(page, candidate, wantedTitle, wantedArtist);
        }


        // Final DOM fallback: anchor on the exact title and walk upwards until
        // the enclosing release row/card also contains the requested artist.
        // This covers SoundOn layouts that do not use semantic table rows.
        const titleNodes = page.getByText(title, { exact: true }).filter({ visible: true });
        const titleCount = Math.min(await titleNodes.count(), 20);
        for (let index = 0; index < titleCount; index += 1) {
            let candidate = titleNodes.nth(index);
            for (let depth = 0; depth < 8; depth += 1) {
                const rawText = await candidate.innerText().catch(() => "");
                const lines = rawText.split(/\r?\n|\t/).map(normalize).filter(Boolean);
                if (lines.some((line) => isSameArtistLine(line, wantedArtist))) {
                    return referenceForMatchedCandidate(page, candidate, wantedTitle, wantedArtist);
                }
                candidate = candidate.locator("..");
            }
        }

        const next = page.getByRole("button", { name: /next|berikutnya/i }).or(
            page.getByRole("link", { name: /next|berikutnya/i }),
        ).first();
        if (!await next.isVisible().catch(() => false) || await next.isDisabled().catch(() => true)) break;
        await Promise.all([
            page.waitForLoadState("domcontentloaded").catch(() => undefined),
            next.click(),
        ]);
    }

    return null;
}

export async function findDrafts(
    page: Page,
    lookups: DraftLookup[],
): Promise<Record<string, DraftMatch>> {
    const matches: Record<string, DraftMatch> = {};

    // Query SoundOn's own draft search for every title. This is intentionally
    // sequential to keep browser/RAM usage bounded and avoids inaccessible
    // icon-only pagination controls.
    for (const lookup of lookups) {
        if (!normalize(lookup.title) || !normalize(lookup.artist)) continue;
        const match = await findDraft(page, lookup.title, lookup.artist);
        if (match) matches[lookup.key] = match;
    }
    return matches;
}
