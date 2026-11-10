import {
    chromium,
    type BrowserContext,
    type BrowserContextOptions,
} from "playwright";
import { mkdir } from "node:fs/promises";
import { resolve } from "node:path";
import { cachedSession, captureSessionState, saveCachedSession, sessionKey, type SessionState } from "./session-cache.js";
const contextBrowsers = new WeakMap<BrowserContext, Awaited<ReturnType<typeof chromium.launch>>>();
const contextSessions = new WeakMap<BrowserContext, { key?: string; startedAt: number; restored?: SessionState }>();
const launchOptions = () => ({
    headless: process.env.BROWSER_HEADLESS !== "false",
    ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE
        ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE }
        : {}),
});
export async function context(
    platform: "soundfresh" | "soundon",
): Promise<BrowserContext> {
    const dir = resolve("storage", "profiles", platform);
    await mkdir(dir, { recursive: true });
    const browserContext = await chromium.launchPersistentContext(dir, {
        ...launchOptions(),
        acceptDownloads: true,
    });
    await browserContext.addInitScript("window.__name = window.__name || function(fn) { return fn; };");
    return browserContext;
}
export async function sessionContext(
    state?: BrowserContextOptions["storageState"],
    options: { headless?: boolean } = {},
): Promise<BrowserContext> {
    const startedAt = Date.now();
    const key = state && typeof state !== "string" ? sessionKey(state) : undefined;
    const restored = key ? await cachedSession(key) ?? state as SessionState : undefined;
    const browser = await chromium.launch({
        ...launchOptions(),
        ...(options.headless === undefined ? {} : { headless: options.headless }),
    });
    try {
        const browserContext = await browser.newContext({
            storageState: restored ? { cookies: restored.cookies, origins: restored.origins } : state,
            acceptDownloads: true,
        });
        await browserContext.addInitScript("window.__name = window.__name || function(fn) { return fn; };");
        if (restored?.sessionStorage?.length) {
            await browserContext.addInitScript((entries) => {
                const entry = entries.find(item => item.origin === location.origin);
                if (entry) for (const [name, value] of Object.entries(entry.values)) {
                    if (sessionStorage.getItem(name) === null) sessionStorage.setItem(name, value);
                }
            }, restored.sessionStorage);
        }
        contextBrowsers.set(browserContext, browser);
        contextSessions.set(browserContext, { key, startedAt, restored });

        return browserContext;
    } catch (error) {
        await browser.close().catch(() => undefined);
        throw error;
    }
}

/** Close the isolated context and the Chromium process that owns it. */
export async function closeContext(browserContext: BrowserContext): Promise<void> {
    const browser = contextBrowsers.get(browserContext);
    contextBrowsers.delete(browserContext);
    const session = contextSessions.get(browserContext);
    contextSessions.delete(browserContext);
    if (session) {
        try {
            const state = await captureSessionState(browserContext);
            // Keep origins from pages already closed by a multi-page operation.
            state.sessionStorage = [...new Map([...(session.restored?.sessionStorage ?? []), ...(state.sessionStorage ?? [])].map(item => [item.origin, item])).values()];
            await saveCachedSession(session.key ?? sessionKey(state), state, session.startedAt);
        } catch {
            console.warn("Session cache could not be updated; the saved database session remains available.");
        }
    }

    await browserContext.close().catch(() => undefined);
    if (browser) {
        await browser.close().catch(() => undefined);
    }
}
