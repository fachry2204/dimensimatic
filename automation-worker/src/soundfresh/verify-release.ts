import type { Locator, Page } from "playwright";

export type VerifyReleaseIdentifiers = { upc: string; isrcs: string[] };

async function persistedIdentifiers(page: Page, identifiers: VerifyReleaseIdentifiers): Promise<boolean> {
    const verified = await page.getByText(/^\s*(?:verified|published|released|live|approved|release verified)\s*$/i)
        .filter({ visible: true }).evaluateAll(nodes => nodes.some(node =>
            !node.closest('nav, aside, a, button, select, label, [role="dialog"], dialog, .modal.show'),
        )).catch(() => false);
    if (!verified) return false;
    const text = (await page.locator('body').innerText()).toUpperCase();
    return new RegExp(`\\b${identifiers.upc}\\b`).test(text)
        && identifiers.isrcs.every(isrc => new RegExp(`\\b${isrc}\\b`).test(text));
}

const editableInputs = 'input:not([type="hidden"]):not([type="button"]):not([type="submit"]):not([type="checkbox"]):not([type="radio"])';

function verificationSubmit(panel: Locator): Locator {
    return panel.getByRole('button', { name: /(?:verify|verified)\s+release/i })
        .or(panel.locator('button, [role="button"]').filter({ hasText: /(?:verify|verified)\s+release/i }))
        .filter({ visible: true }).last();
}

function fieldDescriptor(field: { name: string; id: string; label: string; bindings: string }): string {
    return [field.name, field.id, field.label, field.bindings].join(' ');
}

async function verificationPanelReady(panel: Locator): Promise<boolean> {
    if (await panel.locator(editableInputs).filter({ visible: true }).count() < 2
        || await verificationSubmit(panel).count() !== 1) return false;
    const { descriptions } = await verificationFields(panel);
    // Do not climb out of a loading modal and mistake page search fields
    // or an unrelated form for its UPC/ISRC controls.
    return descriptions.some(field => /\bupc\b/i.test(field.label) || /upc/i.test(field.name + field.id + field.bindings))
        && descriptions.some(field => /isrc/i.test(fieldDescriptor(field)));
}

async function verificationPanel(page: Page, timeout: number): Promise<Locator> {
    const deadline = Date.now() + timeout;
    let headingSeen = false;
    do {
        const headings = page.getByRole('heading', { name: /^(?:publish|verify|verified)\s+release$/i })
            .filter({ visible: true });
        const headingCount = await headings.count();
        headingSeen ||= headingCount > 0;
        // An input in a header can be a hidden CSRF field. A visible title
        // also does not mean the modal body has finished rendering. Choose
        // the nearest ancestor with editable fields AND the actual submit.
        for (let h = headingCount - 1; h >= 0; h--) {
            const candidates = headings.nth(h).locator('xpath=ancestor::*[.//input and .//button and not(self::body) and not(self::html)]');
            for (let index = await candidates.count() - 1; index >= 0; index--) {
                const panel = candidates.nth(index);
                if (await verificationPanelReady(panel)) return panel;
            }
        }
        // If a recognized heading is present, wait for its own body rather
        // than selecting another visible modal while it is loading.
        if (!headingCount) {
            const dialogs = page.locator('dialog, [role="dialog"], .modal.show, [class*="modal" i]')
                .filter({ visible: true }).filter({ hasText: /(?:publish|verify|verified)\s+release/i });
            for (let index = await dialogs.count() - 1; index >= 0; index--) {
                const panel = dialogs.nth(index);
                if (await verificationPanelReady(panel)) return panel;
            }
        }
        await page.waitForTimeout(100);
    } while (Date.now() < deadline);
    throw new Error(`SOUNDFRESH_UI_CHANGED:verify_dialog_not_ready:heading=${headingSeen}:url=${page.url()}`);
}

async function verificationFields(panel: Locator) {
    const inputs = panel.locator(editableInputs)
        .filter({ visible: true });
    const descriptions = await inputs.evaluateAll(nodes => nodes.map((node, index) => {
        const input = node as HTMLInputElement;
        let label = [...(input.labels ?? [])].map(label => label.textContent ?? '').join(' ') || input.getAttribute('aria-label') || '';
        // Some CMS templates place all labels and inputs directly in one
        // container and omit name/id/for. Read the nearest preceding label
        // before deciding whether a parent contains too many fields.
        let child: Element = input;
        let parent = input.parentElement;
        for (let depth = 0; !label && parent && depth < 6; depth++, child = parent, parent = parent.parentElement) {
            const previous = child.previousElementSibling;
            const text = previous?.textContent?.trim() ?? '';
            if (previous && !previous.matches('input, select, textarea')
                && !previous.querySelector('input, select, textarea')
                && /^(?:UPC\b|Track\s*\d+\s*ISRC\b|ISRC\b)/i.test(text)) {
                label = text;
            }
            if (parent.querySelectorAll('input:not([type="hidden"])').length !== 1) break;
            if (!label) label = parent.querySelector('label')?.textContent?.trim() ?? '';
        }
        const bindings = [...input.attributes]
            .filter(attribute => /^(?:x-model(?:\..*)?|wire:model(?:\..*)?|data-testid|placeholder)$/i.test(attribute.name))
            .map(attribute => attribute.value).join(' ');
        return { index, name: input.name, id: input.id, label, bindings };
    }));
    return { inputs, descriptions };
}

