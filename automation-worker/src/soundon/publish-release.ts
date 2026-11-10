import type { PublishProgressReporter } from './publish-progress.js';
import type { Page, Request, Response } from 'playwright';
import { draftLookupReference } from './draft-finder.js';

export type PublishDraft = { draft_id: string; draft_url: string; title: string; artist: string; reference_error?: string };
const rowsSelector = 'tbody tr, [role="rowgroup"] [role="row"], [data-testid="draft-card"]';

const paginationScope = '[class*="pagination" i], [class*="paginator" i], .semi-page, nav[aria-label*="page" i], nav[aria-label*="pagination" i], [data-testid*="pagination" i], [class*="pager" i], [class*="paging" i], [data-dimensimatic-pagination]';

async function discoverDraftPagination(page: Page): Promise<void> {
    await page.locator('button, [role="button"], span').filter({visible:true}).evaluateAll(nodes=>{
        for(const node of nodes) {
            if(!node.querySelector('svg') || node.closest('thead,tbody')) continue;
            let parent=node.parentElement;
            for(let depth=0;parent && depth<4;depth++,parent=parent.parentElement) {
                if(parent.tagName==='BODY' || parent.tagName==='MAIN') break;
                const text=(parent.textContent??'').replace(/(?:total|items?|per|page|of|showing|drafts?|releases?)/gi,'').trim();
                const controls=parent.querySelectorAll('button,[role="button"],span');
                if(parent.querySelectorAll('svg').length>=2 && controls.length<=30 && /^\d[\d\s.,/–—\-…]*$/.test(text)) {
                    parent.setAttribute('data-dimensimatic-pagination','true');break;
                }
            }
        }
    });
}

const draftEndConfirmed=new WeakMap<Page,boolean>();
export type DraftCollectionProgress = { collected: number; total: number | null; page: number; message: string };
export async function readDraftTotal(page: Page): Promise<number | null> {
    await discoverDraftPagination(page);
    const labels=await page.getByRole('tab',{name:/^drafts?\b/i}).filter({visible:true}).allInnerTexts();
    labels.push(...await page.getByText(/^drafts?\s*\(\s*[\d,]+\s*\)$/i).filter({visible:true}).allInnerTexts());
    for(const label of labels) {
        const count=label.match(/^drafts?\s*(?:\(\s*([\d,]+)\s*\)|([\d,]+))\s*$/i);
        if(count) return Number((count[1]??count[2])!.replaceAll(',',''));
    }
    const counters=await page.locator(paginationScope).filter({visible:true}).allInnerTexts();
    counters.push(...await page.getByText(/^\s*(?:showing\s+)?\d+\s*[-–]\s*\d+\s*(?:of|dari|\/)\s*[\d,]+\s*(?:items?|releases?|drafts?)?\s*$/i).filter({visible:true}).allInnerTexts());
    for(const text of counters) {
        if(/(?:page|halaman)\s*\d+\s*(?:of|dari|\/)\s*\d+/i.test(text) && !/total|items|drafts|releases/i.test(text)) continue;
        const match=text.match(/(?:total|of|dari)\s*[:：]?\s*([\d,]+)/i) ?? text.match(/\d+\s*[-–]\s*\d+\s*\/\s*([\d,]+)/);
        if(match) return Number(match[1]!.replaceAll(',',''));
    }
    return null;
}

export async function advanceDraftPage(page: Page, currentPage: number, timeout = 15000, rowSelector = rowsSelector): Promise<boolean> {
    await discoverDraftPagination(page);
    draftEndConfirmed.set(page,false);
    const controls=page.locator(`${paginationScope}`).filter({visible:true});
    const candidates=controls.locator('button, a, li, span, svg, [role="button"], [tabindex]')
        .or(page.locator('button[aria-label*="next" i], a[aria-label*="next" i], [class*="page-next" i], [class*="pagination-next" i]'))
        .filter({visible:true});
    let next: import('playwright').Locator | null = null;
    let score=0;
    for(let index=0;index<await candidates.count();index++) {
        const item=candidates.nth(index);
        const info=await item.evaluate(node=>({text:(node.textContent??'').trim(),
            descriptor:[node.getAttribute('aria-label'),node.getAttribute('title'),node.getAttribute('class'),
                ...Array.from(node.querySelectorAll('svg,i,[data-icon]')).map(icon=>[icon.getAttribute('class'),icon.getAttribute('aria-label'),icon.getAttribute('data-icon')].join(' '))].join(' '),
            disabled:!!node.closest('[disabled], [aria-disabled="true"], .disabled, [class*="item-disabled"], [class*="next-disabled"], [class*="page-disabled"], [class*="pagination-disabled"]')}));
        const weight=/next|chevron[_-]?right|angle[_-]?right|arrow[_-]?right|icon[_-]?right/i.test(info.descriptor)
            || /^(?:Next(?: page)?|Berikutnya|›|»|>)$/i.test(info.text) ? 3
            : info.text===String(currentPage+1) ? 2 : 0;
        const ranked=weight ? weight + (await item.evaluate(node=>node.matches('button,a,[role="button"]')) ? 0.1 : 0) : 0;
        if(ranked>score) {next=item;score=ranked;}
    }
    // Custom SoundOn controls can be unlabelled SVG buttons. The last
    // non-numeric button inside a pagination group is its forward arrow.
    if(!next) {
        const buttons=controls.locator('button, [role="button"], span:has(svg)').filter({visible:true});
        if(await buttons.count()>=2) {
            const last=buttons.last();
            if(!(await last.innerText()).trim() && await last.locator('svg').count()) next=last;
        }
    }
    if(!next) {
        const more=page.getByRole('button',{name:/^(?:load more|show more|muat lagi|tampilkan lebih banyak)$/i}).filter({visible:true}).first();
        if(await more.count()) next=more;
    }
    if(!next) return false;
    if(await next.isDisabled() || await next.evaluate(node=>!!node.closest('[disabled], [aria-disabled="true"], .disabled, [class*="item-disabled"], [class*="next-disabled"], [class*="page-disabled"], [class*="pagination-disabled"]'))) {draftEndConfirmed.set(page,true);return false;}
    const before=(await page.locator(rowSelector).filter({visible:true}).allInnerTexts()).join('\n');
    await next.click({timeout});
    // React clears the table and disables Next while loading. An empty or
    // skeleton row is a transition, not page 2 and not the end of the list.
    await page.waitForFunction(({selector,before})=>{
        const rows=Array.from(document.querySelectorAll(selector)).filter(node=>{
            const element=node as HTMLElement;
            if(!element.offsetHeight || node.closest('[aria-busy="true"]')) return false;
            const cells=Array.from(node.querySelectorAll('td,[role="cell"]'));
            return cells.length>=2 && cells.filter(cell=>cell.textContent?.trim()).length>=2
                || !!node.querySelector('[data-testid="release-title"]') && !!node.querySelector('[data-testid="release-artist"]');
        });
        if(!rows.length) return false;
        const signature=rows.map(node=>(node as HTMLElement).innerText).join('\n');
        if(signature===before) return false;
        const state=window as typeof window & {__draftPageReady?: {signature:string;since:number}};
        if(state.__draftPageReady?.signature!==signature) {
            state.__draftPageReady={signature,since:Date.now()};return false;
        }
        return Date.now()-state.__draftPageReady.since>=250;
    },{selector:rowSelector,before},{timeout}).catch(()=>{throw new Error('SOUNDON_DRAFT_PAGINATION_STALLED:page='+currentPage+': Data halaman berikutnya belum selesai dimuat; tabel kosong/loading tidak dihitung sebagai halaman selesai.');});
    return true;
}

