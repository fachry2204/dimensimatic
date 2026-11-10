import {beforeAll, afterAll, test, expect} from 'vitest';
import {chromium, type Browser} from 'playwright';
import {collectSoundOnDrafts, openDraftForPublishing, openMatchingSoundOnDraft, submitSoundOnDraft} from './publish-release.js';
import {findReleaseStatuses, publishedStatusByDraftId, selectAllReleasesForConfirmation, readVisiblePublishedStatus} from './release-status-finder.js';
let browser: Browser;
beforeAll(async()=>{browser=await chromium.launch({headless:true});});
afterAll(async()=>{await browser.close();});
test('collects every page and deduplicates by draft ID',async()=>{
 const page=await browser.newPage();
 try {
  await page.goto('about:blank');
  await page.setContent(`<table><thead><tr><th>Title</th><th>Artist</th></tr></thead><tbody><tr><td><a href="https://www.soundon.global/library/publish/single?id=1">First</a></td><td>Artist</td></tr></tbody></table><button aria-label="Next page">Next</button><script>document.querySelector('button').onclick=function(){const link=document.querySelector('a');link.textContent='Second';link.href='https://www.soundon.global/library/publish/single?id=2';this.disabled=true}</script>`);
  // Use a real origin for same-domain reference validation.
  const html=await page.content();
  await page.route('https://www.soundon.global/**',route=>route.fulfill({body:html,contentType:'text/html; charset=utf-8'}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  const items=await collectSoundOnDrafts(page,2000);
  expect(items.map(item=>item.title)).toEqual(['First','Second']);
 } finally {await page.close();}
});
test('stalled pagination throws instead of silently reporting a complete list',async()=>{
 const page=await browser.newPage();try {
  await page.setContent('<table><tbody><tr data-id="1"><td>Track</td><td>Artist</td></tr></tbody></table><button aria-label="Next page">Next</button>');
  await expect(collectSoundOnDrafts(page,150)).rejects.toThrow();
 }finally{await page.close();}
});
test('advances the existing draft then submits exactly once',async()=>{
 const page=await browser.newPage();try {
  await page.setContent(`<button onclick="this.textContent='Submit release';this.onclick=()=>{window.count=(window.count||0)+1;this.remove()}">Next</button>`);
  await submitSoundOnDraft(page,1000);
  expect(await page.evaluate(()=>(window as any).count)).toBe(1);
 }finally{await page.close();}
});
test('a draft row without an explicit release status cannot prove publish',async()=>{
 const page=await browser.newPage();try {
  await page.setContent('<table><tbody><tr><td>My Track</td><td>Artist</td><td>Draft</td></tr></tbody></table>');
  expect(await findReleaseStatuses(page,[{key:'selected',title:'My Track',artist:'Artist'}],true,true)).toEqual({});
 }finally{await page.close();}
});

test('collects JS-only drafts with other linked releases and resolves selected draft by clicking its title',async()=>{
 const page=await browser.newPage();let submitted=0;
 try {
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:route.request().url().includes('/publish/')
   ? '<h1>Walking Through Our Home</h1><button>Submit release</button>'
   : `<table><thead><tr><th>Title</th><th>Artist</th></tr></thead><tbody><tr><td><span onclick="location.href='/library/publish/single?id=77'">Walking Through Our Home</span></td><td>Artist One</td></tr><tr><td><a href="/library/publish/single?id=88">Another Track</a></td><td>Artist Two</td></tr></tbody></table>`}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  const drafts=await collectSoundOnDrafts(page,2000);
  expect(drafts).toHaveLength(2);expect(drafts[0]!.draft_id).toMatch(/^existing-/);
  expect(drafts[1]!.draft_id).toBe('88');
  expect(await openDraftForPublishing(page,drafts[0]!,1500)).toBe('77');
  expect(page.url()).toContain('id=77');
  expect(await page.getByRole('button',{name:'Submit release'}).count()).toBe(1);
 }finally{await page.close();}
});
test('ambiguous JS-only title/artist pairs remain listed but cannot open for publishing',async()=>{
 const page=await browser.newPage();try{
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:'<table><tbody><tr><td>Same Title</td><td>Artist</td></tr><tr><td>Same Title</td><td>Artist</td></tr></tbody></table>'}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  const drafts=await collectSoundOnDrafts(page,2000);
  expect(drafts).toHaveLength(1);expect(drafts[0]!.reference_error).toContain('Beberapa draft');
  await expect(openDraftForPublishing(page,drafts[0]!,1000)).rejects.toThrow('SOUNDON_SELECTED_DRAFT_AMBIGUOUS');
 }finally{await page.close();}
});
test('a click-only row that never opens produces a per-release error, not a collection failure',async()=>{
 const page=await browser.newPage();try{
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:'<table><tbody><tr><td>No Navigation</td><td>Artist</td></tr></tbody></table>'}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  const drafts=await collectSoundOnDrafts(page,2000);expect(drafts).toHaveLength(1);
  await expect(openDraftForPublishing(page,drafts[0]!,500)).rejects.toThrow('SOUNDON_DRAFT_OPEN_FAILED');
 }finally{await page.close();}
});

