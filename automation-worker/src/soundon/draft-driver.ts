import type { Locator, Page } from "playwright";
import { basename } from "node:path";
import type { Registry } from "../selectors/registry.js";
import { locate, readField, valueAt } from "../selectors/registry.js";
import { assertDraftOnly } from "../guards/draft-only.js";
import { findDraft } from "./draft-finder.js";
import { orderedAlbumTracks, assertAlbumOrder } from "./album-order.js";

const forbidden = /submit|publish|distribute|send for review|release now/i;
const entityResultTimeout = 20_000;
const entitySubmitTimeout = 8_000;
const entitySaveTimeout = 12_000;
const entityPersistTimeout = 6_000;
const entityMaxRetries = 1;
const skippedFields = new WeakMap<Page, string[]>();
const optionalFieldWarnings = new WeakMap<Page, string[]>();

export function canSkipEntityError(message: string): boolean {
    return /^SOUNDON_UI_CHANGED:(blocking_dialog|stale_dialog):/.test(message)
        || (/^SOUNDON_ENTITY_MATCH_REQUIRED:/.test(message)
            && /:(options_unavailable|submit_disabled|role_unavailable)(:|$)/.test(message));
}

export function assertNoSkippedFields(page: Page): void {
    const skipped = skippedFields.get(page) ?? [];
    if (skipped.length) throw new Error(`SOUNDON_REQUIRED_FIELD_UNRESOLVED:Isian wajib belum terkonfirmasi tersimpan pada form. Isian: ${[...new Set(skipped)].join('; ')}`);
}

export async function skipBlockedEntity(page: Page, field: string, value: string, optional = false): Promise<void> {
    const dialogs = page.locator('.so-form-modal-dialog:visible');
    for (let attempt = 0; attempt < 3 && await dialogs.count(); attempt++) {
        const dialog = dialogs.last();
        await page.keyboard.press('Escape').catch(() => undefined);
        const dismiss = dialog.getByRole('button', { name: /^(Cancel|Close)$/i })
            .or(dialog.locator('.so-form-modal-title-close, .semi-modal-close, [aria-label="Close"]')).filter({ visible: true }).last();
        if (await dismiss.count()) await dismiss.evaluate((element: HTMLElement) => element.click());
        await dialog.waitFor({ state: 'hidden', timeout: 1500 }).catch(() => undefined);
    }
    if (await dialogs.count()) throw new Error(`SOUNDON_FORM_VALIDATION:Dialog masih menghalangi ${field}; tidak aman melanjutkan ke kolom lain`);
    if (optional) {
        const warnings = optionalFieldWarnings.get(page) ?? [];
        optionalFieldWarnings.set(page, [...warnings, `${field}: ${value}`]);
        console.warn(JSON.stringify({ event: 'optional_metadata_field_skipped', field, value, source: 'SoundOn' }));
        return;
    }
    skippedFields.set(page, [...(skippedFields.get(page) ?? []), `${field}: ${value}`]);
    console.warn(JSON.stringify({ event: 'required_metadata_field_unresolved', field, value, source: 'SoundOn' }));
}

export async function selectCreateArtistProfile(dialog: Locator): Promise<boolean> {
    const options = dialog.locator("label.semi-radio").filter({ hasText: "Create and link to a new artist ID" });
    let selectedAny = false;
    for (let index = 0; index < await options.count(); index++) {
        const option = options.nth(index);
        const radio = option.locator('input[type="radio"]');
        const isSelected = async () => {
            if (await radio.count() && await radio.isChecked().catch(() => false)) return true;
            return (await option.getAttribute("aria-checked")) === "true"
                || /semi-radio-checked/.test((await option.getAttribute("class")) ?? "");
        };
        const alreadySelected = await isSelected();
        if (!alreadySelected) {
            if (await radio.count()) await radio.check({ force: true }).catch(() => option.click({ force: true }));
            else await option.click({ force: true });
        }
        await dialog.page().waitForTimeout(300);
        if (!await isSelected()) return false;
        selectedAny = true;
    }
    return selectedAny;
}

export async function createDraft(
    page: Page,
    registry: Registry,
    metadata: Record<string, any>,
    action: unknown,
): Promise<{ draft_id: string; draft_url: string; warnings?: string[] }> {
    assertDraftOnly(action);

    let draft: { draft_id: string; draft_url: string };
    if (registry.soundon.configured) {
        draft = await createFromRegistry(page, registry, metadata);
    } else {
        draft = await createKnownSoundOnDraft(page, metadata);
    }

    const warnings = [...new Set(optionalFieldWarnings.get(page) ?? [])];
    return warnings.length ? { ...draft, warnings } : draft;
}

async function createFromRegistry(
    page: Page,
    registry: Registry,
    metadata: Record<string, unknown>,
): Promise<{ draft_id: string; draft_url: string }> {
    if (!registry.soundon.createButton || !registry.soundon.saveDraftButton)
        throw new Error("SELECTOR_DISCOVERY_REQUIRED");
    for (const spec of [
        registry.soundon.createButton,
        registry.soundon.saveDraftButton,
    ])
        if (spec.strategy === "role" && forbidden.test(spec.name))
            throw new Error("DANGEROUS_SELECTOR_BLOCKED");
    const create = locate(page, registry.soundon.createButton);
    if ((await create.count()) !== 1) throw new Error("SELECTOR_NOT_UNIQUE");
    await resilientClick(create);
    for (const [path, field] of Object.entries(registry.soundon.fields)) {
        const target = locate(page, field.selector);
        if ((await target.count()) !== 1)
            throw new Error(`SELECTOR_NOT_UNIQUE:${path}`);
        const value = valueAt(metadata, path);
        if (field.type === "checkbox") await target.setChecked(Boolean(value));
        else if (field.type === "select")
            await target.selectOption({ label: String(value ?? "") });
        else if (field.type === "file")
            await target.setInputFiles(String(value ?? ""));
        else await target.fill(String(value ?? ""));
    }
    const save = locate(page, registry.soundon.saveDraftButton);
    if ((await save.count()) !== 1)
        throw new Error("SELECTOR_NOT_UNIQUE:saveDraft");
    await resilientClick(save);
    await page.waitForLoadState("domcontentloaded");
    const draftId = registry.soundon.draftIdSelector
        ? await readField(page, registry.soundon.draftIdSelector)
        : (new URL(page.url()).pathname.split("/").filter(Boolean).pop() ?? "");
    if (!draftId) throw new Error("DRAFT_REFERENCE_NOT_FOUND");
    return { draft_id: draftId, draft_url: page.url() };
}

async function createKnownSoundOnDraft(
    page: Page,
    metadata: Record<string, any>,
): Promise<{ draft_id: string; draft_url: string }> {
    const tracks = Array.isArray(metadata.tracks) ? metadata.tracks : [];
    if (tracks.length === 0) throw new Error("METADATA_MISSING:tracks");

    const soundOnPreReleaseDate = resolveSoundOnPreReleaseDate(
        metadata.pre_release_date,
        metadata.release_date,
    );

    if (tracks.length > 1) return createKnownSoundOnAlbumDraft(page, metadata, tracks);

    await openSingleReleaseForm(page);

    const track = tracks[0];
    await setSectionFile(
        page,
        "#form-section-track-audios",
        String(track.audio?.local_path ?? ""),
        "audio",
    );
    const tikTokPath = String(track.tiktok_audio?.local_path ?? "");
    if (tikTokPath) {
        await setTikTokAudio(page, tikTokPath);
    }

    await setTextField(
        page,
        "Title",
        String(track.title ?? metadata.title ?? ""),
    );
    await setTextField(
        page,
        "English title",
        String(track.english_title ?? ""),
        true,
    );
    await setTextField(page, "Version", normalizeVersion(track.version), true);
    await setChoiceField(
        page,
        "Title language",
        normalizeLanguage(track.title_language ?? track.language),
        true,
    );
    await setChoiceField(
        page,
        "Genre",
        normalizeGenre(track.genre ?? metadata.genre),
        true,
    );
    await setChoiceField(
        page,
        "Subgenre",
        normalizeSubgenre(track.subgenre ?? metadata.subgenre),
        true,
    );
    await setScopedRadio(
        page,
        "Instrumental",
        track.instrumental ? "Yes" : "No",
    );
    let lyricsLanguageSet = track.instrumental
        ? true
        : await setChoiceIfPresent(
              page,
              ["Lyrics language", "Lyrics Language"],
              normalizeLanguage(track.language),
          );
    let explicitSet = track.instrumental
        ? true
        : await setChoiceIfPresent(
              page,
              [
                  "Explicit content",
                  "Explicit Content",
                  "Explicit lyrics",
                  "Contains explicit lyrics",
                  "Explicit",
              ],
              track.explicit ? "Explicit" : "Non-Explicit",
          );
    await setPrimaryArtists(
        page,
        String(track.primary_artist ?? metadata.primary_artist ?? ""),
        artistEvidence(track),
    );
    await setSearchField(
        page,
        "Featured artists",
        normalizeArtistMetadata(track.featured_artists),
        true,
    );
    const writers = writerFieldEntries(track.songwriters);
    for (const songwriter of writers.songwriters)
        await setSearchField(page, "Songwriters", songwriter, false);
    // The same person may be both Composer and Lyricist in Soundfresh. SoundOn
    // requires that name to be saved independently in both fields.
    for (const lyricist of writers.lyricists)
        await setWriterField(page, "Lyricists", lyricist);
    const contributors = contributorCreditsForTrack(track, metadata);
    for (const contributor of contributors) {
        await setContributorWithFallback(
            page,
            "Contributors",
            contributor.name,
            contributor.role,
            String(track.primary_artist ?? metadata.primary_artist ?? ""),
            "Vocalist",
        );
    }
    const productionContributors = contributorEntries(
        track.production_contributors || track.primary_artist || metadata.primary_artist,
        "Producer",
    );
    for (const productionContributor of productionContributors) {
        await setContributorWithFallback(
            page,
            "Production and additional contributors",
            productionContributor.name,
            productionContributor.role,
            String(track.primary_artist ?? metadata.primary_artist ?? ""),
            "Producer",
        );
    }
    await closeResidualEntityDialogs(page);
    await setTextField(
        page,
        "Record label",
        "Dimensi Suara",
        false,
    );
    await setRadioIfPresent(
        page,
        ["This track contains licensed content"],
        "No",
    );

    await setSectionFile(
        page,
        "#form-section-cover-art",
        String(metadata.cover?.local_path ?? ""),
        "cover",
    );
    const hasUpc = Boolean(String(metadata.upc ?? "").trim());
    await setScopedRadio(
        page,
        "Previously released?",
        hasUpc || metadata.previously_released
            ? "Yes, I want to move my music from another distributor to SoundOn"
            : "No, I want to distribute a new release",
    );
    if (hasUpc) {
        await setTextField(page, "UPC", String(metadata.upc));
        await setTextField(page, "ISRC", String(track.isrc ?? ""));
        const originalDate = normalizeDate(metadata.original_release_date);
        if (!originalDate)
            throw new Error("METADATA_MISSING:Original Released Date is required when UPC is present.");
        const originalDateSet = await setTextIfPresent(
            page,
            ["Original release date", "Original Release Date"],
            originalDate,
        );
        if (!originalDateSet)
            throw new Error("SOUNDON_UI_CHANGED:field:Original release date");
    }
    let releaseDateSet = await setTextIfPresent(
        page,
        ["Release date", "Planned release date"],
        normalizeDate(metadata.release_date),
    );

    const next = page.getByRole("button", { name: "Next", exact: true });
    if ((await next.count()) === 1) {
        await resilientClick(next);
        await page.waitForTimeout(500);
        const fixLater = page.getByRole("button", { name: "Later", exact: true });
        const validationShown = await fixLater
            .waitFor({ state: "visible", timeout: 10_000 })
            .then(() => true)
            .catch(() => false);
        if (validationShown) {
            await resilientClick(fixLater);
        }
        const releaseDateLabel = page
            .locator(".soundon-formik-field-label:visible")
            .filter({ hasText: /Release date|Planned release date/i })
            .first();
        const reachedSettings = await releaseDateLabel
            .waitFor({ state: "visible", timeout: 5_000 })
            .then(() => true)
            .catch(() => false);
        if (!reachedSettings) {
            const releaseSettings = page
                .getByText("Release Settings", { exact: true })
                .filter({ visible: true });
            if (await releaseSettings.count()) {
                await releaseSettings.first().click({ force: true });
                await releaseDateLabel.waitFor({
                    state: "visible",
                    timeout: 10_000,
                });
            }
        }
        if (!track.instrumental && !lyricsLanguageSet) {
            lyricsLanguageSet = await setChoiceIfPresent(
                page,
                ["Lyrics language", "Lyrics Language"],
                normalizeLanguage(track.language),
            );
        }
        if (!track.instrumental && !explicitSet) {
            explicitSet = await setChoiceIfPresent(
                page,
                [
                    "Explicit content",
                    "Explicit Content",
                    "Explicit lyrics",
                    "Contains explicit lyrics",
                    "Explicit",
                ],
                track.explicit ? "Explicit" : "Non-Explicit",
            );
        }
        if (!releaseDateSet) {
            releaseDateSet = await setTextIfPresent(
                page,
                ["Release date", "Planned release date"],
                normalizeDate(metadata.release_date),
            );
        }
    }

    if (!track.instrumental && track.language && !lyricsLanguageSet)
        throw new Error("SOUNDON_UI_CHANGED:field:Lyrics language");
    if (!track.instrumental && !explicitSet)
        throw new Error("SOUNDON_UI_CHANGED:field:Explicit content");
    if (metadata.release_date && !releaseDateSet)
        throw new Error("SOUNDON_UI_CHANGED:field:Release date");

    // Selecting a near release date can immediately open SoundOn's warning
    // modal. It overlays the TikTok and publishing controls, so acknowledge it
    // before attempting any subsequent field interaction.
    await continueWithCurrentReleaseDate(page);
    await setTikTokPreRelease(
        page,
        soundOnPreReleaseDate,
    );
    await advanceWizardStep(page);

    const moreReleaseOptions = page
        .getByText("More release options", { exact: true })
        .filter({ visible: true });
    if (!(await moreReleaseOptions.count()))
        throw new Error("SOUNDON_UI_CHANGED:step:More release options");
    await moreReleaseOptions.first().click({ force: true });
    await continueWithCurrentReleaseDate(page);
    const publishingRights = page
        .getByText("Publishing rights", { exact: true })
        .filter({ visible: true })
        .first();
    for (let attempt = 0; attempt < 3; attempt++) {
        const visible = await publishingRights
            .waitFor({ state: "visible", timeout: 10_000 })
            .then(() => true)
            .catch(() => false);
        if (visible) break;
        await moreReleaseOptions.first().scrollIntoViewIfNeeded().catch(() => undefined);
        await moreReleaseOptions.first().click({ force: true }).catch(() => undefined);
        await page.waitForTimeout(1_000);
    }
    if (!(await publishingRights.isVisible().catch(() => false)))
        throw new Error("SOUNDON_UI_CHANGED:step:Publishing rights");
    // The release-date warning is rendered asynchronously after the step is
    // opened. Re-check immediately before interacting with publishing fields.
    await continueWithCurrentReleaseDate(page);
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
    await enableStandardMonetization(
        page,
        soundOnPreReleaseDate,
    );
    await advanceWizardStep(page);

    // SoundOn warns when the source planned date is far in the future. Preserve
    // the Soundfresh date by choosing the explicit "continue with this date"
    // action before saving the draft.
    await continueWithCurrentReleaseDate(page);
    const acknowledgeSelectionUpdate = page
        .getByText("Got it", { exact: true })
        .filter({ visible: true });
    if (await acknowledgeSelectionUpdate.count()) {
        await acknowledgeSelectionUpdate.last().click({ force: true });
        await page.waitForTimeout(500);
    }

    const save = page.getByRole("button", {
        name: "Save to my drafts",
        exact: true,
    });
    if ((await save.count()) !== 1)
        throw new Error("SOUNDON_UI_CHANGED:save_draft");
    if (forbidden.test(await save.innerText()))
        throw new Error("DANGEROUS_SELECTOR_BLOCKED");

    assertNoSkippedFields(page);
    await resilientClick(save);
    await page.waitForTimeout(3_000);
    await assertNoValidationErrors(page, 10_000);

    const currentUrl = new URL(page.url());
    const draftId =
        currentUrl.searchParams.get("id") ??
        currentUrl.pathname.match(/(?:draft|release)\/([^/]+)$/i)?.[1] ?? null;

    if (draftId) return { draft_id: draftId, draft_url: page.url() };

    await page.goto("https://www.soundon.global/library/list?type=draft", {
        waitUntil: "domcontentloaded",
    });
    await page.waitForLoadState("networkidle", { timeout: 15_000 }).catch(() => undefined);
    const confirmed = await findDraft(
        page,
        String(metadata.title ?? track.title ?? ""),
        String(metadata.primary_artist ?? track.primary_artist ?? ""),
    );
    if (!confirmed) throw new Error("DRAFT_SAVE_NOT_CONFIRMED");

    return { draft_id: confirmed.draft_id, draft_url: confirmed.draft_url };
}

