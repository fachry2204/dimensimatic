import { describe, expect, it, vi } from "vitest";
import { rejectSoundfreshRelease } from "./reject-release.js";

describe("rejectSoundfreshRelease", () => {
    it("copies the rejection reason and submits Reject Release", async () => {
        let dropdownOpened = false;
        const open = { count: vi.fn(async () => dropdownOpened ? 1 : 0), click: vi.fn(async () => undefined) };
        const dropdown = {
            count: vi.fn(async () => 1),
            click: vi.fn(async () => { dropdownOpened = true; }),
        };
        const submit = { count: vi.fn(async () => 1), click: vi.fn(async () => undefined) };
        const textbox = { count: vi.fn(async () => 1), fill: vi.fn(async () => undefined) };
        const modal = {
            count: vi.fn(async () => 1), waitFor: vi.fn(async () => undefined), isVisible: vi.fn(async () => false),
            getByRole: vi.fn((role: string) => role === "textbox"
                ? { filter: vi.fn(() => ({ first: vi.fn(() => textbox) })) }
                : { filter: vi.fn(() => ({ last: vi.fn(() => submit) })) }),
            locator: vi.fn(),
        };
        const page = {
            url: vi.fn(() => "https://cms.soundfresh.id/admin/releases/123"),
            getByRole: vi.fn((role: string, options?: { name?: RegExp }) => role === "dialog"
                ? { filter: vi.fn(() => ({ first: vi.fn(() => modal) })) }
                : role === "button" && options?.name?.test("Reject Release")
                    ? { filter: vi.fn(() => ({ first: vi.fn(() => ({ count: vi.fn(async () => 0) })) })) }
                : { filter: vi.fn(() => ({ first: vi.fn(() => dropdown) })), allInnerTexts: vi.fn(async () => []) }),
            getByText: vi.fn(() => ({ filter: vi.fn(() => ({ first: vi.fn(() => open) })) })),
            locator: vi.fn(() => ({
                filter: vi.fn(() => ({ filter: vi.fn(() => ({ first: vi.fn(() => dropdown) })) })),
            })),
            waitForTimeout: vi.fn(async () => undefined),
        } as any;

        await rejectSoundfreshRelease(page, "Artwork tidak sesuai ketentuan");

        expect(textbox.fill).toHaveBeenCalledWith("Artwork tidak sesuai ketentuan");
        expect(dropdown.click).toHaveBeenCalledOnce();
        expect(open.click).toHaveBeenCalledOnce();
        expect(submit.click).toHaveBeenCalledOnce();
    });
});
