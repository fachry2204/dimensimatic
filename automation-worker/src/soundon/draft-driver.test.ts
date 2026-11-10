import { describe, expect, it } from "vitest";
import { canSkipEntityError } from "./draft-driver.js";

describe('skippable entity failures', () => {
    it('skips unavailable choices but never a mismatched person or network failure', () => {
        expect(canSkipEntityError('SOUNDON_ENTITY_MATCH_REQUIRED:Contributors:Name:options_unavailable')).toBe(true);
        expect(canSkipEntityError('SOUNDON_ENTITY_MATCH_REQUIRED:Primary artists:Name:submit_disabled:details')).toBe(true);
        expect(canSkipEntityError('SOUNDON_ENTITY_MATCH_REQUIRED:Contributors:Name:role_unavailable:Vocalist')).toBe(true);
        expect(canSkipEntityError('SOUNDON_ENTITY_MATCH_REQUIRED:Primary artists:Name:selected=Different person')).toBe(false);
        expect(canSkipEntityError('NETWORK_TIMEOUT')).toBe(false);
    });
});
import { contributorCreditsForTrack, contributorDetails, contributorEntries, matchesFieldLabel, normalizeArtistMetadata, normalizeLanguage, normalizeSubgenre, preReleaseFieldsPersisted, primaryArtistEntries, resolveSoundOnPreReleaseDate, writerFieldEntries } from "./draft-driver.js";

describe("contributorDetails", () => {
    it("splits comma-separated primary artists into individual entries", () => {
        expect(primaryArtistEntries(
            "Dev Rival Octiano, Dimas Eka Saputra, Edy Haryono, Raka Jack Aditya",
        )).toEqual([
            "Dev Rival Octiano",
            "Dimas Eka Saputra",
            "Edy Haryono",
            "Raka Jack Aditya",
        ]);
    });

    it("preserves unique artist characters and recognizes unicode separators", () => {
        expect(primaryArtistEntries("ALVEΛ， Bryan Renaldo")).toEqual([
            "ALVEΛ",
            "Bryan Renaldo",
        ]);
    });

    it("treats an unlabelled multi-artist fallback as separate contributors", () => {
        expect(contributorEntries("MARCIANO, BLEK, T3ZNO", "Vocalist")).toEqual([
            { name: "MARCIANO", role: "Vocalist" },
            { name: "BLEK", role: "Vocalist" },
            { name: "T3ZNO", role: "Vocalist" },
        ]);
        expect(contributorCreditsForTrack({
            primary_artist: "MARCIANO, BLEK, T3ZNO",
        })).toEqual([
            { name: "MARCIANO", role: "Vocalist" },
            { name: "BLEK", role: "Vocalist" },
            { name: "T3ZNO", role: "Vocalist" },
        ]);
    });

    it("recovers primary artist boundaries lost by old Soundfresh pages", () => {
        expect(primaryArtistEntries(
            "Dev Rival Octiano Dimas Eka Saputra Edy Haryono Raka Jack Aditya",
            "Edy Haryono (Vocalist) Raka Jack Aditya (Bass Guitar) Dimas Eka Saputra (Drums) Dev Rival Octiano (Guitar)",
        )).toEqual([
            "Dev Rival Octiano",
            "Dimas Eka Saputra",
            "Edy Haryono",
            "Raka Jack Aditya",
        ]);
    });

    it("recovers collapsed artist names from songwriter credits", () => {
        expect(primaryArtistEntries(
            "MARCIANO BLEK T3ZNO",
            "Marciano (Lyricist (author)) Marciano (Composer (melody)) Blek (Lyricist (author)) T3zno (Lyricist (author))",
        )).toEqual(["Marciano", "Blek", "T3zno"]);
    });

    it("keeps commas in names and maps Soundfresh roles", () => {
        expect(
            contributorDetails("Syafriadi,.S.Sos (Vocalist)", "Vocalist"),
        ).toEqual({ name: "Syafriadi,.S.Sos", role: "Vocalist" });
        expect(
            contributorDetails("roy (Vocal Design)", "Producer"),
        ).toEqual({ name: "roy", role: "Vocal designer" });
        expect(
            contributorDetails("Raka (Graphic Design)", "Producer"),
        ).toEqual({ name: "Raka", role: "Graphic Designer" });
    });

    it("uses the first pair when Soundfresh concatenates entries", () => {
        expect(
            contributorDetails(
                "May Yunita (Vocalist) Eko Wibowo (Guitar)",
                "Vocalist",
            ),
        ).toEqual({ name: "May Yunita", role: "Vocalist" });
    });

    it("parses every multiline production contributor", () => {
        expect(
            contributorEntries(
                "Thya (Producer)\nIhsan NR (Creative Director)\nSauqia (Studio)\nFirda NA (Vocal Design)",
                "Producer",
            ),
        ).toEqual([
            { name: "Thya", role: "Producer" },
            { name: "Ihsan NR", role: "Creative Director" },
            { name: "Sauqia", role: "Studio" },
            { name: "Firda NA", role: "Vocal designer" },
        ]);
    });

    it("parses concatenated contributor pairs without delimiters", () => {
        expect(
            contributorEntries(
                "May Yunita (Vocalist) Eko Wibowo (Guitar)",
                "Vocalist",
            ),
        ).toEqual([
            { name: "May Yunita", role: "Vocalist" },
            { name: "Eko Wibowo", role: "Guitar" },
        ]);
    });

    it("extracts every songwriter name for SoundOn lyricists", () => {
        expect(
            contributorEntries(
                "Hendie Hari Syaputra (Lyricist & Composer)\nRina Putri (Composer)",
                "Songwriter",
            ),
        ).toEqual([
            { name: "Hendie Hari Syaputra", role: "Lyricist & Composer" },
            { name: "Rina Putri", role: "Composer" },
        ]);
    });

    it("keeps a nested lyricist and author value as one songwriter", () => {
        expect(
            contributorEntries(
                "Adi Damar (Lyricist (author))",
                "Songwriter",
            ),
        ).toEqual([
            { name: "Adi Damar", role: "Lyricist (author)" },
        ]);
    });

    it("falls back to the field's default role", () => {
        expect(contributorDetails("Majestic Malay", "Vocalist")).toEqual({
            name: "Majestic Malay",
            role: "Vocalist",
        });
    });

    it("keeps Soundfresh instrumental contributor roles without adding a vocalist", () => {
        expect(contributorCreditsForTrack({
            instrumental: true,
            primary_artist: "Majestic Malay",
            contributors: "Majestic Malay (Tenor Saxophone)",
        })).toEqual([{ name: "Majestic Malay", role: "Tenor Saxophone" }]);
    });
});