test('collects more than twenty drafts using unlabelled pagination icons and finds the selected later page',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:route.request().url().includes('/publish/')
   ? '<h1>Track 47</h1><button>Next</button>'
   : `<table><thead><tr><th>Title</th><th>Artist</th></tr></thead><tbody></tbody></table><div class="custom-pagination"><button id="previous"><svg></svg></button><span id="counter"></span><button id="forward"><svg></svg></button></div>
   <script>
   let current=0;
   function render(){
    const body=document.querySelector('tbody');body.innerHTML='';
    for(let i=0;i<Math.min(20,53-current*20);i++){
     const n=current*20+i+1;const row=document.createElement('tr');
     row.innerHTML='<td><span></span></td><td>Artist</td>';
     const title=row.querySelector('span');title.textContent='Track '+n;
     title.onclick=()=>location.href='/library/publish/single?id='+n;
     body.append(row);
    }
    document.querySelector('#forward').disabled=current===2;
    document.querySelector('#previous').disabled=current===0;
    document.querySelector('#counter').textContent='Page '+(current+1);
   }
   document.querySelector('#forward').onclick=()=>{current++;render()};
   document.querySelector('#previous').onclick=()=>{current--;render()};render();
   </script>`}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  const drafts=await collectSoundOnDrafts(page,1500);expect(drafts).toHaveLength(53);
  const selected=drafts.find(item=>item.title==='Track 47')!;expect(selected).toBeDefined();
  await page.goto('https://www.soundon.global/library/list?type=draft');
  expect(await openDraftForPublishing(page,selected,2000)).toBe('47');
 }finally{await page.close();}
},15000);
test('uses Next through three steps before Submit and ignores an earlier Upload button',async()=>{
 const page=await browser.newPage();try{
  await page.setContent(`<div id="wizard"></div><script>let step=0;window.submits=0;window.uploads=0;function render(){document.querySelector('#wizard').innerHTML=step<3 ? '<button id="upload">Upload</button><button id="advance">Next</button>' : '<button id="submit">Submit</button>';if(step<3){document.querySelector('#advance').onclick=()=>{step++;render()};document.querySelector('#upload').onclick=()=>window.uploads++}else document.querySelector('#submit').onclick=()=>{window.submits++;document.querySelector('#wizard').innerHTML='Under Review'}}render()</script>`);
  await submitSoundOnDraft(page,1500);
  expect(await page.evaluate(()=>({submits:(window as any).submits,uploads:(window as any).uploads}))).toEqual({submits:1,uploads:0});
 }finally{await page.close();}
},10000);

test('uses numbered pagination when no Next label or arrow is available',async()=>{
 const page=await browser.newPage();try{
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:`<table><tbody><tr data-id="1"><td>First</td><td>Artist</td></tr></tbody></table><div class="pagination"><button>1</button><button onclick="document.querySelector('tr').dataset.id='2';document.querySelector('td').textContent='Second';this.disabled=true">2</button></div>`}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  expect((await collectSoundOnDrafts(page,2000)).map(item=>item.title)).toEqual(['First','Second']);
 }finally{await page.close();}
});
test('does not silently claim all drafts when the visible total exceeds collected rows',async()=>{
 const page=await browser.newPage();try{
  await page.setContent('<table><tbody><tr data-id="1"><td>Track</td><td>Artist</td></tr></tbody></table><div class="pagination">Total 53</div>');
  await expect(collectSoundOnDrafts(page,1000)).rejects.toThrow('SOUNDON_DRAFT_LIST_INCOMPLETE');
 }finally{await page.close();}
});

test('continues an intermediate Next confirmation before reaching Submit',async()=>{
 const page=await browser.newPage();try{
  await page.setContent(`<div id="wizard"><button id="next">Next</button></div><div role="dialog" style="display:none"><button id="continue">Continue</button></div><script>document.querySelector('#next').onclick=()=>document.querySelector('[role=dialog]').style.display='block';document.querySelector('#continue').onclick=()=>{document.querySelector('[role=dialog]').style.display='none';document.querySelector('#wizard').innerHTML='<button id="submit">Submit</button>';document.querySelector('#submit').onclick=()=>{window.submitted=true;document.querySelector('#wizard').innerHTML='Submitted'}}</script>`);
  await submitSoundOnDraft(page,1500);expect(await page.evaluate(()=>(window as any).submitted)).toBe(true);
 }finally{await page.close();}
});
test('stops on required-field validation instead of submitting incomplete metadata',async()=>{
 const page=await browser.newPage();try{
  await page.setContent(`<button onclick="document.querySelector('.semi-form-field-error-message').textContent='Release date is required'">Next</button><div class="semi-form-field-error-message"></div>`);
  await expect(submitSoundOnDraft(page,1500)).rejects.toThrow('SOUNDON_PUBLISH_NEXT_FAILED: Tahap 1 (Next). Pesan SoundOn: Release date is required');
 }finally{await page.close();}
});