async function createKnownSoundOnAlbumDraft(
    page: Page,
    metadata: Record<string, any>,
    tracks: Record<string, any>[],
): Promise<{ draft_id: string; draft_url: string }> {
    tracks = orderedAlbumTracks(tracks);
    let stageStarted = Date.now();
    let stageName = 'release_metadata';
    const stage = (next: string) => {
        console.info(JSON.stringify({ event: 'album_stage', release: metadata.title, stage: stageName, elapsed_ms: Date.now() - stageStarted, next }));
        stageName = next;
        stageStarted = Date.now();
    };
    await openAlbumReleaseForm(page);
    await setTextField(page, "Title", String(metadata.title ?? tracks[0]?.title ?? ""));
    await setChoiceField(page, "Title language", normalizeLanguage(metadata.title_language ?? tracks[0]?.title_language ?? tracks[0]?.language), true);
    await setChoiceField(page, "Genre", normalizeGenre(metadata.genre ?? tracks[0]?.genre), true);
    await setChoiceField(page, "Subgenre", normalizeSubgenre(metadata.subgenre ?? tracks[0]?.subgenre), true);
    await setPrimaryArtists(page, String(metadata.primary_artist ?? tracks[0]?.primary_artist ?? ""), artistEvidence(tracks[0] ?? {}));
    await closeResidualEntityDialogs(page);
    // SoundOn moves Publisher between the first page and publishing-rights
    // page depending on the EP/Album form revision. Fill it here when present,
    // then enforce the same default on the publishing-rights page below.
    await setPublisherField(page, "Dimensi Suara", true);
    await setTextField(page, "Record label", "Dimensi Suara", true);
    await setSectionFile(page, "#form-section-cover-art", String(metadata.cover?.local_path ?? ""), "cover");
    const hasUpc = Boolean(String(metadata.upc ?? "").trim());
    await setScopedRadio(page, "Previously released?", hasUpc || metadata.previously_released
        ? "Yes, I want to move my music from another distributor to SoundOn"
        : "No, I want to distribute a new release");
    if (hasUpc) await setTextField(page, "UPC", String(metadata.upc));
    await advanceWizardStep(page);
    await page.getByText("Track information", { exact: true }).filter({ visible: true }).first()
        .waitFor({ state: "visible", timeout: 45_000 });

    const audioPaths = tracks.map((track) => {
        const path = String(track.audio?.local_path ?? "");
        if (!path) throw new Error(`METADATA_MISSING:audio:${track.title ?? "track"}`);
        return path;
    });
    const addTracks = page.getByRole("button", { name: "Add Tracks", exact: true });
    const filenames = audioPaths.map(path => basename(path));
    assertAlbumOrder(filenames, filenames);
    stage('register_audio_tracks');
    // Add masters one at a time in Soundfresh order. Although the current
    // picker accepts multiple files, browsers/SoundOn may sort a batch by
    // filename; sequential insertion keeps track N paired with its metadata
    // and preview clip deterministically.
    for (const audioPath of audioPaths) {
        const chooserPromise = page.waitForEvent("filechooser", { timeout: 30_000 });
        await resilientClick(addTracks);
        await (await chooserPromise).setFiles(audioPath);
        // Wait for insertion, not transfer completion, before adding the next
        // file. Network uploads can overlap while the UI order stays fixed.
        await page.getByText(basename(audioPath), { exact: true }).filter({ visible: true }).first()
            .waitFor({ state: 'visible', timeout: 30_000 });
    }
    for (const audioPath of audioPaths) {
        await page.getByText(basename(audioPath), { exact: true }).first()
            .waitFor({ state: "visible", timeout: 180_000 })
            .catch(() => {
                throw new Error(`ASSET_UPLOAD_FAILED:album_track_not_added:${basename(audioPath)}`);
            });
    }

    stage('upload_audio_masters');
    const uploading = page.getByText(/Uploading\s+\d+%/i).filter({ visible: true })
        .or(page.locator('.so-audio-preview-card-status-waiting, .so-audio-preview-card-status-uploading'));
    const uploadDeadline = Date.now() + 15 * 60_000;
    while ((await uploading.count()) > 0 && Date.now() < uploadDeadline) {
        await page.waitForTimeout(2_000);
    }
    if ((await uploading.count()) > 0) throw new Error("NETWORK_TIMEOUT:album_track_upload");
    const failedUpload = page.getByText(/failed to (?:upload|be added)|upload failed/i).filter({ visible: true });
    if (await failedUpload.count()) {
        throw new Error(`ASSET_UPLOAD_FAILED:${(await failedUpload.first().innerText()).replace(/\s+/g, " ").trim()}`);
    }

    // Compare leaf labels in DOM order after processing. Abort before writing
    // metadata if SoundOn reordered the tracks or omitted an audio file.
    const actualOrder = await page.evaluate((expected) => Array.from(document.querySelectorAll('*'))
        .filter(element => element.children.length === 0 && (element as HTMLElement).offsetHeight > 0)
        .map(element => element.textContent?.trim() ?? '')
        .filter(text => expected.includes(text)), filenames);
    assertAlbumOrder(filenames, actualOrder);

    for (const track of tracks) {
        stage(`track_${track.position ?? tracks.indexOf(track) + 1}_clip:${track.title}`);
        const filename = basename(String(track.audio?.local_path ?? ""));
        await selectAlbumTrack(page, filename);
        const visibleTrackTitles = page.locator(".soundon-formik-field-label").filter({ hasText: /^Title\s*\*?$/, visible: true });
        if (await visibleTrackTitles.count() > 1) throw new Error(`SOUNDON_UI_CHANGED:multiple_open_tracks:${track.title}`);
        await page.screenshot({ path: `storage/screenshots/album-track-${tracks.indexOf(track) + 1}-${Date.now()}.png`, fullPage: true }).catch(() => undefined);
        const tikTokPath = String(track.tiktok_audio?.local_path ?? "");
        // The album editor renders the TikTok clip control only after the
        // selected track panel finishes opening.  It used to wait three
        // minutes *per track* for a stale selector, which made an 11-track
        // album look frozen for more than half an hour.  Keep the lookup
        // bounded and identify the affected track explicitly so a changed UI
        // becomes a visible failure instead of holding the worker forever.
        if (tikTokPath) {
            await setTikTokAudio(page, tikTokPath, 30_000, String(track.title ?? filename));
        }
        stage(`track_${track.position ?? tracks.indexOf(track) + 1}_metadata:${track.title}`);
        await setTextField(page, "Title", String(track.title ?? ""));
        await setChoiceField(page, "Title language", normalizeLanguage(track.title_language ?? track.language), true);
        await setChoiceField(page, "Genre", normalizeGenre(track.genre ?? metadata.genre), true);
        await setChoiceField(page, "Subgenre", normalizeSubgenre(track.subgenre ?? metadata.subgenre), true);
        await setScopedRadio(page, "Instrumental", track.instrumental ? "Yes" : "No");
        if (!track.instrumental) {
            await setChoiceIfPresent(
                page,
                ["Lyrics language", "Lyrics Language"],
                normalizeLanguage(track.language),
            );
            await setChoiceIfPresent(
                page,
                [
                    "Explicit content",
                    "Explicit Content",
                    "Explicit lyrics",
                    "Contains explicit lyrics",
                    "Explicit",
                ],
                track.explicit ? "Explicit" : "Non-Explicit",
            );
        }
        await setPrimaryArtists(page, String(track.primary_artist ?? metadata.primary_artist ?? ""), artistEvidence(track));
        await setSearchField(page, "Featured artists", normalizeArtistMetadata(track.featured_artists), true);
        const writers = writerFieldEntries(track.songwriters || track.primary_artist);
        for (const songwriter of writers.songwriters)
            await setSearchField(page, "Songwriters", songwriter, false);
        for (const lyricist of writers.lyricists)
            await setWriterField(page, "Lyricists", lyricist);
        const contributors = contributorCreditsForTrack(track, metadata);
        for (const contributor of contributors)
            await setContributorWithFallback(page, "Contributors", contributor.name, contributor.role, String(track.primary_artist ?? metadata.primary_artist ?? ""), "Vocalist");
        for (const contributor of contributorEntries(track.production_contributors || track.primary_artist, "Producer"))
            await setContributorWithFallback(page, "Production and additional contributors", contributor.name, contributor.role, String(track.primary_artist ?? metadata.primary_artist ?? ""), "Producer");
        await closeResidualEntityDialogs(page);
        await setTextField(page, "Record label", "Dimensi Suara", true);
        await setRadioIfPresent(page, ["Previously released?", "Previously released"], (track.previously_released ?? metadata.previously_released) || hasUpc
            ? "Yes, I want to move my music from another distributor to SoundOn"
            : "No, I want to distribute a new release");
        await setRadioIfPresent(page, ["This track contains licensed content"], "No");
        if (hasUpc && track.isrc) await setTextField(page, "ISRC", String(track.isrc), true);
    }

    stage('release_distribution');
    await advanceWizardStep(page);
    const releaseDate = normalizeDate(metadata.release_date);
    const releaseDateSet = await setTextIfPresent(page, ["Release date", "Planned release date"], releaseDate);
    if (releaseDate && !releaseDateSet) throw new Error("SOUNDON_UI_CHANGED:field:Release date");
    await continueWithCurrentReleaseDate(page);
    await setTikTokPreRelease(page, resolveSoundOnPreReleaseDate(metadata.pre_release_date, metadata.release_date));
    await advanceWizardStep(page);
    const more = page.getByText("More release options", { exact: true }).filter({ visible: true }).first();
    if (await more.count()) await more.click({ force: true });
    await continueWithCurrentReleaseDate(page);
    await setPublisherField(page, "Dimensi Suara", true);
    await setRadioIfPresent(page, ["What percentage of this track was written or composed by you?"], "100%");
    await setScopedRadio(page, "Does this track have an existing publishing administrator or publisher?", "No");
    await enableStandardMonetization(
        page,
        resolveSoundOnPreReleaseDate(metadata.pre_release_date, metadata.release_date),
        true,
    );
    // Some EP/Album forms finish directly on the publishing-rights page and
    // do not render the separate Single-only monetization step.
    if (!(await page.getByRole("button", { name: "Save to my drafts", exact: true }).filter({ visible: true }).count())) {
        await advanceWizardStep(page);
    }
    const save = page.getByRole("button", { name: "Save to my drafts", exact: true });
    if (!(await save.count())) throw new Error("SOUNDON_UI_CHANGED:save_draft");
    assertNoSkippedFields(page);
    await resilientClick(save.last());
    await page.waitForTimeout(3_000);
    await assertNoValidationErrors(page, 10_000);
    await page.goto("https://www.soundon.global/library/list?type=draft", { waitUntil: "domcontentloaded" });
    // domcontentloaded only means the SPA shell is loaded. Searching before
    // its release list renders returns a false negative on a blank spinner.
    await page.getByText(/^Drafts?(?:\s+\d+)?$/i, { exact: true }).filter({ visible: true }).first()
        .waitFor({ state: "visible", timeout: 45_000 });
    const found = await findDraft(page, String(metadata.title ?? tracks[0]?.title ?? ""), String(metadata.primary_artist ?? tracks[0]?.primary_artist ?? ""));
    if (!found) throw new Error("DRAFT_SAVE_NOT_CONFIRMED");
    stage('completed');
    return found;
}

export async function selectAlbumTrack(page: Page, filename: string): Promise<void> {
    const cards = page.locator("details").filter({ has: page.locator('[data-formik-id^="tracks."][data-formik-id$=".title"]') });
    const target = cards.filter({ has: page.locator("summary").getByText(filename, { exact: true }) });
    if (await target.count() !== 1) throw new Error(`SOUNDON_UI_CHANGED:album_track_panel:${filename}`);
    for (let index = 0; index < await cards.count(); index++) {
        const card = cards.nth(index);
        const selected = await card.locator("summary").getByText(filename, { exact: true }).count() === 1;
        const open = await card.getAttribute("open") !== null;
        if (selected !== open) {
            const summary = card.locator(":scope > summary");
            // SoundOn prevents summary's native toggle. Its first action is
            // Delete and its last action is the expand/collapse arrow.
            const actions = summary.locator("button");
            if (await actions.count() === 2) await actions.last().click({ timeout: 5_000 });
            else if (await actions.count() === 0) await summary.click({ position: { x: 10, y: 10 }, timeout: 5_000 });
            else throw new Error(`SOUNDON_UI_CHANGED:album_track_actions:${filename}`);
            const deadline = Date.now() + 3_000;
            while ((await card.getAttribute("open") !== null) !== selected && Date.now() < deadline) await page.waitForTimeout(100);
        }
        if ((await card.getAttribute("open") !== null) !== selected) {
            throw new Error(`SOUNDON_UI_CHANGED:album_track_toggle:${filename}`);
        }
    }
    await target.scrollIntoViewIfNeeded();
}

async function continueWithCurrentReleaseDate(page: Page): Promise<void> {
    const warning = page
        .getByText(/^Release Date Too (?:Soon|Far Away)$/i, { exact: true })
        .filter({ visible: true });
    const appeared = await warning
        .last()
        .waitFor({ state: "visible", timeout: 3_000 })
        .then(() => true)
        .catch(() => false);
    if (!appeared) return;

    const dialog = page.getByRole("dialog").filter({ hasText: /Release Date Too (?:Soon|Far Away)/i }).last();
    const continuation = dialog
        .getByRole("button", { name: "No, continue with this date", exact: true })
        .filter({ visible: true })
        .last();
    if (!(await continuation.count()))
        throw new Error("SOUNDON_UI_CHANGED:release_date_warning_continue");

    for (let attempt = 0; attempt < 3; attempt++) {
        await continuation.click({ force: true, timeout: 10_000 }).catch(() => undefined);
        const closed = await dialog
            .waitFor({ state: "hidden", timeout: 3_000 })
            .then(() => true)
            .catch(() => false);
        if (closed) {
            await page.waitForTimeout(300);
            return;
        }
        await continuation.evaluate((element) => (element as HTMLButtonElement).click()).catch(() => undefined);
        await page.waitForTimeout(750);
        if (!(await dialog.isVisible().catch(() => false))) return;
    }
    throw new Error("SOUNDON_UI_CHANGED:release_date_warning_not_closed");
}

