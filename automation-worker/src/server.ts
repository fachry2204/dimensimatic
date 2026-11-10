import { WORKER_VERSION } from './runtime-version.js';
import { startPublishProgress, getPublishProgress, readPublishProgress } from './soundon/publish-progress.js';
import { collectSoundOnDrafts, draftReferenceFromUrl, openDraftForPublishing, openMatchingSoundOnDraft, waitForPublishLibrary, assertPublishSession, submitSoundOnDraft } from "./soundon/publish-release.js";
import { cmsLoginControls, submitCmsLogin } from "./browser/cms-login.js";
import { captureSessionState } from "./browser/session-cache.js";
import { validatePlatformSession } from "./browser/validate-session.js";
import { baseUrl, loginUrl } from "./cms-urls.js";
﻿import Fastify from "fastify";
import { verifyRequest } from "./security.js";
import { closeContext, context, sessionContext } from "./browser/context-manager.js";
import { soundfreshSelectors as sf } from "./selectors/soundfresh.js";
import { Readable } from "node:stream";
import { authSelectors } from "./selectors/auth.js";
import { assertDraftOnly } from "./guards/draft-only.js";
import { loadRegistry } from "./selectors/registry.js";
import { extractRelease } from "./soundfresh/extract.js";
import { advanceWizardStep, createDraft, enableStandardMonetization, setChoiceField, setPublisherField, setScopedRadio, setSearchField, setTextField, setTikTokPreRelease } from "./soundon/draft-driver.js";
import { findDraft, findDrafts } from "./soundon/draft-finder.js";
import { findReleaseStatuses, publishedStatusByDraftId, selectAllReleasesForConfirmation, readVisiblePublishedStatus } from "./soundon/release-status-finder.js";
import { findLastUpdatedDates } from "./soundon/release-status-finder.js";
import { verifySoundfreshRelease } from "./soundfresh/verify-release.js";
import { rejectSoundfreshRelease } from "./soundfresh/reject-release.js";
import { moveSoundfreshReleaseToUploading } from "./soundfresh/upload-release.js";
import { moveSoundfreshReleaseToUnderReview } from "./soundfresh/review-release.js";
import type { BrowserContext, Locator } from "playwright";
const app = Fastify({
    logger: {
        redact: [
            "req.headers.authorization",
            "req.headers.cookie",
            "body.password",
            "body.session",
        ],
    },
});
const jobs = new Map<
    string,
    { status: string; cancelled: boolean; updatedAt: string }
>();
const activeJobContexts = new Map<string, BrowserContext>();
const activeStatusContexts = new Set<BrowserContext>();
const browserLimitedPaths = new Set([
    "/v1/soundfresh/pending",
    "/v1/soundfresh/releases/extract",
    "/v1/soundon/drafts/list",
    "/v1/soundon/drafts/publish",
    "/v1/soundon/drafts/reconcile",
    "/v1/soundon/drafts/find",
    "/v1/soundon/drafts/find-many",
    "/v1/soundon/reviews",
    "/v1/soundfresh/releases/verify-identifiers",
    "/v1/soundfresh/releases/upload-release",
    "/v1/soundon/drafts/create",
    "/v1/selectors/discover",
]);
const browserConcurrency = Math.max(
    1,
    Number.parseInt(process.env.AUTOMATION_BROWSER_CONCURRENCY ?? "2", 10) || 2,
);
let activeBrowserOperations = 0;
const browserWaiters: Array<() => void> = [];

async function acquireBrowserSlot(): Promise<void> {
    if (activeBrowserOperations < browserConcurrency) {
        activeBrowserOperations += 1;
        return;
    }

    await new Promise<void>((resolve) => {
        browserWaiters.push(() => {
            activeBrowserOperations += 1;
            resolve();
        });
    });
}

function releaseBrowserSlot(req: any): void {
    if (!(req as any).releaseBrowserSlot) return;
    (req as any).releaseBrowserSlot = undefined;
    activeBrowserOperations = Math.max(0, activeBrowserOperations - 1);
    browserWaiters.shift()?.();
}

