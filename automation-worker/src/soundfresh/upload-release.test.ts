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
