import type { Locator, Page } from "playwright";

async function assertCmsSession(page: Page): Promise<void> {
    if (/\/(?:login|sign-in)(?:\/|$)/i.test(new URL(page.url()).pathname)
        || await page.locator('input[type="password"]').filter({visible:true}).count()) {
        throw new Error('AUTH_SESSION_EXPIRED: Sesi Dimensi Suara berakhir saat Upload Release.');
    }
}
async function isUploading(page: Page): Promise<boolean> {
    return page.getByText(/^\s*(?:uploading|upload\s*dsp)\s*$/i).filter({visible:true}).evaluateAll(nodes=>nodes.some(node=>
        !node.closest('nav, aside, a, button, select, label, [role="dialog"], dialog, .modal.show'))).catch(()=>false);
}
async function assertNoUploadErrors(page: Page): Promise<void> {
    await assertCmsSession(page);
    const errors=await page.locator('.alert-danger, .text-danger, [data-validation-error], [role="alert"][class*="error" i]')
        .filter({visible:true}).allInnerTexts();
    if(errors.some(text=>text.trim())) throw new Error('SOUNDFRESH_UPLOAD_RELEASE_REJECTED:'+errors.filter(text=>text.trim()).join('; '));
}
async function waitUploading(page: Page, timeout: number): Promise<boolean> {
    const deadline=Date.now()+timeout;
    do {
        await assertNoUploadErrors(page);
        if(await isUploading(page)) return true;
        await page.waitForTimeout(100);
    }while(Date.now()<deadline);
    return false;
}
const confirmName = /upload\s+release|^\s*(?:Upload|Confirm|Yes|OK|Ya|Konfirmasi)\s*$/i;
async function uploadConfirmation(page: Page): Promise<Locator | null> {
    const dialogs=page.locator('dialog, [role="dialog"], .modal.show, [class*="modal" i]')
        .filter({visible:true}).filter({hasText:/upload\s+release/i});
    for(let index=await dialogs.count()-1;index>=0;index--) {
        const confirm=dialogs.nth(index).getByRole('button',{name:confirmName}).filter({visible:true}).last();
        if(await confirm.count() && await confirm.isEnabled()) return confirm;
    }
    // Current CMS forms can use a plain container with a heading instead of
    // role=dialog/.modal. Stay inside that heading's nearest confirmation panel.
    const headings=page.getByRole('heading',{name:/upload\s+release/i}).filter({visible:true});
    for(let index=await headings.count()-1;index>=0;index--) {
        let panel=headings.nth(index).locator('xpath=..');
        for(let depth=0;depth<5;depth++,panel=panel.locator('xpath=..')) {
            if(await panel.evaluate(node=>['BODY','HTML','MAIN'].includes(node.tagName))) break;
            const confirm=panel.getByRole('button',{name:confirmName}).filter({visible:true});
            if(await confirm.count()===1 && await confirm.isEnabled()) return confirm;
        }
    }
    return null;
}
async function uploadDiagnostics(page: Page): Promise<string> {
    const statuses=await page.getByText(/^\s*(?:under\s*review|uploading|upload\s*dsp|pending|revision|rejected|verified|published)\s*$/i)
        .filter({visible:true}).allInnerTexts();
    const buttons=await page.getByRole('button').filter({visible:true}).allInnerTexts();
    return JSON.stringify({statuses:statuses.slice(0,8),buttons:buttons.slice(0,12)});
}
export async function moveSoundfreshReleaseToUploading(page: Page, timeout = 20000): Promise<void> {
    await assertCmsSession(page);
    if(await isUploading(page)) {
        await page.reload({waitUntil:'domcontentloaded',timeout});
        if(!await waitUploading(page,timeout)) throw new Error('SOUNDFRESH_UPLOAD_NOT_PERSISTED: '+await uploadDiagnostics(page));
        return;
    }
    const action = page.getByRole('button',{name:/upload\s+release/i})
        .or(page.getByRole('link',{name:/upload\s+release/i})).filter({visible:true}).first();
    await action.waitFor({state:'visible',timeout}).catch(()=>{throw new Error('SOUNDFRESH_UI_CHANGED:upload_release_button:url='+page.url());});
    await action.click({timeout});
    const deadline=Date.now()+timeout;
    let confirmed=false;
    do {
        await assertNoUploadErrors(page);
        if(await isUploading(page)) break;
        if(!confirmed) {
            const confirm=await uploadConfirmation(page);
            if(confirm) {
                await confirm.click({timeout});
                confirmed=true;
            }
        }
        await page.waitForTimeout(100);
    } while(Date.now()<deadline);
    const appearedUploading=await isUploading(page);
    // Some CMS actions persist on the server but do not refresh the detail
    // badge. Reload once to verify actual state; never repeat Upload blindly.
    await page.reload({waitUntil:'domcontentloaded',timeout});
    if(!await waitUploading(page,timeout)) throw new Error((appearedUploading ? 'SOUNDFRESH_UPLOAD_NOT_PERSISTED' : 'SOUNDFRESH_UPLOAD_NOT_CONFIRMED')+': Upload Release belum mengubah status CMS menjadi Uploading / Upload DSP setelah reload. '+await uploadDiagnostics(page));
}
