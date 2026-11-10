import { describe, expect, it } from "vitest";
import { findDraft, findDrafts } from "./draft-finder.js";

describe("findDraft", () => {
    const missingSearch = {
        filter() { return this; },
        or() { return this; },
        first() { return this; },
        count: async () => 0,
        getAttribute: async () => null,
        click: async () => undefined,
        isVisible: async () => false,
        isDisabled: async () => true,
        innerText: async () => "",
        locator() { return this; },
    };

    it("matches normalized title and artist and returns its draft reference", async () => {
        const candidate = {
            isVisible: async () => true,
            innerText: async () => "  My   Song\nTHE Artist ",
            locator: (selector: string) => selector === "a" ? ({
                allInnerTexts: async () => ["My Song"],
            }) : ({
                count: async () => 1,
                nth: () => ({ getAttribute: async () => "/library/publish/single?source=draft&id=123" }),
            }),
        };
        const page = {
            waitForLoadState: async () => undefined,
            url: () => "https://www.soundon.global/library",
            locator: () => ({ count: async () => 1, nth: () => candidate }),
            getByPlaceholder: () => missingSearch,
            getByRole: () => missingSearch,
            getByText: () => missingSearch,
        } as any;

        await expect(findDraft(page, "My Song", "The Artist")).resolves.toEqual({
            found: true,
            draft_id: "123",
            draft_url: "https://www.soundon.global/library/publish/single?source=draft&id=123",
        });
    });

    it("blocks a duplicate even when SoundOn renders no draft href", async () => {
        const noLinks = { count: async () => 0, allInnerTexts: async () => [] };
        const candidate = {
            isVisible: async () => true,
            innerText: async () => "Goodbye My Beautiful Past\nNorpanic\n1\n70%",
            locator: () => noLinks,
        };
        const page = {
            waitForLoadState: async () => undefined,
            url: () => "https://www.soundon.global/library/list?type=draft",
            locator: () => ({ count: async () => 1, nth: () => candidate }),
            getByPlaceholder: () => missingSearch,
            getByRole: () => missingSearch,
            getByText: () => missingSearch,
        } as any;

        const match = await findDraft(page, "Goodbye My Beautiful Past", "Norpanic");
        expect(match?.found).toBe(true);
        expect(match?.draft_id).toMatch(/^existing-/);
        expect(match?.draft_url).toContain("type=draft");
    });

    it("does not block a release when only its title matches", async () => {
        const candidate = {
            isVisible: async () => true,
            innerText: async () => "My Song\nDifferent Artist\n1",
            locator: () => ({ count: async () => 0, allInnerTexts: async () => [] }),
        };
        const page = {
            waitForLoadState: async () => undefined,
            url: () => "https://www.soundon.global/library/list?type=draft",
            locator: () => ({ count: async () => 1, nth: () => candidate }),
            getByPlaceholder: () => missingSearch,
            getByRole: () => missingSearch,
            getByText: () => missingSearch,
        } as any;

        await expect(findDraft(page, "My Song", "Correct Artist")).resolves.toBeNull();
    });

    it("finds all matching single and album rows in one draft scan", async () => {
        const makeCandidate = (text: string) => ({
            isVisible: async () => true,
            innerText: async () => text,
            locator: () => ({ count: async () => 0, allInnerTexts: async () => [] }),
        });
        const candidates = [
            makeCandidate("Single Song\nArtist A\n1"),
            makeCandidate("Album Name\nArtist B\n5"),
        ];
        const page = {
            waitForLoadState: async () => undefined,
            waitForTimeout: async () => undefined,
            url: () => "https://www.soundon.global/library/list?type=draft",
            locator: () => ({ count: async () => candidates.length, nth: (index: number) => candidates[index] }),
            getByPlaceholder: () => missingSearch,
            getByRole: () => missingSearch,
            getByText: () => missingSearch,
        } as any;

        const matches = await findDrafts(page, [
            { key: "single", title: "Single Song", artist: "Artist A" },
            { key: "album", title: "Album Name", artist: "Artist B" },
        ]);
        expect(Object.keys(matches).sort()).toEqual(["album", "single"]);
    });
});