async function prepareDraftList(page: Page, timeout: number): Promise<void> {
    const tab=page.getByRole('tab',{name:/^drafts?\b/i}).filter({visible:true}).first();
    if(await tab.count() && await tab.getAttribute('aria-selected')!=='true') await tab.click();
    const search=page.getByPlaceholder(/search|cari/i).filter({visible:true}).first();
    // A filled unrelated textbox must not be treated as a global title search.
    if(await search.count() && await search.inputValue()) {await search.fill('');await search.press('Enter');await page.waitForTimeout(500);}
    await page.locator(`${rowsSelector}, [class*="empty" i], [data-testid="empty"]`).filter({visible:true}).first()
        .waitFor({state:'visible',timeout}).catch(()=>{throw new Error('SOUNDON_DRAFT_LIST_NOT_READY: Daftar draft yang terlihat belum tersedia; periksa sesi atau halaman SoundOn. URL='+page.url());});
}

export async function collectSoundOnDrafts(page: Page, timeout = 15000, onProgress?: (progress: DraftCollectionProgress) => void): Promise<PublishDraft[]> {
    await prepareDraftList(page,timeout);
    const result = new Map<string, PublishDraft>();
    let scannedRows=0;
    let previousPageRows:string[]=[];
    let expectedTotal=await readDraftTotal(page);
    onProgress?.({collected:0,total:expectedTotal,page:1,message:"Membaca draft SoundOn"});
    const signatures = new Set<string>();
    for (let number = 0; number < 10000; number++) {
        const rows = page.locator(rowsSelector).filter({ visible: true });
        const pageRows=await rows.allInnerTexts();
        const cumulative=previousPageRows.length>0 && previousPageRows.every((value,index)=>pageRows[index]===value);
        const signature = pageRows.join('\n');
        if (signatures.has(signature)) throw new Error('SOUNDON_DRAFT_PAGINATION_STALLED');
        signatures.add(signature);
        scannedRows=cumulative ? Math.max(scannedRows,pageRows.length) : scannedRows+pageRows.length;
        const pageReferences=new Set<string>();
        const headers = (await page.locator('thead th').allInnerTexts()).map(v => v.trim());
        const titleIndex = headers.findIndex(v => /title|release|album|track\s*name/i.test(v));
        const artistIndex = headers.findIndex(v => /artist/i.test(v));
        for (const row of await rows.all()) {
            const cells = await row.locator('td, [role="cell"]').allInnerTexts();
            const titleNode=row.locator('[data-testid="release-title"]');
            const artistNode=row.locator('[data-testid="release-artist"]');
            const title = (await titleNode.count() ? await titleNode.innerText() : '')
                || (cells[titleIndex >= 0 ? titleIndex : 0] ?? '').split(/\r?\n/).map(v=>v.trim()).filter(Boolean)[0] || '';
            const artist = (await artistNode.count() ? await artistNode.innerText() : '')
                || (cells[artistIndex >= 0 ? artistIndex : 1] ?? '').trim();
            if (!title || !artist || /no (?:data|releases|drafts)/i.test(title)) continue;
            let reference: {draft_id:string; draft_url:string} | null = null;
            for (const link of await row.locator('a[href]').all()) {
                const href=await link.getAttribute('href');
                if (!href) continue;
                const found=draftReferenceFromUrl(new URL(href,page.url()).href,new URL(page.url()).origin);
                if (found) { reference=found; break; }
            }
            if (!reference) {
                const id = await row.getAttribute('data-id');
                // React draft rows often expose only an onClick handler. Keep
                // an exact title/artist lookup reference; resolve the real URL
                // by opening that selected row when publishing, not a guessed URL.
                reference = id
                    ? {draft_id:id,draft_url:page.url()}
                    : {draft_id:draftLookupReference(title,artist),draft_url:page.url()};
            }
            const previous=result.get(reference.draft_id);
            result.set(reference.draft_id,{...reference,title,artist,
                ...((previous && !cumulative || pageReferences.has(reference.draft_id)) && reference.draft_id.startsWith('existing-') ? {reference_error:'Beberapa draft memiliki judul dan artis yang sama. Buka SoundOn untuk memeriksa pasangan draft.'} : {})});
            pageReferences.add(reference.draft_id);
        }
        previousPageRows=pageRows;
        expectedTotal=await readDraftTotal(page) ?? expectedTotal;
        onProgress?.({collected:result.size,total:expectedTotal,page:number+1,message:`Membaca halaman ${number+1}`});
        if(!await advanceDraftPage(page,number+1,timeout)) {
            if(expectedTotal!==null && expectedTotal>scannedRows) throw new Error(`SOUNDON_DRAFT_LIST_INCOMPLETE: ${result.size} dari ${expectedTotal} draft terbaca; kontrol halaman berikutnya belum berhasil dibuka.`);
            if(expectedTotal===null && await rows.count()>=20 && !draftEndConfirmed.get(page))
                throw new Error(`SOUNDON_DRAFT_PAGINATION_UNCONFIRMED: ${result.size} draft terbaca, tetapi total dan akhir daftar belum dapat dipastikan. Kontrol pagination SoundOn belum dikenali; hasil belum dinyatakan lengkap.`);
            return [...result.values()];
        }
    }
    throw new Error('SOUNDON_DRAFT_PAGINATION_LIMIT');
}