app.addHook("preHandler", async (req) => {
    const routeUrl = req.routeOptions.url;
    if (!routeUrl || !browserLimitedPaths.has(routeUrl)) return;

    // Status tracking must remain available while a long SoundOn draft upload
    // owns the main automation slot. Both operations use isolated Chromium
    // processes/session contexts, so Under Review discovery is safe to run in
    // parallel with the upload flow.
    if (
        routeUrl === "/v1/soundfresh/pending" &&
        ["under_review", "uploading"].includes((req.body as any)?.options?.release_status)
    ) return;

    await acquireBrowserSlot();
    (req as any).releaseBrowserSlot = true;
});
app.addHook("onResponse", async (req) => releaseBrowserSlot(req));
app.addHook("onError", async (req) => releaseBrowserSlot(req));
app.addHook("preParsing", async (req, reply, payload) => {
    const chunks: Buffer[] = [];
    for await (const chunk of payload) chunks.push(Buffer.from(chunk));
    const raw = Buffer.concat(chunks).toString();
    if (!verifyRequest(req.headers, raw))
        return reply
            .code(401)
            .send({ success: false, error: "INVALID_SIGNATURE" });
    return Readable.from(raw);
});
app.post("/v1/sessions/validate", async (req) => {
    const body = req.body as any;
    const browser = await sessionContext(body.session_state);
    try {
        const page = await browser.newPage();
        const valid = await validatePlatformSession(page, body.platform, body.cms_login_url);
        return { success: true, data: { valid, ...(valid ? { session_state: await captureSessionState(browser) } : {}) } };
    } finally {
        await closeContext(browser);
    }
});
app.post("/v1/sessions/login", async (req, reply) => {
    const body = req.body as any;
    if (
        !["soundfresh", "soundon"].includes(body.platform) ||
        !body.email ||
        !body.password
    )
        return reply
            .code(422)
            .send({ success: false, error: "INVALID_LOGIN_REQUEST" });
    // Show a real browser for OTP/CAPTCHA on desktop so the operator can
    // complete the challenge in the same context whose cookies are persisted.
    // Linux/server deployments remain headless unless explicitly configured.
    const manualHeadful = process.env.BROWSER_MANUAL_LOGIN_HEADFUL === "true"
        || (process.env.BROWSER_MANUAL_LOGIN_HEADFUL !== "false" && process.platform !== "linux");
    const browser = await sessionContext(undefined, { headless: !manualHeadful });
    try {
        const page = await browser.newPage();
        await page.goto(loginUrl(body.platform, body.cms_login_url), {
            waitUntil: "domcontentloaded",
        });
        let passwordInput: Locator;
        if (body.platform === "soundfresh") {
            const controls = await submitCmsLogin(page, body.email, body.password);
            passwordInput = controls.password;
        } else {
            const selectors = authSelectors.soundon;
            await page.getByRole("button", { name: selectors.submit, exact: true })
                .waitFor({ state: "visible", timeout: 20_000 });
            const tab = page.getByRole("tab", { name: selectors.emailTab });
            if ((await tab.count()) === 1) await tab.click();
            const emailInput = page.locator('input[type="email"][name="username"]');
            passwordInput = page.getByPlaceholder(selectors.password, { exact: true });
            await emailInput.fill(body.email);
            await passwordInput.fill(body.password);
            await page.getByRole("button", { name: selectors.submit, exact: true }).click();
        }

        // The login form may submit through JavaScript. Waiting for the current
        // document's load state can resolve immediately and incorrectly report
        // a failure before the authentication response has completed.
        await Promise.race([
            page.waitForURL((url) => !/login|sign-in/i.test(url.pathname), {
                timeout: 20_000,
            }),
            page
                .getByText(authSelectors.challenge)
                .first()
                .waitFor({ state: "visible", timeout: 20_000 }),
            page
                .getByText(authSelectors.invalidCredentials)
                .first()
                .waitFor({ state: "visible", timeout: 20_000 }),
        ]).catch(() => undefined);

        let challenge = await page
            .getByText(authSelectors.challenge)
            .first()
            .isVisible()
            .catch(() => false);
        const authenticatedRoute = (body.platform === "soundfresh"
            ? authSelectors.soundfresh
            : authSelectors.soundon).authenticatedUrl.test(new URL(page.url()).pathname);
        if (challenge && !authenticatedRoute) {
            const deadline = Date.now() + 180_000;
            while (Date.now() < deadline) {
                await page.waitForTimeout(500);
                const path = new URL(page.url()).pathname;
                const formStillVisible = await passwordInput.isVisible().catch(() => false);
                challenge = await page.getByText(authSelectors.challenge).first().isVisible().catch(() => false);
                const nowAuthenticated = (body.platform === "soundfresh"
                    ? authSelectors.soundfresh
                    : authSelectors.soundon).authenticatedUrl.test(path);
                if (!formStillVisible && (nowAuthenticated || (!challenge && !/login|sign-in/i.test(path)))) break;
            }
            const path = new URL(page.url()).pathname;
            const formStillVisible = await passwordInput.isVisible().catch(() => false);
            challenge = await page.getByText(authSelectors.challenge).first().isVisible().catch(() => false);
            const nowAuthenticated = (body.platform === "soundfresh"
                ? authSelectors.soundfresh
                : authSelectors.soundon).authenticatedUrl.test(path);
            if (formStillVisible || (!nowAuthenticated && (challenge || /login|sign-in/i.test(path)))) {
            return {
                success: true,
                data: {
                    manual_auth_required: true,
                    screenshot: await screenshot(page, "manual-auth"),
                },
            };
            }
        }
        const invalidCredentials = await page
            .getByText(authSelectors.invalidCredentials)
            .first()
            .isVisible()
            .catch(() => false);
        if (invalidCredentials || /login|sign-in/i.test(page.url()))
            return reply
                .code(401)
                .send({ success: false, error: "AUTH_INVALID_CREDENTIALS" });
        return {
            success: true,
            data: {
                manual_auth_required: false,
                session_state: await captureSessionState(browser),
                landing_url: page.url(),
            },
        };
    } finally {
        await closeContext(browser);
    }
});
async function collectCmsReleases(req: any, reply: any, progress?: (value: any) => void) {
    const body = req.body as any;
    const requestedStatus = ["under_review", "uploading", "all"].includes(body?.options?.release_status)
        ? body.options.release_status
        : "pending";
    const requestedStatusPattern = requestedStatus === "under_review"
        ? /under\s*review/i
        : requestedStatus === "uploading" ? /upload(?:ing|\s*dsp)/i
        : requestedStatus === "all" ? /all\s*releases/i : /pending/i;
    const browser = await sessionContext(body.session_state);
    try {
        const page = await browser.newPage();
        await page.goto(
            `${baseUrl("soundfresh", body.cms_login_url)}/admin/releases`,
        );
        if (/\/login/i.test(page.url()))
            return reply
                .code(401)
                .send({ success: false, error: "AUTH_SESSION_EXPIRED" });
        const statusTab = page.getByRole("tab", { name: requestedStatusPattern }).or(
            page.getByText(requestedStatusPattern, { exact: true }),
        ).filter({ visible: true }).first();
        await statusTab.click();
        const table = page.locator(`table#${requestedStatus}:visible, table:visible`).filter({ has: page.locator("tbody tr") }).first();
        await table.locator("tbody tr").first().waitFor({ timeout: 20_000 });
        const tableWrapper = table.locator('xpath=ancestor::div[contains(@class,"dataTables_wrapper") or contains(@class,"dt-container")]').first();

        // DataTables defaults to ten rows. Never force page length -1: some
        // Soundfresh deployments accept that API call but keep only ten rows
        // and disable pagination. Select the largest real option instead, then
        // let the pagination loop below visit every remaining page.
        const tableId = await table.getAttribute("id");
        const rowsBeforeExpansion = await table.locator("tbody tr").count();
        const lengthSelect = tableId
            ? page.locator(`select[name="${tableId}_length"]:visible`)
            : page.locator('select[name$="_length"]:visible');
        if (await lengthSelect.count()) {
            const values = await lengthSelect.first().locator("option").evaluateAll(
                (options) => options.map((option) => Number((option as HTMLOptionElement).value)),
            );
            const largest = values
                .filter((value) => Number.isFinite(value) && value > 0)
                .sort((a, b) => b - a)[0];
            if (largest) {
                await lengthSelect.first().selectOption(String(largest));
                if (tableId && largest > rowsBeforeExpansion) {
                    await page.waitForFunction(
                        ({ id, previous }) =>
                            document.querySelectorAll(`#${CSS.escape(id)} tbody tr`).length > previous,
                        { id: tableId, previous: rowsBeforeExpansion },
                        { timeout: 15_000 },
                    ).catch(() => undefined);
                }
                await page.waitForTimeout(300);
            }
        }

        req.log.info({
            tableId,
            visibleRows: await table.locator("tbody tr").count(),
            selectedPageLength: await lengthSelect.count()
                ? await lengthSelect.first().inputValue()
                : null,
        }, "Soundfresh pending table prepared");

        // Pre-upload duplicate checks use the DataTables search field instead
        // of reading every page in All Releases. A release is a duplicate only
        // when its exact title and primary artist occur in the same result row.
        const duplicateLookups = Array.isArray(body?.options?.duplicate_lookups)
            ? body.options.duplicate_lookups as Array<{ key?: unknown; title?: unknown; artist?: unknown }>
            : [];
        if (duplicateLookups.length) {
            const headers = (await table.locator("thead th").allInnerTexts()).map((value) => value.replace(/\s+/g, " ").trim().toLowerCase());
            const albumIndex = headers.findIndex((value) => /album|release/.test(value));
            const artistIndex = headers.findIndex((value) => /primary\s*artists?|artists?/.test(value));
            const normalizeDuplicate = (value: string) => value.normalize("NFKC").trim().replace(/\s+/g, " ").toLocaleLowerCase();
            const searchInput = tableWrapper.locator('input[type="search"]:visible, input[placeholder*="search" i]:visible').first();
            const matches: Record<string, { release_id: string; workflow_status: string }> = {};
            for (const lookup of duplicateLookups) {
                const key = String(lookup.key ?? "");
                const title = String(lookup.title ?? "").trim();
                const artist = String(lookup.artist ?? "").trim();
                if (!key || !title || !artist || !await searchInput.count()) continue;
                await searchInput.fill(title);
                await page.waitForTimeout(350);
                const rows = await table.locator("tbody tr").all();
                for (const row of rows) {
                    const rawCells = await row.locator("td").allInnerTexts();
                    const albumLines = (rawCells[albumIndex >= 0 ? albumIndex : 0] ?? "").split(/\r?\n/).map((value) => value.replace(/\s+/g, " ").trim()).filter(Boolean);
                    const rowTitle = albumLines[0] ?? "";
                    const rowArtist = (rawCells[artistIndex >= 0 ? artistIndex : -1] ?? albumLines[1] ?? "").split(/\r?\n/)[0]?.trim() ?? "";
                    if (normalizeDuplicate(rowTitle) !== normalizeDuplicate(title) || normalizeDuplicate(rowArtist) !== normalizeDuplicate(artist)) continue;
                    const href = await row.getByRole("link", { name: /view/i }).first().getAttribute("href").catch(() => null);
                    const id = (await row.getAttribute("data-id")) ?? href?.match(/\/(\d+)(?:[/?#]|$)/)?.[1] ?? "unknown";
                    matches[key] = { release_id: id, workflow_status: requestedStatus };
                    break;
                }
            }
            return { success: true, data: { matches } };
        }

        const requestedMax = Number(body?.options?.max_items);
        const max = Number.isFinite(requestedMax) && requestedMax > 0
            ? Math.floor(requestedMax)
            : Number.POSITIVE_INFINITY;
        const items: Array<{ release_id: string; title: string; primary_artist: string; detail_url: string; track_count: number; workflow_status: string; release_date: string | null }> = [];
        const seen = new Set<string>();
        let pagesRead = 0;
        let expectedTotal: number | null = null;

        while (items.length < max) {
            // A status tab may expose 100 rows per page but still contain more
            // releases.  Keep a defensive ceiling only to prevent a broken
            // DataTables control from looping forever; it is not a release
            // limit.
            if (pagesRead++ >= 10_000) {
                req.log.warn({ tableId, items: items.length }, "Soundfresh pagination safety limit reached");
                throw new Error("CMS_PAGINATION_LIMIT: Pengambilan Under Review belum lengkap.");
            }
            const headers = (await table.locator("thead th").allInnerTexts()).map((value) =>
                value.replace(/\s+/g, " ").trim().toLowerCase(),
            );
            const trackIndex = headers.findIndex((value) => /number\s*of\s*tracks|tracks?/.test(value));
            const albumIndex = headers.findIndex((value) => /album|release/.test(value));
            const artistIndex = headers.findIndex((value) => /primary\s*artists?|artists?/.test(value));
            const statusIndex = headers.findIndex((value) => /release\s*status|status/.test(value));
            // The list label is "Release Date", while the release detail form
            // calls the same value "Planned Release Date".
            const releaseDateIndex = headers.findIndex((value) => /(?:planned\s*)?release\s*date/.test(value));
            const rows = await table.locator("tbody tr").all();

            for (const row of rows) {
                const rawCells = await row.locator("td").allInnerTexts();
                const cells = rawCells.map((value) =>
                    value.replace(/\s+/g, " ").trim(),
                );
                const trackCount = Number.parseInt(cells[trackIndex >= 0 ? trackIndex : 1] ?? "", 10);
                const status = cells[statusIndex >= 0 ? statusIndex : 2] ?? "";
                const includeMultiTrack = body?.options?.include_multi_track === true;
                // In the Uploading tab Soundfresh labels the row action/status
                // as "Upload DSP", not "Uploading". The selected tab is the
                // source of truth for those rows.
                if (trackCount < 1 || (!includeMultiTrack && trackCount !== 1) || (!['uploading', 'all'].includes(requestedStatus) && !requestedStatusPattern.test(status))) continue;

                const viewLink = row.getByRole("link", { name: /view/i }).first();
                const fallbackLink = row.locator('a[href*="release"], a[href*="/admin/"]').last();
                const link = (await viewLink.count()) ? viewLink : fallbackLink;
                const href = await link.getAttribute("href");
                if (!href) continue;

                const detailUrl = new URL(href, page.url()).toString();
                const releaseId =
                    (await row.getAttribute("data-id")) ??
                    detailUrl.match(/\/(\d+)(?:[/?#]|$)/)?.[1] ??
                    detailUrl;
                if (seen.has(releaseId)) continue;

                const albumCell = row.locator("td").nth(albumIndex >= 0 ? albumIndex : 0);
                const albumLinks = albumCell.locator("a");
                const linkedTitle = (await albumLinks.count())
                    ? (await albumLinks.first().textContent())?.trim()
                    : null;
                const albumLines = (rawCells[albumIndex >= 0 ? albumIndex : 0] ?? "")
                    .split(/\r?\n/).map((value) => value.replace(/\s+/g, " ").trim()).filter(Boolean);
                const title = linkedTitle || albumLines[0] || releaseId;
                const artistCell = artistIndex >= 0 ? (cells[artistIndex] ?? "") : (albumLines[1] ?? "");
                const primaryArtist = artistCell.split(/\r?\n/)[0]?.trim() ?? "";
                const normalizedStatus = status.toLocaleLowerCase().replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "");
                const workflowStatus = /upload(?:ing|\s*dsp)/i.test(status) ? "uploading"
                    : /under\s*review/i.test(status) ? "under_review"
                    : /pending/i.test(status) ? "pending"
                    : /revision/i.test(status) ? "revision"
                    : /reject/i.test(status) ? "rejected"
                    : /publish/i.test(status) ? "published"
                    : /draft/i.test(status) ? "draft"
                    : normalizedStatus;
                const releaseDate = releaseDateIndex >= 0
                    ? (cells[releaseDateIndex] ?? "").trim() || null
                    : null;
                seen.add(releaseId);
                items.push({ release_id: releaseId, title, primary_artist: primaryArtist, detail_url: detailUrl, track_count: trackCount, workflow_status: workflowStatus, release_date: releaseDate });
                if (items.length >= max) break;
            }

            const infoText = await tableWrapper.locator('.dataTables_info, .dt-info').innerText().catch(() => '');
            const totalMatch = infoText.replace(/,/g,'').match(/of\s+(\d+)\s+entries/i);
            if(totalMatch) expectedTotal=Number(totalMatch[1]);
            progress?.({collected:items.length,total:expectedTotal,page:pagesRead,message:`Membaca Under Review CMS halaman ${pagesRead}`});
            const firstRowBefore = await table.locator("tbody tr").first().innerText().catch(() => "");
            const nextCandidates = tableWrapper.locator([
                // DataTables 1.x usually nests an anchor/button inside the
                // *_next element.  DataTables 2 can make *_next itself the
                // button, so include both forms.
                '[id$="_next"]:visible',
                '[id$="_next"] a:visible',
                '[id$="_next"] button:visible',
                'a.paginate_button.next:visible',
                'button.dt-paging-button.next:visible',
                'a[aria-label*="Next" i]:visible',
                'button[aria-label*="Next" i]:visible',
                'a[title*="Next" i]:visible',
                'button[title*="Next" i]:visible',
            ].join(','));
            const textNext = tableWrapper.locator('a:visible, button:visible').filter({ hasText: /^\s*(next|â€º|Â»|>)\s*$/i });
            const directNextCandidates = tableId
                ? page.locator(`#${tableId}_next:visible, #${tableId}_next a:visible, #${tableId}_next button:visible`)
                : page.locator('[id$="_next"]:visible, [id$="_next"] a:visible, [id$="_next"] button:visible');
            const globalNextCandidates = page.locator([
                '[id$="_next"]:visible',
                '[id$="_next"] a:visible',
                '[id$="_next"] button:visible',
                'a.paginate_button.next:visible',
                'button.dt-paging-button.next:visible',
                'a[aria-label*="Next" i]:visible',
                'button[aria-label*="Next" i]:visible',
            ].join(','));
            const next = (await directNextCandidates.count())
                ? directNextCandidates.last()
                : (await nextCandidates.count())
                    ? nextCandidates.last()
                    : (await textNext.count())
                        ? textNext.last()
                        : globalNextCandidates.last();
            const waitForDifferentFirstRow = async () => {
                for (let attempt = 0; attempt < 80; attempt++) {
                    const current = (await table.locator("tbody tr").first().innerText().catch(() => "")).trim();
                    if (current && current !== firstRowBefore.trim()) return true;
                    await page.waitForTimeout(100);
                }
                return false;
            };
            // Do not require a table id here: some Soundfresh accounts render
            // the Uploading table without one. Requiring an id silently ended
            // collection after its first page (often exactly 100 rows).
            const advancedWithDataTables = await table.evaluate((element) => {
                const jquery = (window as any).jQuery;
                if (!jquery?.fn?.DataTable?.isDataTable(element)) return false;
                const dataTable = jquery(element).DataTable();
                const info = dataTable.page.info();
                if (info.page >= info.pages - 1) return false;
                dataTable.page(info.page + 1).draw("page");
                return true;
            }).catch(() => false);
            if (advancedWithDataTables) {
                req.log.info({ tableId, pagesRead, items: items.length }, "Dimensi Suara page advanced through DataTables API");
                if (await waitForDifferentFirstRow()) continue;
            }
            if (advancedWithDataTables) {
                throw new Error("SOUNDFRESH_PAGINATION_STALLED: halaman Dimensi Suara tidak berubah; pengambilan belum lengkap.");
            }
            if (!(await next.count())) break;
            const nextClass = [
                (await next.getAttribute("class")) ?? "",
                (await next.locator("xpath=..").getAttribute("class")) ?? "",
            ].join(" ");
            const disabled = (await next.getAttribute("aria-disabled")) === "true" || /disabled/.test(nextClass);
            if (disabled) break;
            req.log.info({ tableId, pagesRead, items: items.length }, "Dimensi Suara DataTables API unavailable; next-page control fallback applied");
            await next.click({ timeout: 5_000 });
            if (!(await waitForDifferentFirstRow())) {
                throw new Error("SOUNDFRESH_PAGINATION_STALLED: halaman Dimensi Suara tidak berubah; pengambilan belum lengkap.");
            }
        }

        if(expectedTotal!==null && items.length<Math.min(max,expectedTotal) && requestedStatus==='under_review')
            throw new Error(`CMS_RELEASE_LIST_INCOMPLETE: ${items.length} dari ${expectedTotal} Under Review terbaca. Tabel belum diganti; periksa pagination CMS.`);
        req.log.info({
            tableId,
            selectedPageLength: await lengthSelect.count() ? await lengthSelect.first().inputValue() : null,
            pagesRead,
            collectedItems: items.length,
        }, "Soundfresh release collection completed");
        return { success: true, data: { items, has_more: false } };
    } finally {
        await closeContext(browser);
    }
}
app.post("/v1/soundfresh/pending", (req, reply) => collectCmsReleases(req, reply));
app.post("/v1/soundfresh/releases/extract", async (req, reply) => {
    const body = req.body as any;
    const id = String(body.job_id);
    const browser = await sessionContext(body.session_state);
    if (jobs.get(id)?.cancelled) {
        await closeContext(browser);
        return reply.code(409).send({ success: false, error: "JOB_CANCELLED" });
    }
    activeJobContexts.set(id, browser);
    try {
        const page = await browser.newPage();
        await page.goto(body.release_url, { waitUntil: "domcontentloaded" });
        if (/\/login/i.test(page.url()))
            return reply
                .code(401)
                .send({ success: false, error: "AUTH_SESSION_EXPIRED" });
        try {
            const metadata = await extractRelease(page, await loadRegistry());
            jobs.set(id, {
                status: "metadata_extracted",
                cancelled: false,
                updatedAt: new Date().toISOString(),
            });
            return { success: true, data: metadata };
        } catch (error) {
            jobs.set(id, {
                status: "needs_selector_discovery",
                cancelled: false,
                updatedAt: new Date().toISOString(),
            });
            return reply.code(501).send({
                success: false,
                error:
                    error instanceof Error
                        ? error.message
                        : "SELECTOR_DISCOVERY_REQUIRED",
            });
        }
    } finally {
        activeJobContexts.delete(id);
        await closeContext(browser);
    }
});
app.post("/v1/soundon/drafts/create", async (req, reply) => {
    const body = req.body as any;
    const id = String(body.job_id);
    try {
        assertDraftOnly(body.target_action);
    } catch {
        return reply
            .code(403)
            .send({ success: false, error: "DANGEROUS_ACTION_BLOCKED" });
    }
    const metadata = body.metadata ?? {};
    const trackCount = Math.max(
        1,
        Array.isArray(metadata.tracks) ? metadata.tracks.length : 1,
    );
    const isMultiTrackRelease = trackCount > 1;
    const singleCreateUrl = process.env.SOUNDON_CREATE_RELEASE_URL?.trim()
        || `${baseUrl("soundon")}/library/publish/single`;
    const draftsUrl = process.env.SOUNDON_DRAFTS_URL?.trim()
        || `${baseUrl("soundon")}/library/list?type=draft`;
    const browser = await sessionContext(body.session_state);
    if (jobs.get(id)?.cancelled) {
        await closeContext(browser);
        return reply.code(409).send({ success: false, error: "JOB_CANCELLED" });
    }
    activeJobContexts.set(id, browser);
    try {
        const page = await browser.newPage();
        const duplicateTitle = String(metadata.title ?? "").trim();
        const duplicateArtist = String(metadata.primary_artist ?? metadata.artist ?? "").trim();

        // Last, authoritative duplicate guard inside the create request. This
        // closes the race between the queue-level lookup and opening SoundOn's
        // creation form.
        if (body.duplicates_checked !== true && duplicateTitle && duplicateArtist) {
            await page.goto(draftsUrl, { waitUntil: "domcontentloaded" });
            await page.waitForLoadState("networkidle", { timeout: 15_000 }).catch(() => undefined);
            if (/login|sign-in/i.test(page.url())) {
                return reply.code(401).send({ success: false, error: "AUTH_SESSION_EXPIRED" });
            }
            const duplicate = await findDraft(page, duplicateTitle, duplicateArtist);
            if (duplicate) {
                return {
                    success: true,
                    data: { ...duplicate, already_exists: true },
                };
            }
        }

        // Single and EP/Album are different SoundOn products. Never open a
        // multi-track job inside `/publish/single` and then try to morph that
        // form into an album: doing so leaves Single-only modals and steps in
        // the DOM. EP/Album starts from My Releases and explicitly chooses its
        // own release-type card; Single keeps the direct Single form.
        const entryUrl = isMultiTrackRelease ? draftsUrl : singleCreateUrl;
        if (page.url() !== entryUrl) {
            await page.goto(entryUrl, { waitUntil: "domcontentloaded" });
        }
        await page.waitForLoadState("networkidle", { timeout: 15_000 }).catch(() => undefined);
        if (/login|sign-in/i.test(page.url()))
            return reply
                .code(401)
                .send({ success: false, error: "AUTH_SESSION_EXPIRED" });
        try {
            // Keep a bounded watchdog, but scale it for EP/album releases.
            // Each track has its own upload and metadata form; applying the
            // single-release limit to a 12-track album aborts healthy work.
            // Laravel and the queue worker use the same upper bound below.
            // hold the shared SoundOn session, causing every following job to
            // appear stuck.  Abort this page first and return a meaningful
            // worker error while the caller is still connected.
            let watchdog: NodeJS.Timeout | undefined;
            const operation = createDraft(
                page,
                await loadRegistry(),
                metadata,
                body.target_action,
            );
            // Large albums still configure track metadata sequentially in the
            // SoundOn UI. Give the initial asset upload fifteen minutes plus
            // three minutes per track, but never allow one stalled page to
            // block the only automation worker for hours.
            const watchdogMs = Math.max(1_800_000, 900_000 + trackCount * 180_000);
            const deadline = new Promise<never>((_resolve, reject) => {
                watchdog = setTimeout(() => {
                    reject(new Error(`SOUNDON_AUTOMATION_TIMEOUT:Draft processing exceeded ${Math.ceil(watchdogMs / 60_000)} minutes for ${trackCount} track(s); the SoundOn page stopped responding`));
                    void page.close().catch(() => undefined);
                }, watchdogMs);
            });
            return {
                success: true,
                data: await Promise.race([operation, deadline]).finally(() => {
                    if (watchdog) clearTimeout(watchdog);
                }),
            };
        } catch (error) {
            const code =
                error instanceof Error ? error.message : "DRAFT_SAVE_FAILED";
            await screenshot(page, "soundon-draft-error").catch(() => undefined);
            req.log.warn({ code }, "SoundOn draft creation failed");
            return reply
                .code(code.includes("DANGEROUS") ? 403 : 501)
                .send({ success: false, error: code });
        }
    } finally {
        activeJobContexts.delete(id);
        await closeContext(browser);
    }
});
app.post("/v1/soundon/drafts/find", async (req, reply) => {
    const body = req.body as any;
    if (typeof body.title !== "string" || typeof body.artist !== "string") {
        return reply.code(422).send({ success: false, error: "DRAFT_LOOKUP_METADATA_REQUIRED" });
    }

    const configuredUrl = process.env.SOUNDON_DRAFTS_URL?.trim();
    const urls = [configuredUrl || `${baseUrl("soundon")}/library/list?type=draft`];
    const browser = await sessionContext(body.session_state);
    try {
        const page = await browser.newPage();
        for (const url of urls) {
            await page.goto(url, { waitUntil: "domcontentloaded" });
            await page.waitForLoadState("networkidle", { timeout: 15_000 }).catch(() => undefined);
            if (/login|sign-in/i.test(page.url())) {
                return reply.code(401).send({ success: false, error: "AUTH_SESSION_EXPIRED" });
            }
            const match = await findDraft(page, body.title, body.artist);
            if (match) return { success: true, data: match };
        }
        return { success: true, data: { found: false } };
    } finally {
        await closeContext(browser);
    }
});
app.post("/v1/soundon/drafts/find-many", async (req, reply) => {
    const body = req.body as any;
    const lookups = Array.isArray(body.items) ? body.items.filter((item: any) =>
        typeof item?.key === "string" && typeof item?.title === "string" && typeof item?.artist === "string") : [];
    if (!lookups.length) return { success: true, data: { matches: {} } };
    const browser = await sessionContext(body.session_state);
    try {
        const page = await browser.newPage();
        await page.goto(process.env.SOUNDON_DRAFTS_URL?.trim() || `${baseUrl("soundon")}/library/list?type=draft`, { waitUntil: "domcontentloaded" });
        await page.waitForLoadState("networkidle", { timeout: 15_000 }).catch(() => undefined);
        if (/login|sign-in/i.test(page.url())) return reply.code(401).send({ success: false, error: "AUTH_SESSION_EXPIRED" });
        return { success: true, data: { matches: await findDrafts(page, lookups) } };
    } finally {
        await closeContext(browser);
    }
});
type DraftCollectionState = { status: 'queued'|'running'|'completed'|'failed'; collected: number; total: number|null; page: number; message: string; items?: Awaited<ReturnType<typeof collectSoundOnDrafts>>; error?: string; updatedAt: number };
const draftCollections = new Map<string,DraftCollectionState>();
const cmsCollections = new Map<string, Omit<DraftCollectionState, 'items'> & {items?: any[]}>();
app.post('/v1/soundfresh/under-review/collect/start', async (req, reply) => {
    const body=req.body as any;
    if(!/^[a-f0-9-]{36}$/i.test(body.collection_id ?? '')) return reply.code(422).send({success:false,error:'COLLECTION_ID_REQUIRED'});
    for(const [key,value] of cmsCollections) if(Date.now()-value.updatedAt>3600000) cmsCollections.delete(key);
    const id=String(body.collection_id);
    if(cmsCollections.has(id)) return {success:true,data:{collection_id:id}};
    const state:Omit<DraftCollectionState,'items'> & {items?:any[]}={status:'queued',collected:0,total:null,page:0,message:'Menunggu browser CMS',updatedAt:Date.now()};
    cmsCollections.set(id,state);
    void (async()=>{
        await acquireBrowserSlot();
        try {
            state.status='running';state.message='Membaca seluruh Under Review dari CMS';
            const failureReply={code:(_code:number)=>({send:(value:any)=>{throw new Error(value.error || 'CMS_COLLECTION_FAILED');}})};
            const result=await collectCmsReleases({body:{...body,options:{release_status:'under_review',include_multi_track:true,max_items:null}},log:req.log},failureReply,
                value=>Object.assign(state,value,{updatedAt:Date.now()}));
            state.items=result.data.items;state.collected=state.items!.length;state.total=state.collected;
            state.status='completed';state.message='Seluruh Under Review CMS selesai dibaca';
        } catch(error) {state.status='failed';state.error=error instanceof Error ? error.message : 'CMS_COLLECTION_FAILED';}
        finally {state.updatedAt=Date.now();releaseBrowserSlot({releaseBrowserSlot:true});}
    })().catch(error=>{state.status='failed';state.error=String(error);});
    return {success:true,data:{collection_id:id}};
});
app.post('/v1/soundfresh/under-review/collect/status',async(req,reply)=>{
    const state=cmsCollections.get(String((req.body as any).collection_id ?? ''));
    if(!state) return reply.code(404).send({success:false,error:'COLLECTION_NOT_FOUND: Ambil ulang Under Review CMS.'});
    return {success:true,data:state};
});
app.post('/v1/soundon/drafts/collect/start', async (req,reply) => {
    const body=req.body as any;
    if(!body.collection_id || !/^[a-f0-9-]{36}$/i.test(body.collection_id)) return reply.code(422).send({success:false,error:'COLLECTION_ID_REQUIRED'});
    const id=String(body.collection_id);
    for(const [key,value] of draftCollections) if(Date.now()-value.updatedAt>3600000) draftCollections.delete(key);
    if(draftCollections.has(id)) return {success:true,data:{collection_id:id}};
    const state:DraftCollectionState={status:'queued',collected:0,total:null,page:0,message:'Menunggu giliran browser SoundOn',updatedAt:Date.now()};
    draftCollections.set(id,state);
    void (async()=>{
        await acquireBrowserSlot();
        let browser:BrowserContext|undefined;
        try {
            state.status='running';state.message='Membuka halaman draft SoundOn';state.updatedAt=Date.now();
            browser=await sessionContext(body.session_state);
            const page=await browser.newPage();
            await page.goto(process.env.SOUNDON_DRAFTS_URL?.trim() || `${baseUrl('soundon')}/library/list?type=draft`,{waitUntil:'domcontentloaded'});
            await page.waitForLoadState('networkidle',{timeout:15000}).catch(()=>undefined);
            if(/login|sign-in/i.test(page.url())) throw new Error('AUTH_SESSION_EXPIRED');
            state.items=await collectSoundOnDrafts(page,15000,progress=>{Object.assign(state,progress,{updatedAt:Date.now()});});
            state.total ??= state.items.length;
            state.collected=state.items.length;
            state.status='completed';state.message='Seluruh halaman draft selesai dibaca';state.updatedAt=Date.now();
        } catch(error) {state.status='failed';state.error=error instanceof Error ? error.message : 'DRAFT_COLLECTION_FAILED';state.updatedAt=Date.now();}
        finally {if(browser) await closeContext(browser).catch(()=>undefined);releaseBrowserSlot({releaseBrowserSlot:true});}
    })().catch(error=>{state.status='failed';state.error=String(error);state.updatedAt=Date.now();});
    return {success:true,data:{collection_id:id}};
});
app.post('/v1/soundon/drafts/collect/status', async(req,reply)=>{
    const state=draftCollections.get(String((req.body as any).collection_id ?? ''));
    if(!state) return reply.code(404).send({success:false,error:'COLLECTION_NOT_FOUND: Worker dimulai ulang atau hasil kedaluwarsa. Ambil ulang daftar draft.'});
    return {success:true,data:state};
});
app.post("/v1/soundon/drafts/list", async (req, reply) => {
    const body = req.body as any;
    const browser = await sessionContext(body.session_state);
    try {
        const page = await browser.newPage();
        await page.goto(process.env.SOUNDON_DRAFTS_URL?.trim() || `${baseUrl("soundon")}/library/list?type=draft`, {waitUntil:"domcontentloaded"});
        await page.waitForLoadState('networkidle',{timeout:15000}).catch(()=>undefined);
        if (/login|sign-in/i.test(page.url())) return reply.code(401).send({success:false,error:'AUTH_SESSION_EXPIRED'});
        return {success:true,data:{items:await collectSoundOnDrafts(page)}};
    } finally { await closeContext(browser); }
});
app.get('/v1/runtime', async () => ({success:true,data:{version:WORKER_VERSION,capabilities:['publish-reconcile','submit-receipt-v2']}}));
// Read-only recovery after an interrupted HTTP response. Never opens a draft or clicks Submit.
app.post('/v1/soundon/drafts/reconcile', async (req, reply) => {
    const body=req.body as any;
    if(typeof body.title !== 'string' || !body.title.trim() || typeof body.artist !== 'string' || !body.artist.trim() || typeof body.draft_id !== 'string')
        return reply.code(422).send({success:false,error:'PUBLISH_SELECTION_REQUIRED'});
    const browser=await sessionContext(body.session_state);
    try {
        const page=await browser.newPage();
        await page.goto(process.env.SOUNDON_RELEASES_URL?.trim() || `${baseUrl('soundon')}/library/list`,{waitUntil:'domcontentloaded'});
        await waitForPublishLibrary(page,20000);
        await selectAllReleasesForConfirmation(page);
        const lookup=[{key:'selected',title:body.title,artist:body.artist}];
        const acceptable=(value:any)=>{
            if(!value || !['under_review','approved','delivery','live'].includes(value.status)) return false;
            const url=new URL(value.release_url);
            const id=url.searchParams.get('id') ?? url.pathname.match(/(?:draft|release)\/([^/]+)$/i)?.[1];
            return body.draft_id.startsWith('cms-') ? !!id : id===body.draft_id;
        };
        let match=(await findReleaseStatuses(page,lookup,true,true,true)).selected;
        for(let i=0;i<10 && !acceptable(match);i++) {
            await page.waitForTimeout(1500);
            await assertPublishSession(page);
            match=await readVisiblePublishedStatus(page,body.title,body.artist) ?? match;
        }
        return {success:true,data:{published:acceptable(match),status:acceptable(match)?match!.status:'unconfirmed',confirmation:'read_only_reconciliation',worker_version:WORKER_VERSION}};
    } catch(error) {
        return reply.code(422).send({success:false,error:error instanceof Error?error.message:'SOUNDON_RECONCILE_FAILED'});
    } finally {await closeContext(browser);}
});
app.post('/v1/soundon/drafts/publish/progress',async(req)=>({success:true,data:{progress:readPublishProgress((req.body as any).ids)}}));
app.post("/v1/soundon/drafts/publish", async (req, reply) => {
    const body = req.body as any;
    if (!['publish_selected_draft','publish_matching_draft'].includes(body.action) || !body.draft_id || !body.title || !body.artist)
        return reply.code(422).send({success:false,error:'PUBLISH_SELECTION_REQUIRED'});
    let target: URL;
    try { target=new URL(body.action === 'publish_matching_draft' ? `${baseUrl('soundon')}/library/list` : body.draft_url); } catch { return reply.code(422).send({success:false,error:'DRAFT_URL_REQUIRED'}); }
    if (target.origin !== new URL(baseUrl('soundon')).origin || !/^\/library\//.test(target.pathname))
        return reply.code(403).send({success:false,error:'DOMAIN_NOT_ALLOWED'});
    if(body.progress_id && !/^[a-f0-9-]{36}$/i.test(body.progress_id)) return reply.code(422).send({success:false,error:'INVALID_PROGRESS_ID'});
    const reportProgress=startPublishProgress(body.progress_id);
    const browser = await sessionContext(body.session_state);
    try {
        const page = await browser.newPage();
        const releasesUrl=process.env.SOUNDON_RELEASES_URL?.trim() || `${baseUrl('soundon')}/library/list`;
        // A retry after an interrupted response must not publish twice.
        await page.goto(releasesUrl,{waitUntil:'domcontentloaded'});
        await waitForPublishLibrary(page,20000);
        const lookup=[{key:'selected',title:String(body.title),artist:String(body.artist)}];
        let resolvedDraftId=String(body.draft_id);
        const matching=body.action==='publish_matching_draft';
        const matchesSelected = (value: any): boolean => {
            if (!value || !['under_review','approved','delivery','live'].includes(value.status)) return false;
            const reference = new URL(value.release_url);
            const referenceId = reference.searchParams.get('id') ?? reference.pathname.match(/(?:draft|release)\/([^/]+)$/i)?.[1];
            return (matching && resolvedDraftId.startsWith('cms-') && !!referenceId) || !referenceId || referenceId === resolvedDraftId;
        };
        await selectAllReleasesForConfirmation(page);
        reportProgress(15,'Memeriksa status SoundOn sebelum publish');
        let match=(await findReleaseStatuses(page,lookup,true,true,matching)).selected;
        // A remembered Draft tab can briefly leave stale Draft rows while the
        // All releases request is loading. Read the settled result before a retry.
        for(let attempt=0;attempt<6 && !matchesSelected(match);attempt++) {
            await page.waitForTimeout(400);
            await assertPublishSession(page);
            match=await readVisiblePublishedStatus(page,String(body.title),String(body.artist)) ?? match;
        }
        if (matchesSelected(match)) {
            reportProgress(85,'Publish SoundOn sudah terkonfirmasi');
            return {success:true,data:{published:true,status:match!.status,progress:getPublishProgress(body.progress_id)}};
        }
        reportProgress(20,'Membuka daftar Draft SoundOn');
        const storedEditor=draftReferenceFromUrl(target.href,new URL(baseUrl('soundon')).origin);
        await page.goto(storedEditor && !String(body.draft_id).startsWith('existing-') ? target.href
            : process.env.SOUNDON_DRAFTS_URL?.trim() || `${baseUrl('soundon')}/library/list?type=draft`,{waitUntil:'domcontentloaded'});
        resolvedDraftId=matching
            ? await openMatchingSoundOnDraft(page,String(body.title),String(body.artist),20000,reportProgress)
            : await openDraftForPublishing(page,{draft_id:String(body.draft_id),draft_url:target.href,title:String(body.title),artist:String(body.artist)});
        await page.waitForLoadState('networkidle',{timeout:15000}).catch(()=>undefined);
        reportProgress(40,'Editor draft siap, melanjutkan wizard');
        const receipt=await submitSoundOnDraft(page,30000,reportProgress);
        if(receipt.uiConfirmed) {
            reportProgress(85,'SoundOn mengonfirmasi penerimaan Submit');
            return {success:true,data:{published:true,status:'under_review',confirmation:'soundon_ui',next_clicks:receipt.nextClicks,progress:getPublishProgress(body.progress_id)}};
        }
        reportProgress(80,'Memeriksa penerimaan Submit di All releases ('+receipt.nextClicks+' kali Next selesai)');
        // Keep the selected editor alive: navigating it can cancel the request
        // SoundOn is still processing. Confirmation uses a separate browser tab.
        const confirmationPage=await browser.newPage();
        const loadConfirmation=async()=>{
            await confirmationPage.goto(releasesUrl,{waitUntil:'domcontentloaded'});
            await waitForPublishLibrary(confirmationPage,20000);
            await selectAllReleasesForConfirmation(confirmationPage);
            return (await findReleaseStatuses(confirmationPage,lookup,true,true,matching)).selected;
        };
        match=await loadConfirmation();
        for (let attempt=0; attempt<30; attempt++) {
            await assertPublishSession(confirmationPage);
            match=(matching ? await publishedStatusByDraftId(confirmationPage,resolvedDraftId) : null)
                ?? await readVisiblePublishedStatus(confirmationPage,String(body.title),String(body.artist)) ?? match;
            if (matchesSelected(match)) break;
            await confirmationPage.waitForTimeout(1500);
            // Give the current list request time to render. Refresh only after
            // several reads, rather than repeatedly cancelling its loading state.
            if(attempt===9 || attempt===19)match=await loadConfirmation();
        }
        if (!matchesSelected(match)) {
            const rows=(await confirmationPage.locator('tr:visible,[role="row"]:visible,[class*="card"]:visible').allInnerTexts()).slice(0,3).map(value=>value.replace(/\s+/g,' ').slice(0,240));
            throw new Error('SOUNDON_PUBLISH_NOT_CONFIRMED: Next '+receipt.nextClicks+'/3 dan Submit telah diklik, tetapi penerimaan belum terkonfirmasi. CMS belum diubah. Hasil All releases='+JSON.stringify(rows));
        }
        reportProgress(85,'Publish SoundOn terkonfirmasi');
        return {success:true,data:{published:true,status:match!.status,progress:getPublishProgress(body.progress_id)}};
    } catch (error) {
        return reply.code(422).send({success:false,error:error instanceof Error ? error.message : 'SOUNDON_PUBLISH_FAILED',data:{progress:getPublishProgress(body.progress_id)}});
    } finally { await closeContext(browser); }
});
app.post("/v1/soundon/reviews", async (req, reply) => {
    const body = req.body as any;
    const lookups = Array.isArray(body.items) ? body.items.filter((item: any) =>
        typeof item?.key === "string" && typeof item?.title === "string" && typeof item?.artist === "string") : [];
    if (!lookups.length) return { success: true, data: { matches: {} } };
    const browser = await sessionContext(body.session_state);
    activeStatusContexts.add(browser);
    try {
        const page = await browser.newPage();
        await page.goto(process.env.SOUNDON_RELEASES_URL?.trim() || baseUrl("soundon") + "/library/list", { waitUntil: "domcontentloaded" });
        await page.waitForLoadState("networkidle", { timeout: 15_000 }).catch(() => undefined);
        if (/login|sign-in/i.test(page.url())) return reply.code(401).send({ success: false, error: "AUTH_SESSION_EXPIRED" });
        const matches = await findLastUpdatedDates(page, lookups);
        return { success: true, data: { matches } };
    } catch (error) {
        const code = error instanceof Error ? error.message : "SOUNDON_REVIEW_FETCH_FAILED";
        req.log.warn({ code }, "SoundOn review list collection failed");
        return reply.code(501).send({ success: false, error: code });
    } finally {
        activeStatusContexts.delete(browser);
        await closeContext(browser);
    }
});
app.post("/v1/soundon/releases/statuses", async (req, reply) => {
    const body = req.body as any;
    const lookups = Array.isArray(body.items) ? body.items.filter((item: any) =>
        typeof item?.key === "string" && typeof item?.title === "string" && typeof item?.artist === "string") : [];
    if (!lookups.length) return { success: true, data: { matches: {} } };
    const browser = await sessionContext(body.session_state);
    activeStatusContexts.add(browser);
    try {
        const page = await browser.newPage();
        await page.goto(process.env.SOUNDON_RELEASES_URL?.trim() || `${baseUrl("soundon")}/library/list`, { waitUntil: "domcontentloaded" });
        await page.waitForLoadState("networkidle", { timeout: 15_000 }).catch(() => undefined);
        if (/login|sign-in/i.test(page.url())) return reply.code(401).send({ success: false, error: "AUTH_SESSION_EXPIRED" });
        req.log.info({
            url: page.url(),
            tabs: await page.getByRole("tab").allInnerTexts().catch(() => []),
            buttons: (await page.getByRole("button").allInnerTexts().catch(() => [])).slice(0, 30),
            placeholders: await page.locator("input:visible").evaluateAll((inputs) => inputs.map((input) => input.getAttribute("placeholder") || input.getAttribute("aria-label") || input.getAttribute("type") || "")),
            sampleRows: (await page.locator('tr:visible, [role="row"]:visible').allInnerTexts().catch(() => [])).slice(0, 5),
        }, "SoundOn release status page prepared");
        return { success: true, data: { matches: await findReleaseStatuses(page, lookups, body.strict_artist === true) } };
    } finally {
        activeStatusContexts.delete(browser);
        await closeContext(browser);
    }
});
app.post("/v1/soundfresh/releases/review", async (req, reply) => {
    const body = req.body as any;
    if (body.aggregator !== "SoundOn" || !body.draft_id)
        return reply
            .code(422)
            .send({ success: false, error: "DRAFT_VERIFICATION_REQUIRED" });
    let target: URL;
    try {
        target = new URL(String(body.release_url ?? ""));
    } catch {
        return reply.code(422).send({ success: false, error: "SOUNDFRESH_RELEASE_URL_REQUIRED" });
    }
    if (target.origin !== new URL(baseUrl("soundfresh", body.cms_login_url)).origin)
        return reply.code(403).send({ success: false, error: "DOMAIN_NOT_ALLOWED" });

    const browser = await sessionContext(body.session_state);
    try {
        const page = await browser.newPage();
        await page.goto(target.href, { waitUntil: "domcontentloaded" });
        await page.waitForLoadState("networkidle", { timeout: 15_000 }).catch(() => undefined);
        if (/login|sign-in/i.test(page.url()))
            return reply.code(401).send({ success: false, error: "AUTH_SESSION_EXPIRED" });
        await moveSoundfreshReleaseToUnderReview(page, "SoundOn", String(body.draft_id));
        return { success: true, data: { reviewed: true, status: "under_review" } };
    } catch (error) {
        const code = error instanceof Error ? error.message : "SOUNDFRESH_REVIEW_FAILED";
        req.log.warn({ code, releaseUrl: target.href }, "Soundfresh review release failed");
        return reply.code(422).send({ success: false, error: code });
    } finally {
        await closeContext(browser);
    }
});
app.post("/v1/soundfresh/releases/verify-identifiers", async (req, reply) => {
    const body = req.body as any;
    const releaseUrl = String(body.release_url ?? "");
    const upc = String(body.upc ?? "").trim();
    const isrcs = Array.isArray(body.isrcs)
        ? body.isrcs.map((value: unknown) => String(value).trim().toUpperCase()).filter(Boolean)
        : [];
    let target: URL;
    try {
        target = new URL(releaseUrl);
    } catch {
        return reply.code(422).send({ success: false, error: "SOUNDFRESH_RELEASE_URL_REQUIRED" });
    }
    if (target.origin !== new URL(baseUrl("soundfresh", body.cms_login_url)).origin)
        return reply.code(403).send({ success: false, error: "DOMAIN_NOT_ALLOWED" });
    if (!/^\d{12,13}$/.test(upc) || !isrcs.length || isrcs.some((isrc: string) => !/^[A-Z]{2}[A-Z0-9]{3}\d{7}$/.test(isrc)))
        return reply.code(422).send({ success: false, error: "UPC_ISRC_VALIDATION_FAILED" });

    const browser = await sessionContext(body.session_state);
    try {
        const page = await browser.newPage();
        await page.goto(target.href, { waitUntil: "domcontentloaded" });
        await page.waitForLoadState("networkidle", { timeout: 15_000 }).catch(() => undefined);
        if (/login|sign-in/i.test(page.url()))
            return reply.code(401).send({ success: false, error: "AUTH_SESSION_EXPIRED" });
        await verifySoundfreshRelease(page, { upc, isrcs });
        return { success: true, data: { verified: true } };
    } catch (error) {
        const code = error instanceof Error ? error.message : "SOUNDFRESH_VERIFY_FAILED";
        req.log.warn({ code, releaseUrl }, "Soundfresh release verification failed");
        return reply.code(501).send({ success: false, error: code });
    } finally {
        await closeContext(browser);
    }
});
app.post("/v1/soundfresh/releases/reject", async (req, reply) => {
    const body = req.body as any;
    const reason = String(body.reason ?? "").trim();
    let target: URL;
    try {
        target = new URL(String(body.release_url ?? ""));
    } catch {
        return reply.code(422).send({ success: false, error: "SOUNDFRESH_RELEASE_URL_REQUIRED" });
    }
    if (target.origin !== new URL(baseUrl("soundfresh", body.cms_login_url)).origin)
        return reply.code(403).send({ success: false, error: "DOMAIN_NOT_ALLOWED" });
    if (!reason) return reply.code(422).send({ success: false, error: "REJECTION_REASON_REQUIRED" });

    const browser = await sessionContext(body.session_state);
    try {
        const page = await browser.newPage();
        await page.goto(target.href, { waitUntil: "domcontentloaded" });
        await page.waitForLoadState("networkidle", { timeout: 15_000 }).catch(() => undefined);
        if (/login|sign-in/i.test(page.url()))
            return reply.code(401).send({ success: false, error: "AUTH_SESSION_EXPIRED" });
        await rejectSoundfreshRelease(page, reason);
        return { success: true, data: { rejected: true } };
    } catch (error) {
        const code = error instanceof Error ? error.message : "SOUNDFRESH_REJECT_FAILED";
        req.log.warn({ code, releaseUrl: target.href }, "Soundfresh release rejection failed");
        return reply.code(422).send({ success: false, error: code });
    } finally {
        await closeContext(browser);
    }
});
app.post("/v1/soundfresh/releases/upload-release", async (req, reply) => {
    const body = req.body as any;
    let target: URL;
    try {
        target = new URL(String(body.release_url ?? ""));
    } catch {
        return reply.code(422).send({ success: false, error: "SOUNDFRESH_RELEASE_URL_REQUIRED" });
    }
    if (target.origin !== new URL(baseUrl("soundfresh", body.cms_login_url)).origin)
        return reply.code(403).send({ success: false, error: "DOMAIN_NOT_ALLOWED" });

    const browser = await sessionContext(body.session_state);
    try {
        const page = await browser.newPage();
        await page.goto(target.href, { waitUntil: "domcontentloaded" });
        await page.waitForLoadState("networkidle", { timeout: 15_000 }).catch(() => undefined);
        if (/login|sign-in/i.test(page.url()))
            return reply.code(401).send({ success: false, error: "AUTH_SESSION_EXPIRED" });
        await moveSoundfreshReleaseToUploading(page);
        return { success: true, data: { status: "uploading" } };
    } catch (error) {
        const code = error instanceof Error ? error.message : "SOUNDFRESH_UPLOAD_RELEASE_FAILED";
        req.log.warn({ code, releaseUrl: target.href }, "Soundfresh Upload Release failed");
        return reply.code(501).send({ success: false, error: code });
    } finally {
        await closeContext(browser);
    }
});
app.post("/v1/selectors/discover", async (req, reply) => {
    const body = req.body as any;
    if (!["soundfresh", "soundon"].includes(body.platform))
        return reply
            .code(422)
            .send({ success: false, error: "INVALID_PLATFORM" });
    const target = new URL(String(body.url));
    const allowed = new URL(baseUrl(body.platform, body.cms_login_url));
    if (target.origin !== allowed.origin)
        return reply
            .code(403)
            .send({ success: false, error: "DOMAIN_NOT_ALLOWED" });
    const browser = await sessionContext(body.session_state);
    try {
        const page = await browser.newPage();
        await page.goto(target.href, { waitUntil: "domcontentloaded" });
        await page.waitForTimeout(body.platform === "soundon" ? 8_000 : 3_000);
        if (/login|sign-in/i.test(page.url()))
            return reply
                .code(401)
                .send({ success: false, error: "AUTH_SESSION_EXPIRED" });
        if (body.platform === "soundfresh") {
            const trackInformation = page.getByRole("button", {
                name: "Track Information",
                exact: true,
            });
            if ((await trackInformation.count()) === 1) {
                await trackInformation.click();
                await page.waitForTimeout(500);
            }
            const trackAccordions = page.locator("h2.accordion-header button");
            const trackCount = Math.min(await trackAccordions.count(), 10);
            for (let index = 0; index < trackCount; index++) {
                await trackAccordions.nth(index).click();
            }
            if (trackCount > 0) await page.waitForTimeout(500);
        }
        if (body.platform === "soundon") {
            const maybeLater = page.getByRole("button", {
                name: "Maybe later",
                exact: true,
            });
            if ((await maybeLater.count()) === 1) {
                await maybeLater.click();
                await page.waitForTimeout(500);
            }
            if (
                [
                    "#discover-upload",
                    "#discover-album",
                    "#discover-album-track",
                    "#discover-single",
                    "#discover-single-genre",
                    "#discover-single-language",
                    "#discover-single-language-thai",
                    "#discover-single-artist",
                    "#discover-single-explicit",
                    "#discover-single-contributors",
                    "#discover-single-production",
                    "#discover-single-lyricists",
                    "#discover-pre-release",
                    "#discover-single-label",
                    "#discover-publishing",
                    "#discover-monetization",
                ].includes(target.hash)
            ) {
                const uploadMusic = page.getByRole("button", {
                    name: /^(Upload|Upload your music)$/i,
                });
                if ((await uploadMusic.count()) === 1) {
                    await uploadMusic.click();
                    await page.waitForTimeout(2_000);
                }
            }
            if (["#discover-album", "#discover-album-track"].includes(target.hash)) {
                const album = page
                    .getByRole("button", { name: /^(?:EP\s*\/\s*Album|Album|EP)$/i })
                    .or(page.getByText(/^(?:EP\s*\/\s*Album|Album|EP)$/i, { exact: true }))
                    .filter({ visible: true })
                    .first();
                if (!(await album.count())) {
                    throw new Error(`SOUNDON_UI_CHANGED:ep_album_option:url=${page.url()}`);
                }
                await album.click({ timeout: 20_000 });
                await page.waitForTimeout(5_000);
            }
            if (target.hash === "#discover-album-track") {
                await setTextField(page, "Title", "SoundMatic Album Test");
                await setChoiceField(page, "Title language", "English");
                await setChoiceField(page, "Genre", "Pop");
                await setSearchField(page, "Primary artists", "RND");
                const coverPath = target.searchParams.get("cover") ?? "";
                if (!coverPath) throw new Error("DISCOVERY_ASSET_MISSING:cover");
                const coverInput = page.locator('input[type="file"].semi-upload-hidden-input').first();
                await coverInput.setInputFiles(coverPath);
                await page.waitForTimeout(3_000);
                const newRelease = page.getByText(/No, I want to distribute a new release/i).first();
                if (await newRelease.count()) await newRelease.click({ force: true });
                await advanceWizardStep(page);
                await page.getByText("Track information", { exact: true })
                    .filter({ visible: true }).first()
                    .waitFor({ state: "visible", timeout: 30_000 });
                const audioPaths = [target.searchParams.get("audio"), target.searchParams.get("audio2")]
                    .filter((value): value is string => Boolean(value));
                if (audioPaths.length) {
                    for (const audioPath of audioPaths) {
                        const chooserPromise = page.waitForEvent("filechooser");
                        await page.getByRole("button", { name: "Add Tracks", exact: true }).click();
                        const chooser = await chooserPromise;
                        await chooser.setFiles(audioPath);
                        await page.waitForTimeout(12_000);
                    }
                }
            }
            if (
                ["#discover-single", "#discover-single-genre", "#discover-single-language", "#discover-single-language-thai", "#discover-single-artist", "#discover-single-explicit", "#discover-single-contributors", "#discover-single-production", "#discover-single-lyricists", "#discover-single-label", "#discover-pre-release", "#discover-publishing", "#discover-monetization"].includes(
                    target.hash,
                )
            ) {
                const singleSong = page.getByText("Single Song", {
                    exact: true,
                });
                if ((await singleSong.count()) === 1) {
                    await singleSong.click();
                    await page.waitForTimeout(3_000);
                }
            }
            if (target.hash === "#discover-single-genre") {
                const genreLabel = page
                    .locator(".soundon-formik-field-label")
                    .filter({ hasText: /^Genre$/ })
                    .first();
                if (await genreLabel.count()) {
                    let container = genreLabel.locator("..");
                    for (let depth = 0; depth < 4; depth++) {
                        const controls = container.locator(
                            'input:not([type="hidden"]),[role="combobox"],.semi-select-content-wrapper',
                        );
                        if (await controls.count()) {
                            await controls.first().click({ force: true });
                            await page.waitForTimeout(500);
                            break;
                        }
                        container = container.locator("..");
                    }
                }
            }
            if (target.hash === "#discover-single-language") {
                const languageLabel = page
                    .locator(".soundon-formik-field-label")
                    .filter({ hasText: /^Title language$/ })
                    .first();
                if (await languageLabel.count()) {
                    let container = languageLabel.locator("..");
                    for (let depth = 0; depth < 4; depth++) {
                        const control = container.locator('input:not([type="hidden"]),[role="combobox"]');
                        if (await control.count()) {
                            await control.first().click();
                            await page.waitForTimeout(500);
                            break;
                        }
                        container = container.locator("..");
                    }
                }
            }
            if (target.hash === "#discover-single-language-thai") {
                await setChoiceField(page, "Title language", "à¹„à¸—à¸¢ (Thai)");
            }
            if (target.hash === "#discover-single-artist") {
                const artistLabel = page
                    .locator(".soundon-formik-field-label")
                    .filter({ hasText: /^Primary artists$/ })
                    .first();
                if (await artistLabel.count()) {
                    let container = artistLabel.locator("..");
                    for (let depth = 0; depth < 5; depth++) {
                        const add = container.getByRole("button", { name: "Add", exact: true });
                        if (await add.count()) {
                            await add.first().click();
                            await page.waitForTimeout(500);
                            const dialog = page.locator("dialog:visible").last();
                            const select = dialog.locator(
                                ".semi-select-content-wrapper:visible,[role=combobox]:visible",
                            );
                            if (await select.count()) {
                                await select.first().click({ force: true });
                                const input = dialog.locator('input[type="text"]:visible');
                                if (await input.count()) {
                                    await input.first().fill("Rytma Band");
                                    await page.waitForTimeout(1_000);
                                    const create = page.locator(
                                        ".semi-select-option-list-outer-bottom-slot:visible",
                                    );
                                    if (await create.count()) {
                                        await create.first().click({ force: true });
                                        await page.waitForTimeout(1_000);
                                        const createProfiles = dialog
                                            .locator("label.semi-radio")
                                            .filter({
                                                hasText: "Create and link to a new artist ID",
                                            });
                                        for (
                                            let profile = 0;
                                            profile < (await createProfiles.count());
                                            profile++
                                        ) {
                                            await createProfiles.nth(profile).click();
                                        }
                                        await page.waitForTimeout(500);
                                        const submit = dialog.getByRole("button", {
                                            name: "Submit",
                                            exact: true,
                                        });
                                        if (await submit.count()) {
                                            await submit.first().click({ force: true });
                                            await page.waitForTimeout(5_000);
                                        }
                                    }
                                }
                            }
                            break;
                        }
                        container = container.locator("..");
                    }
                }
            }
            if (
                ["#discover-single-contributors", "#discover-single-production", "#discover-single-lyricists"].includes(
                    target.hash,
                )
            ) {
                if (target.hash === "#discover-single-lyricists") {
                    await setSearchField(
                        page,
                        "Songwriters",
                        "Hendie Hari Syaputra",
                        false,
                    );
                }
                const wantedLabel =
                    target.hash === "#discover-single-contributors"
                        ? "Contributors"
                        : target.hash === "#discover-single-lyricists"
                          ? "Lyricists"
                        : "Production and additional contributors";
                const discoveryEntries = target.hash === "#discover-single-contributors"
                    ? [{ name: "Syafriadi,.S.Sos", role: "Vocalist" }]
                    : target.hash === "#discover-single-lyricists"
                      ? [{ name: "Hendie Hari Syaputra", role: "Songwriter" }]
                      : [
                          { name: "Thya", role: "Producer" },
                          { name: "Ihsan NR", role: "Creative Director" },
                          { name: "Sauqia", role: "Studio" },
                          { name: "Firda NA", role: "Vocal designer" },
                      ];
                for (const entry of discoveryEntries) {
                    await setSearchField(
                        page,
                        wantedLabel,
                        entry.name,
                        target.hash === "#discover-single-lyricists",
                        0,
                        entry.role,
                    );
                }
            }
            if (target.hash === "#discover-single-label") {
                await setTextField(page, "Record label", "Dimensi Suara", false);
            }
            if (target.hash === "#discover-pre-release") {
                const releaseSettings = page
                    .getByText("Release Settings", { exact: true })
                    .filter({ visible: true });
                if (await releaseSettings.count()) {
                    await releaseSettings.first().click({ force: true });
                    await page.waitForTimeout(1_000);
                }
                await setTextField(page, "Planned release date", "2026-08-31", true);
                await setTextField(page, "Release date", "2026-08-31", true);
                await setTikTokPreRelease(page, "2026-08-22");
                await advanceWizardStep(page);
                const moreReleaseOptions = page
                    .getByText("More release options", { exact: true })
                    .filter({ visible: true });
                await moreReleaseOptions.first().click({ force: true });
                await page.waitForTimeout(1_000);
                await enableStandardMonetization(page, "2026-08-22");
                await advanceWizardStep(page);

                // Re-open both steps after they were committed. This catches
                // values that only appeared in the DOM but were not retained
                // by SoundOn's controlled form state.
                const back = page.getByRole("button", { name: "Back", exact: true })
                    .filter({ visible: true });
                await back.last().click();
                await page.waitForTimeout(700);
                await page.getByText("Monetization options", { exact: true })
                    .filter({ visible: true }).first().click({ force: true });
                const youtubeDate = page.getByText("Pre-release date", { exact: true })
                    .filter({ visible: true }).last();
                await youtubeDate.waitFor({ state: "visible", timeout: 10_000 });
                const youtubeValues = await page.locator('input:visible')
                    .evaluateAll((inputs) => inputs.map((input) => (input as HTMLInputElement).value));
                const youtubeMidnight = page.getByText(/12:00\s*AM|00:00/i)
                    .filter({ visible: true });
                if (!youtubeValues.includes("2026-08-22") ||
                    !(await youtubeMidnight.count())) {
                    throw new Error(`SOUNDON_PRE_RELEASE_NOT_COMMITTED:youtube:${youtubeValues.join("|")}`);
                }
                await screenshot(page, "selector-soundon-youtube-pre-release");

                await back.last().click();
                await page.getByText("TikTok pre-release", { exact: true })
                    .filter({ visible: true }).first()
                    .waitFor({ state: "visible", timeout: 10_000 });
                const tiktokValues = await page.locator('input:visible')
                    .evaluateAll((inputs) => inputs.map((input) => (input as HTMLInputElement).value));
                const tiktokMidnight = page.getByText(/12:00\s*AM|00:00/i)
                    .filter({ visible: true });
                if (!tiktokValues.includes("2026-08-22") ||
                    !(await tiktokMidnight.count())) {
                    throw new Error(`SOUNDON_PRE_RELEASE_NOT_COMMITTED:tiktok:${tiktokValues.join("|")}`);
                }
            }
            if (target.hash === "#discover-publishing") {
                const moreReleaseOptions = page
                    .getByText("More release options", { exact: true })
                    .filter({ visible: true });
                if (await moreReleaseOptions.count()) {
                    await moreReleaseOptions.first().click({ force: true });
                    await page.waitForTimeout(2_000);
                }
                const publishing = page
                    .getByText("Publishing rights", { exact: true })
                    .filter({ visible: true });
                await publishing
                    .first()
                    .waitFor({ state: "visible", timeout: 10_000 });
                await setPublisherField(page, "Dimensi Suara");
                await setScopedRadio(
                    page,
                    "What percentage of this track was written or composed by you?",
                    "100%",
                );
                await setScopedRadio(
                    page,
                    "Does this track have an existing publishing administrator or publisher?",
                    "No",
                );
            }
            if (target.hash === "#discover-monetization") {
                const moreReleaseOptions = page
                    .getByText("More release options", { exact: true })
                    .filter({ visible: true });
                if (await moreReleaseOptions.count()) {
                    await moreReleaseOptions.first().click({ force: true });
                    await page.waitForTimeout(1_000);
                }
                await setPublisherField(page, "Dimensi Suara");
                await setScopedRadio(
                    page,
                    "What percentage of this track was written or composed by you?",
                    "100%",
                );
                await setScopedRadio(
                    page,
                    "Does this track have an existing publishing administrator or publisher?",
                    "No",
                );
                await enableStandardMonetization(page);
                await page.waitForTimeout(1_000);
            }
            if (target.hash === "#discover-single-explicit") {
                const instrumental = page
                    .locator(".soundon-formik-field-label")
                    .filter({ hasText: /^Instrumental$/ })
                    .first();
                if (await instrumental.count()) {
                    let container = instrumental.locator("..");
                    for (let depth = 0; depth < 4; depth++) {
                        const no = container.getByText("No", { exact: true });
                        if (await no.count()) {
                            await no.first().click();
                            await page.waitForTimeout(500);
                            break;
                        }
                        container = container.locator("..");
                    }
                }
                const explicit = page
                    .locator(".soundon-formik-field-label")
                    .filter({ hasText: /^Explicit$/ })
                    .first();
                if (await explicit.count()) {
                    let container = explicit.locator("..");
                    for (let depth = 0; depth < 4; depth++) {
                        const control = container.locator(
                            'input:not([type="hidden"]),[role="combobox"],.semi-select-content-wrapper',
                        );
                        if (await control.count()) {
                            await control.first().click({ force: true });
                            await page.waitForTimeout(500);
                            break;
                        }
                        container = container.locator("..");
                    }
                }
            }
            if (target.hash === "#discover-release-settings") {
                const next = page.getByRole("button", {
                    name: "Next",
                    exact: true,
                });
                if (await next.count()) {
                    await next.first().click({ timeout: 5_000 }).catch(() => undefined);
                    await page.waitForTimeout(1_000);
                }
                const later = page.getByRole("button", {
                    name: "Later",
                    exact: true,
                });
                if (await later.count()) {
                    await later.first().click({ timeout: 5_000 }).catch(() => undefined);
                    await page.waitForTimeout(2_000);
                }
                const releaseSettings = page
                    .getByText("Release Settings", { exact: true })
                    .filter({ visible: true });
                if (await releaseSettings.count()) {
                    await releaseSettings
                        .first()
                        .evaluate((element: HTMLElement) => element.click())
                        .catch(() => undefined);
                    await page.waitForTimeout(3_000);
                }
                const releaseDateLabel = page
                    .locator(".soundon-formik-field-label:visible")
                    .filter({ hasText: /Release date|Planned release date/i })
                    .first();
                if (await releaseDateLabel.count()) {
                    const dateInput = releaseDateLabel
                        .locator("..")
                        .locator('input[type="text"]:visible')
                        .first();
                    if (await dateInput.count()) {
                        await dateInput.click({ force: true });
                        await page.waitForTimeout(1_000);
                    }
                }
            }
        }
        const controls = await page.evaluate(() =>
            Array.from(
                document.querySelectorAll(
                    'input,select,textarea,button,[role="button"],[role="tab"],[role="listbox"],[role="option"],.semi-select-option-list *',
                ),
            )
                .slice(0, 250)
                .map((node: any) => ({
                    tag: node.tagName.toLowerCase(),
                    type: node.getAttribute("type"),
                    checked:
                        typeof node.checked === "boolean"
                            ? node.checked
                            : null,
                    disabled:
                        typeof node.disabled === "boolean"
                            ? node.disabled
                            : null,
                    name: node.getAttribute("name"),
                    placeholder: node.getAttribute("placeholder"),
                    value:
                        "value" in node && typeof node.value === "string"
                            ? node.value
                            : null,
                    id: node.id || null,
                    testId: node.getAttribute("data-testid"),
                    role: node.getAttribute("role"),
                    className:
                        typeof node.className === "string"
                            ? node.className.slice(0, 180)
                            : null,
                    label:
                        node.getAttribute("aria-label") ||
                        node.labels?.[0]?.textContent?.trim() ||
                        null,
                    text:
                        node.tagName.toLowerCase() === "button" ||
                        node.getAttribute("role")
                            ? node.textContent?.trim().slice(0, 120)
                            : null,
                })),
        );
        const textBlocks = await page.evaluate(() =>
            Array.from(
                document.querySelectorAll(
                    "main h1,main h2,main h3,main h4,main h5,main p,main dt,main dd,main th,main td,main label,main [class*='label'],main [class*='value'],.content-wrapper h1,.content-wrapper h2,.content-wrapper h3,.content-wrapper p,.content-wrapper dt,.content-wrapper dd,.content-wrapper th,.content-wrapper td,.content-wrapper label,.content-wrapper [class*='label'],.content-wrapper [class*='value'],.semi-portal *",
                ),
            )
                .filter((node: any) => {
                    const style = window.getComputedStyle(node);
                    return (
                        style.display !== "none" &&
                        style.visibility !== "hidden" &&
                        node.getBoundingClientRect().width > 0 &&
                        node.getBoundingClientRect().height > 0
                    );
                })
                .slice(0, 500)
                .map((node: any) => ({
                    tag: node.tagName.toLowerCase(),
                    id: node.id || null,
                    className:
                        typeof node.className === "string"
                            ? node.className.slice(0, 180)
                            : null,
                    text: node.textContent
                        ?.replace(/\s+/g, " ")
                        .trim()
                        .slice(0, 500),
                }))
                .filter((node: any) => node.text),
        );
        const links = await page.evaluate(() =>
            Array.from(document.querySelectorAll("a[href]"))
                .filter((node: any) => {
                    const style = window.getComputedStyle(node);
                    return (
                        style.display !== "none" &&
                        style.visibility !== "hidden"
                    );
                })
                .slice(0, 300)
                .map((node: any) => {
                    const url = new URL(node.href, window.location.href);
                    return {
                        text: node.textContent
                            ?.replace(/\s+/g, " ")
                            .trim()
                            .slice(0, 180),
                        origin: url.origin,
                        path: url.pathname,
                        className:
                            typeof node.className === "string"
                                ? node.className.slice(0, 180)
                                : null,
                        download: node.getAttribute("download"),
                    };
                }),
        );
        return {
            success: true,
            data: {
                url: page.url(),
                title: await page.title(),
                controls,
                textBlocks,
                links,
                screenshot: await screenshot(page, `selector-${body.platform}`),
            },
        };
    } finally {
        await closeContext(browser);
    }
});
app.get("/v1/jobs/:jobId", async (req, reply) => {
    const state = jobs.get((req.params as any).jobId);
    return state
        ? { success: true, data: state }
        : reply.code(404).send({ success: false, error: "JOB_NOT_FOUND" });
});
app.post("/v1/jobs/:jobId/cancel", async (req) => {
    const id = (req.params as any).jobId;
    const current = jobs.get(id) ?? {
        status: "unknown",
        cancelled: false,
        updatedAt: new Date().toISOString(),
    };
    jobs.set(id, {
        ...current,
        status: "cancelled",
        cancelled: true,
        updatedAt: new Date().toISOString(),
    });
    const active = activeJobContexts.get(id);
    if (active) {
        await closeContext(active);
        if (activeJobContexts.get(id) === active) activeJobContexts.delete(id);
    }
    return { success: true, data: jobs.get(id) };
});
app.post("/v1/jobs/:jobId/prepare", async (req, reply) => {
    const id = String((req.params as any).jobId);
    if (activeJobContexts.has(id)) {
        return reply.code(409).send({ success: false, error: "JOB_ALREADY_RUNNING" });
    }
    jobs.set(id, { status: "ready", cancelled: false, updatedAt: new Date().toISOString() });
    return { success: true, data: jobs.get(id) };
});
app.post("/v1/jobs/cancel-all", async () => {
    const contexts = [...new Set([...activeJobContexts.values(), ...activeStatusContexts.values()])];
    activeJobContexts.clear();
    activeStatusContexts.clear();
    await Promise.allSettled(contexts.map((context) => closeContext(context)));
    return { success: true, data: { cancelled_contexts: contexts.length } };
});
async function screenshot(page: any, prefix: string): Promise<string> {
    const { mkdir } = await import("node:fs/promises");
    const { resolve } = await import("node:path");
    const dir = resolve("storage", "screenshots");
    await mkdir(dir, { recursive: true });
    const path = resolve(dir, `${prefix}-${Date.now()}.png`);
    await page.screenshot({ path, fullPage: true });
    return path;
}
app.listen({ host: "127.0.0.1", port: Number(process.env.PORT ?? 3112) });
