import { afterAll, beforeAll, expect, test } from 'vitest';
import { chromium, type Browser } from 'playwright';
import { cmsLoginControls, submitCmsLogin } from './cms-login.js';

let browser: Browser;
beforeAll(async () => { browser = await chromium.launch({ headless: true }); });
afterAll(async () => { await browser?.close(); });

for (const legacy of [false, true]) {
    test(`fills saved credentials and submits the ${legacy ? 'legacy English' : 'Dimensi Suara Indonesian'} login form`, async () => {
        const page = await browser.newPage();
        const submissions: URLSearchParams[] = [];
        await page.route('https://cms-login.test/**', async route => {
            if (route.request().method() === 'POST') {
                submissions.push(new URLSearchParams(route.request().postData() ?? ''));
                await route.fulfill({ contentType: 'text/html', body: '<h1>Dashboard</h1>' });
                return;
            }
            await route.fulfill({ contentType: 'text/html', body: route.request().url().endsWith('/dashboard') ? '<h1>Dashboard</h1>' : `
                <form><input type="email" name="newsletter"></form>
                <form style="display:none"><input type="password"></form>
                <form action="/dashboard" method="post">
                    <label for="email">${legacy ? 'Email Address *' : 'Alamat email'}</label>
                    <input id="email" name="${legacy ? 'username' : 'email'}" type="email" placeholder="nama@email.com" required>
                    <label for="password">${legacy ? 'Password *' : 'Kata sandi'}</label>
                    <input id="password" name="password" type="password" placeholder="Masukkan kata sandi" required>
                    <button type="button" aria-label="Tampilkan kata sandi" onclick="window.toggleClicked=true">Lihat</button>
                    <button type="submit">${legacy ? 'Login' : 'Masuk ke dashboard'}</button>
                </form>` });
        });
        try {
            await page.goto('https://cms-login.test/login');
            expect(await cmsLoginControls(page).password.isVisible()).toBe(true);
            await submitCmsLogin(page, 'operator@example.com', 'fixture-password');
            await page.waitForURL('https://cms-login.test/dashboard');
            expect(submissions).toHaveLength(1);
            expect(submissions[0]!.get(legacy ? 'username' : 'email')).toBe('operator@example.com');
            expect(submissions[0]!.get('password')).toBe('fixture-password');
            expect(await cmsLoginControls(page).password.isVisible()).toBe(false);
        } finally { await page.close(); }
    });
}

test('does not submit when the page clears a filled credential before login', async () => {
    const page = await browser.newPage();
    try {
        await page.setContent(`<form onsubmit="window.didSubmit=true;return false">
            <input type="email" name="email" onblur="this.value=''">
            <input type="password" name="password">
            <button type="submit">Masuk ke dashboard</button>
        </form>`);
        await expect(submitCmsLogin(page, 'operator@example.com', 'fixture-password'))
            .rejects.toThrow('AUTH_CMS_FORM_NOT_FILLED');
        expect(await page.evaluate(() => (window as any).didSubmit)).toBeUndefined();
    } finally { await page.close(); }
});
