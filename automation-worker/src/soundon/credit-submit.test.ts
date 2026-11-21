import {beforeAll,afterAll,test,expect} from 'vitest';
import {chromium,type Browser} from 'playwright';
import {isPlainWriterNameForm,submitEntityAndVerify} from './draft-driver.js';
let browser:Browser;beforeAll(async()=>{browser=await chromium.launch({headless:true});});afterAll(async()=>{await browser.close();});

test('search text in SoundOn profile picker is not a committed plain writer name',async()=>{
 const page=await browser.newPage();try {
  await page.setContent('<dialog open><h4>Add songwriter</h4><div role="combobox"><input type="text" value="Natasha Khairani"></div><button>Submit</button></dialog>');
  expect(await isPlainWriterNameForm(page.locator('dialog'),'Songwriters')).toBe(false);
  await page.setContent('<dialog open><h4>Add songwriter</h4><input type="text" value="Natasha Khairani"><button>Submit</button></dialog>');
  expect(await isPlainWriterNameForm(page.locator('dialog'),'Songwriters')).toBe(true);
  expect(await isPlainWriterNameForm(page.locator('dialog'),'Primary artists')).toBe(false);
 }finally{await page.close();}
});

for(const outcome of ['slow','http-error','business-error']) test(`writer Submit tracks actual API outcome: ${outcome}`,async()=>{
 const page=await browser.newPage();try {
  await page.route('https://soundon-credit.test/**',async route=>{
   if(route.request().url().endsWith('/api/writer')) {
    await new Promise(resolve=>setTimeout(resolve,outcome==='slow'?900:100));
    return route.fulfill({status:outcome==='http-error'?503:200,contentType:'application/json',body:JSON.stringify({baseResp:{errorCode:outcome==='business-error'?42:0}})});
   }
   return route.fulfill({contentType:'text/html',body:`<section><span class="soundon-formik-field-label">Songwriters</span><span id="saved"></span></section>
    <dialog open><h4>Add songwriter</h4><label>Name</label><input type="text" value="Natasha Khairani" onblur="window.committed=this.value">
    <button onclick="fetch('/api/writer',{method:'POST'}).then(r=>r.json()).then(data=>{if(!data.baseResp.errorCode){setTimeout(()=>{document.querySelector('#saved').textContent=window.committed;this.closest('dialog').close()},100)}})">Submit</button></dialog>`});
  });
  await page.goto('https://soundon-credit.test/editor');
  const saving=submitEntityAndVerify(page,page.locator('dialog'),page.getByRole('button',{name:'Submit'}),'Songwriters','Natasha Khairani',500);
  if(outcome==='slow') {
   expect(await saving).toBe(true);
   expect(await page.locator('#saved').innerText()).toBe('Natasha Khairani');
  }else await expect(saving).rejects.toThrow(outcome==='http-error'?'HTTP 503':'errorCode=42');
 }finally{await page.close();}
},8000);