test('reports the disabled Submit reason and the invalid field label',async()=>{
 const page=await browser.newPage();try{
  await page.setContent('<label for="date">Release date</label><input id="date" required><button disabled>Submit</button>');
  await expect(submitSoundOnDraft(page,500)).rejects.toThrow(/SOUNDON_PUBLISH_SUBMIT_FAILED.*Release date.*Submit \(nonaktif\)/);
 }finally{await page.close();}
});
test('reports SoundOn rejection after Submit with the failing phase',async()=>{
 const page=await browser.newPage();try{
  await page.setContent(`<button onclick="document.querySelector('[role=alert]').textContent='Audio file is still processing'">Submit</button><div role="alert"></div>`);
  await expect(submitSoundOnDraft(page,1500)).rejects.toThrow('SOUNDON_PUBLISH_SUBMIT_FAILED: Tahap 1 (Submit). Pesan SoundOn: Audio file is still processing');
 }finally{await page.close();}
});

test('a successful Submit notification is not treated as a validation error',async()=>{
 const page=await browser.newPage();try{
  await page.setContent(`<button onclick="document.querySelector('[role=alert]').textContent='Release submitted successfully';this.remove()">Submit</button><div role="alert" class="success"></div>`);
  await submitSoundOnDraft(page,1500);
 }finally{await page.close();}
});

test('reads total draft badge and publishes real progress across unlabelled span SVG pagination',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:`<div role="tab" aria-selected="true">Drafts (43)</div><table><tbody></tbody></table><div><span id="back"><svg></svg></span><span id="page">1</span><span id="next"><svg></svg></span></div><script>let p=0;function render(){document.querySelector('tbody').innerHTML=Array.from({length:Math.min(20,43-p*20)},(_,i)=>'<tr data-id="'+(p*20+i+1)+'"><td>Track '+(p*20+i+1)+'</td><td>Artist</td></tr>').join('');document.querySelector('#page').textContent=p+1;document.querySelector('#next').setAttribute('aria-disabled',p===2?'true':'false')}document.querySelector('#next').onclick=()=>{p++;render()};render()</script>`}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  const progress:any[]=[];const drafts=await collectSoundOnDrafts(page,2000,state=>progress.push({...state}));
  expect(drafts).toHaveLength(43);expect(progress.map(s=>s.collected)).toEqual([0,20,40,43]);
  expect(progress.every(s=>s.total===43)).toBe(true);expect(progress.at(-1).page).toBe(3);
 }finally{await page.close();}
},15000);
test('never reports twenty rows as complete when pagination and total remain unknown',async()=>{
 const page=await browser.newPage();try{
  await page.setContent('<table><tbody>'+Array.from({length:20},(_,i)=>'<tr data-id="'+i+'"><td>Track '+i+'</td><td>Artist</td></tr>').join('')+'</tbody></table>');
  await expect(collectSoundOnDrafts(page,1000)).rejects.toThrow('SOUNDON_DRAFT_PAGINATION_UNCONFIRMED');
 }finally{await page.close();}
});

test('load more accumulates JS-only rows without marking existing rows as ambiguous',async()=>{
 const page=await browser.newPage();try{
  await page.setContent(`<div role="tab" aria-selected="true">Drafts (25)</div><table><tbody></tbody></table><button id="more">Load More</button><script>function add(start,end){for(let n=start;n<=end;n++){const row=document.createElement('tr');row.innerHTML='<td>Track '+n+'</td><td>Artist</td>';document.querySelector('tbody').append(row)}}add(1,20);document.querySelector('#more').onclick=function(){add(21,25);this.disabled=true}</script>`);
  const drafts=await collectSoundOnDrafts(page,2000);
  expect(drafts).toHaveLength(25);expect(drafts.every(item=>!item.reference_error)).toBe(true);
 }finally{await page.close();}
});

