import { createCipheriv, createDecipheriv, createHash, randomBytes } from "node:crypto";
import { mkdir, readFile, rename, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import type { BrowserContext } from "playwright";

export type SessionState = Awaited<ReturnType<BrowserContext["storageState"]>> & {
    sessionStorage?: Array<{ origin: string; values: Record<string, string> }>;
};
type CachedSession = { state: SessionState; startedAt: number; savedAt: number };
const writes = new Map<string, Promise<void>>();
const encryptionKey = () => process.env.AUTOMATION_HMAC_KEY
    ? createHash("sha256").update(process.env.AUTOMATION_HMAC_KEY).digest() : null;
export const sessionKey = (state: SessionState): string => createHash("sha256").update(JSON.stringify(state)).digest("hex");
const directory = () => resolve(process.env.AUTOMATION_SESSION_CACHE_DIR || "storage/session-cache");

async function readEntry(key: string): Promise<CachedSession | undefined> {
    const secret = encryptionKey();
    if (!secret) return;
    try {
        const bytes = await readFile(resolve(directory(), `${key}.bin`));
        const decipher = createDecipheriv("aes-256-gcm", secret, bytes.subarray(0, 12));
        decipher.setAuthTag(bytes.subarray(12, 28));
        const entry = JSON.parse(Buffer.concat([decipher.update(bytes.subarray(28)), decipher.final()]).toString()) as CachedSession;
        if (Date.now() - entry.savedAt < 14 * 86400_000) return entry;
    } catch { /* A missing, corrupt, or old-key cache falls back to the encrypted DB snapshot. */ }
}

export async function cachedSession(key: string): Promise<SessionState | undefined> {
    await writes.get(key)?.catch(() => undefined);
    return (await readEntry(key))?.state;
}

export async function saveCachedSession(key: string, state: SessionState, startedAt: number): Promise<void> {
    const secret = encryptionKey();
    if (!secret) return;
    const previous = writes.get(key) ?? Promise.resolve();
    const pending = previous.catch(() => undefined).then(async () => {
        const current = await readEntry(key);
        // A slow older operation must not overwrite a newer operation's session.
        if (current && current.startedAt > startedAt) return;
        await mkdir(directory(), { recursive: true });
        const iv = randomBytes(12);
        const cipher = createCipheriv("aes-256-gcm", secret, iv);
        const payload = Buffer.concat([cipher.update(JSON.stringify({ state, startedAt, savedAt: Date.now() })), cipher.final()]);
        const target = resolve(directory(), `${key}.bin`);
        const temp = `${target}.${randomBytes(8).toString("hex")}.tmp`;
        await writeFile(temp, Buffer.concat([iv, cipher.getAuthTag(), payload]), { mode: 0o600 });
        await rename(temp, target);
    });
    writes.set(key, pending);
    try { await pending; } finally { if (writes.get(key) === pending) writes.delete(key); }
}

export async function captureSessionState(context: BrowserContext): Promise<SessionState> {
    const state: SessionState = await context.storageState({ indexedDB: true });
    const origins = new Map<string, Record<string, string>>();
    for (const page of context.pages()) {
        const value = await page.evaluate(() => ({ origin: location.origin, values: { ...sessionStorage } })).catch(() => null);
        if (value && /^https?:/.test(value.origin)) origins.set(value.origin, value.values);
    }
    state.sessionStorage = [...origins].map(([origin, values]) => ({ origin, values }));
    return state;
}