/**
 * SoundOn no longer opens the single-release form consistently when its old
 * `/library/publish/single` URL is requested directly. It can first render the
 * library dashboard (sometimes behind a long loading spinner), requiring the
 * operator flow Upload -> Single Song. Recover that flow before concluding
 * that the form changed.
 */
async function openSingleReleaseForm(page: Page): Promise<void> {
    const trackInformation = page
        .locator("h1:visible, h2:visible, h3:visible, [role=heading]:visible")
        .filter({ hasText: /^Track information$/i })
        .first();

    if (await trackInformation.isVisible().catch(() => false)) return;

    const upload = uploadEntry(page);

    const initialState = await Promise.race([
        trackInformation
            .waitFor({ state: "visible", timeout: 45_000 })
            .then(() => "form" as const)
            .catch(() => null),
        upload
            .waitFor({ state: "visible", timeout: 45_000 })
            .then(() => "upload" as const)
            .catch(() => null),
    ]);

    if (initialState === "form" || await trackInformation.isVisible().catch(() => false)) return;
    if (initialState !== "upload" && !await upload.isVisible().catch(() => false)) {
        throw new Error(`SOUNDON_UI_CHANGED:upload_entry:url=${page.url()}`);
    }

    await upload.click({ timeout: 20_000 });

    const singleSong = page
        .getByRole("button", { name: /^(Single|Single Song)$/i })
        .or(page.getByText(/^(Single|Single Song)$/i, { exact: true }))
        .first();

    const afterUpload = await Promise.race([
        trackInformation
            .waitFor({ state: "visible", timeout: 45_000 })
            .then(() => "form" as const)
            .catch(() => null),
        singleSong
            .waitFor({ state: "visible", timeout: 45_000 })
            .then(() => "single" as const)
            .catch(() => null),
    ]);

    if (afterUpload === "form" || await trackInformation.isVisible().catch(() => false)) return;
    if (afterUpload !== "single" && !await singleSong.isVisible().catch(() => false)) {
        throw new Error(`SOUNDON_UI_CHANGED:single_release_option:url=${page.url()}`);
    }

    await singleSong.click({ timeout: 20_000 });
    await trackInformation
        .waitFor({ state: "visible", timeout: 75_000 })
        .catch(() => {
            throw new Error(`SOUNDON_UI_CHANGED:single_form_after_upload:url=${page.url()}`);
        });
}

async function openAlbumReleaseForm(page: Page): Promise<void> {
    const albumInformation = page
        .locator("h1:visible,h2:visible,h3:visible,[role=heading]:visible")
        .filter({ hasText: /^Album Information$/i }).first();
    if (await albumInformation.isVisible().catch(() => false)) return;

    const upload = uploadEntry(page);
    await upload.waitFor({ state: "visible", timeout: 45_000 }).catch(() => {
        throw new Error(`SOUNDON_UI_CHANGED:upload_entry:url=${page.url()}`);
    });
    await resilientClick(upload);
    await continueLeavingSingleForm(page);
    const album = albumEntry(page);
    if (!await album.isVisible().catch(() => false)) {
        // Some SoundOn builds return to the library after confirming the
        // unsaved form. Open the upload chooser once more from that page.
        const reopenedUpload = uploadEntry(page);
        if (await reopenedUpload.isVisible().catch(() => false)) {
            await resilientClick(reopenedUpload);
            await page.waitForTimeout(1_000);
        }
    }
    await album.waitFor({ state: "visible", timeout: 45_000 }).catch(() => {
        throw new Error(`SOUNDON_RELEASE_TYPE_NOT_FOUND:EP/Album:url=${page.url()}`);
    });
    await clickAlbumReleaseCard(page, album);
    await albumInformation.waitFor({ state: "visible", timeout: 75_000 }).catch(() => {
        throw new Error(`SOUNDON_RELEASE_TYPE_NOT_SELECTED:EP/Album:url=${page.url()}`);
    });
}

function albumEntry(page: Page): Locator {
    const name = /^(?:EP\s*(?:\/|&|and)\s*Album|Album|EP)$/i;
    return page
        .getByRole("button", { name })
        .or(page.getByRole("link", { name }))
        .or(page.getByText(name, { exact: true }))
        .filter({ visible: true })
        .first();
}

async function clickAlbumReleaseCard(page: Page, label: Locator): Promise<void> {
    // The visible "EP / Album" text is nested inside a card. In newer
    // SoundOn builds only the card owns the click handler, so clicking the
    // text locator can be a no-op even though Playwright reports success.
    const card = label.locator("xpath=ancestor-or-self::*[self::button or self::a or @role='button'][1]");
    if (await card.count()) {
        await resilientClick(card.first());
        return;
    }

    let candidate = label;
    for (let depth = 0; depth < 5; depth++) {
        const clickable = await candidate.evaluate((element) => {
            const style = window.getComputedStyle(element);
            return style.cursor === "pointer" || element.hasAttribute("tabindex") || Boolean((element as HTMLElement).onclick);
        }).catch(() => false);
        if (clickable) {
            await resilientClick(candidate);
            return;
        }
        candidate = candidate.locator("..");
    }

    await resilientClick(label);
}

async function continueLeavingSingleForm(page: Page): Promise<void> {
    const dialog = page.getByRole("dialog").filter({ hasText: /^Leave page/i }).last();
    const appeared = await dialog.waitFor({ state: "visible", timeout: 4_000 })
        .then(() => true)
        .catch(() => false);
    if (!appeared) return;

    const leave = dialog
        .getByRole("button", { name: /Leave without saving/i })
        .filter({ visible: true })
        .last();
    if (!await leave.count()) throw new Error("SOUNDON_UI_CHANGED:leave_without_saving");
    await resilientClick(leave);
    await dialog.waitFor({ state: "hidden", timeout: 15_000 }).catch(() => undefined);
    await page.waitForTimeout(1_500);
}

function uploadEntry(page: Page): Locator {
    return page
        .getByRole("button", { name: /^(Upload|Upload your music)$/i })
        .or(page.getByRole("link", { name: /^(Upload|Upload your music)$/i }))
        .or(page.locator('button, a, [role="button"]').filter({ hasText: /^(Upload|Upload your music)$/i }))
        .filter({ visible: true })
        .first();
}

async function setWriterField(page: Page, label: string, name: string): Promise<void> {
    try {
        await setSearchField(page, label, name, false);
    } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        if (label !== "Lyricists" || !message.startsWith("SOUNDON_UI_CHANGED:search:Lyricists")) {
            throw error;
        }

        // The current album editor sometimes exposes one combined Songwriters
        // control instead of a separate Lyricists control. If the person was
        // already saved as songwriter, the legal credit is complete; opening
        // Add again reuses stale modal state and can replace the name.
        if (await waitForEntityCard(page, "Songwriters", name, 1_500)) return;
        await setSearchField(page, "Songwriters", name, false);
    }
}

async function setPrimaryArtists(
    page: Page,
    rawArtists: string,
    rawContributors: unknown = "",
): Promise<void> {
    const artists = primaryArtistEntries(rawArtists, rawContributors);
    if (!artists.length) throw new Error("METADATA_MISSING:primary_artist");
    for (const artist of artists) {
        // Album tracks can inherit the release artist. Do not open Add again
        // for the same exact credit; the duplicate dialog may never submit.
        if (await waitForEntityCard(page, "Primary artists", artist, 150)) continue;
        await setSearchField(page, "Primary artists", artist, false);
        // Complete and persist one artist before opening Add for the next
        // artist. This is essential for releases with several primary artists.
        if (!await waitForEntityCard(page, "Primary artists", artist, entityPersistTimeout)) {
            throw new Error(`SOUNDON_CONTRIBUTOR_NOT_PERSISTED:Primary artists:${artist}`);
        }
    }
}

export function primaryArtistEntries(value: unknown, contributors: unknown = ""): string[] {
    const normalized = normalizeArtistMetadata(value);
    if (!normalized) return [];

    // Soundfresh displays multiple primary artists as a comma-separated list
    // (for example "Dev Rival Octiano, Dimas Eka Saputra, Edy Haryono").
    // Preserve each credit as its own SoundOn artist entry instead of sending
    // the complete list as one artist.
    const separated = normalized.split(/\s*(?:,|，|،|;|\||\r?\n|\s\/\s)\s*/).filter(Boolean);
    if (separated.length > 1) return separated;

    // Older Soundfresh detail pages rendered multiple artist chips without a
    // textual delimiter. Recover their boundaries only when the contributor
    // names, ordered by their position, reconstruct the complete value exactly.
    const candidates = contributorEntries(contributors, "")
        .map((entry) => entry.name)
        .filter((name, index, all) => name && all.findIndex(
            (candidate) => candidate.localeCompare(name, undefined, { sensitivity: "accent" }) === 0,
        ) === index)
        .map((name) => ({ name, position: normalized.toLocaleLowerCase().indexOf(name.toLocaleLowerCase()) }))
        .filter((entry) => entry.position >= 0)
        .sort((left, right) => left.position - right.position);
    if (candidates.length > 1) {
        const reconstructed = candidates.map((entry) => entry.name).join(" ").replace(/\s+/g, " ").trim();
        if (reconstructed.localeCompare(normalized, undefined, { sensitivity: "accent" }) === 0) {
            return candidates.map((entry) => entry.name);
        }
    }

    return [normalized];
}

function artistEvidence(track: Record<string, any>): string {
    // Some Soundfresh pages collapse the Primary Artists display into one
    // string but still expose the individual names in songwriter/production
    // credits. Supply all of that evidence to primaryArtistEntries so it can
    // restore the artist boundaries without guessing from word casing.
    return [
        track.contributors,
        track.songwriters,
        track.production_contributors,
    ].filter(Boolean).join("\n");
}

/**
 * Preserve the contributor credits declared in Soundfresh for instrumental
 * tracks. SoundOn rejects a Vocalist role on those tracks, so the primary
 * artist must never be injected or overwrite a real instrumental role.
 */
export function contributorCreditsForTrack(
    track: Record<string, any>,
    metadata: Record<string, any> = {},
): Array<{ name: string; role: string }> {
    const instrumental = Boolean(track.instrumental);
    const contributors = contributorEntries(
        track.contributors || (instrumental ? "" : track.primary_artist || metadata.primary_artist),
        "Vocalist",
    );
    if (instrumental) return contributors;

    // A vocal track still needs a Vocalist credit for every primary artist.
    for (const primaryArtist of primaryArtistEntries(
        track.primary_artist ?? metadata.primary_artist ?? "",
        artistEvidence(track),
    )) {
        const existing = contributors.find((entry) => entry.name.localeCompare(primaryArtist, undefined, { sensitivity: "accent" }) === 0);
        if (existing) existing.role = "Vocalist";
        else contributors.push({ name: primaryArtist, role: "Vocalist" });
    }

    return contributors;
}

async function setContributorWithFallback(
    page: Page,
    label: string,
    name: string,
    role: string,
    primaryArtist: string,
    fallbackRole: string,
): Promise<void> {
    // Missing choices are recorded by setSearchField. Never replace a source
    // contributor with a different person merely to make validation pass.
    await setSearchField(page, label, name, false, 0, role);
}

export async function advanceWizardStep(page: Page): Promise<void> {
    const next = page.getByRole("button", {
        name: "Next",
        exact: true,
    }).filter({ visible: true });
    if (!(await next.count())) throw new Error("SOUNDON_UI_CHANGED:wizard_next");
    await resilientClick(next.last());
    await page.waitForTimeout(500);

    // Drafts are allowed to continue with unrelated optional/incomplete data.
    // SoundOn displays this confirmation as a modal overlay; leaving it open
    // blocks every subsequent click and previously caused a false timeout.
    const modal = page.locator(".semi-modal-wrap:visible").last();
    const confirmationAppeared = await modal
        .waitFor({ state: "visible", timeout: 5_000 })
        .then(() => true)
        .catch(() => false);
    if (confirmationAppeared) {
        await assertNoValidationErrors(page);
        let continuation = modal
            .getByRole("button", {
                name: /^(?:Later|No, continue with this date|Continue(?: anyway)?|Proceed)$/i,
            })
            .filter({ visible: true });
        if (!(await continuation.count())) {
            continuation = modal
                .getByText(/^(?:Later|No, continue with this date|Continue(?: anyway)?|Proceed)$/i, {
                    exact: true,
                })
                .filter({ visible: true });
        }
        if (!(await continuation.count())) {
            const message = (await modal.innerText().catch(() => ""))
                .replace(/\s+/g, " ")
                .trim()
                .slice(0, 500);
            throw new Error(`SOUNDON_UI_CHANGED:wizard_confirmation:${message}`);
        }
        await resilientClick(continuation.last());
        await modal
            .waitFor({ state: "hidden", timeout: 5_000 })
            .catch(() => undefined);
        await page.waitForTimeout(500);
    }
}

export async function assertNoValidationErrors(page: Page, timeout = 0): Promise<void> {
    const errorHeading = page.getByText(/\b\d+ errors? detected\b/i).filter({ visible: true }).first();
    if (timeout) await errorHeading.waitFor({ state: "visible", timeout }).catch(() => undefined);
    if (!await errorHeading.count()) return;
    const summary = (await errorHeading.innerText()).trim();
    const fix = page.getByRole("button", { name: "Fix now", exact: true }).filter({ visible: true });
    if (await fix.count()) {
        await fix.last().click({ timeout: 5_000 });
        await errorHeading.waitFor({ state: "hidden", timeout: 5_000 }).catch(() => undefined);
    }
    const details = await page.locator('[role="alert"]:visible, .semi-form-field-error-message:visible, .soundon-formik-field-error:visible, [aria-invalid="true"]:visible')
        .evaluateAll(elements => elements.map(element => (element.textContent?.trim() || element.getAttribute("aria-label") || element.getAttribute("name") || "")).filter(Boolean));
    throw new Error(`SOUNDON_FORM_VALIDATION:${summary}:${[...new Set(details)].join("; ").slice(0, 1500) || "Periksa field merah pada tangkapan layar SoundOn"}`);
}

async function fieldContainer(
    page: Page,
    label: string,
    optional = false,
): Promise<Locator | null> {
    await dismissVisibleEntityDialogs(page);
    const labels = page
        .locator(".soundon-formik-field-label")
        .filter({ hasText: label, visible: true });
    const count = await labels.count();
    if (count === 0) {
        if (optional) return null;
        throw new Error(`SOUNDON_UI_CHANGED:field:${label}`);
    }

    for (let index = 0; index < count; index++) {
        const candidate = labels.nth(index);
        const candidateText = (await candidate.textContent())
            ?.replace(/\s+/g, " ")
            .trim();
        if (!matchesFieldLabel(candidateText, label))
            continue;
        let container = candidate.locator("..");
        for (let depth = 0; depth < 4; depth++) {
            const fieldLabels = container.locator(".soundon-formik-field-label");
            if ((await fieldLabels.count()) > 1) break;
            if (
                (await container
                    .locator(
                        'input:not([type="hidden"]),textarea,[role="combobox"]',
                    )
                    .count()) > 0
            )
                return container;
            container = container.locator("..");
        }
    }

    if (optional) return null;
    throw new Error(`SOUNDON_UI_CHANGED:control:${label}`);
}

async function dismissVisibleEntityDialogs(page: Page): Promise<void> {
    await page.waitForTimeout(300);
    const dialogs = page.locator(".so-form-modal-dialog:visible");
    let dismissAttempts = 0;
    while ((await dialogs.count()) > 0) {
        if (++dismissAttempts > 3) throw new Error('SOUNDON_UI_CHANGED:entity_dialog_will_not_close');
        const dialog = dialogs.last();
        const cancel = dialog.getByRole("button", { name: "Cancel", exact: true });
        const close = dialog.locator(".so-form-modal-title-close");
        if (await cancel.count()) await cancel.click({ force: true });
        else if (await close.count()) await close.click({ force: true });
        else await page.keyboard.press("Escape");
        await dialog.waitFor({ state: "hidden", timeout: 5_000 }).catch(() => undefined);
        if (await dialog.isVisible().catch(() => false)) {
            await page.keyboard.press("Escape");
            await page.waitForTimeout(500);
        }
    }
}

