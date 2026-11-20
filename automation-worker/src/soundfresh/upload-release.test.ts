import {beforeAll,afterAll,test,expect} from 'vitest';
import {chromium,type Browser} from 'playwright';
import {moveSoundfreshReleaseToUploading} from './upload-release.js';
let browser: Browser; beforeAll(async()=>{browser=await chromium.launch({headless:true});});afterAll(async()=>{await browser.close();});
for(const persist of [true,false]) test(`CMS Upload Release must persist: ${persist}`,async()=>{
 const page=await browser.newPage();let saved=false;let clicks=0;
 try {
  await page.route('https://cms-upload.test/**',route=>route.fulfill({contentType:'text/html',body:`<nav>Uploading</nav><span id="state">${saved?'Upload DSP':'Under Review'}</span><button onclick="fetch('/save',{method:'POST'}).then(()=>{document.querySelector('#state').textContent='Upload DSP';this.remove()})">✓ Upload Release</button>`}));
  await page.route('https://cms-upload.test/save',route=>{saved=persist;clicks++;return route.fulfill({body:'OK'});});
  await page.goto('https://cms-upload.test/admin/releases/1');
  if(persist){await moveSoundfreshReleaseToUploading(page,1500);await moveSoundfreshReleaseToUploading(page,1500);expect(clicks).toBe(1);}
  else await expect(moveSoundfreshReleaseToUploading(page,1500)).rejects.toThrow('SOUNDFRESH_UPLOAD_NOT_PERSISTED');
 }finally{await page.close();}
},10000);

for(const plain of [false,true]) test(`waits for delayed Upload Release confirmation (${plain?'plain heading panel':'dialog'})`,async()=>{
 const page=await browser.newPage();let saved=false;let submissions=0;
 try {
  await page.route('https://cms-upload.test/**',route=>{
   if(route.request().url().endsWith('/save')) {saved=true;submissions++;return route.fulfill({body:'OK'});}
   return route.fulfill({contentType:'text/html',body:`<nav>Uploading</nav><span id="state">${saved?'Upload DSP':'Under Review'}</span>
    <a href="#" onclick="setTimeout(()=>document.querySelector('#panel').hidden=false,700);return false">Upload Release</a>
    <section id="panel" hidden ${plain?'':'role="dialog"'}><h2>Upload Release</h2><p>Confirm upload</p><button onclick="fetch('/save',{method:'POST'}).then(()=>{state.textContent='Upload DSP';document.querySelector('#panel').hidden=true})">&#10003; Upload Release</button></section>`});
  });
  await page.goto('https://cms-upload.test/admin/releases/1');
  await moveSoundfreshReleaseToUploading(page,1800);
  await moveSoundfreshReleaseToUploading(page,1800);
  expect(submissions).toBe(1);
 }finally{await page.close();}
},10000);

test('confirms server persistence when Upload Release does not update the detail badge until reload',async()=>{
 const page=await browser.newPage();let saved=false;let submissions=0;
 try {
  await page.route('https://cms-upload.test/**',route=>{
   if(route.request().url().endsWith('/save')) {saved=true;submissions++;return route.fulfill({body:'OK'});}
   return route.fulfill({contentType:'text/html',body:`<span>${saved?'Uploading':'Under Review'}</span><button onclick="fetch('/save',{method:'POST'}).then(()=>this.remove())">Upload Release</button>`});
  });
  await page.goto('https://cms-upload.test/admin/releases/1');
  await moveSoundfreshReleaseToUploading(page,700);
  expect(submissions).toBe(1);
 }finally{await page.close();}
});

test('reports the CMS validation error instead of an unconfirmed upload',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://cms-upload.test/**',route=>route.fulfill({contentType:'text/html',body:`<span>Under Review</span><div class="alert-danger" hidden>Aggregator is required</div><button onclick="document.querySelector('.alert-danger').hidden=false">Upload Release</button>`}));
  await page.goto('https://cms-upload.test/admin/releases/1');
  await expect(moveSoundfreshReleaseToUploading(page,600)).rejects.toThrow('SOUNDFRESH_UPLOAD_RELEASE_REJECTED:Aggregator is required');
 }finally{await page.close();}
});

test('a closed confirmation dialog without a saved status cannot count as success',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://cms-upload.test/**',route=>route.fulfill({contentType:'text/html',body:`<span>Under Review</span><button onclick="this.remove()">Upload Release</button>`}));
  await page.goto('https://cms-upload.test/admin/releases/1');
  await expect(moveSoundfreshReleaseToUploading(page,300)).rejects.toThrow('SOUNDFRESH_UPLOAD_NOT_CONFIRMED');
 }finally{await page.close();}
});

test('detects a login redirect during Upload Release',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://cms-upload.test/**',route=>route.fulfill({contentType:'text/html',body:route.request().url().endsWith('/login') ? '<input type="password">' : '<button onclick="location.href=\'/login\'">Upload Release</button>'}));
  await page.goto('https://cms-upload.test/admin/releases/1');
  await expect(moveSoundfreshReleaseToUploading(page,800)).rejects.toThrow('AUTH_SESSION_EXPIRED');
 }finally{await page.close();}
});

test('live CMS regression: Publish Release modal, nested Bootstrap panels and delayed enabled Upload Release submit',async()=>{
 const page=await browser.newPage();let saved=false;let submissions=0;
 try {
  await page.route('https://cms-upload.test/**',route=>{
   if(route.request().url().endsWith('/save')) {saved=true;submissions++;return route.fulfill({body:'OK'});}
   return route.fulfill({contentType:'text/html',body:`<h2>The Door That Never Closes</h2><p>Jason Edward Coleman <span>${saved?'Upload DSP':'Under Review'}</span></p>
    <button onclick="setTimeout(()=>{document.querySelector('#uploading-modal').hidden=false;setTimeout(()=>document.querySelector('#submit-upload').disabled=false,150)},550)">Upload Release</button>
    <div class="modal fade show" id="uploading-modal" role="dialog" hidden>
     <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <div class="modal-header border-bottom-0 py-2"><h5 class="modal-title">Publish Release</h5><a>close</a></div>
      <p>Set release status to uploading?</p><div class="modal-footer"><button type="button">Cancel</button>
      <button id="submit-upload" type="submit" disabled onclick="fetch('/save',{method:'POST'}).then(()=>location.reload())">Upload Release</button></div>
     </div></div>
    </div>`});
  });
  await page.goto('https://cms-upload.test/admin/releases/3949');
  await moveSoundfreshReleaseToUploading(page,2000);
  await moveSoundfreshReleaseToUploading(page,2000);
  expect(submissions).toBe(1);
  expect(await page.getByText('Upload DSP',{exact:true}).isVisible()).toBe(true);
 }finally{await page.close();}
},10000);
