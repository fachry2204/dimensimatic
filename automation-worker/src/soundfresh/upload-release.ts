import type { Page } from "playwright";

async function isUploading(page: Page): Promise<boolean> {
    return page.getByText(/^\s*(?:uploading|upload\s*dsp)\s*$/i).filter({visible:true}).evaluateAll(nodes=>nodes.some(node=>
        !node.closest('nav, aside, a, button, select, label, [role="dialog"], dialog, .modal.show'))).catch(()=>false);
}
async function waitUploading(page: Page, timeout: number): Promise<boolean> {
    const deadline=Date.now()+timeout;
    do {
        if(await isUploading(page)) return true;
        const errors=await page.locator('.alert-danger, .text-danger, [data-validation-error]').filter({visible:true}).allInnerTexts();
        if(errors.some(text=>text.trim())) throw new Error('SOUNDFRESH_UPLOAD_RELEASE_REJECTED:'+errors.join('; '));
        await page.waitForTimeout(100);
    }while(Date.now()<deadline);
    return false;
}
export async function moveSoundfreshReleaseToUploading(page: Page, timeout = 20000): Promise<void> {
    if(await isUploading(page)) return;
    const action = page.getByRole('button',{name:/upload\s+release/i})
        .or(page.getByRole('link',{name:/upload\s+release/i})).filter({visible:true}).first();
    await action.waitFor({state:'visible',timeout}).catch(()=>{throw new Error('SOUNDFRESH_UI_CHANGED:upload_release_button:url='+page.url());});
    await action.click({timeout});
    await page.waitForTimeout(400);
    const dialog=page.locator('dialog, [role="dialog"], .modal.show, [class*="modal" i]').filter({visible:true}).last();
    if(await dialog.count()) {
        const confirm=dialog.getByRole('button',{name:/^(?:Upload Release|Upload|Confirm|Yes|OK)$/i}).filter({visible:true}).last();
        if(await confirm.count()) await confirm.click({timeout});
    }
    if(!await waitUploading(page,timeout)) throw new Error('SOUNDFRESH_UPLOAD_NOT_CONFIRMED');
    await page.reload({waitUntil:'domcontentloaded',timeout});
    if(!await waitUploading(page,timeout)) throw new Error('SOUNDFRESH_UPLOAD_NOT_PERSISTED');
}
