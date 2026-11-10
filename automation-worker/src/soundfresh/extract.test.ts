import { describe, expect, it } from "vitest";
import { normalizePrimaryArtists, preReleaseValue } from "./extract.js";

describe("normalizePrimaryArtists", () => {
    it("preserves separate Soundfresh artist rows", () => {
        expect(normalizePrimaryArtists("MARCIANO\nBLEK\nT3ZNO"))
            .toBe("MARCIANO, BLEK, T3ZNO");
    });
});

describe("preReleaseValue", () => {
    it("recognizes Soundfresh label punctuation and capitalization variants", () => {
        expect(preReleaseValue({
            "TikTok and YouTube Music Pre release date": "September 20, 2026",
        })).toBe("September 20, 2026");
    });
});