const normalized = (value: string): string => value.normalize('NFKC').replace(/[\u200B-\u200D\uFEFF]/g,'').trim().replace(/[’‘`]/g,"'").replace(/\s+/g,' ').toLocaleLowerCase();
export function draftReferenceFromUrl(value: string, origin: string): {draft_id: string; draft_url: string} | null {
    const url=new URL(value);
    if(url.origin !== origin || !/^\/library\//.test(url.pathname) || /\/list\/?$/.test(url.pathname)) return null;
    const id=url.searchParams.get('id') ?? url.searchParams.get('draft_id') ?? url.searchParams.get('release_id')
        ?? url.pathname.match(/(?:draft|release)\/([^/]+)$/i)?.[1];
    return id ? {draft_id:id,draft_url:url.href} : null;
}

async function waitDraftEditor(page: Page, timeout: number): Promise<void> {
    if(/login|sign-in/i.test(page.url())) throw new Error('AUTH_SESSION_EXPIRED: Login SoundOn diperlukan sebelum membuka draft.');
    await page.getByRole('button',{name:/^(?:Next|Continue|Submit(?: release| for review)?)$/i}).filter({visible:true}).first()
        .waitFor({state:'visible',timeout}).catch(()=>{throw new Error('SOUNDON_DRAFT_EDITOR_NOT_READY: Halaman draft terbuka tetapi tombol Next/Submit belum terlihat. URL='+page.url());});
}

export async function openDraftForPublishing(page: Page, selected: PublishDraft, timeout = 20000): Promise<string> {
    const lookupId=draftLookupReference(selected.title,selected.artist);
    if(selected.draft_id.startsWith('existing-') && selected.draft_id!==lookupId) throw new Error('SOUNDON_SELECTED_DRAFT_MISMATCH');
    const origin=new URL(selected.draft_url).origin;
    if(new URL(page.url()).origin!==origin) throw new Error('DOMAIN_NOT_ALLOWED');
    const current=draftReferenceFromUrl(page.url(),origin);
    if(current && !selected.draft_id.startsWith('existing-')) {
        if(current.draft_id!==selected.draft_id) throw new Error('SOUNDON_SELECTED_DRAFT_MISMATCH');
        await waitDraftEditor(page,timeout);
        return current.draft_id;
    }
    const saved=draftReferenceFromUrl(selected.draft_url,origin);
    if(saved && !selected.draft_id.startsWith('existing-')) {
        if(saved.draft_id!==selected.draft_id) throw new Error('SOUNDON_SELECTED_DRAFT_MISMATCH');
        await page.goto(saved.draft_url,{waitUntil:'domcontentloaded',timeout});
        const actual=draftReferenceFromUrl(page.url(),origin);
        if(!actual || actual.draft_id!==selected.draft_id) throw new Error('SOUNDON_SELECTED_DRAFT_MISMATCH');
        await waitDraftEditor(page,timeout);
        return actual.draft_id;
    }
    await prepareDraftList(page,timeout);
    const seen=new Set<string>();
    for(let number=1;number<=10000;number++) {
        const rows=page.locator(rowsSelector).filter({visible:true});
        const signature=(await rows.allInnerTexts()).join('\n');
        if(seen.has(signature)) throw new Error('SOUNDON_DRAFT_PAGINATION_STALLED');
        seen.add(signature);
        const indexes=await rows.evaluateAll((nodes,wanted)=>nodes.flatMap((node,index)=>{
            const normalize=(text:string)=>text.normalize('NFKC').trim().replace(/[’‘`]/g,"'").replace(/\s+/g,' ').toLocaleLowerCase();
            const values=[...(node as HTMLElement).innerText.split(/\r?\n|\t/),...Array.from(node.querySelectorAll('td,[role="cell"],a,button,span')).map(el=>el.textContent ?? '')].map(normalize);
            return values.includes(wanted.title) && values.some(value=>value===wanted.artist || value.replace(/^primary artists?\s*:?\s*/,'')===wanted.artist) ? [index] : [];
        }),{title:normalized(selected.title),artist:normalized(selected.artist)});
        if(indexes.length>1) throw new Error('SOUNDON_SELECTED_DRAFT_AMBIGUOUS');
        if(!indexes.length) {
            if(await advanceDraftPage(page,number,timeout)) continue;
            throw new Error('SOUNDON_SELECTED_DRAFT_NOT_FOUND:pages='+number+':title='+selected.title);
        }
        const row=rows.nth(indexes[0]!);
        const edit=row.getByRole('button',{name:/^(?:edit|edit release|continue|continue editing)$/i})
            .or(row.getByRole('link',{name:/^(?:edit|edit release|continue|continue editing)$/i})).filter({visible:true}).first();
        const title=row.getByText(selected.title,{exact:true}).filter({visible:true}).first();
        const target=await edit.count() ? edit : await title.count() ? title : row;
        const origin=new URL(page.url()).origin;
        await target.click({timeout});
        await page.waitForURL(url=>draftReferenceFromUrl(url.href,origin)!==null,{timeout})
            .catch(()=>{throw new Error('SOUNDON_DRAFT_OPEN_FAILED:'+selected.title);});
        const reference=draftReferenceFromUrl(page.url(),origin)!;
        if(!selected.draft_id.startsWith('existing-') && reference.draft_id!==selected.draft_id) throw new Error('SOUNDON_SELECTED_DRAFT_MISMATCH');
        await waitDraftEditor(page,timeout);
        return reference.draft_id;
    }
    throw new Error('SOUNDON_DRAFT_PAGINATION_LIMIT');
}

