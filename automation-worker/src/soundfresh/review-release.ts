import type { Locator, Page } from "playwright";

async function isUnderReview(page: Page): Promise<boolean> {
    return page.getByText(/^\s*under\s*review\s*$/i).filter({ visible: true }).evaluateAll(nodes =>
        nodes.some(node => !node.closest('nav, aside, a, button, select, [role="dialog"], dialog, .modal.show')),
    ).catch(() => false);
}

async function waitForReviewStatus(page: Page, timeoutMs: number): Promise<boolean> {
    const deadline = Date.now() + timeoutMs;
    do {
        if (await isUnderReview(page)) return true;
        const errors = await page.locator('[role="alert"], .alert-danger, .text-danger, [data-validation-error]')
            .filter({ visible: true }).allInnerTexts();
        const message = errors.map(value => value.trim()).filter(Boolean).join('; ');
        if (message) throw new Error(`SOUNDFRESH_REVIEW_RELEASE_REJECTED:${message}`);
        await page.waitForTimeout(100);
    } while (Date.now() < deadline);
    return false;
}

export async function moveSoundfreshReleaseToUnderReview(
    page: Page, aggregator: string, draftId: string, options: { timeoutMs?: number } = {},
): Promise<void> {
    if (aggregator !== "SoundOn" || !draftId.trim()) throw new Error("DRAFT_VERIFICATION_REQUIRED");
    const timeout = options.timeoutMs ?? 20_000;
    if (await isUnderReview(page)) return;

    // Icons can contribute to a button's accessible name, and the CMS can
    // render the same action as an anchor instead of a native button.
    const action = page.getByRole("button", { name: /review\s+release/i })
        .or(page.getByRole("link", { name: /review\s+release/i }))
        .or(page.locator('button, a, [role="button"]').filter({ hasText: /review\s+release/i }))
        .filter({ visible: true }).first();
    await action.waitFor({ state: "visible", timeout }).catch(() => {
        throw new Error(`SOUNDFRESH_UI_CHANGED:review_release_button:url=${page.url()}`);
    });
    await action.click({ timeout });

    // The current modal has an explanatory paragraph, not a label associated
    // with its select. Match the actual SoundOn option rather than a label/id.
    const selects = page.locator('select').filter({ visible: true })
        .filter({ has: page.locator('option').filter({ hasText: /^\s*Sound\s*On\s*$/i }) });
    await selects.first().waitFor({ state: "visible", timeout }).catch(() => {
        throw new Error("SOUNDFRESH_REVIEW_AGGREGATOR_NOT_FOUND:SoundOn");
    });
    if (await selects.count() !== 1) throw new Error("SOUNDFRESH_REVIEW_AGGREGATOR_AMBIGUOUS");
    const select = selects.first();
    const soundOnOption = await select.locator('option').evaluateAll(nodes => nodes
        .map(node => ({ label: node.textContent?.trim() ?? '', value: (node as HTMLOptionElement).value }))
        .find(option => option.label.replace(/\s+/g, '').toLowerCase() === 'soundon'));
    if (!soundOnOption?.value) throw new Error("SOUNDFRESH_REVIEW_AGGREGATOR_NOT_FOUND:SoundOn");
    await select.selectOption(soundOnOption.value, { timeout });
    if (await select.inputValue() !== soundOnOption.value) throw new Error("SOUNDFRESH_REVIEW_AGGREGATOR_NOT_SELECTED");

    const modal = select.locator('xpath=ancestor::*[@role="dialog" or self::dialog or contains(concat(" ", normalize-space(@class), " "), " modal ")][1]');
    const form = select.locator('xpath=ancestor::form[1]');
    const scope: Locator | Page = await modal.count() ? modal : await form.count() ? form : page;
    const reference = scope.locator('input[name*="draft" i], input[name*="reference" i], input[id*="draft" i], input[id*="reference" i]')
        .filter({ visible: true }).first();
    if (await reference.count()) await reference.fill(draftId);
    const confirm = scope.getByRole("button", { name: /review\s+release|^(?:review|save|confirm|submit|yes|ok)$/i })
        .or(scope.getByRole("link", { name: /review\s+release/i }))
        .or(scope.locator('button, [role="button"]').filter({ hasText: /review\s+release/i }))
        .filter({ visible: true }).last();
    await confirm.waitFor({ state: "visible", timeout }).catch(() => {
        throw new Error("SOUNDFRESH_UI_CHANGED:review_release_confirm");
    });
    await confirm.click({ timeout });
    if (!await waitForReviewStatus(page, timeout)) throw new Error("SOUNDFRESH_REVIEW_NOT_CONFIRMED:status remained pending");

    // A closed modal or a disappearing button does not prove persistence.
    await page.reload({ waitUntil: "domcontentloaded", timeout });
    if (!await waitForReviewStatus(page, timeout)) throw new Error("SOUNDFRESH_REVIEW_NOT_CONFIRMED:status did not persist after reload");
}