async function waitForVerification(page: Page, identifiers: VerifyReleaseIdentifiers, timeout: number): Promise<boolean> {
    const deadline = Date.now() + timeout;
    do {
        if (await persistedIdentifiers(page, identifiers)) return true;
        const errors = await page.locator('.alert-danger, .text-danger, [data-validation-error], [role="alert"][class*="error" i]')
            .filter({ visible: true }).allInnerTexts();
        const message = errors.map(text => text.trim()).filter(Boolean).join('; ');
        if (message) throw new Error(`SOUNDFRESH_VERIFY_REJECTED:${message}`);
        await page.waitForTimeout(100);
    } while (Date.now() < deadline);
    return false;
}

export async function verifySoundfreshRelease(
    page: Page, identifiers: VerifyReleaseIdentifiers, options: { timeoutMs?: number } = {},
): Promise<void> {
    const timeout = options.timeoutMs ?? 20_000;
    identifiers = { upc: identifiers.upc.trim(), isrcs: identifiers.isrcs.map(value => value.trim().toUpperCase()) };
    if (!/^\d{12,13}$/.test(identifiers.upc) || !identifiers.isrcs.length
        || identifiers.isrcs.some(isrc => !/^[A-Z]{2}[A-Z0-9]{3}\d{7}$/.test(isrc))) {
        throw new Error('UPC_ISRC_VALIDATION_FAILED');
    }
    if (await persistedIdentifiers(page, identifiers)) return;
    const action = page.getByRole('button', { name: /(?:verify|verified)\s+release/i })
        .or(page.getByRole('link', { name: /(?:verify|verified)\s+release/i }))
        .or(page.locator('button, a, [role="button"]').filter({ hasText: /(?:verify|verified)\s+release/i }))
        .filter({ visible: true }).first();
    await action.waitFor({ state: 'visible', timeout }).catch(() => {
        throw new Error(`SOUNDFRESH_UI_CHANGED:verify_release_button:url=${page.url()}`);
    });
    await action.click({ timeout });
    const panel = await verificationPanel(page, timeout);
    const { inputs, descriptions } = await verificationFields(panel);
    const upcFields = descriptions.filter(field => /upc/i.test(field.name + ' ' + field.id + ' ' + field.bindings) || /^\s*UPC\b/i.test(field.label));
    const isrcFields = descriptions.filter(field => /isrc/i.test(field.name + ' ' + field.id + ' ' + field.label + ' ' + field.bindings));
    if (upcFields.length !== 1) throw new Error(`SOUNDFRESH_UI_CHANGED:verify_upc_input:matched=${upcFields.length}:visible_fields=${descriptions.length}`);
    if (isrcFields.length !== identifiers.isrcs.length) {
        throw new Error(`SOUNDFRESH_VERIFY_TRACK_COUNT_MISMATCH:expected=${identifiers.isrcs.length}:actual=${isrcFields.length}`);
    }
    const trackNumbers = isrcFields.map(field => field.label.match(/Track\s*(\d+)\s*ISRC\b/i)?.[1]);
    const numbered = trackNumbers.some(Boolean);
    if (numbered && (trackNumbers.some(value => !value) || new Set(trackNumbers).size !== identifiers.isrcs.length
        || trackNumbers.some(value => Number(value) < 1 || Number(value) > identifiers.isrcs.length))) {
        throw new Error('SOUNDFRESH_VERIFY_TRACK_ORDER_AMBIGUOUS');
    }
    await inputs.nth(upcFields[0]!.index).fill(identifiers.upc);
    for (let index = 0; index < isrcFields.length; index++) {
        const position = numbered ? Number(trackNumbers[index]) - 1 : index;
        await inputs.nth(isrcFields[index]!.index).fill(identifiers.isrcs[position]!);
    }
    if (await inputs.nth(upcFields[0]!.index).inputValue() !== identifiers.upc) throw new Error('SOUNDFRESH_VERIFY_FIELDS_NOT_FILLED:UPC');
    for (let index = 0; index < isrcFields.length; index++) {
        const position = numbered ? Number(trackNumbers[index]) - 1 : index;
        if (await inputs.nth(isrcFields[index]!.index).inputValue() !== identifiers.isrcs[position]) {
            throw new Error(`SOUNDFRESH_VERIFY_FIELDS_NOT_FILLED:Track ${position + 1}`);
        }
    }
    const submit = verificationSubmit(panel);
    await submit.waitFor({ state: 'visible', timeout }).catch(() => { throw new Error('SOUNDFRESH_UI_CHANGED:verify_submit'); });
    await submit.click({ timeout });
    if (!await waitForVerification(page, identifiers, timeout)) throw new Error('SOUNDFRESH_VERIFY_NOT_CONFIRMED:status or identifiers not updated');
    await page.reload({ waitUntil: 'domcontentloaded', timeout });
    if (!await waitForVerification(page, identifiers, timeout)) throw new Error('SOUNDFRESH_VERIFY_NOT_CONFIRMED:status or identifiers did not persist after reload');
}