/** Resolve just the selected CMS release; no full draft-table prerequisite. */
export async function openMatchingSoundOnDraft(page: Page, title: string, artist: string, timeout=20000, onProgress?: PublishProgressReporter): Promise<string> {
    const origin=new URL(page.url()).origin;
    if(!title.trim() || !artist.trim()) throw new Error('SOUNDON_TITLE_ARTIST_REQUIRED');
    onProgress?.(20,'Menunggu halaman Draft siap');
    await waitForPublishLibrary(page,timeout);
    const tabs=page.getByRole('tab',{name:/^drafts?\b/i})
        .or(page.getByRole('button',{name:/^drafts?(?:\s*\(?\d+\)?)?$/i}))
        .or(page.getByText(/^Drafts?(?:\s*\(?\d+\)?)?$/i,{exact:true})).filter({visible:true});
    if(await tabs.count()) {
        const tab=tabs.first();
        const selected=await tab.getAttribute('aria-selected');
        if(selected!=='true' && !/active|selected/i.test(await tab.getAttribute('class') ?? '')) {
            await tab.click({timeout});
            await page.waitForTimeout(Math.min(timeout,400));
        }
    }
    onProgress?.(25,'Mencari judul di draft SoundOn');
    let search=page.getByPlaceholder(/search|cari/i).or(page.locator('input[type="search"]')).filter({visible:true}).first();
    if(!await search.count()) {
        const textboxes=page.getByRole('textbox').filter({visible:true});
        if(await textboxes.count()<=1) search=textboxes.first();
    }
    await search.waitFor({state:'visible',timeout:Math.min(timeout,5000)}).catch(()=>undefined);
    const queries: Array<string | null> = await search.count() ? [title, ''] : [null];
    for (const query of queries) {
        if (query !== null) {
            if (query === '') onProgress?.(25,'Pencarian judul belum cocok; memeriksa halaman daftar Draft');
            // Enter also resets pagination. Clear any previous query before the
            // fallback scan, because the server search can omit an existing draft.
            for(let attempt=0;attempt<2;attempt++) {
                await assertPublishSession(page);
                await search.fill('');
                if(query) await search.pressSequentially(query,{delay:10});
                else await search.press('Backspace');
                await search.press('Enter');
                await page.waitForTimeout(Math.min(timeout,800));
                if(await search.inputValue()===query) break;
                if(attempt===1) throw new Error('SOUNDON_DRAFT_SEARCH_RESET: Kolom pencarian direset saat halaman memuat ulang. Coba ulang setelah halaman Draft siap.');
            }
            // A pending request/spinner must finish before unfiltered rows are
            // interpreted as the fallback list. Loading is never "not found".
            await page.waitForLoadState('networkidle',{timeout:Math.min(timeout,10000)}).catch(()=>undefined);
            const loading=page.locator('[aria-busy="true"],.ant-spin-spinning,.arco-spin-loading,[role="progressbar"]').filter({visible:true});
            await loading.first().waitFor({state:'hidden',timeout}).catch(()=>{throw new Error('SOUNDON_DRAFT_LIST_LOADING: Daftar Draft masih dimuat.');});
        }
        const seen=new Set<string>();
        for(let number=1;number<=10000;number++) {
            // React lists can consist entirely of divs, so inspect the smallest
            // visible ancestor containing both exact metadata values, not tbody.
            const mark=async()=>page.evaluate(wanted=>{
                document.querySelectorAll('[data-dimensimatic-artist-truncated]').forEach(el=>el.removeAttribute('data-dimensimatic-artist-truncated'));
                document.querySelectorAll('[data-dimensimatic-match-title]').forEach(el=>el.removeAttribute('data-dimensimatic-match-title'));
                document.querySelectorAll('[data-dimensimatic-match]').forEach(el=>el.removeAttribute('data-dimensimatic-match'));
                const norm=(value:string)=>value.normalize('NFKC').replace(/[\u200B-\u200D\uFEFF]/g,'').trim().replace(/[’‘`]/g,"'").replace(/\s+/g,' ').toLocaleLowerCase();
                const visible=(el:Element)=>(el as HTMLElement).getBoundingClientRect().height>0 && getComputedStyle(el).visibility!=='hidden';
                const text=(el:Element)=>(el as HTMLElement).innerText ?? '';
                const titleValue=(el:Element)=>[text(el),el.getAttribute('title')??'',el.getAttribute('aria-label')??'',el.getAttribute('data-full-title')??''].some(value=>norm(value)===wanted.title);
                const titles=Array.from(document.querySelectorAll('body *'))
                    .filter(el=>visible(el) && titleValue(el))
                    .filter(el=>!Array.from(el.children).some(child=>visible(child) && titleValue(child)));
                // A single exact title is the primary identity requested by the operator.
                // Artist disambiguation is required only for repeated titles.
                if(titles.length===1) {
                    const node=titles[0]!;
                    const scope=node.closest('tr,[role="row"],article,[data-testid="draft-card"],[class*="table-row"],[class*="list-item"],[class*="card"]') ?? node.parentElement!;
                    scope.setAttribute('data-dimensimatic-match','0');
                    node.setAttribute('data-dimensimatic-match-title','true');
                    return 1;
                }
                const candidates=new Set<Element>();
                const uncertain=new Set<Element>();
                for(const node of titles) {
                    const boundary=node.closest('tr,[role="row"],article,[data-testid="draft-card"],[class*="table-row"],[class*="list-item"],[class*="card"]');
                    let row:Element|null=node.parentElement;
                    for(let depth=0;row && depth<8 && !['BODY','HTML','MAIN'].includes(row.tagName);depth++,row=row.parentElement) {
                        const nodes=[row,...Array.from(row.querySelectorAll('span,p,td,a,div,button,[title],[aria-label]'))].filter(visible);
                        const texts=[...(row as HTMLElement).innerText.split(/\r?\n|\t/),...nodes.flatMap(el=>[el.textContent??'',el.getAttribute('title')??'',el.getAttribute('aria-label')??'',el.getAttribute('data-full-name')??''])].map(norm);
                        const exact=texts.includes(wanted.artist);
                        const shortened=texts.some(value=>/(?:\.\.\.|…)$/.test(value) && value.replace(/(?:\.\.\.|…)$/,'').trim().length>=4 && wanted.artist.startsWith(value.replace(/(?:\.\.\.|…)$/,'').trim()));
                        if(exact || shortened) {
                            const scope=boundary ?? row;
                            candidates.add(scope);
                            if(!exact) uncertain.add(scope);
                            else uncertain.delete(scope);
                            break;
                        }
                        if(row===boundary || (!boundary && depth>=1)) break;
                    }
                }
                const smallest=[...candidates].filter(row=>![...candidates].some(other=>other!==row && row.contains(other)));
                smallest.forEach((row,index)=>{row.setAttribute('data-dimensimatic-match',String(index));if(uncertain.has(row)) row.setAttribute('data-dimensimatic-artist-truncated','true');titles.filter(node=>row.contains(node)).forEach(node=>node.setAttribute('data-dimensimatic-match-title','true'));});
                return smallest.length;
            },{title:normalized(title),artist:normalized(artist)});
            const settledRows = query === '' && await page.locator(rowsSelector).filter({visible:true}).count() > 0;
            const deadline=Date.now()+(number > 1 || settledRows ? Math.min(timeout,500) : timeout);
            let count=0;
            do {await assertPublishSession(page);count=await mark();if(count) break;await page.waitForTimeout(200);} while(Date.now()<deadline);
            if(count>1) throw new Error('SOUNDON_SELECTED_DRAFT_AMBIGUOUS: Beberapa draft memiliki judul dan artis yang sama: '+title+' / '+artist);
            if(count===1) {
                onProgress?.(35,'Draft ditemukan, membuka editor');
                const row=page.locator('[data-dimensimatic-match="0"]');
                let needsArtistProof=await row.getAttribute('data-dimensimatic-artist-truncated')==='true';
                if(needsArtistProof) {
                    const shortened=row.locator('span,button,div').filter({hasText:/(?:\.\.\.|…)$/}).filter({visible:true}).last();
                    if(await shortened.count()) {
                        await shortened.hover({timeout});
                        const tooltips=page.locator('[role="tooltip"],.semi-tooltip-content,.arco-tooltip-content,.ant-tooltip-inner').filter({visible:true});
                        await tooltips.first().waitFor({state:'visible',timeout:Math.min(timeout,1500)}).catch(()=>undefined);
                        const names=(await tooltips.allInnerTexts()).map(normalized);
                        if(names.includes(normalized(artist))) needsArtistProof=false;
                        else if(names.length) throw new Error('SOUNDON_DRAFT_ARTIST_MISMATCH: Tooltip artis SoundOn berbeda dari '+artist);
                    }
                }
                const edit=row.getByRole('button',{name:/^(?:edit|edit release|continue|continue editing)$/i})
                    .or(row.getByRole('link',{name:/^(?:edit|edit release|continue|continue editing)$/i})).filter({visible:true});
                const iconEdit=row.locator('[data-icon*="edit" i],[data-testid*="edit" i],[class*="icon-edit" i],[title*="edit" i],[aria-label*="edit" i]').filter({visible:true});
                const titleNode=row.locator('[data-dimensimatic-match-title]').filter({visible:true});
                await (await edit.count()===1 ? edit : await iconEdit.count()===1 ? iconEdit : titleNode.first()).click({timeout});
                await page.waitForURL(url=>draftReferenceFromUrl(url.href,origin)!==null,{timeout})
                    .catch(()=>{throw new Error('SOUNDON_DRAFT_OPEN_FAILED: '+title+' / '+artist+'; URL='+page.url());});
                await waitDraftEditor(page,timeout);
                if(needsArtistProof) await verifyOpenedDraftArtist(page,artist,timeout);
                onProgress?.(40,'Editor draft siap');
                return draftReferenceFromUrl(page.url(),origin)!.draft_id;
            }
            // Search usually covers the whole library. If pagination remains, scan
            // it defensively rather than silently selecting the wrong artist.
            const signature=await page.locator('body').innerText();
            if(seen.has(signature) || !await advanceDraftPage(page,number,timeout)) break;
            seen.add(signature);
        }
    }
    throw new Error('SOUNDON_SELECTED_DRAFT_NOT_FOUND: Judul '+title+' belum ditemukan pada hasil pencarian Draft. '+await publishSearchDiagnostics(page));
}

export async function assertPublishSession(page: Page): Promise<void> {
    const path=new URL(page.url()).pathname;
    if(/\/(?:login|sign-in)(?:\/|$)/i.test(path) || await page.locator('input[type="password"]').filter({visible:true}).count())
        throw new Error('AUTH_SESSION_EXPIRED: Browser aplikasi diarahkan ke login SoundOn. Sesi akan diperbarui sebelum mencoba pencarian draft lagi.');
}

export async function waitForPublishLibrary(page: Page, timeout: number): Promise<void> {
    const deadline=Date.now()+timeout;
    do {
        await assertPublishSession(page);
        const ready=await page.getByRole('tab',{name:/^drafts?\b/i}).or(page.getByText(/^Drafts?(?:\s*\(?\d+\)?)?$/i,{exact:true}))
            .or(page.locator('tbody tr,[role="row"],[data-testid="draft-card"],[class*="card"]')).filter({visible:true}).count();
        if(ready) return;
        if(await page.getByPlaceholder(/search|cari/i).or(page.locator('input[type="search"]')).filter({visible:true}).count()) return;
        await page.waitForTimeout(100);
    } while(Date.now()<deadline);
    throw new Error('SOUNDON_DRAFT_LIST_NOT_READY: Halaman Draft belum siap. '+await publishSearchDiagnostics(page));
}

async function publishSearchDiagnostics(page: Page): Promise<string> {
    await assertPublishSession(page);
    const snapshot=await page.evaluate(()=>{
        const visible=(node:Element)=>(node as HTMLElement).getBoundingClientRect().height>0 && getComputedStyle(node).visibility!=='hidden';
        const titles=Array.from(document.querySelectorAll('tbody tr,[role="row"],[data-testid="draft-card"],[class*="card"]')).filter(visible)
            .slice(0,5).map(node=>(node as HTMLElement).innerText.replace(/\s+/g,' ').trim().slice(0,240));
        const inputs=Array.from(document.querySelectorAll('input')).filter(visible).filter(node=>!['password','email','hidden'].includes(node.type))
            .map(node=>({placeholder:node.placeholder,type:node.type,query:node.value}));
        const hints=(document.body.innerText.match(/Showing[^\n]{0,100}|No (?:results|drafts|releases)[^\n]{0,100}|Loading[^\n]{0,50}/gi)??[]).slice(0,3);
        return {inputs,visibleRows:titles,hints};
    });
    const url=new URL(page.url());url.search='';url.hash='';
    return 'URL='+url.href+'; hasil browser='+JSON.stringify(snapshot);
}

async function verifyOpenedDraftArtist(page: Page, artist: string, timeout: number): Promise<void> {
    const verified=async()=>{
        const scopes=page.locator('.so-artist-preview-item-artist-name,[class*="artist-name"],[data-testid*="artist-name"],[aria-label="Primary artist"],input[name="artistName"],input[name="primary_artist"]');
        const values=await scopes.filter({visible:true}).evaluateAll(nodes=>nodes.flatMap(node=>[
            node.textContent??'',node.getAttribute('title')??'',node.getAttribute('aria-label')??'',(node as HTMLInputElement).value??''
        ]));
        if(values.some(value=>normalized(value)===normalized(artist))) return true;
        const labels=page.getByText(/^Primary artists?$/i,{exact:true}).filter({visible:true});
        for(const label of await labels.all()) {
            let scope=label.locator('xpath=..');
            for(let depth=0;depth<3;depth++,scope=scope.locator('xpath=..')) {
                const texts=await scope.locator('span,button,input,[title]').filter({visible:true}).evaluateAll(nodes=>nodes.flatMap(node=>[node.textContent??'',node.getAttribute('title')??'',(node as HTMLInputElement).value??'']));
                if(texts.some(value=>normalized(value)===normalized(artist))) return true;
            }
        }
        return false;
    };
    const deadline=Date.now()+Math.min(timeout,10000);
    do {if(await verified()) return;await page.waitForTimeout(200);} while(Date.now()<deadline);
    throw new Error('SOUNDON_DRAFT_ARTIST_NOT_VERIFIED: Judul ditemukan, tetapi nama artis pada daftar dipotong dan nama lengkap '+artist+' belum dapat dikonfirmasi di editor. Next/Submit belum dijalankan.');
}

const publishErrors = '.semi-toast-content-error, .ant-message-error, .arco-message-error, .semi-form-field-error-message, .semi-form-field-error-message-wrapper, .ant-form-item-explain-error, .arco-form-message, .alert-danger, .text-danger, [role="alert"], .semi-toast-error .semi-toast-content';

async function visiblePublishErrors(page: Page): Promise<string[]> {
    return page.locator(publishErrors).filter({visible:true}).evaluateAll(nodes=>nodes
        .filter(node=>!node.closest('[class*="success" i], [class*="info" i], [data-type="success"]'))
        .map(node=>(node.textContent??'').trim()).filter(text=>text && !/\b(?:successfully|successful|success|submission completed)\b/i.test(text)));
}

export async function publishFailureReason(page: Page, phase: string, step: number, fallback: string): Promise<string> {
    const messages=await visiblePublishErrors(page).catch(()=>[]);
    const invalid=await page.locator('input, select, textarea').filter({visible:true}).evaluateAll(nodes=>nodes.flatMap(node=>{
        const input=node as HTMLInputElement;
        if(input.type==='password' || (!input.matches('[aria-invalid="true"]') && (!input.willValidate || input.validity.valid))) return [];
        const group=input.closest('.semi-form-field, .ant-form-item, .arco-form-item');
        const label=Array.from(input.labels ?? []).map(el=>el.textContent ?? '').join(' ').trim()
            || input.getAttribute('aria-label') || group?.querySelector('label')?.textContent?.trim() || input.name || input.id || 'kolom tanpa label';
        return [label+': '+(input.validationMessage || 'nilai belum valid')];
    })).catch(()=>[]);
    const details=[...new Set([...messages.map(v=>v.trim()).filter(Boolean),...invalid])].slice(0,12).join('; ').slice(0,2200);
    const controls=await page.getByRole('button',{name:/^(?:Next|Continue|Submit(?: release| for review)?)$/i}).filter({visible:true})
        .evaluateAll(nodes=>nodes.map(node=>(node.textContent??'').trim()+((node as HTMLButtonElement).disabled || node.getAttribute('aria-disabled')==='true' ? ' (nonaktif)' : ' (aktif)'))).catch(()=>[]);
    return `SOUNDON_PUBLISH_${phase.toUpperCase().replace(/[^A-Z]+/g,'_')}_FAILED: Tahap ${step} (${phase}). ${details ? 'Pesan SoundOn: '+details : fallback}.`+(controls.length ? ' Tombol: '+controls.join(', ')+'.' : ' Tombol Next/Submit tidak terlihat.');
}

type WizardState = {signature:string; observable:boolean};
async function wizardState(page:Page):Promise<WizardState> {
    return page.evaluate(()=>{
        const visible=(node:Element)=>(node as HTMLElement).getBoundingClientRect().height>0 && getComputedStyle(node).visibility!=='hidden';
        const headings=Array.from(document.querySelectorAll('h1,h2,h3,[role="heading"]')).filter(visible)
            .map(node=>(node as HTMLElement).innerText.trim()).filter(text=>/^(?:Track information|Release Settings|More release options|Review)$/i.test(text));
        const active=Array.from(document.querySelectorAll('[aria-current="step"],.semi-steps-item-active,.arco-steps-item-active,.ant-steps-item-process')).filter(visible).map(node=>(node as HTMLElement).innerText);
        const labels=Array.from(document.querySelectorAll('label,.semi-form-field-label,.arco-form-label')).filter(visible).map(node=>(node as HTMLElement).innerText);
        const submit=Array.from(document.querySelectorAll('button')).filter(visible).some(node=>/^Submit(?: release| for review)?$/i.test(node.innerText.trim()));
        return {signature:JSON.stringify({headings,active,labels,submit}),observable:headings.length+active.length+labels.length>0};
    });
}
async function waitWizardAdvance(page:Page,before:WizardState,timeout:number):Promise<void> {
    const deadline=Date.now()+timeout;
    do {
        const errors=await visiblePublishErrors(page);
        if(errors.length) throw new Error('SoundOn menolak Next');
        if(await page.locator('[role="dialog"],dialog,.semi-modal-content,.ant-modal-content,.arco-modal').filter({visible:true}).count()) return;
        if((await wizardState(page)).signature!==before.signature) return;
        // Minimal embedded forms can reuse a single button without step labels.
        // The real SoundOn editor exposes headings/active steps and must advance.
        if(!before.observable) {await page.waitForTimeout(Math.min(700,timeout));return;}
        await page.waitForTimeout(100);
    } while(Date.now()<deadline);
    throw new Error('Halaman belum berpindah setelah Next; menunggu penyimpanan/validasi SoundOn. Next tidak diklik berulang pada tahap yang sama.');
}

export type SubmitReceipt={uiConfirmed:boolean; nextClicks:number; message?:string};
async function submissionNotices(page:Page):Promise<string[]> {
    const success=/(?:release|submission|track)\s+(?:(?:has|have)\s+been\s+)?(?:successfully\s+)?submitted|submitted\s+successfully|submission\s+(?:completed?|successful)|successfully\s+(?:submitted|published)|^Submitted(?: successfully)?$|^Under Review$/i;
    return page.getByText(success).filter({visible:true}).allInnerTexts().then(values=>values.map(value=>value.trim()).filter(Boolean));
}

async function clickSubmitAndWait(page:Page,timeout:number,nextClicks:number):Promise<SubmitReceipt> {
    const baseline=new Set(await submissionNotices(page));
    const beforeUrl=page.url();
    const pending=new Set<Request>();
    let rejection='';let responseDetail='';let lastActivity=Date.now();
    const mutation=(request:Request)=>['POST','PUT','PATCH'].includes(request.method()) && !/analytics|telemetry|\/(?:log|collect|report|event)(?:\/|$)/i.test(new URL(request.url()).pathname);
    const requestListener=(request:Request)=>{if(mutation(request)){pending.add(request);lastActivity=Date.now();}};
    const failureListener=(request:Request)=>{if(pending.delete(request)){lastActivity=Date.now();rejection='Permintaan Submit gagal: '+(request.failure()?.errorText??'koneksi terputus');}};
    const responseListener=(response:Response)=>{void (async()=>{
        const request=response.request();if(!pending.has(request))return;
        try {
            const path=new URL(response.url()).pathname;
            let message='';let explicitFailure=false;
            if(/json/i.test(response.headers()['content-type']??'')) {
                const body=await response.json().catch(()=>null);
                message=String(body?.message??body?.msg??body?.status_msg??body?.error?.message??'').slice(0,600);
                const code=body?.status_code??body?.error_code??body?.code;
                explicitFailure=body?.success===false || (/submit|publish/i.test(path) && code!==undefined && !['0','200','success','ok'].includes(String(code).toLowerCase()) && !!message && !/^(?:success(?:ful(?:ly)?)?|ok)[.!]?$/i.test(message));
            }
            responseDetail='Respons SoundOn HTTP '+response.status()+' ('+path+')'+(message ? ': '+message : '');
            if(response.status()>=400 || explicitFailure) rejection=responseDetail;
        } finally {pending.delete(request);lastActivity=Date.now();}
    })().catch(()=>{pending.delete(response.request());});};
    page.on('request',requestListener);page.on('requestfailed',failureListener);page.on('response',responseListener);
    try {
        await page.getByRole('button',{name:/^Submit(?: release| for review)?$/i}).filter({visible:true}).click({timeout});
        const started=Date.now(),deadline=started+timeout;
        let confirmationClicked=false;
        do {
            await assertPublishSession(page);
            const errors=await visiblePublishErrors(page);
            if(errors.length)throw new Error('SoundOn menolak Submit');
            if(rejection)throw new Error(rejection);
            const accepted=(await submissionNotices(page)).find(value=>!baseline.has(value));
            if(accepted && pending.size===0)return {uiConfirmed:true,nextClicks,message:accepted};
            const dialog=page.locator('[role="dialog"],dialog,.semi-modal-content,.ant-modal-content,.arco-modal').filter({visible:true}).last();
            if(await dialog.count()) {
                const confirm=dialog.getByRole('button',{name:/^(?:Confirm|Submit|Publish|Upload|Yes|Confirm (?:publish|submission))$/i}).filter({visible:true});
                if(!confirmationClicked && await confirm.count()===1) {confirmationClicked=true;await confirm.click({timeout});}
                else if(await confirm.count()!==1) {
                    // React can replace the editor with a success dialog between
                    // the notice read above and this dialog read. Re-read the
                    // receipt before interpreting a Done/View releases button.
                    const receipt=(await submissionNotices(page)).find(value=>!baseline.has(value));
                    if(receipt && pending.size===0)return {uiConfirmed:true,nextClicks,message:receipt};
                    if(!receipt && pending.size===0 && Date.now()-lastActivity>1500)
                        throw new Error('Konfirmasi Submit masih terbuka; tombol konfirmasi belum dikenali.');
                }
            } else {
                const notice=(await submissionNotices(page)).find(value=>!baseline.has(value));
                if(notice && pending.size===0)return {uiConfirmed:true,nextClicks,message:notice};
                const submitVisible=await page.getByRole('button',{name:/^Submit(?: release| for review)?$/i}).filter({visible:true}).count();
                if(pending.size===0 && !submitVisible && Date.now()-started>=Math.min(700,timeout/2) && Date.now()-lastActivity>=Math.min(400,timeout/3))
                    return {uiConfirmed:false,nextClicks};
                if(page.url()!==beforeUrl && !/\/publish\//.test(new URL(page.url()).pathname) && pending.size===0)
                    return {uiConfirmed:false,nextClicks};
            }
            await page.waitForTimeout(100);
        }while(Date.now()<deadline);
        throw new Error('Submit telah diklik tetapi SoundOn belum memberi konfirmasi penerimaan. '+(pending.size ? 'Permintaan masih diproses; halaman editor dipertahankan sampai batas tunggu.' : responseDetail || 'Tombol Submit masih terlihat.'));
    } finally {
        page.off('request',requestListener);page.off('requestfailed',failureListener);page.off('response',responseListener);
    }
}

export async function submitSoundOnDraft(page: Page, timeout = 20000, onProgress?: PublishProgressReporter): Promise<SubmitReceipt> {
    let nextClicks=0;
    for(let step=1;step<=30;step++) {
        await page.getByRole('button',{name:/^(?:Next|Continue|Submit(?: release| for review)?)$/i}).filter({visible:true}).first()
            .waitFor({state:'visible',timeout}).catch(async()=>{throw new Error(await publishFailureReason(page,'Next/Submit',step,'Tombol belum siap atau halaman tidak sesuai'));});
        const next=page.getByRole('button',{name:/^(?:Next|Continue)$/i}).filter({visible:true});
        if(await next.count()===1) {
            if(nextClicks>=3)throw new Error(await publishFailureReason(page,'Next',step,'Tiga kali Next sudah dijalankan tetapi halaman Review/Submit belum tersedia'));
            onProgress?.(45+nextClicks*5,'Next '+(nextClicks+1)+'/3 — menunggu tahap berikutnya');
            try {
                const before=await wizardState(page);
                await next.click({timeout});
                await waitWizardAdvance(page,before,timeout);
                const dialog=page.locator('[role="dialog"],dialog,.semi-modal-content,.ant-modal-content,.arco-modal').filter({visible:true}).last();
                if(await dialog.count()) {
                    const continuation=dialog.getByRole('button',{name:/^(?:Next|Continue(?: anyway)?|Proceed|Later|No, continue with this date)$/i}).filter({visible:true}).last();
                    if(!await continuation.count())throw new Error('SoundOn meminta konfirmasi tambahan sebelum Next');
                    await continuation.click({timeout});await dialog.waitFor({state:'hidden',timeout});
                    await waitWizardAdvance(page,before,timeout);
                }
                nextClicks++;
            }catch(error) {
                const fallback=error instanceof Error && error.name!=='TimeoutError' ? error.message : 'Tombol Next belum siap diklik: belum stabil, nonaktif, atau tertutup elemen lain';
                throw new Error(await publishFailureReason(page,'Next',step,fallback));
            }
            continue;
        }
        const submit=page.getByRole('button',{name:/^Submit(?: release| for review)?$/i}).filter({visible:true});
        if(await submit.count()!==1)throw new Error(await publishFailureReason(page,'Submit',step,'Tombol Submit tidak ditemukan secara unik'));
        onProgress?.(72,'Submit — '+nextClicks+' kali Next selesai; menunggu penerimaan SoundOn');
        try {return await clickSubmitAndWait(page,timeout,nextClicks);}
        catch(error) {throw new Error(await publishFailureReason(page,'Submit',step,error instanceof Error ? error.message : 'Submit belum terkonfirmasi'));}
    }
    throw new Error(await publishFailureReason(page,'Next',30,'Submit belum tersedia'));
}