describe("matchesFieldLabel", () => {
    it("matches required SoundOn labels that include an asterisk and helper text", () => {
        expect(matchesFieldLabel(
            "Does this track have an existing publishing administrator or publisher? * Required field",
            "Does this track have an existing publishing administrator or publisher?",
        )).toBe(true);
    });
});

describe("resolveSoundOnPreReleaseDate", () => {
    it("defaults to seven days before release when Soundfresh omits the value", () => {
        expect(resolveSoundOnPreReleaseDate(
            null,
            "September 27, 2026",
            "September 14, 2026",
        )).toBe("2026-09-20");
    });

    it("keeps a future Soundfresh pre-release date earlier than the release date", () => {
        expect(resolveSoundOnPreReleaseDate(
            "September 1, 2026",
            "September 15, 2026",
            "August 16, 2026",
        )).toBe("2026-09-01");
    });

    it("keeps a pre-release date close to release", () => {
        expect(resolveSoundOnPreReleaseDate(
            "September 10, 2026",
            "September 15, 2026",
            "August 16, 2026",
        )).toBe("2026-09-10");
    });

    it("omits an elapsed pre-release date", () => {
        expect(resolveSoundOnPreReleaseDate(
            "August 10, 2026",
            "August 30, 2026",
            "August 16, 2026",
        )).toBe("");
    });

    it("omits a same-day pre-release date rejected by SoundOn", () => {
        expect(resolveSoundOnPreReleaseDate(
            "September 14, 2026",
            "September 23, 2026",
            "September 14, 2026",
        )).toBe("");
    });

    it("keeps a future pre-release date equal to the release date", () => {
        expect(resolveSoundOnPreReleaseDate(
            "September 15, 2026",
            "September 15, 2026",
            "August 16, 2026",
        )).toBe("2026-09-15");
    });
});

describe("writerFieldEntries", () => {
    it("splits composer and lyricist roles into the correct SoundOn fields", () => {
        expect(writerFieldEntries(
            "Mads Alhamid (Lyricist & Composer)\nRina Putri (Composer)\nAdi Damar (Lyricist (author))",
        )).toEqual({
            songwriters: ["Mads Alhamid", "Rina Putri"],
            lyricists: ["Mads Alhamid", "Adi Damar"],
        });
    });

    it("maps Soundfresh composer to Songwriters and keeps the same person as Lyricist", () => {
        expect(writerFieldEntries(
            "Syam Sainal (Lyricist (author))\nSyam Sainal (Composer (melody))",
        )).toEqual({
            songwriters: ["Syam Sainal"],
            lyricists: ["Syam Sainal"],
        });
    });
});

describe("normalizeLanguage", () => {
    it("maps Thai to SoundOn's localized option label", () => {
        expect(normalizeLanguage("Thai")).toBe("ไทย (Thai)");
        expect(normalizeLanguage("ภาษาไทย")).toBe("ไทย (Thai)");
    });

    it("maps regional language names to SoundOn option labels", () => {
        expect(normalizeLanguage("Batak Toba")).toBe("Bahasa Indonesia (Bahasa)");
        expect(normalizeLanguage("Tagalog")).toBe("English");
        expect(normalizeLanguage("Filipino")).toBe("English");
    });
});

describe("normalizeArtistMetadata", () => {
    it("removes a leaked Soundfresh language column label", () => {
        expect(normalizeArtistMetadata("Anca/Asrul language")).toBe("Anca/Asrul");
    });
});

describe("normalizeSubgenre", () => {
    it("keeps source values so SoundOn can select valid genre-dependent options", () => {
        expect(normalizeSubgenre("Indie")).toBe("Indie");
        expect(normalizeSubgenre("Indie Pop")).toBe("Indie Pop");
        expect(normalizeSubgenre("Indonesian Pop")).toBe("Indonesian Pop");
    });
});

describe("preReleaseFieldsPersisted", () => {
    it("does not mistake the time input for the date input", () => {
        expect(preReleaseFieldsPersisted(["00:00", "2026-08-30"], "2026-08-30"))
            .toMatchObject({ date: true, time: true });
    });

    it("accepts SoundOn's localized date display", () => {
        expect(preReleaseFieldsPersisted(["Aug 30, 2026", "12:00 AM"], "2026-08-30"))
            .toMatchObject({ date: true, time: true });
    });

    it("accepts the selected time text rendered by SoundOn's combobox", () => {
        expect(preReleaseFieldsPersisted(["2026-08-30", "12:00 AM"], "2026-08-30"))
            .toEqual({
                date: true,
                time: true,
                actualDate: "2026-08-30",
                actualTime: "12:00 AM",
            });
    });
});
