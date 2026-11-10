import { describe, expect, it } from "vitest";
import { chromium } from "playwright";
import { detectReleaseStatus, findReleaseStatuses, parseRejectionReason } from "./release-status-finder.js";

describe("parseRejectionReason", () => {
    it("reads every rejection bullet from the SoundOn release warning", () => {
        const text = [
            "The track Karma Tak Lagi Bisa Dipercaya was not approved due to the following reason(s):",
            "· Instrumental: track with lyrics cannot be marked as instrumental",
            "· Cover art: text is unreadable",
            "Track Information",
            "This text must not be included",
        ].join("\n");

        expect(parseRejectionReason(text)).toBe([
            "Instrumental: track with lyrics cannot be marked as instrumental",
            "Cover art: text is unreadable",
        ].join("\n"));
    });
});

describe("detectReleaseStatus", () => {
    it("detects Live when the row starts with the release title", () => {
        expect(detectReleaseStatus("Ampuni Aku\nA. Robi Firdaus\n2026-09-06\nLive")).toBe("live");
    });
});

describe("findReleaseStatuses", () => {
    it("finds a unique title when SoundOn renders nested row and card wrappers", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.route("http://soundon.test/**", (route) => route.fulfill({ body: "" }));
            await page.goto("http://soundon.test/");
            await page.setContent(`
                <div class="release-card" role="row">
                    <table><tbody><tr>
                        <td><a href="/library/release/123">Meski Jauh Kau Tetap Di Hati</a></td>
                        <td>Inonok feat. Another Artist</td>
                        <td>Under Review</td>
                    </tr></tbody></table>
                </div>
            `);

            const matches = await findReleaseStatuses(page, [{
                key: "release-123",
                title: "Meski Jauh Kau Tetap Di Hati",
                artist: "Inonok",
            }]);

            expect(matches["release-123"]?.status).toBe("under_review");
            expect(matches["release-123"]?.release_url).toBe("http://soundon.test/library/release/123");
        } finally {
            await browser.close();
        }
    });
});


describe("SoundOn status search readiness", () => {
    it("selects All releases and waits for keyboard-triggered delayed results for each title", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.route("http://soundon.test/**", route => route.fulfill({ body: "" }));
            await page.goto("http://soundon.test/");
            await page.setContent(`
                <button role="tab" aria-selected="true">Drafts</button>
                <button role="tab" aria-selected="false" id="all">All releases (2)</button>
                <input placeholder="Search releases" />
                <table><tbody id="rows"></tbody></table>
                <script>
                    let all = false, timer;
                    document.querySelector('#all').onclick = () => { all = true; };
                    document.querySelector('input').onkeyup = event => {
                        clearTimeout(timer);
                        rows.innerHTML = '';
                        const query = event.target.value;
                        timer = setTimeout(() => {
                            const title = ['Lagu Pertama', 'Lagu Kedua'].find(value => value.toLowerCase().includes(query.toLowerCase()));
                            if (all && query && title) {
                                rows.innerHTML = '<tr><td><a href="/library/release/' + title.replaceAll(' ', '-') + '">' + title + '</a></td><td>Artis</td><td>Under Review</td></tr>';
                            }
                        }, 2200);
                    };
                </script>
            `);
            const result = await findReleaseStatuses(page, [
                { key: 'one', title: 'Lagu Pertama', artist: 'Artis' },
                { key: 'two', title: 'Lagu Kedua', artist: 'Artis' },
            ]);
            expect(result.one?.status).toBe('under_review');
            expect(result.two?.status).toBe('under_review');
            expect(result.two?.release_url).toContain('Lagu-Kedua');
        } finally { await browser.close(); }
    }, 30_000);

    it("waits for a delayed next page instead of reporting a missing release", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.route("http://soundon.test/**", route => route.fulfill({ body: "" }));
            await page.goto("http://soundon.test/");
            await page.setContent(`
                <table><tbody id="rows"><tr><td>Other release</td><td>Under Review</td></tr></tbody></table>
                <button aria-label="Next" onclick="this.disabled=true;setTimeout(() => rows.innerHTML='<tr><td>Lagu Lama</td><td>Artis</td><td>Under Review</td></tr>', 2200)">Next</button>
            `);
            const result = await findReleaseStatuses(page, [{key:'old',title:'Lagu Lama',artist:'Artis'}]);
            expect(result.old?.status).toBe('under_review');
        } finally { await browser.close(); }
    }, 20_000);
});


it("does not confuse Approved with the Not Approved tab", async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
    try {
        const page = await browser.newPage();
        await page.route("http://soundon.test/**", route => route.fulfill({ body: "" }));
        await page.goto("http://soundon.test/");
        await page.setContent(`
            <button role="tab" onclick="rows.innerHTML=''">Not Approved</button>
            <button role="tab" onclick="rows.innerHTML='<tr><td>Lagu Disetujui</td><td>Artis</td></tr>'">Approved (1)</button>
            <table><tbody id="rows"><tr><td>Other</td></tr></tbody></table>
        `);
        const result = await findReleaseStatuses(page, [{key:'approved',title:'Lagu Disetujui',artist:'Artis'}]);
        expect(result.approved?.status).toBe('approved');
    } finally { await browser.close(); }
}, 20_000);