export function matchesFieldLabel(candidate: string | null | undefined, label: string): boolean {
    if (!candidate) return false;
    const normalized = candidate.toLowerCase().replace(/\s+/g, " ").trim();
    const expected = label.toLowerCase().replace(/\s+/g, " ").trim();
    if (!normalized.startsWith(expected)) return false;
    const suffix = normalized.slice(expected.length).trim();
    return suffix === "" || suffix.startsWith("(") || suffix.startsWith("*") || suffix.startsWith("required");
}

export async function setTextField(
    page: Page,
    label: string,
    value: string,
    optional = false,
): Promise<void> {
    if (!value && optional) return;

    // A failed/slow optional entity submit can leave SoundOn's legacy modal
    // visible even after its value has been accepted. Clear only stale entity
    // modals before opening the next field so their overlay cannot intercept it.
    const staleDialogs = page.locator(".so-form-modal-dialog:visible");
    let staleAttempts = 0;
    while ((await staleDialogs.count()) > 0) {
        if (++staleAttempts > 3) throw new Error(`SOUNDON_UI_CHANGED:stale_dialog:${label}`);
        const stale = staleDialogs.last();
        const cancel = stale.getByRole("button", { name: "Cancel", exact: true });
        const close = stale.locator(".so-form-modal-title-close");
        if (await cancel.count()) await cancel.click({ force: true });
        else if (await close.count()) await close.click({ force: true });
        else break;
        await stale.waitFor({ state: "hidden", timeout: 5_000 }).catch(() => undefined);
    }
    const container = await fieldContainer(page, label, optional);
    if (!container) return;
    const inputs = container.locator(
        'input:not([type="hidden"]):not([type="radio"]):not([type="checkbox"]):visible,textarea:visible',
    );
    const count = await inputs.count();
    if (count === 0) {
        if (optional) return;
        throw new Error(`SOUNDON_UI_CHANGED:input:${label}`);
    }
    const input = inputs.nth(0);
    if (/date/i.test(label) && await selectCalendarDate(input, value)) {
        return;
    }
    if ((await input.getAttribute("readonly")) !== null) {
        // Semi DatePicker exposes a readonly text input. Trigger React/Formik's
        // controlled input path with the native value setter instead of waiting
        // forever for Playwright's fill() editability check.
        await input.evaluate((element, nextValue) => {
            const inputElement = element as HTMLInputElement;
            const setter = Object.getOwnPropertyDescriptor(
                HTMLInputElement.prototype,
                "value",
            )?.set;
            setter?.call(inputElement, String(nextValue));
            inputElement.dispatchEvent(new Event("input", { bubbles: true }));
            inputElement.dispatchEvent(new Event("change", { bubbles: true }));
            inputElement.dispatchEvent(new Event("blur", { bubbles: true }));
        }, value);
        await page.waitForTimeout(300);
        return;
    }
    await pasteInputValue(input, value);
    if (!/date/i.test(label)) {
        // Formik can accept transient input text then reset it on blur.
        // Verify the committed value and retry once before moving on.
        for (let attempt = 0; attempt < 2; attempt++) {
            await input.evaluate(element => (element as HTMLInputElement).blur());
            await page.waitForTimeout(150);
            const actual = await input.inputValue();
            if (actual.normalize("NFC") === value.normalize("NFC")) return;
            if (attempt === 0) await pasteInputValue(input, value);
            else throw new Error(`SOUNDON_FIELD_NOT_PERSISTED:${label}:expected=${value}:actual=${actual}`);
        }
    }
}

export async function setPublisherField(
    page: Page,
    value = "Dimensi Suara",
    optional = false,
): Promise<void> {
    const container = await fieldContainer(page, "Publisher", optional);
    if (!container) return;
    const persisted = container.getByText(value, { exact: true });
    if (await persisted.first().isVisible().catch(() => false)) return;

    const input = container
        .locator('input:not([type="hidden"]):not([type="radio"]):not([type="checkbox"]):visible')
        .first();
    const combobox = container.locator('[role="combobox"]:visible').first();
    if (await input.count()) {
        await input.click({ force: true });
        await input.fill(value).catch(async () => {
            await input.press(process.platform === "darwin" ? "Meta+A" : "Control+A");
            await input.pressSequentially(value);
        });
    } else if (await combobox.count()) {
        await combobox.click({ force: true });
        await page.keyboard.type(value);
    } else {
        if (optional) return;
        throw new Error("SOUNDON_UI_CHANGED:input:Publisher");
    }

    await page.waitForTimeout(400);
    const exactOption = page
        .getByRole("option", { name: value, exact: true })
        .filter({ visible: true })
        .first();
    if (await exactOption.count()) {
        await exactOption.click({ force: true });
    } else {
        const visibleChoice = page
            .locator('[role="listbox"]:visible, .semi-select-option-list:visible')
            .getByText(value, { exact: true })
            .filter({ visible: true })
            .first();
        if (await visibleChoice.count()) await visibleChoice.click({ force: true });
        else if (await input.count()) await input.press("Enter");
        else await page.keyboard.press("Enter");
    }
    await page.waitForTimeout(300);
    if (!(await persisted.first().isVisible().catch(() => false)))
        throw new Error(`SOUNDON_PUBLISHER_NOT_PERSISTED:${value}`);
}

export async function enableMonetizationOption(page: Page, toggle: Locator, option: string): Promise<boolean> {
    // An ineligible store is optional, not a broken wizard. Never bypass its
    // disabled control or change the source genre to make it eligible.
    if (await toggle.isChecked()) return true;
    const disabled = async () => await toggle.isDisabled()
        || await toggle.getAttribute("aria-disabled") === "true";
    for (let attempt = 0; attempt < 3; attempt++) {
        if (await disabled()) {
            await toggle.hover({ force: true, timeout: 1500 }).catch(() => undefined);
            const reason = await toggle.evaluate((element) => {
                const ids = `${element.getAttribute('aria-describedby') ?? ''} ${element.getAttribute('aria-errormessage') ?? ''}`.trim().split(/\s+/);
                return ids.map(id => document.getElementById(id)?.textContent ?? '').join(' ').trim()
                    || element.getAttribute('title') || element.parentElement?.getAttribute('title') || '';
            });
            const tooltip = await page.getByRole('tooltip').filter({ visible: true }).allTextContents();
            const detail = (reason || tooltip.join(' ') || 'SoundOn menonaktifkan opsi ini untuk rilisan ini.').replace(/\s+/g, ' ').trim();
            const warning = `${option}: tidak diaktifkan oleh SoundOn. ${detail}`;
            optionalFieldWarnings.set(page, [...(optionalFieldWarnings.get(page) ?? []), warning]);
            console.warn(JSON.stringify({ event: 'monetization_option_unavailable', option, source: 'SoundOn', reason: detail }));
            return false;
        }
        await toggle.click({ timeout: 3000 }).catch(() => undefined);
        await page.waitForTimeout(500);
        if (await toggle.isChecked()) return true;
    }
    throw new Error(`SOUNDON_MONETIZATION_NOT_ENABLED:${option}`);
}

export async function enableStandardMonetization(
    page: Page,
    preReleaseDate = "",
    optionalStep = false,
): Promise<void> {
    const normalizedText = (text: string) => text.normalize("NFC").replace(/\s+/g, " ").trim().toLocaleLowerCase();
    const findVisibleText = async (label: string): Promise<Locator | null> => {
        const exact = page.getByText(label, { exact: true }).filter({ visible: true });
        if (await exact.count()) return exact.first();
        const normalized = normalizedText(label);
        const candidates = page.locator("h1:visible,h2:visible,h3:visible,h4:visible,[role='heading']:visible,button:visible");
        for (let index = 0; index < await candidates.count(); index++) {
            const candidate = candidates.nth(index);
            if (normalizedText(await candidate.innerText().catch(() => "")) === normalized) return candidate;
        }
        return null;
    };
    // SoundOn updates this wizard asynchronously. Give the next step time to
    // render before classifying it as an incompatible page.
    let monetization: Locator | null = null;
    let youtubeContentId: Locator | null = null;
    const deadline = Date.now() + 12_000;
    while (Date.now() < deadline) {
        monetization = await findVisibleText("Monetization options");
        youtubeContentId = await findVisibleText("YouTube Content ID");
        if (monetization || youtubeContentId) break;
        await page.waitForTimeout(400);
    }
    if (monetization) {
        await monetization.click({ force: true });
        const youtubeHeading = page.getByText("YouTube Content ID", { exact: true }).filter({ visible: true }).first();
        await youtubeHeading.waitFor({ state: "visible", timeout: 10_000 }).catch(() => undefined);
    } else if (!youtubeContentId) {
        if (optionalStep) return;
        const pageState = await page.evaluate(() => ({
            url: location.pathname,
            headings: Array.from(document.querySelectorAll("h1,h2,h3,h4,[role=heading]"))
                .filter((element) => (element as HTMLElement).offsetParent !== null)
                .map((element) => element.textContent?.replace(/\s+/g, " ").trim())
                .filter(Boolean)
                .slice(0, 12),
            buttons: Array.from(document.querySelectorAll("button"))
                .filter((element) => (element as HTMLElement).offsetParent !== null)
                .map((element) => element.textContent?.replace(/\s+/g, " ").trim())
                .filter(Boolean)
                .slice(0, 12),
        })).catch(() => ({ url: "unknown", headings: [], buttons: [] }));
        throw new Error(`SOUNDON_UI_CHANGED:step:Monetization options:state=${JSON.stringify(pageState)}`);
    }

    let youtubeEnabled = true;
    for (const option of [
        "Commercial Music Licensing",
        "CapCut",
        "YouTube Content ID",
    ]) {
        const heading = await findVisibleText(option);
        if (!heading) {
            if (option === "YouTube Content ID")
                throw new Error(`SOUNDON_UI_CHANGED:monetization:${option}`);
            continue;
        }
        let card = heading.locator("..");
        let toggle: Locator | null = null;
        for (let depth = 0; depth < 6; depth++) {
            const candidate = card.locator('[role="switch"]');
            if ((await candidate.count()) === 1) {
                toggle = candidate.first();
                break;
            }
            card = card.locator("..");
        }
        if (!toggle)
            throw new Error(`SOUNDON_UI_CHANGED:monetization_switch:${option}`);
        const enabled = await enableMonetizationOption(page, toggle, option);
        if (option === "YouTube Content ID") youtubeEnabled = enabled;
    }

    // SoundOn also disables the dependent Shorts pre-release for an ineligible
    // Content ID store. Do not try to force its checkbox or dates on.
    if (!youtubeEnabled) {
        if (preReleaseDate) optionalFieldWarnings.set(page, [...(optionalFieldWarnings.get(page) ?? []),
            'Pre-release on YouTube Shorts: tidak diisi karena YouTube Content ID tidak tersedia pada rilisan ini.']);
        return;
    }

    const preReleases = page
        .getByText(/Pre-release on YouTube Shorts/)
        .filter({ visible: true });
    const preReleaseCount = await preReleases.count();
    if (!preReleaseCount) {
        if (!preReleaseDate) return;
        throw new Error("SOUNDON_UI_CHANGED:youtube_pre_release");
    }
    for (let index = 0; index < preReleaseCount; index++) {
        let youtubeCard = preReleases.nth(index).locator("..");
        let cardFound = false;
        for (let depth = 0; depth < 8; depth++) {
            const checkboxCount = await youtubeCard.locator('input[type="checkbox"]').count();
            const dateLabelCount = await youtubeCard.getByText("Pre-release date", { exact: true }).count();
            if (checkboxCount === 1 && dateLabelCount === 1) {
                cardFound = true;
                break;
            }
            youtubeCard = youtubeCard.locator("..");
        }
        if (!cardFound)
            throw new Error("SOUNDON_UI_CHANGED:youtube_pre_release_card:" + (index + 1));
        const checkbox = youtubeCard.locator('input[type="checkbox"]').first();
        if (!preReleaseDate) {
            await setCheckboxState(checkbox, false, "SOUNDON_YOUTUBE_PRE_RELEASE_NOT_DISABLED");
            continue;
        }
        if (!(await checkbox.isChecked())) await checkbox.click({ force: true });
        await youtubeCard
            .getByText("Pre-release date", { exact: true })
            .first()
            .waitFor({ state: "visible", timeout: 10_000 });
        try {
            await setScopedInput(youtubeCard, "Pre-release date", preReleaseDate);
            // Selecting a calendar date re-renders the current SoundOn card. Set
            // the controlled time dropdown last so its committed form-state value
            // is not replaced by that render.
            await setScopedTime(youtubeCard, "Pre-release time");
            await assertScopedPreReleasePersisted(youtubeCard, preReleaseDate);
        } catch (error) {
            const detail = error instanceof Error ? error.message : String(error);
            throw new Error("PRE_RELEASE_INVALID:YouTube:track_" + (index + 1) + ":" + preReleaseDate + ":" + detail);
        }
    }
}

async function setCheckboxState(
    checkbox: Locator,
    checked: boolean,
    errorCode: string,
): Promise<void> {
    for (let attempt = 0; attempt < 3; attempt++) {
        if ((await checkbox.isChecked().catch(() => !checked)) === checked) return;
        await checkbox.setChecked(checked, { force: true }).catch(() => undefined);
        await checkbox.page().waitForTimeout(300);
        if ((await checkbox.isChecked().catch(() => !checked)) === checked) return;
        await checkbox.evaluate((element) => {
            const input = element as HTMLInputElement;
            input.click();
            input.dispatchEvent(new Event("input", { bubbles: true }));
            input.dispatchEvent(new Event("change", { bubbles: true }));
        }).catch(() => undefined);
        await checkbox.page().waitForTimeout(300);
    }
    if ((await checkbox.isChecked().catch(() => !checked)) !== checked)
        throw new Error(errorCode);
}