test('waits through an empty loading table and temporarily disabled pagination before reading the next page',async()=>{
 const page=await browser.newPage();try{
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:`<div role="tab" aria-selected="true">Drafts (185)</div><table><tbody></tbody></table><div class="pagination"><button id="next">Next</button></div><script>let p=0;function render(){document.querySelector('tbody').innerHTML=Array.from({length:Math.min(20,185-p*20)},(_,i)=>'<tr data-id="'+(p*20+i+1)+'"><td>Track '+(p*20+i+1)+'</td><td>Artist</td></tr>').join('');document.querySelector('#next').disabled=p===9}document.querySelector('#next').onclick=function(){this.disabled=true;document.querySelector('tbody').innerHTML='<tr><td colspan="2">Loading...</td></tr>';setTimeout(()=>{p++;render()},300)};render()</script>`}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  const progress:any[]=[];
  const drafts=await collectSoundOnDrafts(page,2000,state=>progress.push({...state}));
  expect(drafts).toHaveLength(185);expect(progress.at(-1).collected).toBe(185);expect(progress.at(-1).page).toBe(10);
 }finally{await page.close();}
},20000);

test('opens a stored draft edit URL directly and runs Next through to Submit without a list table',async()=>{
 const page=await browser.newPage();try{
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:route.request().url().includes('/publish/single')
   ? `<h1>Bossa Rock Walk</h1><div id="wizard"><button id="next">Next</button></div><script>let step=0;document.querySelector('#next').onclick=function(){step++;if(step===3){this.textContent='Submit';this.id='submit';this.onclick=()=>{window.didSubmit=true;this.remove()}}}</script>`
   : '<h1>Library navigation unavailable</h1>'}));
  await page.goto('https://www.soundon.global/library/list');
  expect(await openDraftForPublishing(page,{draft_id:'7705256571500087348',draft_url:'https://www.soundon.global/library/publish/single?source=draft&id=7705256571500087348',title:'Bossa Rock Walk',artist:'Sanz Melodies'},1000)).toBe('7705256571500087348');
  await submitSoundOnDraft(page,1500);expect(await page.evaluate(()=>(window as any).didSubmit)).toBe(true);
 }finally{await page.close();}
},10000);
test('accepts the selected draft already open at Review without waiting for table rows',async()=>{
 const page=await browser.newPage();try{
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:'<h1>Review</h1><div>Bossa Rock Walk</div><button>Submit</button>'}));
  await page.goto('https://www.soundon.global/library/publish/single?source=draft&id=77');
  expect(await openDraftForPublishing(page,{draft_id:'77',draft_url:page.url(),title:'Bossa Rock Walk',artist:'Sanz Melodies'},500)).toBe('77');
 }finally{await page.close();}
});
test('ignores a hidden first table when the active Draft table is visible',async()=>{
 const page=await browser.newPage();try{
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:route.request().url().includes('/publish/') ? '<h1>Review</h1><button>Submit</button>' : '<table style="display:none"><tbody><tr><td>Hidden</td><td>Hidden</td></tr></tbody></table><table><tbody><tr><td><a href="/library/publish/single?source=draft&id=11">Selected Track</a></td><td>Artist</td></tr></tbody></table>'}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  const drafts=await collectSoundOnDrafts(page,700);expect(drafts).toHaveLength(1);
  expect(await openDraftForPublishing(page,{...drafts[0]!,draft_url:'https://www.soundon.global/library/list?type=draft'},700)).toBe('11');
 }finally{await page.close();}
});

test('does not submit a saved editor URL belonging to a different draft ID',async()=>{
 const page=await browser.newPage();try{
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:'<button>Submit</button>'}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  await expect(openDraftForPublishing(page,{draft_id:'11',draft_url:'https://www.soundon.global/library/publish/single?source=draft&id=99',title:'Track',artist:'Artist'},500)).rejects.toThrow('SOUNDON_SELECTED_DRAFT_MISMATCH');
  expect(page.url()).toContain('/library/list');
 }finally{await page.close();}
});


test('CMS-first searches a non-table React list and opens exact artist match before Next and Submit',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:route.request().url().includes('/publish/')
   ? `<button onclick="this.textContent='Submit';this.onclick=()=>{window.submits=(window.submits||0)+1;this.textContent='Submitted';this.disabled=true}">Next</button>`
   : `<button id="draft">Drafts (185)</button><input hidden placeholder="Search releases"><div id="results"></div><script>document.querySelector('#draft').onclick=()=>document.querySelector('input').hidden=false;document.querySelector('input').onkeydown=event=>{if(event.key==='Enter'){document.querySelector('#results').innerHTML='<div class="card"><span id="title">My Track</span><span>Correct Artist</span></div>';document.querySelector('#title').onclick=()=>location.href='/library/publish/single?source=draft&id=77'}}</script>`}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  expect(await openMatchingSoundOnDraft(page,'My Track','Correct Artist',1500)).toBe('77');
  await submitSoundOnDraft(page,1000);
  expect(await page.evaluate(()=>(window as any).submits)).toBe(1);
 }finally{await page.close();}
});
test('repeated titles require artist matching within the same row',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:`<table><tbody><tr><td><span>My Track</span></td><td>Wrong Artist</td></tr><tr><td>My Track</td><td>Another Wrong Artist</td></tr><tr><td>Other Track</td><td>Correct Artist</td></tr></tbody></table>`}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  await expect(openMatchingSoundOnDraft(page,'My Track','Correct Artist',100)).rejects.toThrow('SOUNDON_SELECTED_DRAFT_NOT_FOUND');
 }finally{await page.close();}
});
test('CMS-first refuses duplicate title and artist instead of submitting first result',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:`<div class="card"><span>My Track</span><span>Artist</span></div><div class="card"><span>My Track</span><span>Artist</span></div>`}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  await expect(openMatchingSoundOnDraft(page,'My Track','Artist',100)).rejects.toThrow('SOUNDON_SELECTED_DRAFT_AMBIGUOUS');
 }finally{await page.close();}
});