describe('current SoundOn library layout', () => {
    it('resets the separate status dropdown and uses a distinctive title word', async () => {
        const browser = await chromium.launch({headless:true});
        try {
            const page=await browser.newPage();
            await page.route('http://soundon.test/**',route=>route.fulfill({body:''}));
            await page.goto('http://soundon.test/');
            await page.setContent(`<button role="tab" aria-selected="true">All releases</button>
                <div role="combobox" onclick="options.hidden=false">Under Review</div>
                <div id="options" hidden><div role="option" onclick="document.querySelector('[role=combobox]').textContent='All status';options.hidden=true">All status</div></div>
                <input placeholder="Find release by album name or artist name">
                <table><tbody id="rows"></tbody></table>
                <script>
                 window.queries=[];
                 document.querySelector('input').onkeyup=event=>{
                  window.queries.push(event.target.value);
                  if(document.querySelector('[role=combobox]').textContent==='All status' && event.target.value.toLowerCase()==='paradise') {
                   rows.innerHTML='<tr><td><div class="so-album-preview-item-title-text">A Paradise of Roses</div><span>Brandon Samuel Hayes</span></td><td>Delivered</td></tr>';
                  }else rows.innerHTML='<tr><td>No Result</td></tr>';
                 };
                </script>`);
            const result=await findReleaseStatuses(page,[{key:'rose',title:'A Paradise of Roses',artist:'Brandon Samuel Hayes'}]);
            expect(result.rose?.status).toBe('delivery');
            expect(await page.evaluate(()=>(window as any).queries)).toContain('paradise');
            expect(await page.getByRole('combobox').innerText()).toBe('All status');
        }finally{await browser.close();}
    },20000);

    it('finds the exact release on page two using Semi UI li role=button arrows', async()=>{
        const browser=await chromium.launch({headless:true});
        try{
            const page=await browser.newPage();
            await page.route('http://soundon.test/**',route=>route.fulfill({body:''}));
            await page.goto('http://soundon.test/');
            await page.setContent(`<table><tbody id="rows"><tr><td>Other title</td><td>Under Review</td></tr></tbody></table>
                <ul class="semi-page"><li role="button" aria-label="Previous" aria-disabled="true"><span>�</span></li><li aria-label="Page 1" aria-current="page">1</li><li role="button" aria-label="Next" onclick="this.setAttribute('aria-disabled','true');setTimeout(()=>rows.innerHTML='<tr><td>The Forever Blooming Rose</td><td>Noah Benjamin Hayes</td><td>Under Review</td></tr>',700)"><span aria-label="chevron_right">�</span></li></ul>`);
            const result=await findReleaseStatuses(page,[{key:'forever',title:'The Forever Blooming Rose',artist:'Noah Benjamin Hayes'}]);
            expect(result.forever?.status).toBe('under_review');
        }finally{await browser.close();}
    },20000);

    it('scans the unfiltered library once when SoundOn search omits matching titles',async()=>{
        const browser=await chromium.launch({headless:true});
        try{
            const page=await browser.newPage();
            await page.route('http://soundon.test/**',route=>route.fulfill({body:''}));
            await page.goto('http://soundon.test/');
            await page.setContent(`<input placeholder="Find release by album name or artist name"><table><tbody id="rows"><tr><td>Other</td><td>Under Review</td></tr></tbody></table><button aria-label="Next" id="next">Next</button>
             <script>
              let searched=false;window.nexts=0;
              document.querySelector('input').onkeyup=event=>{searched=true;rows.innerHTML='<tr><td>No Result</td></tr>';next.disabled=!!event.target.value;};
              next.onclick=()=>{window.nexts++;next.disabled=true;rows.innerHTML='<tr><td>The Roots of True Love</td><td>Evan Richard Mitchell</td><td>Under Review</td></tr><tr><td>A Paradise of Roses</td><td>Brandon Samuel Hayes</td><td>Under Review</td></tr>';};
             </script>`);
            const result=await findReleaseStatuses(page,[{key:'roots',title:'The Roots of True Love',artist:'Evan Richard Mitchell'},{key:'paradise',title:'A Paradise of Roses',artist:'Brandon Samuel Hayes'}]);
            expect(result.roots?.status).toBe('under_review');
            expect(result.paradise?.status).toBe('under_review');
            expect(await page.evaluate(()=>(window as any).nexts)).toBe(1);
        }finally{await browser.close();}
    },30000);

    it('does not wait for rejection messages when reading live-release identifiers',async()=>{
        const browser=await chromium.launch({headless:true});
        try{
            const context=await browser.newContext();
            const page=await context.newPage();
            await context.route('http://soundon.test/**',route=>route.fulfill({body:route.request().url().includes('/detail')?'UPC: 5064083067720 ISRC: IDABC2612345':''}));
            await page.goto('http://soundon.test/');
            await page.setContent('<table><tbody><tr><td><a href="/library/detail?id=1">The Roots of True Love</a></td><td>Evan Richard Mitchell</td><td>Live</td></tr></tbody></table>');
            const result=await findReleaseStatuses(page,[{key:'live',title:'The Roots of True Love',artist:'Evan Richard Mitchell'}]);
            expect(result.live?.status).toBe('live');
            expect(result.live?.upc).toBe('5064083067720');
            expect(result.live?.isrcs).toEqual(['IDABC2612345']);
        }finally{await browser.close();}
    },5000);
});