export async function setTikTokPreRelease(
    page: Page,
    preReleaseDate: string,
): Promise<void> {
    const heading = page
        .getByText("TikTok pre-release", { exact: true })
        .filter({ visible: true })
        .first();
    if (!(await heading.count()))
        throw new Error("SOUNDON_UI_CHANGED:tiktok_pre_release");
    let card = heading.locator("..");
    for (let depth = 0; depth < 7; depth++) {
        const turnOn = card
            .getByText("Turn on TikTok pre-release", { exact: true })
            .filter({ visible: true });
        const skip = card
            .getByText(/Skip for now/i)
            .filter({ visible: true });
        if ((await turnOn.count()) && (await skip.count())) break;
        card = card.locator("..");
    }
    if (!preReleaseDate) {
        const skip = card
            .getByText(/Skip for now/i)
            .filter({ visible: true })
            .first();
        if (!(await skip.count()))
            throw new Error("SOUNDON_UI_CHANGED:tiktok_skip_for_now");
        const skipRadio = skip.locator("xpath=ancestor::label[1]//input[@type='radio']");
        if (!(await skipRadio.count()) || !(await skipRadio.first().isChecked()))
            await skip.click({ force: true });
        if ((await skipRadio.count()) && !(await skipRadio.first().isChecked()))
            throw new Error("SOUNDON_TIKTOK_PRE_RELEASE_NOT_SKIPPED");
        return;
    }
    const turnOn = card
        .getByText("Turn on TikTok pre-release", { exact: true })
        .filter({ visible: true })
        .first();
    if (!(await turnOn.count()))
        throw new Error("SOUNDON_UI_CHANGED:tiktok_pre_release_toggle");
    // SoundOn's release-date calendar is rendered in a portal and currently
    // ignores Escape. Close it by clicking a stable form heading outside the
    // portal; otherwise the overlay intercepts the TikTok option click and the
    // date/time inputs are never mounted.
    await closeOpenDatePicker(page);
    // Do not inspect card.locator('input[type=radio]').first(): the card's
    // responsive ancestor can also contain Local/Global release radios. Click
    // the exact TikTok option so its own date/time fields are rendered.
    const turnOnRadio = turnOn.locator("xpath=ancestor::label[1]//input[@type='radio']").first();
    if (!(await turnOnRadio.count()) || !(await turnOnRadio.isChecked().catch(() => false))) {
        if (await turnOnRadio.count()) {
            await turnOnRadio.check({ force: true }).catch(() => turnOn.click({ force: true }));
        } else {
            await turnOn.click({ force: true });
        }
        await page.waitForTimeout(800);
    }
    // Selecting the option makes SoundOn replace this part of the form.  Do
    // not keep using the pre-click ancestor because it can point to a detached
    // or collapsed responsive wrapper after that render.
    try {
        const activeHeading = page
            .getByText("TikTok pre-release", { exact: true })
            .filter({ visible: true })
            .first();
        await activeHeading.waitFor({ state: "visible", timeout: 20_000 });
        let activeCard = activeHeading.locator("..");
        for (let depth = 0; depth < 7; depth++) {
            const scopedHeading = activeCard
                .getByText("TikTok pre-release", { exact: true })
                .filter({ visible: true });
            const dateField = activeCard
                .getByText("Pre-release date", { exact: true })
                .filter({ visible: true });
            const timeField = activeCard
                .getByText("Pre-release time", { exact: true })
                .filter({ visible: true });
            if ((await scopedHeading.count()) && (await dateField.count()) && (await timeField.count())) break;
            activeCard = activeCard.locator("..");
        }
        await setScopedInput(activeCard, "Pre-release date", preReleaseDate);
        await setScopedTime(activeCard, "Pre-release time");
        await assertScopedPreReleasePersisted(activeCard, preReleaseDate);
    } catch (error) {
        const detail = error instanceof Error ? error.message : String(error);
        throw new Error(`PRE_RELEASE_INVALID:TikTok:${preReleaseDate}:${detail}`);
    }
}

async function closeOpenDatePicker(page: Page): Promise<void> {
    await page.keyboard.press("Escape").catch(() => undefined);
    const picker = page.locator(
        ".semi-datepicker:visible,.semi-datepicker-month-grid:visible,.semi-calendar:visible,.semi-portal .semi-datepicker:visible",
    ).first();
    if (!(await picker.count()) || !(await picker.isVisible().catch(() => false))) return;

    const outsideTarget = page
        .getByText("TikTok pre-release", { exact: true })
        .filter({ visible: true })
        .first();
    if (await outsideTarget.count()) {
        await outsideTarget.click({ position: { x: 4, y: 4 }, force: true }).catch(() => undefined);
    } else {
        await page.mouse.click(20, 20).catch(() => undefined);
    }
    await picker.waitFor({ state: "hidden", timeout: 3_000 }).catch(() => undefined);
}

async function assertScopedPreReleasePersisted(
    scope: Locator,
    expectedDate: string,
): Promise<void> {
    const inputValues = await scope
        .locator('input:not([type="hidden"]):not([type="radio"]):not([type="checkbox"])')
        .filter({ visible: true })
        .evaluateAll((inputs) =>
            inputs.map((input) => (input as HTMLInputElement).value),
        );
    // In SoundOn's current Semi UI the committed time is rendered as the
    // combobox text while its internal input remains empty. Include the visible
    // selected text so a valid 12:00 AM choice is not reported as lost.
    const selectedValues = await scope
        .locator('[role="combobox"]')
        .filter({ visible: true })
        .allInnerTexts();
    const values = [...inputValues, ...selectedValues.map(value => value.replace(/\s+/g, " ").trim())];
    const persisted = preReleaseFieldsPersisted(values, expectedDate);
    if (!persisted.date) {
        throw new Error(
            `SOUNDON_FIELD_NOT_PERSISTED:Pre-release date:${expectedDate}:${persisted.actualDate}`,
        );
    }
    if (!persisted.time) {
        throw new Error(
            `SOUNDON_FIELD_NOT_PERSISTED:Pre-release time:12:00 AM:${persisted.actualTime}`,
        );
    }
}

export function preReleaseFieldsPersisted(
    values: string[],
    expectedDate: string,
): { date: boolean; time: boolean; actualDate: string; actualTime: string } {
    const actualDate = values.find((value) => normalizeDate(value) === expectedDate)
        ?? values.find((value) => /^\d{4}[-/]\d{1,2}[-/]\d{1,2}/.test(value.trim()))
        ?? "";
    const actualTime = values.find((value) =>
        /^(?:00:00|12:00\s*AM(?:\s*\(00:00\))?)$/i.test(value.trim()),
    ) ?? "";

    return {
        date: normalizeDate(actualDate) === expectedDate,
        time: Boolean(actualTime),
        actualDate,
        actualTime,
    };
}

export async function setScopedTime(scope: Locator, label: string): Promise<void> {
    const page = scope.page();
    // SoundOn's date picker can remain open after a date is selected and cover
    // the time control. Close it before opening the time dropdown.
    await page.keyboard.press("Tab").catch(() => undefined);
    await page
        .locator(".semi-datepicker:visible")
        .last()
        .waitFor({ state: "hidden", timeout: 3_000 })
        .catch(() => undefined);
    const fieldLabel = scope.getByText(label, { exact: true }).first();
    if (!(await fieldLabel.count()))
        throw new Error(`SOUNDON_UI_CHANGED:field:${label}`);
    const field = fieldLabel.locator("..");
    // Do not fill the visible time input directly. Semi's controlled input can
    // display 00:00 without updating the form state. The next field render then
    // restores an empty value, producing SOUNDON_FIELD_NOT_PERSISTED. Selecting
    // the real dropdown option is the only reliable commit path.
    const timeSelect = field.getByRole("combobox").filter({ visible: true }).first();
    if (!(await timeSelect.count()))
        throw new Error(`SOUNDON_UI_CHANGED:time_combobox:${label}`);

    await timeSelect.click({ force: true });
    let options = page.getByRole("option").filter({ visible: true });
    if (!(await options.first().isVisible().catch(() => false))) {
        options = page
            .locator('[role="option"]:visible, .semi-select-option:visible')
            .filter({ visible: true });
    }
    await options.first().waitFor({ state: "visible", timeout: 20_000 });
    let midnight = options
        .filter({ hasText: /^12:00\s*AM(?:\s*\(00:00\))?$/i })
        .first();
    if (!(await midnight.count())) {
        midnight = options.filter({ hasText: /^(?:00:00|12:00\s*AM)/i }).first();
    }
    if (!(await midnight.count())) {
        const available = await options.allInnerTexts();
        throw new Error(`SOUNDON_UI_CHANGED:time_option:${available.join("|")}`);
    }
    await midnight.click({ force: true });
    await scope.page().waitForTimeout(500);
    const displayed = (await timeSelect.innerText()).replace(/\s+/g, " ").trim();
    if (!/12:00\s*AM|00:00/i.test(displayed))
        throw new Error(`SOUNDON_FIELD_NOT_PERSISTED:${label}:12:00 AM:${displayed}`);
}

async function closestInputBelowLabel(
    scope: Locator,
    fieldLabel: Locator,
    selector = 'input:not([type="hidden"]):not([type="radio"]):not([type="checkbox"])',
): Promise<Locator> {
    const labelBox = await fieldLabel.boundingBox();
    const inputs = scope.locator(selector).filter({ visible: true });
    if (!labelBox || !(await inputs.count())) return inputs.first();

    let bestIndex = -1;
    let bestDistance = Number.POSITIVE_INFINITY;
    for (let index = 0; index < await inputs.count(); index++) {
        const box = await inputs.nth(index).boundingBox();
        if (!box || box.y + 2 < labelBox.y + labelBox.height) continue;
        const distance = box.y - (labelBox.y + labelBox.height);
        if (distance < bestDistance) {
            bestDistance = distance;
            bestIndex = index;
        }
    }
    return bestIndex >= 0 ? inputs.nth(bestIndex) : inputs.first();
}

async function setScopedInput(
    scope: Locator,
    label: string,
    value: string,
): Promise<void> {
    const fieldLabel = scope.getByText(label, { exact: true }).first();
    await fieldLabel
        .waitFor({ state: "visible", timeout: 10_000 })
        .catch(() => undefined);
    if (!(await fieldLabel.count()))
        throw new Error(`SOUNDON_UI_CHANGED:field:${label}`);
    if (/date/i.test(label)) {
        const dateInput = await closestInputBelowLabel(scope, fieldLabel);
        if ((await dateInput.count()) && await selectCalendarDate(dateInput, value)) return;
    }
    let container = fieldLabel.locator("..");
    for (let depth = 0; depth < 5; depth++) {
        const siblingInput = fieldLabel
            .locator(
                "xpath=following-sibling::*[1]//input[not(@type='hidden') and not(@type='radio') and not(@type='checkbox')]",
            )
            .first();
        let input = (await siblingInput.count())
            ? siblingInput
            : container.locator('input:not([type="hidden"])');
        if ((await input.count()) !== 1) {
            const followingInput = fieldLabel.locator(
                "xpath=following::input[not(@type='hidden') and not(@type='radio') and not(@type='checkbox')][1]",
            ).first();
            if (await followingInput.count()) input = followingInput;
        }
        if ((await input.count()) === 1) {
            if (/date/i.test(label) && await selectCalendarDate(input, value)) {
                return;
            }
            await fillControlledInput(input, value);
            await scope.page().waitForTimeout(700);
            const persisted = await input.inputValue();
            if (persisted !== value)
                throw new Error(`SOUNDON_FIELD_NOT_PERSISTED:${label}:${value}`);
            return;
        }
        container = container.locator("..");
    }
    const inputDetails = await container
        .locator('input:not([type="hidden"])')
        .evaluateAll((inputs) =>
            inputs.map((input) => ({
                type: input.getAttribute("type"),
                value: (input as HTMLInputElement).value,
                placeholder: input.getAttribute("placeholder"),
                readOnly: (input as HTMLInputElement).readOnly,
            })),
        );
    if (label === "Pre-release time" && value === "00:00") {
        const clockSegments = inputDetails
            .map((item) => item.value)
            .filter((item) => /^\d{1,2}$/.test(item));
        if (
            (clockSegments.length >= 2 &&
                Number(clockSegments[0]) === 0 &&
                Number(clockSegments[1]) === 0) ||
            inputDetails.some((item) => /^(?:0|00|00:00)$/.test(item.value))
        ) {
            return;
        }
    }
    throw new Error(
        `SOUNDON_UI_CHANGED:input:${label}:count=${inputDetails.length}:` +
            `values=${inputDetails.map((item) => item.value).join("|")}`,
    );
}

async function selectCalendarDate(input: Locator, isoDate: string): Promise<boolean> {
    const match = isoDate.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) return false;
    const targetYear = Number(match[1]);
    const targetMonth = Number(match[2]);
    const targetDay = Number(match[3]);
    await input.click({ force: true });
    const page = input.page();
    const picker = page.locator(".semi-datepicker:visible").last();
    const opened = await picker
        .waitFor({ state: "visible", timeout: 5_000 })
        .then(() => true)
        .catch(() => false);
    if (!opened) return false;

    const monthNames = [
        "Jan", "Feb", "Mar", "Apr", "May", "Jun",
        "Jul", "Aug", "Sep", "Oct", "Nov", "Dec",
    ];
    for (let attempt = 0; attempt < 24; attempt++) {
        const heading = (await picker
            .locator(".semi-datepicker-navigation-month")
            .first()
            .innerText()
            .catch(() => ""))
            .trim();
        const shown = heading.match(/^([A-Za-z]{3})\s+(\d{4})$/);
        if (!shown) break;
        const shownMonth = monthNames.indexOf(shown[1] ?? "");
        const shownYear = Number(shown[2]);
        const monthDifference =
            (targetYear - shownYear) * 12 + (targetMonth - 1 - shownMonth);
        if (monthDifference === 0) break;

        const icons = picker.locator(".semi-datepicker-navigation svg");
        const iconCount = await icons.count();
        if (iconCount < 2) break;
        const iconIndex = monthDifference > 0
            ? (iconCount >= 4 ? 2 : iconCount - 1)
            : (iconCount >= 4 ? 1 : 0);
        await icons.nth(iconIndex).click({ force: true });
        await page.waitForTimeout(250);
    }

    const enabledDay = picker
        .locator(".semi-datepicker-day:not(.semi-datepicker-day-disabled)")
        .filter({ hasText: new RegExp(`^${targetDay}$`) })
        .first();
    if (!(await enabledDay.count())) {
        await page.keyboard.press("Escape");
        return false;
    }
    await enabledDay.click({ force: true });
    await page.waitForTimeout(500);
    const confirm = picker
        .getByRole("button", { name: /^(?:Confirm|OK|Apply)$/i })
        .filter({ visible: true })
        .last();
    if (await confirm.count()) {
        await confirm.click({ force: true });
        await page.waitForTimeout(300);
    }
    const selectedValue = await input.inputValue().catch(() => "");
    if (!selectedValue) return false;
    const selected = new Date(selectedValue);
    const persisted = !Number.isNaN(selected.getTime()) &&
        selected.getFullYear() === targetYear &&
        selected.getMonth() + 1 === targetMonth &&
        selected.getDate() === targetDay;
    // Semi's calendar sometimes stays visible even after the value persists.
    // It intercepts the following time selector and caused the misleading
    // NETWORK_TIMEOUT failures reported by the dashboard.
    if (persisted) {
        // Escape cancels the pending value in the current Semi DatePicker.
        // Blur/Tab commits it to Formik before closing the calendar.
        await input.press("Tab").catch(() => page.keyboard.press("Tab"));
        await page.waitForTimeout(300);
        if (await picker.isVisible().catch(() => false)) {
            await page.mouse.click(20, 20).catch(() => undefined);
        }
        await picker
            .waitFor({ state: "hidden", timeout: 3_000 })
            .catch(() => undefined);
    }
    return persisted;
}

async function fillControlledInput(input: Locator, value: string): Promise<void> {
    await input.evaluate((element) => {
        (element as HTMLInputElement).removeAttribute("readonly");
    });
    await input.click({ force: true });
    await input.fill(value);
    await input.press("Tab");
    await input.page().waitForTimeout(300);

    // A matching DOM value is not sufficient: Semi/Formik can still retain an
    // empty controlled value and discard it on Next/Save. Always dispatch the
    // native setter events so the form state is committed.
    await input.evaluate((element, nextValue) => {
        const target = element as HTMLInputElement & {
            _valueTracker?: { setValue(value: string): void };
        };
        const previousValue = target.value;
        target.removeAttribute("readonly");
        const setter = Object.getOwnPropertyDescriptor(
            HTMLInputElement.prototype,
            "value",
        )?.set;
        setter?.call(target, nextValue);
        target._valueTracker?.setValue(previousValue);
        target.dispatchEvent(new InputEvent("input", {
            bubbles: true,
            inputType: "insertText",
            data: nextValue,
        }));
        target.dispatchEvent(new Event("change", { bubbles: true }));
        target.dispatchEvent(new FocusEvent("blur", { bubbles: true }));
    }, value);
    await input.page().waitForTimeout(500);
    const persisted = await input.inputValue().catch(() => "");
    if (persisted !== value)
        throw new Error(`SOUNDON_FIELD_NOT_PERSISTED:controlled:${value}:${persisted}`);
}