test('publish confirmation refuses duplicate exact title and artist library rows',async()=>{
 const page=await browser.newPage();try {
  await page.setContent('<table><tbody><tr><td>Track</td><td>Artist</td><td>Under Review</td></tr><tr><td>Track</td><td>Artist</td><td>Draft</td></tr></tbody></table>');
  await expect(findReleaseStatuses(page,[{key:'selected',title:'Track',artist:'Artist'}],true,true,true)).rejects.toThrow('SOUNDON_SELECTED_DRAFT_AMBIGUOUS');
 }finally{await page.close();}
});


test('screenshot regression: unnamed search box, clipped artist button with full title and icon edit action',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:route.request().url().includes('/publish/')
   ? '<button>Next</button>'
   : `<div>Drafts <span>178</span></div><input type="text" onkeydown="if(event.key==='Enter')document.querySelector('table').hidden=false"><table hidden><tbody><tr><td><span>Our Love Among the Flowers</span></td><td><button title="Caleb Thomas Parker">Caleb Thomas...</button></td><td>1</td><td><button onclick="location.href='/library/publish/single?source=draft&id=77'"><svg data-icon="edit"></svg></button><button>Delete</button></td></tr></tbody></table>`}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  expect(await openMatchingSoundOnDraft(page,'Our Love Among the Flowers','Caleb Thomas Parker',1000)).toBe('77');
 }finally{await page.close();}
});
test('clipped artist without tooltip opens candidate but requires exact artist evidence in editor before Next',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:route.request().url().includes('/publish/')
   ? '<div class="so-artist-preview-item-artist-name">Caleb Thomas Parker</div><button onclick="window.nextClicked=true">Next</button>'
   : `<table><tbody><tr><td><a href="/library/publish/single?source=draft&id=77">Our Love Among the Flowers</a></td><td><button>Caleb Thomas…</button></td></tr><tr><td>Our Love Among the Flowers</td><td>Different Artist</td></tr></tbody></table>`}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  expect(await openMatchingSoundOnDraft(page,'Our Love Among the Flowers','Caleb Thomas Parker',250)).toBe('77');
  expect(await page.evaluate(()=>(window as any).nextClicked)).toBeUndefined();
 }finally{await page.close();}
});
test('same clipped prefix for another artist never reaches Next or Submit',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:route.request().url().includes('/publish/')
   ? '<div class="so-artist-preview-item-artist-name">Caleb Thomas Other</div><button onclick="window.nextClicked=true">Next</button>'
   : `<table><tbody><tr><td><a href="/library/publish/single?source=draft&id=77">Our Love Among the Flowers</a></td><td><button>Caleb Thomas...</button></td></tr><tr><td>Our Love Among the Flowers</td><td>Different Artist</td></tr></tbody></table>`}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  await expect(openMatchingSoundOnDraft(page,'Our Love Among the Flowers','Caleb Thomas Parker',250)).rejects.toThrow('SOUNDON_DRAFT_ARTIST_NOT_VERIFIED');
  expect(await page.evaluate(()=>(window as any).nextClicked)).toBeUndefined();
 }finally{await page.close();}
});
test('clipped artist resolves by hovered tooltip without guessing a name',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:route.request().url().includes('/publish/')
   ? '<button>Submit</button>'
   : `<table><tbody><tr><td><a href="/library/publish/single?source=draft&id=77">Our Love Among the Flowers</a></td><td><button onmouseover="document.querySelector('[role=tooltip]').hidden=false">Caleb Thomas...</button></td></tr><tr><td>Our Love Among the Flowers</td><td>Different Artist</td></tr></tbody></table><div role="tooltip" hidden>Caleb Thomas Parker</div>`}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  expect(await openMatchingSoundOnDraft(page,'Our Love Among the Flowers','Caleb Thomas Parker',500)).toBe('77');
 }finally{await page.close();}
});
test('post-submit confirmation uses resolved draft ID when library artist is shortened',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://www.soundon.global/**',route=>route.fulfill({body:'<html></html>',contentType:'text/html; charset=utf-8'}));
  await page.goto('https://www.soundon.global/library/list');
  await page.setContent('<table><tbody><tr><td><a href="/library/release/77">Track</a></td><td>Caleb Thomas...</td><td>Under Review</td></tr><tr><td><a href="/library/release/88">Other</a></td><td>Live</td></tr></tbody></table>');
  expect((await publishedStatusByDraftId(page,'77'))?.status).toBe('under_review');
  expect(await publishedStatusByDraftId(page,'99')).toBeNull();
 }finally{await page.close();}
});