it('waits for the exact search response and its matching rendered rows',async()=>{
    const browser=await chromium.launch({headless:true});
    try{
        const page=await browser.newPage();
        await page.route('http://soundon.test/**',route=>route.request().url().includes('/api/search/entity')
            ? route.fulfill({contentType:'application/json',body:JSON.stringify({ids:JSON.parse(route.request().postData()!).queries[0]?.queryValue ? ['42'] : [],baseResp:{errorCode:0}})})
            : route.fulfill({body:''}));
        await page.goto('http://soundon.test/');
        await page.setContent(`<input placeholder="Find release by album name or artist name"><table><tbody id="rows"><tr data-row-key="old"><td>Old result</td><td>Other artist</td><td>Under Review</td></tr></tbody></table>
            <script>document.querySelector('input').onkeyup=event=>{const value=event.target.value;fetch('/api/search/entity',{method:'POST',body:JSON.stringify({queries:[{queryValue:value}],offset:0})}).then(r=>r.json()).then(()=>setTimeout(()=>{rows.innerHTML=value?'<tr data-row-key="42"><td>The Forever Blooming Rose</td><td>Noah Benjamin Hayes</td><td>Under Review</td></tr>':'<tr><td>No Result</td></tr>';},350));};</script>`);
        const result=await findReleaseStatuses(page,[{key:'rose',title:'The Forever Blooming Rose',artist:'Noah Benjamin Hayes'}]);
        expect(result.rose?.status).toBe('under_review');
    }finally{await browser.close();}
},10000);

it('reports a failed SoundOn search as an error rather than no release found',async()=>{
    const browser=await chromium.launch({headless:true});
    try{
        const page=await browser.newPage();
        await page.route('http://soundon.test/**',route=>route.request().url().includes('/api/search/entity')?route.fulfill({status:503,body:'Unavailable'}):route.fulfill({body:''}));
        await page.goto('http://soundon.test/');
        await page.setContent(`<input placeholder="Find release by album name or artist name"><script>document.querySelector('input').onkeyup=event=>fetch('/api/search/entity',{method:'POST',body:JSON.stringify({queries:[{queryValue:event.target.value}],offset:0})});</script>`);
        await expect(findReleaseStatuses(page,[{key:'rose',title:'The Forever Blooming Rose',artist:'Noah Benjamin Hayes'}])).rejects.toThrow('SOUNDON_SEARCH_REQUEST_FAILED');
    }finally{await browser.close();}
},10000);


it('does not report not-found when the page counter proves more rows remain',async()=>{
    const browser=await chromium.launch({headless:true});
    try{
        const page=await browser.newPage();
        await page.route('http://soundon.test/**',route=>route.fulfill({body:''}));
        await page.goto('http://soundon.test/');
        await page.setContent('<table><tbody><tr><td>Other</td><td>Under Review</td></tr></tbody></table><p>Showing 1 to 10 of 564</p>');
        await expect(findReleaseStatuses(page,[{key:'rose',title:'The Forever Blooming Rose',artist:'Noah Benjamin Hayes'}])).rejects.toThrow('SOUNDON_STATUS_LIST_INCOMPLETE');
    }finally{await browser.close();}
});

it('reads rejection details even when the library row already contains UPC and ISRC', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const context = await browser.newContext();
        const page = await context.newPage();
        await context.route('http://soundon-reject.test/**', route => route.fulfill({ contentType: 'text/html; charset=utf-8', body:
            route.request().url().includes('/detail/')
                ? '<div role="alert">The track Love Grows Like Ivy was not approved due to the following reason(s):<p>· Artwork: unreadable text</p><p>· Audio: incorrect instrumental flag</p></div>'
                : '<table><tbody><tr><td><a href="/detail/123">Love Grows Like Ivy</a></td><td>Ryan Christopher Morgan</td><td>Not Approved</td><td>UPC: 1234567890123 ISRC: IDABC2612345</td></tr></tbody></table>'
        }));
        await page.goto('http://soundon-reject.test/');
        const matches = await findReleaseStatuses(page, [{ key: 'ivy', title: 'Love Grows Like Ivy', artist: 'Ryan Christopher Morgan' }]);
        expect(matches.ivy?.status).toBe('not_approved');
        expect(matches.ivy?.reason).toBe('Artwork: unreadable text\nAudio: incorrect instrumental flag');
    } finally { await browser.close(); }
}, 15000);
