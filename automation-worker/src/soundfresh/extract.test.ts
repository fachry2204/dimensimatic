import { describe, expect, it } from "vitest";
import { labelIdentity, normalizePrimaryArtists, preReleaseValue } from "./extract.js";

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

describe('whitelabel identity', () => {
    it('reads label name and explicit active/inactive status without inferring activation from a label name', () => {
        expect(labelIdentity({ 'Nama Label': 'PT RUANG DIMENSI RECORDS', 'Status Whitelabel': 'Aktif' })).toEqual({ whitelabel_active: true, label_name: 'PT RUANG DIMENSI RECORDS' });
        expect(labelIdentity({ 'Record Label': 'Other label', 'Whitelabel': 'Tidak Aktif' })).toEqual({ whitelabel_active: false, label_name: 'Other label' });
        expect(labelIdentity({ 'Record Label': 'Other label' }).whitelabel_active).toBe(false);
    });
});