test('unique exact title opens even when artist text is shortened or differs from CMS',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:route.request().url().includes('/publish/') ? '<button>Submit</button>'
   : '<table><tbody><tr><td><a href="/library/publish/single?source=draft&id=77">Unique Title</a></td><td>Other credit...</td></tr></tbody></table>'}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  expect(await openMatchingSoundOnDraft(page,'Unique Title','CMS Artist',300)).toBe('77');
 }finally{await page.close();}
});
test('repeated titles choose the exact artist from the matching row',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:route.request().url().includes('/publish/') ? '<button>Next</button>'
   : '<table><tbody><tr><td><a href="/library/publish/single?source=draft&id=88">Shared Title</a></td><td>Other Artist</td></tr><tr><td><a href="/library/publish/single?source=draft&id=77">Shared Title</a></td><td>Chosen Artist</td></tr></tbody></table>'}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  expect(await openMatchingSoundOnDraft(page,'Shared Title','Chosen Artist',300)).toBe('77');
 }finally{await page.close();}
});


test('publish retry confirms unique title with shortened artist without submitting again',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:'<table><tbody><tr><td><a href="/library/release/77">Our Love Among the Flowers</a></td><td><button>Caleb Thomas...</button></td><td>Under Review</td></tr></tbody></table>'}));
  await page.goto('https://www.soundon.global/library/list');
  const result=await findReleaseStatuses(page,[{key:'selected',title:'Our Love Among the Flowers',artist:'Caleb Thomas Parker'}],true,true,true);
  expect(result.selected?.status).toBe('under_review');
  expect(result.selected?.release_url).toContain('/release/77');
 }finally{await page.close();}
});


test('visible title still matches when hidden tooltip descendants pollute textContent',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:route.request().url().includes('/publish/') ? '<button>Next</button>'
   : '<table><tbody><tr><td><a href="/library/publish/single?source=draft&id=77"><strong>Our Love Among the Flowers<span style="display:none">Tooltip extra content</span></strong><span style="display:none">Our Love Among the Flowers</span></a></td><td>Caleb Thomas...</td></tr></tbody></table>'}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  expect(await openMatchingSoundOnDraft(page,'Our Love Among the Flowers','Caleb Thomas Parker',400)).toBe('77');
 }finally{await page.close();}
});
test('waits for delayed Draft UI and uses keyboard events for keyup-driven search',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:route.request().url().includes('/publish/') ? '<button>Submit</button>'
   : `<div id="app">Loading...</div><script>setTimeout(()=>{document.querySelector('#app').innerHTML='<button>Drafts 178</button><input type="text"><table><tbody><tr><td>Other release</td></tr></tbody></table>';document.querySelector('input').onkeyup=function(){if(this.value.toLowerCase()==='bossa rock walk')document.querySelector('tbody').innerHTML='<tr><td><a href="/library/publish/single?source=draft&id=77">Bossa Rock Walk</a></td><td>Sanz...</td></tr>'}},350)</script>`}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  expect(await openMatchingSoundOnDraft(page,'Bossa Rock Walk','Sanz Melodies',2000)).toBe('77');
 }finally{await page.close();}
});
test('late login redirect is reported as expired session rather than missing draft',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:route.request().url().includes('/login') ? '<h1>Log in</h1><input type="password">' : '<div>Loading...</div><script>setTimeout(()=>location.href="/login",150)</script>'}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  await expect(openMatchingSoundOnDraft(page,'Bossa Rock Walk','Sanz Melodies',1000)).rejects.toThrow('AUTH_SESSION_EXPIRED');
 }finally{await page.close();}
});
test('missing draft reason contains visible search results and the actual page',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:'<table><tbody><tr><td>Different Release</td><td>Different Artist</td></tr></tbody></table>'}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  await expect(openMatchingSoundOnDraft(page,'Bossa Rock Walk','Sanz Melodies',150)).rejects.toThrow('Different Release');
 }finally{await page.close();}
});