export async function setChoiceField(
    page: Page,
    label: string,
    value: string,
    optional = false,
): Promise<void> {
    if (!value && optional) return;
    const container = await fieldContainer(page, label, optional);
    if (!container) return;
    const controls = container.locator(
        'input:not([type="hidden"]),[role="combobox"]',
    );
    if ((await controls.count()) === 0)
        throw new Error(`SOUNDON_UI_CHANGED:choice:${label}`);
    await resilientClick(controls.nth(0));
    await page.waitForTimeout(200);
    const option = page.getByRole("option", { name: value, exact: true });
    if ((await option.count()) === 1) {
        await resilientClick(option);
        return;
    }
    const portalOption = page
        .locator(".semi-portal,.semi-select-dropdown")
        .getByText(value, { exact: true })
        .filter({ visible: true });
    if ((await portalOption.count()) >= 1) {
        await resilientClick(portalOption.last());
        return;
    }
    const textOption = page
        .getByText(value, { exact: true })
        .filter({ visible: true });
    if ((await textOption.count()) >= 1) {
        await resilientClick(textOption.last());
        return;
    }
    // Long language/genre lists are virtualized. Search the open dropdown so
    // options outside the initial viewport (for example Malay) are rendered.
    let dropdownSearch = page
        .locator(".semi-portal input:visible,.semi-select-dropdown input:visible")
        .first();
    if (
        (await dropdownSearch.count()) === 0 &&
        (await controls.nth(0).evaluate((element) => element.tagName.toLowerCase()).catch(() => "")) === "input"
    ) {
        dropdownSearch = controls.nth(0);
    }
    if (await dropdownSearch.count()) {
        await dropdownSearch.fill(value);
        await page.waitForTimeout(500);
        const escaped = value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
        const searchedOption = page
            .locator(".semi-select-option:visible,[role=option]:visible")
            .filter({ hasText: new RegExp(`^(?:${escaped})(?:\\s*\\(|$)|\\(${escaped}\\)$`, "i") });
        if (await searchedOption.count()) {
            await searchedOption.first().click({ force: true });
            return;
        }
    }
    if (optional && label === "Subgenre") {
        // SoundOn changes the available subgenre list based on the selected
        // genre. An unmatched subgenre is optional and must not block syncing
        // the rest of the valid metadata.
        await page.keyboard.press("Escape").catch(() => undefined);
        return;
    }
    throw new Error(`METADATA_MAPPING_MISSING:${label}:${value}`);
}

export async function setSearchField(
    page: Page,
    label: string,
    value: string,
    optional = false,
    entityRetry = 0,
    contributorRole = "",
): Promise<void> {
    const started = Date.now();
    console.log(JSON.stringify({ event: "metadata_field_started", field: label, value, attempt: entityRetry + 1 }));
    try {
        await setSearchFieldValue(page, label, value, optional, entityRetry, contributorRole);
        console.log(JSON.stringify({ event: "metadata_field_completed", field: label, value, elapsed_ms: Date.now() - started }));
    } catch (error) {
        console.log(JSON.stringify({ event: "metadata_field_failed", field: label, value, role: contributorRole || undefined,
            error: error instanceof Error ? error.message : String(error), elapsed_ms: Date.now() - started }));
        if (error instanceof Error && canSkipEntityError(error.message)) {
            await skipBlockedEntity(page, label, value, optional);
            return;
        }
        throw error;
    }
}

async function setSearchFieldValue(
    page: Page,
    label: string,
    value: string,
    optional = false,
    entityRetry = 0,
    contributorRole = "",
): Promise<void> {
    if (!value && optional) return;
    if (!contributorRole && await waitForEntityCard(page, label, value, 100)) return;

    let fieldLabel = page
        .locator(".soundon-formik-field-label")
        .filter({ hasText: new RegExp(`^${label}`) })
        .filter({ visible: true })
        .first();
    if (!(await fieldLabel.count())) {
        fieldLabel = page
            .getByText(label, { exact: true })
            .filter({ visible: true })
            .first();
    }
    if (await fieldLabel.count()) {
        let addContainer = fieldLabel.locator("..");
        for (let depth = 0; depth < 5; depth++) {
            const fieldLabels = addContainer
                .locator(".soundon-formik-field-label")
                .filter({ visible: true });
            const addButton = addContainer
                .getByRole("button", { name: "Add", exact: true })
                .filter({ visible: true });
            const addText = addContainer
                .getByText("Add", { exact: true })
                .filter({ visible: true });
            let add = (await addButton.count()) === 1 ? addButton : addText;
            if ((await add.count()) !== 1 && (await fieldLabels.count()) > 1) {
                const followingAddButton = fieldLabel
                    .locator("xpath=following::button[normalize-space()='Add']")
                    .filter({ visible: true })
                    .first();
                const followingAddText = fieldLabel
                    .locator("xpath=following::*[normalize-space()='Add']")
                    .filter({ visible: true })
                    .first();
                add = (await followingAddButton.count()) === 1
                    ? followingAddButton
                    : followingAddText;
            }
            if ((await add.count()) === 1) {
                await page.waitForTimeout(500);
                const blockingDialogs = page.locator(".so-form-modal-dialog:visible");
                let blockingAttempts = 0;
                while ((await blockingDialogs.count()) > 0) {
                    const blocking = blockingDialogs.last();
                    // A newer SoundOn layout renders the contributor editor
                    // itself as a native dialog. That dialog contains the
                    // target field and is not a stale overlay. Closing it
                    // here discards the contributor flow and caused repeated
                    // `blocking_dialog:Production and additional contributors`
                    // failures. Only dismiss a dialog that does not own the
                    // field currently being filled.
                    const ownsCurrentField = (await blocking
                        .locator(".soundon-formik-field-label")
                        .filter({ hasText: new RegExp(`^${escapeRegExp(label)}`) })
                        .count()) > 0
                        || (await blocking
                            .getByText(label, { exact: true })
                            .filter({ visible: true })
                            .count()) > 0;
                    if (ownsCurrentField) break;

                    if (++blockingAttempts > 3) throw new Error(`SOUNDON_UI_CHANGED:blocking_dialog:${label}`);
                    const cancel = blocking.getByRole("button", { name: "Cancel", exact: true });
                    const close = blocking.locator(".so-form-modal-title-close");
                    if (await cancel.count()) await cancel.click({ force: true });
                    else if (await close.count()) await close.click({ force: true });
                    else await page.keyboard.press("Escape");
                    await blocking.waitFor({ state: "hidden", timeout: 5_000 }).catch(() => undefined);
                    if (await blocking.isVisible().catch(() => false)) {
                        await page.keyboard.press("Escape");
                        await page.waitForTimeout(500);
                    }
                }
                await add.click({ force: true });
                await page.waitForTimeout(300);
                // The add-artist modal heading has changed between SoundOn
                // builds ("Add ...", "Primary artists", or a generic title).
                // Prefer the old heading, then fall back to the visible modal
                // container so EP/Album uploads are not rejected solely due to
                // a copy/markup change in the dialog title.
                let heading = page.locator("h4:visible").filter({ hasText: /^Add / }).last();
                let modalFallback = page.locator("dialog.so-form-modal-dialog:visible, .so-form-modal-dialog:visible").last();
                if (!(await heading.count()) && await modalFallback.count()) {
                    heading = modalFallback.locator("h4:visible, .so-form-modal-title:visible, [role='heading']:visible").first();
                }
                if (!(await heading.count()))
                    throw new Error(`SOUNDON_UI_CHANGED:add_dialog:${label}`);
                const nativeDialog = page.locator("dialog:visible").filter({ has: heading });
                const hasNativeDialog = (await nativeDialog.count()) === 1;
                let dialog = hasNativeDialog ? nativeDialog : ((await modalFallback.count()) ? modalFallback : heading.locator(".."));
                // Pin this editor: `:visible` plus `.last()` otherwise resolves
                // to a different person's dialog after Submit closes this one.
                const editorId = `entity-${Date.now()}-${Math.random().toString(36).slice(2)}`;
                await dialog.evaluate((element, id) => element.setAttribute('data-automation-editor', id), editorId);
                dialog = page.locator(`[data-automation-editor="${editorId}"]`);
                if (hasNativeDialog || contributorRole || await dialog.locator('.semi-select-content-wrapper:visible,[role="combobox"]:visible').count()) {
                    let selectControl = dialog.locator(
                        ".semi-select-content-wrapper:visible",
                    );
                    if (!(await selectControl.count())) {
                        selectControl = dialog.locator('[role="combobox"]:visible');
                    }
                    if ((await selectControl.count()) === 0) {
                        if (contributorRole) throw new Error(`SOUNDON_UI_CHANGED:contributor_controls:${label}`);
                    } else if (contributorRole) {
                        if ((await selectControl.count()) < 2)
                            throw new Error(`SOUNDON_UI_CHANGED:contributor_controls:${label}`);
                        await selectControl.first().locator("..").click({ force: true });
                        await page.waitForTimeout(300);
                        const roleOption = page
                            .locator(".semi-select-option-text:visible")
                            .filter({ hasText: new RegExp(`^${escapeRegExp(contributorRole)}$`, "i") });
                        if (!(await roleOption.count())) {
                            if (!optional) throw new Error(`SOUNDON_ENTITY_MATCH_REQUIRED:${label}:${value}:role_unavailable:${contributorRole}`);
                            // Contributor roles are secondary metadata. SoundOn
                            // regularly changes this catalog; skip only the
                            // unsupported entry instead of rejecting the release.
                            await page.keyboard.press("Escape").catch(() => undefined);
                            const cancel = dialog
                                .getByRole("button", { name: "Cancel", exact: true })
                                .filter({ visible: true });
                            if (await cancel.count()) await cancel.last().click({ force: true });
                            else await page.keyboard.press("Escape").catch(() => undefined);
                            return;
                        }
                        await roleOption.first().evaluate((element) => {
                            const option = element.closest(".semi-select-option");
                            (option as HTMLElement | null)?.click();
                        });
                        await page.waitForTimeout(300);
                        await selectControl.nth(1).evaluate((element) => {
                            const select = element.closest(".semi-select");
                            (select as HTMLElement | null)?.click();
                        });
                    } else {
                        await selectControl.first().click({ force: true });
                    }
                    await page.waitForTimeout(300);
                }
                const dialogInput = contributorRole
                    ? page.locator(
                          '.semi-select-open:visible input[type="text"]:visible,.semi-select-focus:visible input[type="text"]:visible,.semi-portal input[type="text"]:visible',
                      )
                    : dialog.locator('input[type="text"]:visible');
                const initialDialogInput = contributorRole ? dialogInput.last() : dialogInput.first();
                await initialDialogInput
                    .waitFor({ state: "visible", timeout: 10_000 })
                    .catch(() => undefined);
                for (let dialogDepth = 0; dialogDepth < (hasNativeDialog ? 1 : 6); dialogDepth++) {
                    const input = contributorRole
                        ? page.locator(
                              '.semi-select-open:visible input[type="text"]:visible,.semi-select-focus:visible input[type="text"]:visible,.semi-portal input[type="text"]:visible',
                          )
                        : dialog.locator('input[type="text"]:visible');
                    const submit = dialog.getByRole("button", { name: "Submit", exact: true });
                    // The name-search dialog has no Submit button until an
                    // existing result or Add "name" as an artist is selected.
                    // Requiring Submit here prevented that first selection.
                    if (await input.count()) {
                        // Contributor dropdowns live in a portal outside the
                        // dialog. The newest (last) visible input belongs to
                        // the editor we just opened; an older portal can remain
                        // visible briefly and contains the previous person.
                        const activeInput = contributorRole ? input.last() : input.first();
                        // Set the complete copied value in one operation. A
                        // key-by-key simulation can corrupt Λ and other artist
                        // characters under a different Windows keyboard layout.
                        await pasteInputValue(activeInput, value);
                        const escapedValue = value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
                        const exactValue = new RegExp(`^${escapedValue}$`, "i");
                        let entitySelected = false;
                        let createdEntity = false;
                        // Semi portals can have a zero-size wrapper while the
                        // dropdown's children are visible. Check visibility on
                        // the actual options, not the portal container.
                        const entityScope = dialog.or(page.locator(".semi-portal,.semi-select-dropdown,[role=listbox]"));
                        const deadline = Date.now() + entityResultTimeout;
                        while (Date.now() < deadline && !entitySelected) {
                            // SoundOn's current artist results are plain divs rather
                            // than ARIA options. Prefer the exact result card before
                            // considering the "Add as an artist" footer; otherwise an
                            // existing artist can accidentally enter the create flow.
                            const currentArtistNames = entityScope.locator(".so-artist-preview-item-artist-name:visible");
                            const matchingArtistIndex = await currentArtistNames.evaluateAll((elements, expected) => {
                                const normalize = (text: string) => text
                                    .normalize("NFC")
                                    .replace(/\s+/g, " ")
                                    .trim()
                                    .toLocaleLowerCase();
                                return elements.findIndex((element) => normalize(element.textContent ?? "") === normalize(String(expected)));
                            }, value);
                            if (matchingArtistIndex >= 0) {
                                // Match canonical Unicode text rather than raw
                                // code points (e.g. precomposed vs combining
                                // accents), then click the exact live result.
                                await currentArtistNames.nth(matchingArtistIndex).evaluate((element) => {
                                    (element as HTMLElement).click();
                                });
                                entitySelected = true;
                                break;
                            }
                            const option = entityScope.getByRole("option", { name: exactValue }).filter({ visible: true });
                            if (await option.count()) {
                                await resilientClick(option.first());
                                entitySelected = true;
                                break;
                            }
                            const semiOption = page
                                .locator(".semi-select-option:visible")
                                .filter({ hasText: exactValue });
                            if (await semiOption.count()) {
                                await resilientClick(semiOption.first());
                                entitySelected = true;
                                break;
                            }
                            const entityOption = page
                                .locator("[artistname]:visible")
                                .filter({ hasText: exactValue });
                            if (await entityOption.count()) {
                                await resilientClick(entityOption.first());
                                entitySelected = true;
                                break;
                            }
                            const exactTextOption = page
                                .locator(".semi-portal")
                                .getByText(value, { exact: true })
                                .filter({ visible: true });
                            if ((await exactTextOption.count()) === 1) {
                                await resilientClick(exactTextOption);
                                entitySelected = true;
                                break;
                            }
                            const addAsEntityOption = entityScope
                                .getByText(new RegExp(
                                    `^\\s*\\+?\\s*Add\\s+["“']?${escapedValue}["”']?\\s+as\\s+(?:an?\\s+)?(?:artist|songwriter|lyricist|contributor)\\s*$`,
                                    "i",
                                ))
                                .filter({ visible: true });
                            if (await addAsEntityOption.count()) {
                                await addAsEntityOption.first().click({ force: true });
                                entitySelected = true;
                                createdEntity = true;
                                break;
                            }
                            const genericAddEntityOption = entityScope
                                .getByText(/^\s*\+?\s*Add\s+as\s+(?:an?\s+)?(?:artist|songwriter|lyricist|contributor)\s*$/i)
                                .filter({ visible: true });
                            if (await genericAddEntityOption.count()) {
                                await genericAddEntityOption.last().click({ force: true });
                                entitySelected = true;
                                createdEntity = true;
                                break;
                            }
                            const createOption = page
                                .locator(".semi-portal")
                                .getByText(new RegExp(`^(?:Create|Add)(?: new)?(?: artist)?\\s*["“']?${escapedValue}["”']?$`, "i"))
                                .filter({ visible: true });
                            if (await createOption.count()) {
                                await resilientClick(createOption.first());
                                entitySelected = true;
                                createdEntity = true;
                                break;
                            }
                            await page.waitForTimeout(100);
                        }
                        // Songwriter/Lyricist dialogs can be plain name forms
                        // without a results list. A correctly typed name and
                        // enabled Submit is the valid selection in that UI.
                        if (!entitySelected && /^(?:Songwriters|Lyricists)$/i.test(label)) {
                            const typed = await activeInput.inputValue().catch(() => "");
                            if (sameEntityName(typed, value) && await submit.first().isEnabled().catch(() => false)) {
                                entitySelected = true;
                                createdEntity = true;
                            }
                        }
                        if (!entitySelected) {
                            if (!optional)
                                throw new Error(
                                    `SOUNDON_ENTITY_MATCH_REQUIRED:${label}:${value}:options_unavailable`,
                                );
                            await page.keyboard.press("Escape");
                            const cancel = dialog.getByRole("button", { name: "Cancel", exact: true });
                            if ((await cancel.count()) === 1)
                                await cancel.click({ force: true });
                            return;
                        }
                        await page.waitForTimeout(300);
                        await submit.first().waitFor({ state: "visible", timeout: entitySubmitTimeout });
                        // The create option can reveal a form prefilled with
                        // the previous modal's person. Correct that form before
                        // validating the selected value.
                        await ensureDialogEntityName(dialog, value, contributorRole);
                        // Selection closes the dropdown and removes its visible
                        // search input. inputValue() on that vanished locator waits
                        // the full default timeout for EACH credit (30s). Read only
                        // an input that still exists; the saved entity card below
                        // remains the authoritative persistence check.
                        const selectedArtistValue = await activeInput.evaluateAll(elements =>
                            ((elements[0] as HTMLInputElement | undefined)?.value ?? "").trim(),
                        );
                        if (
                            selectedArtistValue !== "" &&
                            selectedArtistValue.localeCompare(value, undefined, { sensitivity: "accent" }) !== 0
                        ) {
                            throw new Error(
                                `SOUNDON_ENTITY_MATCH_REQUIRED:${label}:${value}:selected=${selectedArtistValue}`,
                            );
                        }
                        const createProfileIds = dialog
                            .locator("label.semi-radio")
                            .filter({
                                hasText: "Create and link to a new artist ID",
                            });
                        const profileCount = await createProfileIds.count();
                        if (profileCount > 0) {
                            // SoundOn may leave Submit disabled until this radio
                            // is actually selected. Clicking only the styled label
                            // was not reliable across browser builds; select the
                            // underlying radio when available and verify state.
                            const selected = await selectCreateArtistProfile(dialog);
                            if (!selected && createdEntity) {
                                throw new Error(`SOUNDON_ARTIST_PROFILE_CREATE_NOT_SELECTED:${label}:${value}`);
                            }
                        }
                        if (createdEntity || profileCount > 0) await page.waitForTimeout(300);
                        const submitButton = submit.first();
                        const submitReady = await waitUntilEnabled(submitButton, entitySubmitTimeout);
                        if (!submitReady) {
                            if (optional) {
                                // Secondary contributors are optional metadata in
                                // the SoundOn release flow. Some names cannot be
                                // linked or created (Submit remains disabled). Do
                                // not block the complete release for that entry.
                                const cancel = dialog
                                    .getByRole("button", { name: "Cancel", exact: true })
                                    .filter({ visible: true });
                                if (await cancel.count()) await cancel.last().click({ force: true });
                                else {
                                    const close = dialog.locator(".so-form-modal-title-close:visible");
                                    if (await close.count()) await close.last().click({ force: true });
                                    else await page.keyboard.press("Escape");
                                }
                                await heading
                                    .waitFor({ state: "hidden", timeout: 5_000 })
                                    .catch(() => undefined);
                                return;
                            }
                            const dialogMessage = (await dialog.innerText().catch(() => ""))
                                .replace(/\s+/g, " ")
                                .trim()
                                .slice(0, 500);
                            if (createdEntity) {
                                throw new Error(
                                    `SOUNDON_ARTIST_CREATE_SUBMIT_DISABLED:${label}:${value}:${dialogMessage}`,
                                );
                            }
                            throw new Error(
                                `SOUNDON_ENTITY_MATCH_REQUIRED:${label}:${value}:submit_disabled:${dialogMessage}`,
                            );
                        }
                        await resilientClick(submitButton);
                        const savedAfterSubmit = await waitForEntitySave(
                            page,
                            dialog,
                            label,
                            value,
                            entitySaveTimeout,
                        );
                        if (!savedAfterSubmit) {
                            // Capture the actual failing form before Cancel
                            // hides its inputs and text; hidden-dialog counts
                            // previously produced misleading profiles=0 details.
                            const selectedProfiles = await dialog.locator('input[type="radio"]:checked').count();
                            const submitEnabled = await submit.first().isEnabled().catch(() => false);
                            const dialogMessage = (await dialog.innerText().catch(() => ""))
                                .replace(/\s+/g, " ").trim().slice(0, 500);
                            const validationErrors = await dialog.locator('[role="alert"]:visible,.semi-form-field-error-message:visible,.soundon-formik-field-error:visible')
                                .allTextContents().catch(() => [] as string[]);
                            console.warn(JSON.stringify({ event: 'metadata_entity_save_unconfirmed', field: label, value, role: contributorRole,
                                dialog_visible: await dialog.isVisible().catch(() => false), selected_profiles: selectedProfiles,
                                submit_enabled: submitEnabled, dialog: dialogMessage, errors: validationErrors }));
                            const cancel = dialog
                                .getByRole("button", { name: "Cancel", exact: true })
                                .filter({ visible: true });
                            if (await cancel.count()) await cancel.last().click({ force: true });
                            else {
                                const close = dialog.locator(".so-form-modal-title-close:visible");
                                if (await close.count()) await close.last().click({ force: true });
                            }
                            await heading
                                .waitFor({ state: "hidden", timeout: 5_000 })
                                .catch(() => undefined);
                            if (await heading.isVisible().catch(() => false)) {
                                await page.keyboard.press("Escape");
                                await heading
                                    .waitFor({ state: "hidden", timeout: 5_000 })
                                    .catch(() => undefined);
                            }
                            if (!contributorRole && await waitForEntityCard(page, label, value, entityPersistTimeout)) return;
                            // A server/form rejection of the exact artist name is
                            // not a transient save delay. Retrying Add creates no
                            // valid credit; never silently strip punctuation.
                            if (validationErrors.some(error => /artist name cannot contain urls or website links/i.test(error))) {
                                throw new Error(`SOUNDON_CONTRIBUTOR_NOT_PERSISTED:${label}:${value}:stage=submit_verification:errors=${validationErrors.join('; ')}`);
                            }
                            if (!optional && entityRetry < entityMaxRetries) {
                                await page.waitForTimeout(1_000);
                                return setSearchField(
                                    page,
                                    label,
                                    value,
                                    optional,
                                    entityRetry + 1,
                                    contributorRole,
                                );
                            }
                            if (optional) return;
                            throw new Error(
                                `SOUNDON_CONTRIBUTOR_NOT_PERSISTED:${label}:${value}:stage=submit_verification:role=${contributorRole || "none"}:profiles=${selectedProfiles}:submit=${submitEnabled ? "enabled" : "disabled"}:errors=${validationErrors.join('; ')}:dialog=${dialogMessage}`,
                            );
                        }
                        // SoundOn can re-parent the saved entity card after the
                        // modal closes, making the old field container stale.
                        // Verify against the live page as well to avoid treating
                        // a successfully saved artist/contributor as a failure.
                        const persisted = await waitForEntityCard(page, label, value, entityPersistTimeout);
                        if (!persisted) {
                            if (entityRetry < entityMaxRetries) {
                                await page.waitForTimeout(1_000);
                                return setSearchField(
                                    page,
                                    label,
                                    value,
                                    optional,
                                    entityRetry + 1,
                                    contributorRole,
                                );
                            }
                            throw new Error(
                                `SOUNDON_CONTRIBUTOR_NOT_PERSISTED:${label}:${value}:stage=verify_after_submit:role=${contributorRole || "none"}`,
                            );
                        }
                        return;
                    }
                    dialog = dialog.locator("..");
                }
                const visibleInputs = await page
                    .locator('input[type="text"]:visible')
                    .evaluateAll((inputs) =>
                        inputs.map((input) => ({
                            placeholder: input.getAttribute("placeholder"),
                            className: input.getAttribute("class"),
                            parentClass: input.parentElement?.getAttribute("class"),
                        })),
                    );
                const selectControls = await dialog
                    .locator(".semi-select-content-wrapper:visible")
                    .evaluateAll((controls) =>
                        controls.map((control) => ({
                            text: control.textContent?.trim(),
                            html: control.parentElement?.outerHTML.slice(0, 900),
                        })),
                    );
                throw new Error(
                    `SOUNDON_UI_CHANGED:add_dialog_control:${label}:inputs=${JSON.stringify(visibleInputs).slice(0, 500)}:selects=${JSON.stringify(selectControls).slice(0, 1000)}`,
                );
            }
            addContainer = addContainer.locator("..");
        }
    }

    // Search/entity fields must never fall back to an arbitrary text input.
    // SoundOn places these fields in button-driven dialogs; a broad ancestor
    // lookup can otherwise select the track Title input and overwrite it.
    if (!optional) throw new Error(`SOUNDON_UI_CHANGED:search:${label}`);
}

