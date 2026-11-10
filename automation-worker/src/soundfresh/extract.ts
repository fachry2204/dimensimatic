import type { Page } from "playwright";
import { mkdir, writeFile } from "node:fs/promises";
import { randomUUID } from "node:crypto";
import path from "node:path";
import type { Registry } from "../selectors/registry.js";
import { locate, readField } from "../selectors/registry.js";

type FieldMap = Record<string, string>;

export const cleanArtist = (value: string | undefined): string =>
    String(value ?? "")
        .replace(/\blanguage\b/gi, "")
        .replace(/\s+/g, " ")
        .trim();

/**
 * Soundfresh renders each primary artist as a separate visual row. textContent
 * merges those rows ("MARCIANOBLEKT3ZNO"), while innerText preserves the line
 * breaks. Keep those boundaries so SoundOn receives individual artist names.
 */
export function normalizePrimaryArtists(value: string | undefined): string {
    return String(value ?? "")
        .split(/\r?\n/)
        .map((artist) => cleanArtist(artist))
        .filter((artist, index, all) => artist.length > 0 && all.indexOf(artist) === index)
        .join(", ");
}

export function preReleaseValue(fields: FieldMap): string | null {
    const entry = Object.entries(fields).find(([label]) => {
        const normalized = label.toLowerCase().replace(/[^a-z0-9]+/g, " ").trim();
        return normalized.includes("pre release") && normalized.includes("date") &&
            (normalized.includes("tiktok") || normalized.includes("youtube"));
    });

    return entry?.[1]?.trim() || null;
}

const parseBoolean = (value: string | undefined): boolean =>
    /^(?:yes|explicit)$/i.test(String(value ?? "").trim());

const normalizeIdentifier = (value: string | undefined): string | null => {
    const normalized = String(value ?? "")
        .trim()
        .replace(/[\s-]+/g, "")
        .toUpperCase();
    return /^[A-Z]{2}[A-Z0-9]{3}\d{7}$/.test(normalized) ? normalized : null;
};

const normalizeUpc = (value: string | undefined): string | null => {
    const normalized = String(value ?? "").trim().replace(/[\s-]+/g, "");
    return /^\d{12,13}$/.test(normalized) ? normalized : null;
};

export async function extractRelease(
    page: Page,
    registry: Registry,
): Promise<Record<string, unknown>> {
    if (registry.soundfresh.configured) {
        return extractFromRegistry(page, registry);
    }

    return extractSoundfreshDetail(page);
}

async function extractFromRegistry(
    page: Page,
    registry: Registry,
): Promise<Record<string, unknown>> {
    const result: Record<string, unknown> = {};
    for (const [key, field] of Object.entries(
        registry.soundfresh.releaseFields,
    ))
        result[key] = await readField(page, field);
    const tracks = [];
    if (registry.soundfresh.tracksContainer) {
        const rows = locate(page, registry.soundfresh.tracksContainer);
        const count = await rows.count();
        for (let index = 0; index < count; index++) {
            const row = rows.nth(index);
            const track: Record<string, unknown> = {};
            for (const [key, field] of Object.entries(
                registry.soundfresh.trackFields,
            ))
                track[key] = await readField(row, field);
            tracks.push(track);
        }
    }
    result.tracks = tracks;
    return result;
}