test('reports each actual Next and Submit milestone without claiming full CMS completion',async()=>{
 const page=await browser.newPage();try {
  const phases:Array<{percent:number;message:string}>=[];
  await page.setContent(`<button onclick="window.steps=(window.steps||0)+1;if(window.steps===2){this.textContent='Submit';this.onclick=()=>this.remove()}">Next</button>`);
  await submitSoundOnDraft(page,3000,(percent,message)=>phases.push({percent,message}));
  expect(phases.map(value=>value.message)).toEqual(['Next 1/3 — menunggu tahap berikutnya','Next 2/3 — menunggu tahap berikutnya','Submit — 2 kali Next selesai; menunggu penerimaan SoundOn']);
  expect(phases.every(value=>value.percent<100)).toBe(true);
 }finally{await page.close();}
});


test('waits for all three slow Next transitions, then delayed Submit acceptance without aborting it',async()=>{
 const page=await browser.newPage();let submits=0;let nexts=0;
 try{
  await page.route('https://www.soundon.global/**',async route=>{
   if(route.request().url().endsWith('/submit')){submits++;await new Promise(resolve=>setTimeout(resolve,1200));await route.fulfill({contentType:'application/json',body:'{"code":0}'});return;}
   await route.fulfill({contentType:'text/html; charset=utf-8',body:`<h1 id="phase">Track information</h1><div id="wizard"></div><script>let step=0;const phases=['Track information','Release Settings','More release options','Review'];window.clicks=0;function render(){document.querySelector('#phase').textContent=phases[step];document.querySelector('#wizard').innerHTML=step<3?'<button>Next</button>':'<button>Submit</button>';document.querySelector('button').onclick=step<3?()=>{window.clicks++;setTimeout(()=>{step++;render()},900)}:()=>fetch('/submit',{method:'POST'}).then(()=>{document.querySelector('#wizard').innerHTML='<div role="dialog"><h2>Release submitted successfully</h2><button>View releases</button></div>'})}render()</script>`});
  });
  await page.goto('https://www.soundon.global/library/publish/single?source=draft&id=77');
  const receipt=await submitSoundOnDraft(page,2500);
  nexts=await page.evaluate(()=>(window as any).clicks);
  expect(nexts).toBe(3);expect(submits).toBe(1);expect(receipt.uiConfirmed).toBe(true);expect(receipt.nextClicks).toBe(3);
 }finally{await page.close();}
},12000);
test('does not repeat Next when SoundOn stays on the same visible wizard step',async()=>{
 const page=await browser.newPage();try{
  await page.setContent('<h1>Track information</h1><button onclick="window.clicks=(window.clicks||0)+1">Next</button>');
  await expect(submitSoundOnDraft(page,250)).rejects.toThrow('Halaman belum berpindah setelah Next');
  expect(await page.evaluate(()=>(window as any).clicks)).toBe(1);
 }finally{await page.close();}
});
test('waits for a late confirmation dialog before waiting for acceptance',async()=>{
 const page=await browser.newPage();try{
  await page.setContent(`<button onclick="setTimeout(()=>document.querySelector('#modal').innerHTML='<div role=dialog><button id=confirm>Confirm</button></div>',250)">Submit</button><div id="modal"></div><script>document.addEventListener('click',event=>{if(event.target.id==='confirm'){document.querySelector('#modal').innerHTML='<div role=dialog><h2>Submission successful</h2><button>Done</button></div>'}})</script>`);
  const receipt=await submitSoundOnDraft(page,1500);expect(receipt.uiConfirmed).toBe(true);
 }finally{await page.close();}
});
test('reports a delayed API rejection from Submit instead of a generic library failure',async()=>{
 const page=await browser.newPage();try{
  await page.route('https://www.soundon.global/**',async route=>{
   if(route.request().url().endsWith('/submit')){await new Promise(resolve=>setTimeout(resolve,800));await route.fulfill({status:422,contentType:'application/json',body:'{"message":"Release date is too early"}'});return;}
   await route.fulfill({contentType:'text/html',body:`<button onclick="fetch('/submit',{method:'POST'})">Submit</button>`});
  });
  await page.goto('https://www.soundon.global/library/publish/single?id=77');
  await expect(submitSoundOnDraft(page,2000)).rejects.toThrow('Release date is too early');
 }finally{await page.close();}
});
test('explicitly switches from remembered Draft tab to All releases for confirmation',async()=>{
 const page=await browser.newPage();try{
  await page.setContent(`<h1>All releases</h1><button role="tab" class="inactive" aria-selected="false" onclick="this.setAttribute('aria-selected','true');document.querySelector('td').textContent='Under Review'">All releases</button><button role="tab" aria-selected="true">Drafts</button><table><tbody><tr><td>Draft</td></tr></tbody></table>`);
  await selectAllReleasesForConfirmation(page);
  expect(await page.locator('td').innerText()).toBe('Under Review');
 }finally{await page.close();}
});