async function waitUntilEnabled(locator: Locator, timeout: number): Promise<boolean> {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        if (
            (await locator.isVisible().catch(() => false)) &&
            (await locator.isEnabled().catch(() => false))
        ) return true;
        await locator.page().waitForTimeout(300);
    }
    return false;
}

async function pasteInputValue(input: Locator, value: string): Promise<void> {
    await input.fill(value);
    if ((await input.inputValue()).normalize("NFC") === value.normalize("NFC")) return;

    // Fallback for React/Semi controlled inputs that rewrite fill(). This uses
    // the native setter and input events, equivalent to pasting one string.
    await input.evaluate((element, nextValue) => {
        const field = element as HTMLInputElement;
        const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value")?.set;
        setter?.call(field, String(nextValue));
        field.dispatchEvent(new InputEvent("input", {
            bubbles: true,
            inputType: "insertFromPaste",
            data: String(nextValue),
        }));
        field.dispatchEvent(new Event("change", { bubbles: true }));
    }, value);
}

function sameEntityName(actual: string, expected: string): boolean {
    const normalize = (value: string) => value
        .normalize("NFC")
        .replace(/\s+/g, " ")
        .trim()
        .toLocaleLowerCase();
    return normalize(actual) === normalize(expected);
}

async function ensureDialogEntityName(
    dialog: Locator,
    value: string,
    contributorRole: string,
): Promise<void> {
    const labels = dialog
        .getByText(/^(?:Artist name|Name)$/i, { exact: true })
        .filter({ visible: true });
    const labelCount = await labels.count();
    // Contributor dialogs also contain a Role control. Never guess an input
    // when the explicit Artist name label is unavailable.
    if (labelCount === 0 && contributorRole) return;
    let resolvedInput: Locator | null = null;
    const explicitName = dialog.locator('input[name="artistName"],input[name="name"],input[id="artistName"],input[aria-label="Artist name"],input[aria-label="Name"]')
        .filter({ visible: true });
    if (await explicitName.count() === 1) resolvedInput = explicitName;
    if (!resolvedInput && labelCount > 0) {
        const group = labels.first().locator('xpath=ancestor::*[@data-so-field-id or contains(concat(" ",normalize-space(@class)," ")," semi-form-field ")][1]');
        if (await group.count()) {
            const nameInputs = group.locator('input[type="text"]:visible');
            if (await nameInputs.count() === 1) resolvedInput = nameInputs;
        }
    }
    if (!resolvedInput) {
        // After Add as an artist, Artist name becomes read-only text. The
        // first textbox below it is SoundCloud Artist ID, NOT the name.
        // Never use spatial proximity to fill optional external profile IDs.
        const externalProfiles = dialog.getByText(/^(?:Artist profile pages|SoundCloud|Spotify|Apple Music|Artist ID|User handle|Profile page link)$/i)
            .filter({ visible: true });
        const simpleInputs = dialog.locator('input[type="text"]:visible');
        if (!(await externalProfiles.count()) && await simpleInputs.count() === 1) resolvedInput = simpleInputs;
    }
    if (!resolvedInput) return;

    const current = await resolvedInput.inputValue().catch(() => "");
    if (!sameEntityName(current, value)) {
        await pasteInputValue(resolvedInput, value);
        await dialog.page().waitForTimeout(300);
    }
    const persisted = await resolvedInput.inputValue().catch(() => "");
    if (!sameEntityName(persisted, value)) {
        throw new Error(`SOUNDON_ENTITY_MATCH_REQUIRED:dialog_name:${value}:actual=${persisted}`);
    }
}

async function waitForEntitySave(
    page: Page,
    dialog: Locator,
    label: string,
    value: string,
    timeout: number,
): Promise<boolean> {
    const deadline = Date.now() + timeout;
    let confirmedAt: number | undefined;
    while (Date.now() < deadline) {
        const hidden = !await dialog.isVisible().catch(() => false);
        const persisted = await waitForEntityCard(page, label, value, 150);
        if (hidden && persisted) {
            confirmedAt ??= Date.now();
            if (Date.now() - confirmedAt >= 300) return true;
        } else {
            confirmedAt = undefined;
        }
        // An artist preview inside an open editor is not a committed credit.
        // Never close/cancel that editor while SoundOn is saving it.
        await page.waitForTimeout(150);
    }
    return false;
}

async function resilientClick(locator: Locator): Promise<void> {
    await locator.scrollIntoViewIfNeeded().catch(() => undefined);
    try {
        await locator.click({ timeout: 15_000 });
    } catch (error) {
        if (!(await locator.isVisible().catch(() => false))) throw error;
        await locator.evaluate((element: HTMLElement) => element.click());
    }
}

export async function waitForEntityCard(
    page: Page,
    label: string,
    value: string,
    timeout: number,
): Promise<boolean> {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        let fieldLabel = page
            .locator(".soundon-formik-field-label")
            .filter({ hasText: new RegExp(`^${escapeRegExp(label)}`) })
            .filter({ visible: true })
            .first();
        if (!(await fieldLabel.count())) {
            fieldLabel = page.getByText(label, { exact: true }).filter({ visible: true }).first();
        }
        if (await fieldLabel.count()) {
            const persisted = await fieldLabel.evaluate((labelElement, expected) => {
                const normalize = (text: string) => text.normalize("NFC").replace(/\s+/g, " ").trim().toLocaleLowerCase();
                const visible = (element: Element) => element.getClientRects().length > 0
                    && getComputedStyle(element).visibility !== "hidden";
                const editor = 'dialog,[role="dialog"],.so-form-modal-dialog,.semi-modal-content,.semi-portal,[data-automation-editor]';
                const owner = labelElement.closest(editor);
                if (labelElement.closest('[data-automation-editor]')) return false;
                let container = labelElement.parentElement;
                for (let depth = 0; container && depth < 12; depth++, container = container.parentElement) {
                    if (container === document.body || container === document.documentElement) break;
                    // Do not climb into a shared wrapper containing adjacent
                    // fields/tracks. The same person may appear in many credits.
                    const otherLabels = Array.from(container.querySelectorAll('.soundon-formik-field-label'))
                        .filter(element => element !== labelElement && visible(element)
                            && element.closest(editor) === owner);
                    if (otherLabels.length) break;
                    const saved = Array.from(container.querySelectorAll('*')).some(element => {
                        if (element.contains(labelElement) || labelElement.contains(element)) return false;
                        if (!visible(element) || element.closest(editor) !== owner) return false;
                        return normalize(element.textContent ?? "") === normalize(expected);
                    });
                    if (saved) return true;
                }
                return false;
            }, value);
            if (persisted) return true;
        }
        const remaining = deadline - Date.now();
        if (remaining > 0) await page.waitForTimeout(Math.min(200, remaining));
    }
    return false;
}