async function extractSoundfreshDetail(
    page: Page,
): Promise<Record<string, unknown>> {
    const trackInformation = page.getByRole("button", {
        name: "Track Information",
        exact: true,
    });
    if ((await trackInformation.count()) === 1) {
        await trackInformation.click();
        await page.waitForTimeout(300);
    }

    const accordions = page.locator("h2.accordion-header button");
    const accordionCount = Math.min(await accordions.count(), 100);
    for (let index = 0; index < accordionCount; index++) {
        await accordions.nth(index).click();
    }
    if (accordionCount > 0) await page.waitForTimeout(300);

    let clickedCover: { url: string; filename: string; worker_local_path: string } | null = null;
    const coverButton = page
        .getByRole("link", { name: /album cover/i })
        .or(page.getByRole("button", { name: /album cover/i }))
        .first();
    if ((await coverButton.count()) > 0) {
        const downloadPromise = page.waitForEvent("download", { timeout: 30_000 });
        await coverButton.click({ timeout: 15_000 });
        const download = await downloadPromise;
        const directory = path.resolve("storage", "extracted-assets");
        await mkdir(directory, { recursive: true });
        const suggested = download.suggestedFilename() || "cover.jpg";
        const extension = path.extname(suggested).toLowerCase();
        const filename = /\.(?:jpe?g|png)$/i.test(extension) ? suggested : "cover.jpg";
        const destination = path.join(directory, `${randomUUID()}${path.extname(filename) || ".jpg"}`);
        await download.saveAs(destination);
        clickedCover = { url: download.url(), filename, worker_local_path: destination };
    }

    const raw = await page.evaluate(() => {
        const text = (node: Element | null | undefined): string =>
            (node?.textContent ?? "").replace(/\s+/g, " ").trim();
        const primaryArtistText = (node: Element | null | undefined): string =>
            ((node as HTMLElement | null)?.innerText ?? node?.textContent ?? "").trim();
        const rowsToMap = (root: ParentNode): Record<string, string> => {
            const values: Record<string, string> = {};
            for (const row of Array.from(root.querySelectorAll("tr"))) {
                const cells = Array.from(row.querySelectorAll(":scope > td"));
                if (cells.length < 2) continue;
                const key = text(cells[0]);
                const value = /^primary artists?$/i.test(key)
                    ? primaryArtistText(cells[1])
                    : text(cells[1]);
                if (key && value && (!values[key] || values[key].trim() === ""))
                    values[key] = value;
            }
            return values;
        };
        const absoluteUrl = (candidate: string | null): string => {
            if (!candidate) return "";
            try {
                return new URL(candidate, window.location.href).href;
            } catch {
                return "";
            }
        };
        const findDownload = (root: ParentNode, pattern: RegExp) => {
            const anchors = Array.from(root.querySelectorAll("a[href]"));
            const match = anchors.find((anchor) =>
                pattern.test(
                    `${text(anchor)} ${(anchor as HTMLAnchorElement).href}`,
                ),
            ) as HTMLAnchorElement | undefined;
            return match
                ? {
                      url: absoluteUrl(match.getAttribute("href")),
                      filename: text(match),
                  }
                : { url: "", filename: "" };
        };

        const releaseRoot = document.querySelector("main") ?? document;
        const releaseRows: Record<string, string> = {};
        for (const row of Array.from(releaseRoot.querySelectorAll("tr"))) {
            if (row.closest(".accordion-item")) continue;
            const cells = Array.from(row.querySelectorAll(":scope > td"));
            if (cells.length < 2) continue;
            const key = text(cells[0]);
            const value = /^primary artists?$/i.test(key)
                ? primaryArtistText(cells[1])
                : text(cells[1]);
            if (
                key &&
                value &&
                (!releaseRows[key] || releaseRows[key].trim() === "")
            )
                releaseRows[key] = value;
        }

        const cover = findDownload(releaseRoot, /album cover|cover.*download/i);
        if (cover.url && !/\.(?:jpe?g|png)$/i.test(cover.filename)) {
            cover.filename = "cover.jpg";
        }
        if (!cover.url) {
            const coverImage = Array.from(
                releaseRoot.querySelectorAll("img[src]"),
            )
                .filter(
                    (image: any) =>
                        image.naturalWidth >= 500 && image.naturalHeight >= 500,
                )
                .sort(
                    (a: any, b: any) => b.naturalWidth - a.naturalWidth,
                )[0] as HTMLImageElement | undefined;
            if (coverImage) {
                cover.url = absoluteUrl(coverImage.getAttribute("src"));
                cover.filename =
                    cover.url.split("/").pop()?.split("?")[0] || "cover.jpg";
            }
        }

        const trackItems = Array.from(
            releaseRoot.querySelectorAll(".accordion-item"),
        );
        const tracks = trackItems.map((item, index) => {
            const fields = rowsToMap(item);
            const audio = findDownload(
                item,
                /(?:^|\s)track(?:\s|$)|full track/i,
            );
            if (!/\.(?:wav|flac)$/i.test(audio.filename)) {
                const filename = Array.from(item.querySelectorAll("p"))
                    .map(text)
                    .find((value) => /\.(?:wav|flac)$/i.test(value));
                audio.filename = filename ?? `track-${index + 1}.wav`;
            }
            const trimmedAudio = findDownload(item, /trimmed|tiktok\s*cut/i);
            if (!/\.(?:wav|flac)$/i.test(trimmedAudio.filename)) {
                const trimmedFilename = Array.from(item.querySelectorAll("p,span,div"))
                    .map(text)
                    .find((value) => /(?:tiktok|trimmed)[^\n]*\.(?:wav|flac)/i.test(value));
                const filenameMatch = trimmedFilename?.match(/Trimmed Track\s+(.+?\.(?:wav|flac))/i);
                trimmedAudio.filename = filenameMatch?.[1] ?? `track-${index + 1}-trimmed.wav`;
            }
            return { fields, audio, trimmedAudio };
        });

        return {
            title: text(releaseRoot.querySelector("h2.fw-bold")),
            release: releaseRows,
            cover,
            tracks,
        };
    });
    if (clickedCover) raw.cover = clickedCover;

    // PHP/cURL on some Windows installations cannot validate Soundfresh's
    // certificate chain. Download protected audio through the authenticated
    // Playwright context, which is also the session that discovered the URL.
    const assetDirectory = path.resolve("storage", "extracted-assets");
    await mkdir(assetDirectory, { recursive: true });
    const downloads = raw.tracks.flatMap(track => [track.audio, track.trimmedAudio])
        .filter(asset => asset.url && /\.(?:wav|flac)$/i.test(asset.filename));
    let nextDownload = 0;
    // Two transfers per release avoid serially downloading all 22 files of
    // an 11-track album. Assign results to their original objects, never to
    // completion-order indexes, so master and clip remain paired.
    const results = await Promise.allSettled(Array.from({ length: Math.min(2, downloads.length) }, async () => {
        while (nextDownload < downloads.length) {
            const asset = downloads[nextDownload++]!;
            const started = Date.now();
            const response = await page.request.get(asset.url, { timeout: 300_000 });
            try {
                if (!response.ok()) {
                    throw new Error(`ASSET_DOWNLOAD_FAILED: Soundfresh returned HTTP ${response.status()} for ${asset.filename}`);
                }
                const extension = path.extname(asset.filename).toLowerCase();
                const destination = path.join(assetDirectory, `${randomUUID()}${extension}`);
                await writeFile(destination, await response.body());
                Object.assign(asset, { worker_local_path: destination });
                console.info(JSON.stringify({ event: 'soundfresh_asset_download', release: raw.title, file: asset.filename, elapsed_ms: Date.now() - started }));
            } finally {
                // Playwright retains response bodies unless disposed. Large
                // albums otherwise keep every WAV in browser-worker memory.
                await response.dispose();
            }
        }
    }));
    const failure = results.find((result): result is PromiseRejectedResult => result.status === 'rejected');
    if (failure) throw failure.reason;

    const tracks = raw.tracks.map(({ fields, audio, trimmedAudio }, index) => ({
        position: index + 1,
        title: fields.Title ?? `Track ${index + 1}`,
        title_language: fields["Title language"] ?? null,
        language: fields["Lyrics Language"] ?? fields["Title language"] ?? null,
        version: fields.Version ?? null,
        genre: fields.Genre ?? raw.release.Genre ?? null,
        subgenre: fields.Subgenre ?? raw.release.Subgenre ?? null,
        isrc: normalizeIdentifier(fields.ISRC),
        instrumental: parseBoolean(fields.Instrumental),
        explicit: parseBoolean(
            fields["Explicit content"] ??
                fields["Explicit Content"] ??
                fields.Explicit,
        ),
        primary_artist: normalizePrimaryArtists(fields["Primary artists"]),
        featured_artists: cleanArtist(fields["Featured artists"]) || null,
        // Depending on the release type, Soundfresh renders songwriter
        // credits either inside each track accordion or once at release level.
        // Carry the release-level value down so Composer/Lyricist roles are
        // preserved when creating the SoundOn draft.
        songwriters:
            fields.Author || fields.Authors || fields.Songwriter ||
            raw.release.Songwriter || raw.release.Songwriters || raw.release.Author || null,
        contributors: fields.Contributors || null,
        production_contributors: fields["Production / Engineering"] || null,
        audio: {
            url: audio.url,
            filename: audio.filename,
            worker_local_path: (audio as { worker_local_path?: string }).worker_local_path,
        },
        tiktok_audio: {
            url: trimmedAudio.url,
            filename: trimmedAudio.filename,
            worker_local_path: (trimmedAudio as { worker_local_path?: string }).worker_local_path,
        },
    }));

    const releaseType =
        tracks.length <= 1 ? "single" : tracks.length <= 6 ? "ep" : "album";

    return {
        title: raw.title,
        version: raw.release.Version || null,
        primary_artist: normalizePrimaryArtists(raw.release["Primary Artists"]),
        release_type: releaseType,
        genre: raw.release.Genre,
        subgenre: raw.release.Subgenre || null,
        title_language: raw.release["Title Language"] || null,
        language: raw.release["Title Language"] || null,
        release_date: raw.release["Planned Release Date"],
        pre_release_date: preReleaseValue(raw.release),
        upc: normalizeUpc(raw.release.UPC),
        original_release_date:
            raw.release["Original Released Date"] ||
            raw.release["Original Release Date"] ||
            null,
        record_label: raw.release["Record Label"] || null,
        cover: raw.cover,
        tracks,
    };
}