test('reports business rejection when Submit endpoint returns HTTP 200 with a failure code',async()=>{
 const page=await browser.newPage();try{
  await page.route('https://www.soundon.global/**',async route=>{
   if(route.request().url().endsWith('/submit')){await route.fulfill({contentType:'application/json',body:'{"status_code":4001,"status_msg":"Rights confirmation is required"}'});return;}
   await route.fulfill({contentType:'text/html',body:`<button onclick="fetch('/submit',{method:'POST'})">Submit</button>`});
  });
  await page.goto('https://www.soundon.global/library/publish/single?id=77');
  await expect(submitSoundOnDraft(page,1000)).rejects.toThrow('Rights confirmation is required');
 }finally{await page.close();}
});


test('confirmation polling reads loaded status without resetting the search request',async()=>{
 const page=await browser.newPage();try{
  await page.setContent('<input type="search" value="Track" oninput="window.searches=(window.searches||0)+1"><table><tbody><tr><td>Track</td><td>Artist...</td><td id="status">Draft</td></tr></tbody></table>');
  expect(await readVisiblePublishedStatus(page,'Track','Artist Full')).toBeNull();
  await page.locator('#status').evaluate(node=>node.textContent='Under Review');
  expect((await readVisiblePublishedStatus(page,'Track','Artist Full'))?.status).toBe('under_review');
  expect(await page.locator('input').inputValue()).toBe('Track');
  expect(await page.evaluate(()=>(window as any).searches)).toBeUndefined();
 }finally{await page.close();}
});


test('falls back to unfiltered Draft pagination when title search omits an existing song',async()=>{
 const page=await browser.newPage();try {
  let submissions=0;
  await page.route('https://www.soundon.global/**',route=>{
   if(route.request().url().endsWith('/submit')) return route.fulfill({body:'OK'});
   if(route.request().url().includes('/publish/')) return route.fulfill({contentType:'text/html',body:`<h1 id="phase">Track information</h1><div id="wizard"></div><script>
    let step=0;window.nextClicks=0;
    function render(){
     phase.textContent=step===3?'Review':'Step '+step;
     wizard.innerHTML=step<3?'<button>Next</button>':'<button>Submit</button>';
     document.querySelector('button').onclick=step<3?()=>{window.nextClicks++;step++;render()}:()=>fetch('/submit',{method:'POST'}).then(()=>wizard.innerHTML='<div role="dialog">Release submitted successfully</div>');
    }render();
   </script>`});
   return route.fulfill({contentType:'text/html',body:`
    <button role="tab" aria-selected="true">Drafts</button><input placeholder="Search releases">
    <table><tbody id="rows"><tr><td>Other draft</td><td>Artist</td></tr></tbody></table>
    <button aria-label="Next page" id="next">Next</button>
    <script>
     const input=document.querySelector('input');
     input.onkeyup=()=>{
      if(input.value) {rows.innerHTML='';next.disabled=true;}
      else {rows.innerHTML='<tr><td>Other draft</td><td>Artist</td></tr>';next.disabled=false;}
     };
     next.onclick=()=>{rows.innerHTML='<tr><td><a href="/library/publish/single?source=draft&id=991">Bossa Rock Walk</a></td><td>Sanz Melodies</td></tr>';next.disabled=true;};
    </script>`});
  });
  page.on('request',request=>{if(request.method()==='POST')submissions++;});
  await page.goto('https://www.soundon.global/library/list?type=draft');
  expect(await openMatchingSoundOnDraft(page,'Bossa Rock Walk','Sanz Melodies',1000)).toBe('991');
  expect(submissions).toBe(0);
  const receipt=await submitSoundOnDraft(page,2000);
  expect(receipt.uiConfirmed).toBe(true);
  expect(receipt.nextClicks).toBe(3);
  expect(submissions).toBe(1);
 }finally{await page.close();}
},15000);

test('resolves a visually clipped title from its full accessible title',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://www.soundon.global/**',route=>route.fulfill({contentType:'text/html',body:route.request().url().includes('/publish/') ? '<button>Next</button>'
   : '<table><tbody><tr><td><a aria-label="Our Love Among the Flowers" href="/library/publish/single?source=draft&id=992">Our Love Among...</a></td><td>Caleb Thomas Parker</td></tr></tbody></table>'}));
  await page.goto('https://www.soundon.global/library/list?type=draft');
  expect(await openMatchingSoundOnDraft(page,'Our Love Among the Flowers','Caleb Thomas Parker',500)).toBe('992');
 }finally{await page.close();}
});