export async function setScopedRadio(
    page: Page,
    field: string,
    answer: string,
): Promise<void> {
    await closeResidualEntityDialogs(page);
    const container = await fieldContainer(page, field);
    if (!container) return;
    const label = container.getByText(answer, { exact: true });
    const count = await label.count();
    if (count !== 1)
        throw new Error(`SOUNDON_UI_CHANGED:radio:${field}:${answer}`);
    await resilientClick(label);
}

async function closeResidualEntityDialogs(page: Page): Promise<void> {
    // SoundOn occasionally renders the saved artist/contributor card but keeps
    // its Add modal mounted above the page. That invisible workflow residue
    // intercepts every later click (notably Publishing 100%) and used to make
    // an album repeat 30-second waits until the global watchdog fired.
    for (let attempt = 0; attempt < 4; attempt++) {
        const dialog = page
            .locator('dialog:visible,[role="dialog"]:visible,.so-form-modal-dialog:visible')
            .filter({
                has: page.locator("h4:visible").filter({ hasText: /^Add\s+/i }),
            })
            .last();
        if (!(await dialog.count()) || !(await dialog.isVisible().catch(() => false))) return;

        const cancel = dialog
            .getByRole("button", { name: "Cancel", exact: true })
            .filter({ visible: true })
            .last();
        const close = dialog.locator(
            ".so-form-modal-title-close:visible,.semi-modal-close:visible,button[aria-label='Close']:visible",
        ).last();
        if (await cancel.count()) {
            await cancel.evaluate((element: HTMLElement) => element.click()).catch(() => undefined);
        } else if (await close.count()) {
            await close.evaluate((element: HTMLElement) => element.click()).catch(() => undefined);
        } else {
            await page.keyboard.press("Escape").catch(() => undefined);
        }
        await dialog.waitFor({ state: "hidden", timeout: 3_000 }).catch(() => undefined);
        if (!(await dialog.isVisible().catch(() => false))) continue;

        // React/Semi sometimes ignores the first synthetic transition. Use the
        // visible backdrop as its supported secondary dismissal path.
        const mask = page.locator(".semi-modal-mask:visible").last();
        if (await mask.count()) await mask.click({ force: true }).catch(() => undefined);
        await page.keyboard.press("Escape").catch(() => undefined);
        await page.waitForTimeout(300);
    }

    const remaining = page
        .locator('dialog:visible,[role="dialog"]:visible,.so-form-modal-dialog:visible')
        .filter({ has: page.locator("h4:visible").filter({ hasText: /^Add\s+/i }) });
    if (await remaining.count()) {
        const title = (await remaining.last().innerText().catch(() => "Add entity"))
            .replace(/\s+/g, " ")
            .trim()
            .slice(0, 160);
        throw new Error(`SOUNDON_UI_CHANGED:entity_dialog_not_closed:${title}`);
    }
}

async function setChoiceIfPresent(
    page: Page,
    labels: string[],
    value: string,
): Promise<boolean> {
    if (!value) return false;
    for (const label of labels) {
        if (await fieldContainer(page, label, true)) {
            await setChoiceField(page, label, value);
            return true;
        }
    }
    return false;
}

async function setRadioIfPresent(
    page: Page,
    labels: string[],
    answer: string,
): Promise<boolean> {
    for (const label of labels) {
        const container = await fieldContainer(page, label, true);
        if (!container) continue;
        const option = container.getByText(answer, { exact: true });
        if ((await option.count()) !== 1)
            throw new Error(`SOUNDON_UI_CHANGED:radio:${label}:${answer}`);
        await resilientClick(option);
        return true;
    }
    return false;
}

async function setTextIfPresent(
    page: Page,
    labels: string[],
    value: string,
): Promise<boolean> {
    if (!value) return false;
    for (const label of labels) {
        if (await fieldContainer(page, label, true)) {
            await setTextField(page, label, value);
            return true;
        }
    }
    return false;
}

async function setSectionFile(
    page: Page,
    headingSelector: string,
    path: string,
    label: string,
): Promise<void> {
    if (!path) throw new Error(`ASSET_MISSING:${label}`);
    const heading = page.locator(headingSelector);
    if ((await heading.count()) !== 1)
        throw new Error(`SOUNDON_UI_CHANGED:file:${label}`);
    let container = heading.locator("..");
    for (let depth = 0; depth < 5; depth++) {
        const files = container.locator('input[type="file"]');
        const count = await files.count();
        if (count > 0) {
            await files.nth(0).setInputFiles(path);
            await waitForUpload(page, label);
            return;
        }
        container = container.locator("..");
    }
    throw new Error(`SOUNDON_UI_CHANGED:file_input:${label}`);
}

async function setTikTokAudio(
    page: Page,
    path: string,
    sectionTimeout = 180_000,
    trackLabel = "release",
): Promise<void> {
    if (!path) throw new Error("TIKTOK_AUDIO_INVALID:preview_missing");
    const heading = page
        .getByText("Create several official sounds for TikTok", { exact: true })
        .filter({ visible: true })
        .first();
    const sectionVisible = await heading
        .waitFor({ state: "visible", timeout: sectionTimeout })
        .then(() => true)
        .catch(() => false);
    if (!sectionVisible) {
        throw new Error(`SOUNDON_UI_CHANGED:tiktok_audio_section:${trackLabel}`);
    }
    let container = heading.locator("..");
    let addFile: Locator | null = null;
    for (let depth = 0; depth < 8; depth++) {
        const fileInput = container.locator('input[type="file"]');
        if (await fileInput.count()) {
            await fileInput.last().setInputFiles(path);
            await waitForUpload(page, "tiktok_audio");
            return;
        }
        const candidate = container.getByRole("button", {
            name: /add\s*file|upload\s*(?:audio|file)/i,
        });
        if (await candidate.count()) {
            addFile = candidate.first();
            break;
        }
        container = container.locator("..");
    }
    if (!addFile) throw new Error("SOUNDON_UI_CHANGED:tiktok_add_file");

    const deadline = Date.now() + 180_000;
    while (!(await addFile.isEnabled())) {
        if (Date.now() > deadline) throw new Error("TIKTOK_AUDIO_UPLOAD_FAILED:add_file_disabled");
        await page.waitForTimeout(1_000);
    }
    const chooserPromise = page.waitForEvent("filechooser", { timeout: 30_000 });
    await addFile.click();
    const chooser = await chooserPromise;
    await chooser.setFiles(path);

    await waitForUpload(page, "tiktok_audio");
}

export async function waitForUpload(page: Page, asset: string): Promise<void> {
    await page.waitForTimeout(500);
    const uploading = page.getByText(/Uploading\s+\d+%|processing\s+audio/i).filter({ visible: true })
        .or(page.locator('.so-audio-preview-card-status-waiting, .so-audio-preview-card-status-uploading, .so-audio-preview-card-status-processing'));
    const deadline = Date.now() + 300_000;
    while (await uploading.count()) {
        if (Date.now() >= deadline) throw new Error(`ASSET_UPLOAD_FAILED:${asset}:upload_did_not_complete`);
        await page.waitForTimeout(250);
    }
    const uploadError = page
        .locator('.so-audio-preview-card-status-error, .so-audio-preview-card-status-failed')
        .or(page.getByText(/upload (?:failed|error)|failed to upload|could not be processed|processing error/i).filter({ visible: true }));
    if (await uploadError.count()) {
        const code = asset === "cover"
            ? "COVER_UPLOAD_FAILED"
            : asset === "tiktok_audio"
              ? "TIKTOK_AUDIO_UPLOAD_FAILED"
              : "AUDIO_UPLOAD_FAILED";
        const detail = (await uploadError.first().innerText().catch(() => ""))
            .replace(/\s+/g, " ")
            .trim()
            .slice(0, 300);
        throw new Error(`${code}:${detail || "SoundOn rejected the asset"}`);
    }
}

function normalizeVersion(value: unknown): string {
    const version = String(value ?? "").trim();
    return /^original$/i.test(version) ? "" : version;
}

function normalizeGenre(value: unknown): string {
    const genre = String(value ?? "").trim();
    const soundOnGenres: Record<string, string> = {
        alternative: "Alternative/Indie",
        "alternative/indie": "Alternative/Indie",
        "hip-hop/rap": "Hip Hop/Rap",
        "hip hop/rap": "Hip Hop/Rap",
        religious: "Devotional/Inspirational",
        religi: "Devotional/Inspirational",
    };
    return soundOnGenres[genre.toLowerCase()] ?? genre;
}

export function normalizeSubgenre(value: unknown): string {
    // Try the exact Soundfresh subgenre after its parent genre is selected.
    // The SoundOn list is genre-dependent; setChoiceField safely skips only a
    // truly unavailable optional value instead of blanking valid choices.
    return String(value ?? "").trim();
}

function contributorName(value: unknown): string {
    return (String(value ?? "").split(/[,;]/)[0] ?? "")
        .replace(/\s*\(.*$/, "")
        .trim();
}

export function contributorDetails(
    value: unknown,
    defaultRole: string,
): { name: string; role: string } {
    return contributorEntries(value, defaultRole)[0] ?? { name: "", role: defaultRole };
}

export function contributorEntries(
    value: unknown,
    defaultRole: string,
): Array<{ name: string; role: string }> {
    const raw = String(value ?? "").replace(/\r/g, "").trim();
    if (!raw) return [];
    const roleAliases: Record<string, string> = {
        vocal: "Vocalist",
        vocals: "Vocalist",
        vocalist: "Vocalist",
        producer: "Producer",
        production: "Producer",
        "co-producer": "Co Producer",
        "co producer": "Co Producer",
        mixing: "Mixing Engineer",
        mastering: "Mastering Engineer",
        recording: "Recording Engineer",
        engineer: "Recording Engineer",
        "vocal design": "Vocal designer",
        "vocal designer": "Vocal designer",
        "graphic design": "Graphic Designer",
        "graphic designer": "Graphic Designer",
    };
    const normalizeEntry = (name: string, role: string) => ({
        name: name.trim(),
        role: roleAliases[role.trim().toLowerCase()] ?? role.trim(),
    });
    const entries: Array<{ name: string; role: string }> = [];
    // Soundfresh may nest the author marker inside the role, for example:
    // "Adi Damar (Lyricist (author))". Treat the complete parenthesized
    // suffix as the role while keeping the person as one entity.
    const pattern = /([^()\n;]+?)\s*\(((?:[^()]|\([^()]*\))+)\)/g;
    for (const match of raw.matchAll(pattern)) {
        const entry = normalizeEntry(match[1] ?? "", match[2] ?? defaultRole);
        if (entry.name) entries.push(entry);
    }
    if (entries.length === 0) {
        // A plain (role-less) Soundfresh contributor value is commonly the
        // same comma-separated primary-artist list.  Each comma-separated
        // name is a distinct SoundOn entity; submitting the complete string
        // makes SoundOn search for a non-existent person such as
        // "MARCIANO, BLEK, T3ZNO".
        //
        // Do not split a comma immediately followed by a dot: punctuation
        // such as "Syafriadi,.S.Sos" is part of one displayed name.
        for (const name of raw.split(/(?:[;\n]+|\s*,\s*(?!\.))/)) {
            const entry = normalizeEntry(name, defaultRole);
            if (entry.name) entries.push(entry);
        }
    }

    return entries.filter(
        (entry, index, all) =>
            all.findIndex(
                (candidate) =>
                    candidate.name.toLocaleLowerCase() === entry.name.toLocaleLowerCase() &&
                    candidate.role.toLocaleLowerCase() === entry.role.toLocaleLowerCase(),
            ) === index,
    );
}

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

function normalizeDate(value: unknown): string {
    const raw = String(value ?? "").trim();
    if (!raw) return "";
    const isoDate = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (isoDate) return `${isoDate[1]}-${isoDate[2]}-${isoDate[3]}`;
    const parsed = new Date(raw);
    if (Number.isNaN(parsed.getTime())) return raw;
    return [
        parsed.getFullYear(),
        String(parsed.getMonth() + 1).padStart(2, "0"),
        String(parsed.getDate()).padStart(2, "0"),
    ].join("-");
}

export function normalizeArtistMetadata(value: unknown): string {
    return String(value ?? "")
        // Soundfresh's table may append the next column heading to an artist
        // value (for example "Anca/Asrul language"). Never send that UI label
        // to SoundOn as part of the artist name.
        .replace(/\s+language\s*$/i, "")
        .replace(/\s+/g, " ")
        .trim();
}

export function resolveSoundOnPreReleaseDate(
    preReleaseValue: unknown,
    releaseValue: unknown,
    todayValue: unknown = new Date(),
): string {
    let preReleaseDate = normalizeDate(preReleaseValue);
    const releaseDate = normalizeDate(releaseValue);
    if (!releaseDate) return "";

    // Several Soundfresh layouts omit the pre-release table value even though
    // the release should receive the standard schedule. Use seven days before
    // the planned release as the deterministic fallback.
    if (!preReleaseDate) {
        const fallback = new Date(`${releaseDate}T00:00:00Z`);
        if (Number.isNaN(fallback.getTime())) return "";
        fallback.setUTCDate(fallback.getUTCDate() - 7);
        preReleaseDate = normalizeDate(fallback);
    }

    const release = new Date(`${releaseDate}T00:00:00Z`);
    const preRelease = new Date(`${preReleaseDate}T00:00:00Z`);
    const todayDate = normalizeDate(todayValue);
    const today = new Date(`${todayDate}T00:00:00Z`);
    if (
        Number.isNaN(release.getTime()) ||
        Number.isNaN(preRelease.getTime()) ||
        Number.isNaN(today.getTime())
    ) return "";

    // A pre-release is normally earlier than the planned release. Preserve
    // Soundfresh's date for both TikTok and YouTube; reject only an impossible
    // date after the planned release.
    if (preRelease > release) return "";

    // Also keep optional pre-release disabled for elapsed dates. This guards
    // malformed/source values without blocking the main draft creation.
    if (preRelease <= today) return "";
    return preReleaseDate;
}

export function writerFieldEntries(value: unknown): {
    songwriters: string[];
    lyricists: string[];
} {
    const entries = contributorEntries(value, "Songwriter");
    const unique = (names: string[]) => names.filter(
        (name, index, all) => all.findIndex(
            (candidate) => candidate.toLocaleLowerCase() === name.toLocaleLowerCase(),
        ) === index,
    );
    return {
        songwriters: unique(entries
            .filter((entry) => /composer|songwriter|writer/i.test(entry.role))
            .map((entry) => entry.name)),
        lyricists: unique(entries
            .filter((entry) => /lyricist|author/i.test(entry.role))
            .map((entry) => entry.name)),
    };
}

export function normalizeLanguage(value: unknown): string {
    const raw = String(value ?? "").trim();
    // SoundOn's current language catalog has no Malay/Melayu entry; its only
    // supported Bahasa option is this value.
    if (/^(malay|bahasa melayu|bahasa malaysia)$/i.test(raw)) {
        return "Bahasa Indonesia (Bahasa)";
    }
    if (/^(thai|ภาษาไทย|ไทย)$/i.test(raw)) {
        return "ไทย (Thai)";
    }
    if (/^(batak(?:\s+toba)?|toba batak)$/i.test(raw)) {
        return "Bahasa Indonesia (Bahasa)";
    }
    if (/^(?:tagalog|filipino|filipino\s*\(tagalog\)|tagalog\s*\(filipino\))$/i.test(raw)) {
        // SoundOn's current catalog does not expose Filipino/Tagalog. English
        // is the supported neutral fallback used to keep the draft uploadable.
        return "English";
    }
    return raw;
}
