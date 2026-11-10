import type { Page } from 'playwright';

export function cmsLoginControls(page: Page) {
    const form = page.locator('form')
        .filter({ has: page.locator('input[type="password"]') })
        .filter({ visible: true }).first();

    return {
        email: form.locator('input[name="email"], input[name="username"], input[type="email"]')
            .filter({ visible: true }).first(),
        password: form.locator('input[type="password"]')
            .filter({ visible: true }).first(),
        submit: form.locator('button[type="submit"], input[type="submit"]')
            .or(form.getByRole('button', { name: /^(?:masuk ke dashboard|masuk|login|log in|sign in)$/i }))
            .filter({ visible: true }).first(),
    };
}

export async function submitCmsLogin(page: Page, email: string, password: string) {
    const controls = cmsLoginControls(page);
    await controls.submit.waitFor({ state: 'visible', timeout: 20_000 });
    await controls.email.fill(email);
    await controls.password.fill(password);
    if (await controls.email.inputValue() !== email || await controls.password.inputValue() !== password) {
        throw new Error('AUTH_CMS_FORM_NOT_FILLED');
    }
    await controls.submit.click();

    return controls;
}
