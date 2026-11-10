import { afterEach, beforeEach, expect, it } from "vitest";
import { mkdtemp, readFile, readdir, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { closeContext, sessionContext } from "./context-manager.js";
import { cachedSession, captureSessionState, saveCachedSession, sessionKey } from "./session-cache.js";
import { validatePlatformSession } from "./validate-session.js";
import type { BrowserContext } from "playwright";

let directory: string;
let contexts: BrowserContext[];
let oldKey: string | undefined;
let oldDirectory: string | undefined;
beforeEach(async () => {
    contexts = [];
    directory = await mkdtemp(join(tmpdir(), "dimensimatic-sessions-"));
    oldKey = process.env.AUTOMATION_HMAC_KEY;
    oldDirectory = process.env.AUTOMATION_SESSION_CACHE_DIR;
    process.env.AUTOMATION_HMAC_KEY = "test-session-secret";
    process.env.AUTOMATION_SESSION_CACHE_DIR = directory;
});
afterEach(async () => {
    for (const context of contexts) await closeContext(context);
    await rm(directory, { recursive: true, force: true });
    if (oldKey === undefined) delete process.env.AUTOMATION_HMAC_KEY; else process.env.AUTOMATION_HMAC_KEY = oldKey;
    if (oldDirectory === undefined) delete process.env.AUTOMATION_SESSION_CACHE_DIR; else process.env.AUTOMATION_SESSION_CACHE_DIR = oldDirectory;
});

it("reuses rotated cookies and browser storage across isolated browser operations", async () => {
    const first = await sessionContext(undefined, { headless: true });
    contexts.push(first);
    await first.route("https://session.test/**", route => route.fulfill({ body: "<main>Authenticated</main>", contentType: "text/html" }));
    const page = await first.newPage();
    await page.goto("https://session.test/dashboard");
    await page.evaluate(async () => {
        localStorage.setItem("auth", "initial-local");
        sessionStorage.setItem("auth", "initial-tab");
        await new Promise<void>((resolve, reject) => {
            const request = indexedDB.open("authentication", 1);
            request.onupgradeneeded = () => request.result.createObjectStore("tokens");
            request.onsuccess = () => {
                const transaction = request.result.transaction("tokens", "readwrite");
                transaction.objectStore("tokens").put("idb-token", "auth");
                transaction.oncomplete = () => { request.result.close(); resolve(); };
            };
            request.onerror = () => reject(request.error);
        });
    });
    await first.addCookies([{ name: "login", value: "initial-cookie", url: "https://session.test" }]);
    const snapshot = await captureSessionState(first);
    // The application continues to send its original encrypted DB snapshot.
    const operation = await sessionContext(snapshot, { headless: true });
    contexts.push(operation);
    await operation.route("https://session.test/**", route => route.fulfill({ body: "<main>Authenticated</main>" }));
    const active = await operation.newPage();
    await active.goto("https://session.test/dashboard");
    expect(await active.evaluate(() => sessionStorage.getItem("auth"))).toBe("initial-tab");
    await operation.addCookies([{ name: "login", value: "rotated-cookie", url: "https://session.test" }]);
    await active.evaluate(() => { localStorage.setItem("auth", "rotated-local"); sessionStorage.setItem("auth", "rotated-tab"); });
    await closeContext(operation);
    contexts.splice(contexts.indexOf(operation), 1);
    const next = await sessionContext(snapshot, { headless: true });
    contexts.push(next);
    await next.route("https://session.test/**", route => route.fulfill({ body: "<main>Authenticated</main>" }));
    const resumed = await next.newPage();
    await resumed.goto("https://session.test/dashboard");
    expect((await next.cookies()).find(cookie => cookie.name === "login")?.value).toBe("rotated-cookie");
    expect(await resumed.evaluate(() => [localStorage.getItem("auth"), sessionStorage.getItem("auth")])).toEqual(["rotated-local", "rotated-tab"]);
    expect((await captureSessionState(next)).origins[0]).toMatchObject({ indexedDB: expect.any(Array) });
    const bytes = await readFile(join(directory, `${sessionKey(snapshot)}.bin`));
    expect(bytes.includes(Buffer.from("rotated-cookie"))).toBe(false);
    expect(bytes.includes(Buffer.from("rotated-tab"))).toBe(false);
}, 20_000);

it("keeps a newer session when an older concurrent operation finishes last", async () => {
    const original = { cookies: [], origins: [] };
    const key = sessionKey(original);
    await saveCachedSession(key, { ...original, sessionStorage: [{ origin: "https://test", values: { token: "newer" } }] }, 200);
    await saveCachedSession(key, original, 100);
    expect((await cachedSession(key))?.sessionStorage?.[0]?.values.token).toBe("newer");
    expect(await readdir(directory)).toEqual([`${key}.bin`]);
});

it("validates the protected library without visiting a login page", async () => {
    const context = await sessionContext(undefined, { headless: true });
    contexts.push(context);
    const paths: string[] = [];
    await context.route("https://www.soundon.global/**", route => {
        paths.push(new URL(route.request().url()).pathname);
        return route.fulfill({ body: "<main>All releases</main>" });
    });
    expect(await validatePlatformSession(await context.newPage(), "soundon")).toBe(true);
    expect(paths).toContain("/library/list");
    expect(paths).not.toContain("/login");
}, 15_000);

it("treats a server failure as inconclusive rather than an expired session", async () => {
    const context = await sessionContext(undefined, { headless: true });
    contexts.push(context);
    await context.route("https://www.soundon.global/**", route => route.fulfill({ status: 503, body: "Unavailable" }));
    await expect(validatePlatformSession(await context.newPage(), "soundon")).rejects.toThrow("SESSION_VALIDATION_UNAVAILABLE");
});

it("only reports expiry when a protected page redirects to the login form", async () => {
    const context = await sessionContext(undefined, { headless: true });
    contexts.push(context);
    await context.route("https://www.soundon.global/**", route => new URL(route.request().url()).pathname === '/library/list'
        ? route.fulfill({ status: 302, headers: { location: '/login' } })
        : route.fulfill({ body: '<input placeholder="Password" type="password">' }));
    expect(await validatePlatformSession(await context.newPage(), 'soundon')).toBe(false);
}, 15_000);

it("keeps CAPTCHA validation inconclusive so the saved session is not discarded", async () => {
    const context = await sessionContext(undefined, { headless: true });
    contexts.push(context);
    await context.route("https://www.soundon.global/**", route => route.fulfill({ body: '<p>Security verification</p>' }));
    await expect(validatePlatformSession(await context.newPage(), 'soundon')).rejects.toThrow('SESSION_VALIDATION_CHALLENGE');
}, 15_000);
