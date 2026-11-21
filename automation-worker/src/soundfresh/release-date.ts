import type { BrowserContext, Page } from 'playwright';

export function normalizeCmsReleaseDate(value: string | null | undefined): string | null {
    const text=String(value ?? '').replace(/\s+/g,' ').trim();
    const iso=text.match(/\b(\d{4})-(\d{2})-(\d{2})\b/);
    const months=['january','february','march','april','may','june','july','august','september','october','november','december'];
    const translated=text.replace(/\b(januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember)\b/gi,
        word=>months[['januari','februari','maret','april','mei','juni','juli','agustus','september','oktober','november','desember'].indexOf(word.toLowerCase())]!);
    const named=translated.match(/\b([a-z]+)\s+(\d{1,2}),?\s+(\d{4})\b/i);
    const dayFirst=translated.match(/\b(\d{1,2})\s+([a-z]+)\s+(\d{4})\b/i);
    const year=iso?Number(iso[1]):Number(named?.[3] ?? dayFirst?.[3]);
    const month=iso?Number(iso[2]):months.indexOf((named?.[1] ?? dayFirst?.[2] ?? '').toLowerCase())+1;
    const day=iso?Number(iso[3]):Number(named?.[2] ?? dayFirst?.[1]);
    const date=new Date(Date.UTC(year,month-1,day));
    if(!month || !year || !day || date.getUTCFullYear()!==year || date.getUTCMonth()!==month-1 || date.getUTCDate()!==day) return null;
    return `${year}-${String(month).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
}

export async function readPlannedReleaseDate(page: Page): Promise<string | null> {
    if(/\/(?:login|sign-in)(?:\/|$)/i.test(new URL(page.url()).pathname)) throw new Error('AUTH_SESSION_EXPIRED: Login CMS diperlukan untuk membaca tanggal rilis.');
    const labels=page.getByText(/^(?:Planned Release Date|Release Date|Tanggal Rilis)$/i).filter({visible:true});
    await labels.first().waitFor({state:'visible',timeout:8000}).catch(()=>undefined);
    for(const label of await labels.all()) {
        let scope=label.locator('xpath=..');
        for(let depth=0;depth<3;depth++,scope=scope.locator('xpath=..')) {
            if(await scope.evaluate(node=>['BODY','HTML','MAIN'].includes(node.tagName))) break;
            // Do not pick dates from neighbouring pre-release/original-date rows.
            if(await scope.getByText(/(?:pre.release|original.*date)/i).count()) break;
            const value=normalizeCmsReleaseDate(await scope.innerText());
            if(value) return value;
        }
    }
    return null;
}

export async function completeCmsReleaseDates(context: BrowserContext, items: Array<{detail_url:string;release_date:string|null}>, origin: string, onProgress?: (completed:number,total:number)=>void): Promise<void> {
    const missing=items.filter(item=>!normalizeCmsReleaseDate(item.release_date));
    if(!missing.length) return;
    const detail=await context.newPage();
    try {
        onProgress?.(0,missing.length);
        let completed=0;
        for(const item of missing) {
            const url=new URL(item.detail_url);
            if(url.origin !== origin || !/^\/admin\/releases\/\d+\/?$/.test(url.pathname)) throw new Error('CMS_RELEASE_DATE_INVALID_DETAIL_URL');
            await detail.goto(url.href,{waitUntil:'domcontentloaded',timeout:20000});
            item.release_date=await readPlannedReleaseDate(detail);
            onProgress?.(++completed,missing.length);
        }
    } finally { await detail.close(); }
}
