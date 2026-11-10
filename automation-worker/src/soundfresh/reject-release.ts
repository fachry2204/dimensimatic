import type { Locator, Page } from "playwright";

export async function rejectSoundfreshRelease(page: Page, reason: string): Promise<void> {
    // Current Soundfresh pages expose Reject Release as a primary action.
    // Use the button directly; only fall back to the legacy Verify dropdown
    // when that direct action is genuinely absent.
    let rejectButton = page.getByRole("button", { name: /^Reject Release$/i }).filter({ visible: true }).first();
    if (!await rejectButton.count()) {
        rejectButton = page.getByText(/^Reject Release$/i, { exact: true }).filter({ visible: true }).first();
    }
    if (!await rejectButton.count()) {
        // Soundfresh exposes the arrow as a separate "Toggle Dropdown"
        // button. Prefer it over the main Verify Release action.
        let dropdown = page.getByRole("button", { name: /Toggle Dropdown/i }).filter({ visible: true }).first();
        if (!await dropdown.count()) dropdown = page.locator([
            '[data-bs-toggle="dropdown"]',
            '[data-toggle="dropdown"]',
            'button.dropdown-toggle',
            'button[aria-haspopup="menu"]',
            'button[aria-expanded]',
        ].join(",")).filter({ hasText: /Verify Release/i }).filter({ visible: true }).first();
        if (!await dropdown.count()) {
            dropdown = page.getByRole("button", { name: /^Verify Release\b/i }).filter({ visible: true }).first();
        }
        if (!await dropdown.count()) {
            dropdown = page.getByText(/Toggle Dropdown/i).filter({ visible: true }).first();
        }
        if (!await dropdown.count()) {
            dropdown = page.getByText(/^Verify Release$/i).filter({ visible: true }).last();
        }
        if (!await dropdown.count()) {
            const buttons = (await page.getByRole("button").allInnerTexts().catch(() => [])).slice(0, 20);
            throw new Error(`SOUNDFRESH_UI_CHANGED:verify_release_dropdown:buttons=${JSON.stringify(buttons)}:url=${page.url()}`);
        }
        // Do not toggle an already-open menu closed. Soundfresh sometimes
        // restores the Verify Release menu in its expanded state on reload.
        const expanded = typeof (dropdown as any).getAttribute === "function"
            ? await dropdown.getAttribute("aria-expanded").catch(() => null)
            : null;
        if (expanded !== "true") {
            await dropdown.click({ timeout: 20_000 });
        }
        await page.waitForTimeout(300);
        // getByText resolves the deepest visible text node; broad ancestor
        // selectors can accidentally choose the whole header btn-group,
        // which is then blocked when the confirmation modal opens.
        rejectButton = page.getByText(/Reject\s+Release/i).filter({ visible: true }).first();
    }
    if (!await rejectButton.count()) {
        const buttons = (await page.getByRole("button").allInnerTexts().catch(() => [])).slice(0, 20);
        throw new Error(`SOUNDFRESH_UI_CHANGED:reject_release_button:buttons=${JSON.stringify(buttons)}:url=${page.url()}`);
    }
    await rejectButton.click({ timeout: 20_000 });

    let modal: Locator = page.getByRole("dialog").filter({ visible: true }).first();
    if (!await modal.count()) modal = page.locator('.modal.show:visible, [class*="modal" i]:visible').first();
    await modal.waitFor({ state: "visible", timeout: 10_000 });

    let reasonInput = modal.getByRole("textbox").filter({ visible: true }).first();
    if (!await reasonInput.count()) reasonInput = page.locator('textarea:visible, input[name*="reason" i]:visible').first();
    if (!await reasonInput.count()) throw new Error(`SOUNDFRESH_UI_CHANGED:reject_reason_input:url=${page.url()}`);
    await reasonInput.fill(reason);

    const submit = modal.getByRole("button", { name: /^(?:Reject|Reject Release|Submit)$/i }).filter({ visible: true }).last();
    if (!await submit.count()) throw new Error(`SOUNDFRESH_UI_CHANGED:reject_submit:url=${page.url()}`);
    await submit.click({ timeout: 20_000 });
    await modal.waitFor({ state: "hidden", timeout: 20_000 }).catch(() => undefined);
    if (await modal.isVisible().catch(() => false)) {
        const error = await modal.locator('[role="alert"], .alert-danger, .text-danger, [class*="error" i]').first().innerText().catch(() => "");
        throw new Error(`SOUNDFRESH_REJECT_FAILED:${error || "rejection dialog remained open"}`);
    }
}
