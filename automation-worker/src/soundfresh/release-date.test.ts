import { beforeAll, afterAll, test, expect } from 'vitest';
import { chromium, type Browser } from 'playwright';
import { normalizeCmsReleaseDate, completeCmsReleaseDates, readPlannedReleaseDate } from './release-date.js';
let browser: Browser;
beforeAll(async()=>{browser=await chromium.launch({headless:true});});
afterAll(async()=>{await browser.close();});

test('normalizes dates without countdown text and rejects missing or impossible dates',()=>{
 expect(normalizeCmsReleaseDate('December 4, 2026 14 days left')).toBe('2026-12-04');
 expect(normalizeCmsReleaseDate('4 Desember 2026')).toBe('2026-12-04');
 expect(normalizeCmsReleaseDate('2026-12-04')).toBe('2026-12-04');
 expect(normalizeCmsReleaseDate('February 31, 2026')).toBeNull();
 expect(normalizeCmsReleaseDate('14 days left')).toBeNull();
 expect(normalizeCmsReleaseDate(null)).toBeNull();
});

test('reads the planned date from current CMS div layout while ignoring TikTok and original dates',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://cms-date.test/**',route=>route.fulfill({contentType:'text/html',body:`<main><div><div>Planned Release Date</div><div>December 4, 2026</div></div><div><div>TikTok &amp; YouTube Music Pre-Release Date</div><div>November 27, 2026</div></div><div><div>Original Release Date</div><div>January 1, 2020</div></div></main>`}));
  await page.goto('https://cms-date.test/admin/releases/3945');
  expect(await readPlannedReleaseDate(page)).toBe('2026-12-04');
 }finally{await page.close();}
});

test('fills missing list dates from details without downloading assets or revisiting dates already available',async()=>{
 const context=await browser.newContext();let requests=0;
 try {
  await context.route('https://cms-date.test/**',route=>{requests++;return route.fulfill({contentType:'text/html',body:'<table><tr><td>Planned Release Date</td><td>December 4, 2026</td></tr></table>'});});
  const items=[{detail_url:'https://cms-date.test/admin/releases/3945',release_date:null as string|null},{detail_url:'https://cms-date.test/admin/releases/3944',release_date:'2026-11-29'}];
  await completeCmsReleaseDates(context,items,'https://cms-date.test');
  expect(items.map(x=>x.release_date)).toEqual(['2026-12-04','2026-11-29']);
  expect(requests).toBe(1);
  expect(context.pages()).toHaveLength(0);
 }finally{await context.close();}
});

test('does not substitute pre-release date when the planned date is empty',async()=>{
 const page=await browser.newPage();try {
  await page.route('https://cms-date.test/**',route=>route.fulfill({contentType:'text/html',body:'<main><div><div>Planned Release Date</div><div>Not available</div></div><div>TikTok Pre-Release Date: November 27, 2026</div></main>'}));
  await page.goto('https://cms-date.test/admin/releases/3945');
  expect(await readPlannedReleaseDate(page)).toBeNull();
 }finally{await page.close();}
});
