import type { Page } from "playwright";
import { baseUrl } from "../cms-urls.js";
import { authSelectors } from "../selectors/auth.js";
import { cmsLoginControls } from "./cms-login.js";

export async function validatePlatformSession(page: Page, platform: string, cmsLoginUrl?: string): Promise<boolean> {
    if (!["soundfresh", "soundon"].includes(platform)) throw new Error("INVALID_PLATFORM");
    const url = platform === "soundon"
        ? process.env.SOUNDON_RELEASES_URL?.trim() || `${baseUrl(platform)}/library/list`
        : `${baseUrl(platform, cmsLoginUrl)}/admin/releases`;
    const response = await page.goto(url, { waitUntil: "domcontentloaded" });
    if (response && response.status() >= 400) throw new Error("SESSION_VALIDATION_UNAVAILABLE");
    await page.waitForTimeout(1500);
    await page.waitForLoadState("networkidle", { timeout: 10_000 }).catch(() => undefined);
    if (await page.getByText(authSelectors.challenge).first().isVisible().catch(() => false)) {
        throw new Error("SESSION_VALIDATION_CHALLENGE");
    }
    const password = platform === "soundon"
        ? page.getByPlaceholder(authSelectors.soundon.password, { exact: true })
        : cmsLoginControls(page).password;
    if (await password.isVisible().catch(() => false) || /login|sign-in/i.test(new URL(page.url()).pathname)) return false;
    const route = platform === "soundon" ? /^\/(?:library|dashboard|releases|catalog)(?:\/|$)/i : authSelectors.soundfresh.authenticatedUrl;
    if (!route.test(new URL(page.url()).pathname)) throw new Error("SESSION_VALIDATION_INCONCLUSIVE");
    return true;
}
