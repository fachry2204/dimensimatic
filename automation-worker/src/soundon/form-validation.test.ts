import { describe, expect, it } from "vitest";
import { chromium } from "playwright";
import { assertNoValidationErrors, advanceWizardStep, setScopedTime, setSearchField, setTextField, selectAlbumTrack, waitForUpload, waitForEntityCard, skipBlockedEntity, assertNoSkippedFields, selectCreateArtistProfile } from "./draft-driver.js";

describe("SoundOn validation", () => {
    it("closes a blocked editor, allows the next field, but does not report complete metadata", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent('<input aria-label="Next field"><dialog open class="so-form-modal-dialog"><button onclick="this.closest(\'dialog\').close()">Cancel</button></dialog>');
            await skipBlockedEntity(page, 'Contributors', 'Test artist');
            await page.getByLabel('Next field').fill('continued');
            expect(await page.getByLabel('Next field').inputValue()).toBe('continued');
            expect(() => assertNoSkippedFields(page)).toThrow('SOUNDON_REQUIRED_FIELD_UNRESOLVED:');
            expect(() => assertNoSkippedFields(page)).toThrow('Contributors: Test artist');
        } finally { await browser.close(); }
    }, 10000);
    it("continues past an unresolved optional field without blocking a draft save", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent('<input aria-label="Next field"><dialog open class="so-form-modal-dialog"><button onclick="this.closest(\'dialog\').close()">Cancel</button></dialog>');
            await skipBlockedEntity(page, 'Featured artists', 'Optional Guest', true);
            await page.getByLabel('Next field').fill('continued');
            expect(await page.getByLabel('Next field').inputValue()).toBe('continued');
            expect(() => assertNoSkippedFields(page)).not.toThrow();
        } finally { await browser.close(); }
    }, 10000);
    it("does not count search results in an open artist dialog as a saved credit", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<section><div class="soundon-formik-field-label">Primary artists</div><button>Add</button><dialog open><span>Husni</span></dialog><div id="saved"></div></section>`);
            expect(await waitForEntityCard(page, 'Primary artists', 'Husni', 50)).toBe(false);
            await page.locator('#saved').evaluate(el => el.textContent = 'Husni');
            expect(await waitForEntityCard(page, 'Primary artists', 'Husni', 50)).toBe(true);
            expect(await waitForEntityCard(page, 'Primary artists', 'Hus', 50)).toBe(false);
        } finally { await browser.close(); }
    }, 10000);
    it("verifies a saved primary-artist card when SoundOn uses decomposed Unicode", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<section><div class="soundon-formik-field-label">Primary artists</div><div class="artist-wrapper"><div class="so-artist-preview-item"><span class="so-artist-preview-item-artist-name">Cafe\u0301</span></div></div></section>`);
            expect(await waitForEntityCard(page, "Primary artists", "Café", 100)).toBe(true);
        } finally { await browser.close(); }
    }, 10000);
    it("does not mistake an unsaved custom-modal preview or another field for a saved primary artist", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<section><div class="soundon-formik-field-label">Primary artists</div><button>Add</button></section>
                <div class="so-form-modal-dialog"><h4>Add primary artist</h4><div class="so-artist-preview-item-artist-name">Zenal Farell</div></div>`);
            expect(await waitForEntityCard(page, 'Primary artists', 'Zenal Farell', 50)).toBe(false);
            await page.setContent(`<section><div class="soundon-formik-field-label">Primary artists</div><button>Add</button></section>
                <section><div class="soundon-formik-field-label">Featured artists</div><div class="so-artist-preview-item-artist-name">Zenal Farell</div></section>`);
            expect(await waitForEntityCard(page, 'Primary artists', 'Zenal Farell', 50)).toBe(false);
        } finally { await browser.close(); }
    }, 15000);
    it("verifies each credit within its field, including deep card wrappers and an outer track dialog", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            for (const label of ['Primary artists', 'Featured artists', 'Songwriters', 'Lyricists', 'Contributors', 'Production and additional contributors']) {
                await page.setContent(`<dialog open><section><div><div><span class="soundon-formik-field-label">${label}</span><button>Add</button></div></div>
                    <div><div><div><span id="committed"></span></div></div></div></section>
                    <section><span class="soundon-formik-field-label">Record label</span><span>Zenal Farell</span></section></dialog>`);
                expect(await waitForEntityCard(page, label, 'Zenal Farell', 50)).toBe(false);
                await page.locator('#committed').evaluate(element => element.textContent = 'Zenal Farell');
                expect(await waitForEntityCard(page, label, 'Zenal Farell', 50)).toBe(true);
            }
        } finally { await browser.close(); }
    }, 15000);
    it("waits for SoundOn to commit an artist instead of closing its custom editor during Submit", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<main><section><span class="soundon-formik-field-label">Primary artists</span><span id="saved"></span>
                <button onclick="document.querySelector('.so-form-modal-dialog').hidden=false">Add</button></section></main>
                <div class="so-form-modal-dialog" hidden><h4>Add primary artist</h4><label>Artist name</label><input type="text" value="Zenal Farell">
                <div class="semi-select-option-list-outer-bottom-slot" onclick="document.querySelector('#preview').hidden=false;this.hidden=true">Add "Zenal Farell" as an artist</div>
                <div id="preview" hidden><span class="so-artist-preview-item-artist-name">Zenal Farell</span></div>
                <button onclick="this.disabled=true;window.saveTimer=setTimeout(()=>{document.querySelector('#saved').textContent='Zenal Farell';this.parentElement.hidden=true},900)">Submit</button>
                <button class="so-form-modal-title-close" onclick="window.cancelled=true;clearTimeout(window.saveTimer);this.parentElement.hidden=true">Cancel</button></div>`);
            await setSearchField(page, 'Primary artists', 'Zenal Farell');
            expect(await page.locator('#saved').innerText()).toBe('Zenal Farell');
            expect(await page.evaluate(() => (window as any).cancelled)).toBeUndefined();
        } finally { await browser.close(); }
    }, 20000);
    it("selects Add as an artist from a visible dropdown inside a zero-size portal", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<section><span class="soundon-formik-field-label">Primary artists</span><span id="saved"></span><button onclick="document.querySelector('.so-form-modal-dialog').hidden=false">Add</button></section>
                <div class="so-form-modal-dialog" hidden><h4>Add primary artist</h4><label>Artist name</label><div class="semi-select-content-wrapper"><input type="text" oninput="document.querySelector('#new-artist').hidden=false"></div>
                <button disabled id="submit" onclick="document.querySelector('#saved').textContent='Zenal Farell';this.parentElement.hidden=true">Submit</button></div>
                <div class="semi-portal" style="display:contents"><div id="new-artist" hidden onclick="document.querySelector('#submit').disabled=false;this.hidden=true">Add "Zenal Farell" as an artist</div></div>`);
            expect(await page.locator('.semi-portal').isVisible()).toBe(false);
            await setSearchField(page, 'Primary artists', 'Zenal Farell');
            expect(await page.locator('#saved').innerText()).toBe('Zenal Farell');
        } finally { await browser.close(); }
    }, 30000);
    it("preserves blank external profile IDs when Artist name becomes static text after Add", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<section><span class="soundon-formik-field-label">Primary artists</span><span id="saved"></span><button onclick="document.querySelector('dialog').showModal()">Add</button></section>
                <dialog class="so-form-modal-dialog"><h4>Add primary artist</h4><div class="semi-select-content-wrapper"><input id="search-name" type="text"></div>
                <div id="create-name" onclick="document.querySelector('#search-name').parentElement.remove();this.parentElement.append(document.querySelector('template').content.cloneNode(true));this.remove()">Add "Zenal Farell" as an artist</div></dialog>
                <template><div data-so-field-id="artistName"><span>Artist name</span><span>Zenal Farell</span></div><h5>Artist profile pages</h5>
                <label class="semi-radio"><input type="radio" name="spotify" onchange="document.querySelector('#save-credit').disabled=document.querySelectorAll('input:checked').length!==2">Create and link to a new artist ID</label>
                <label class="semi-radio"><input type="radio" name="apple" onchange="document.querySelector('#save-credit').disabled=document.querySelectorAll('input:checked').length!==2">Create and link to a new artist ID</label>
                <label>SoundCloud</label><label>Artist ID</label><input id="soundcloud" type="text">
                <button id="save-credit" disabled onclick="if(!document.querySelector('#soundcloud').value){document.querySelector('#saved').textContent='Zenal Farell';this.closest('dialog').close()}">Submit</button></template>`);
            await setSearchField(page, 'Primary artists', 'Zenal Farell');
            expect(await page.locator('#soundcloud').inputValue()).toBe('');
            expect(await page.locator('#saved').innerText()).toBe('Zenal Farell');
        } finally { await browser.close(); }
    }, 20000);
    it("waits for queued audio even without an upload percentage", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<div class="so-audio-preview-card-status-waiting">Waiting</div>`);
            await page.evaluate(() => setTimeout(() => {
                document.querySelector('div')!.className = 'so-audio-preview-card-status-success';
                document.querySelector('div')!.textContent = 'Upload complete';
            }, 1000));
            await waitForUpload(page, "audio");
            expect(await page.locator('div').innerText()).toBe('Upload complete');
        } finally { await browser.close(); }
    }, 10000);
    it("waits until SoundOn finishes processing audio", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<div class="so-audio-preview-card-status-processing">Processing audio</div>`);
            await page.evaluate(() => setTimeout(() => {
                document.querySelector('div')!.className = 'so-audio-preview-card-status-success';
                document.querySelector('div')!.textContent = 'Upload complete';
            }, 1000));
            await waitForUpload(page, "audio");
            expect(await page.locator('div').innerText()).toBe('Upload complete');
        } finally { await browser.close(); }
    }, 10000);
    it("retries text reset on blur and reports a field that SoundOn still rejects", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<section><label class="soundon-formik-field-label">Title</label><input onblur="window.blurs=(window.blurs||0)+1;if(window.blurs===1)this.value=''" /></section>`);
            await setTextField(page, 'Title', 'Tanpa Kamu');
            expect(await page.locator('input').inputValue()).toBe('Tanpa Kamu');
            await page.setContent(`<section><label class="soundon-formik-field-label">Record label</label><input onblur="this.value=''" /></section>`);
            await expect(setTextField(page, 'Record label', 'Dimensi Suara')).rejects.toThrow('SOUNDON_FIELD_NOT_PERSISTED:Record label:expected=Dimensi Suara:actual=');
        } finally { await browser.close(); }
    }, 15000);
    it("does not reopen Add for an exact songwriter credit already saved on the form", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<section><span class="soundon-formik-field-label">Songwriters</span><span>Zenal Farell</span><button onclick="window.opened=true">Add</button></section>`);
            await setSearchField(page, 'Songwriters', 'Zenal Farell');
            expect(await page.evaluate(() => (window as any).opened)).toBeUndefined();
        } finally { await browser.close(); }
    }, 10000);
    it("selects the contributor role in a custom modal and verifies the requested credit field", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<section><span class="soundon-formik-field-label">Production and additional contributors</span><div id="saved"></div><button onclick="document.querySelector('.so-form-modal-dialog').hidden=false">Add</button></section>
                <section><span class="soundon-formik-field-label">Primary artists</span><span>Sadadi Nusantara</span></section>
                <div class="so-form-modal-dialog" hidden><h4>Add contributor</h4>
                <div class="semi-select" onclick="document.querySelector('#roles').hidden=false"><div class="semi-select-content-wrapper">Choose role</div></div>
                <div class="semi-select" onclick="this.classList.add('semi-select-open');document.querySelector('#credit-name').hidden=false;document.querySelector('#add-credit').hidden=false"><label>Name</label><div class="semi-select-content-wrapper">Choose artist</div><input id="credit-name" type="text" hidden></div>
                <div id="add-credit" hidden onclick="document.querySelector('#submit-credit').disabled=window.role!=='Producer';this.hidden=true">Add "Sadadi Nusantara" as an artist</div>
                <button id="submit-credit" disabled onclick="document.querySelector('#saved').innerHTML='<span>Sadadi Nusantara</span><span>Producer</span>';this.parentElement.hidden=true">Submit</button></div>
                <div id="roles" class="semi-portal" hidden><div class="semi-select-option" onclick="window.role='Producer';this.parentElement.hidden=true"><span class="semi-select-option-text">Producer</span></div></div>`);
            await setSearchField(page, 'Production and additional contributors', 'Sadadi Nusantara', false, 0, 'Producer');
            expect(await page.evaluate(() => (window as any).role)).toBe('Producer');
            expect(await page.locator('#saved').innerText()).toContain('Sadadi Nusantara');
            expect(await waitForEntityCard(page, 'Production and additional contributors', 'Sadadi Nusantara', 50)).toBe(true);
        } finally { await browser.close(); }
    }, 20000);
    it("returns SoundOn audio processing error verbatim", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<div class="so-audio-preview-card-status-error">Audio file could not be processed: invalid codec</div>`);
            await expect(waitForUpload(page, "audio"))
                .rejects.toThrow("AUDIO_UPLOAD_FAILED:Audio file could not be processed: invalid codec");
        } finally { await browser.close(); }
    }, 10000);
    it("opens only the requested album track even when every panel starts open", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent([1, 2].map(n => `<details open><summary onclick="event.preventDefault()"><span>track-${n}.wav</span><button onclick="window.deleted=true">Delete</button><button onclick="const card=this.closest('details');setTimeout(()=>card.toggleAttribute('open'),100)">Toggle</button></summary><div data-formik-id="tracks.${n}.title"><input value="Title ${n}"></div></details>`).join(""));
            await selectAlbumTrack(page, "track-2.wav");
            expect(await page.locator("input:visible").count()).toBe(1);
            expect(await page.locator("input:visible").inputValue()).toBe("Title 2");
            await page.locator("input:visible").fill("Updated track 2");
            expect(await page.locator("input").first().inputValue()).toBe("Title 1");
            await selectAlbumTrack(page, "track-1.wav");
            expect(await page.locator("input:visible").inputValue()).toBe("Title 1");
            expect(await page.evaluate(() => (window as any).deleted)).toBeUndefined();
        } finally { await browser.close(); }
    }, 10000);
    it("does not wait for a search input removed after artist selection", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<section><span class="soundon-formik-field-label">Primary artists</span><span id="saved"></span>
                <button onclick="document.querySelector('dialog').showModal()">Add</button></section>
                <dialog class="so-form-modal-dialog"><h4>Add artist</h4><div class="semi-select-content-wrapper">Choose</div>
                <input type="text"><div class="so-artist-preview-item-artist-name" onclick="document.querySelector('input').remove()">Husni</div>
                <button onclick="document.querySelector('#saved').textContent='Husni';this.closest('dialog').close();document.querySelector('#next-editor').showModal()">Submit</button></dialog>
                <dialog id="next-editor" class="so-form-modal-dialog"><h4>Add songwriter</h4><input type="text" value="Another person"><button>Submit</button></dialog>`);
            await setSearchField(page, "Primary artists", "Husni");
            expect(await page.locator("#saved").innerText()).toBe("Husni");
        } finally { await browser.close(); }
    }, 8000);
    it("replaces a stale modal name before submitting a new artist", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<section><span class="soundon-formik-field-label">Primary artists</span><span id="saved"></span>
                <button onclick="document.querySelector('dialog').showModal()">Add</button></section>
                <dialog class="so-form-modal-dialog"><h4>Add primary artist</h4>
                <div class="semi-select-content-wrapper">Choose</div><label>Artist name</label><input type="text" value="Sandy Andika">
                <div class="semi-select-option-list-outer-bottom-slot" onclick="document.querySelector('input').value='Sandy Andika'">Add “Randy Wahyuda” as an artist</div>
                <button onclick="document.querySelector('#saved').textContent=document.querySelector('input').value;this.closest('dialog').close()">Submit</button></dialog>`);
            await setSearchField(page, "Primary artists", "Randy Wahyuda");
            expect(await page.locator("#saved").innerText()).toBe("Randy Wahyuda");
        } finally { await browser.close(); }
    }, 10000);
    it.each(['Nightrize', "Starkid's", 'ALVEΛ'])('creates %s when Submit appears only after Add as an artist', async (name) => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<section><span class="soundon-formik-field-label">Primary artists</span><span id="saved"></span>
                <button onclick="document.querySelector('dialog').showModal()">Add</button></section>
                <dialog class="so-form-modal-dialog"><h4>Add primary artist</h4><label>Artist name</label>
                <div class="semi-select-content-wrapper"><input id="artist-name" type="text" oninput="document.querySelector('#add-artist').textContent='Add &quot;'+this.value+'&quot; as an artist'"></div>
                <p>No matching artists found</p>
                <div id="add-artist" onclick="window.created=true;document.querySelector('#profile').append(document.querySelector('template').content.cloneNode(true));this.remove()">Add as an artist</div>
                <div id="profile"></div></dialog>
                <template><label class="semi-radio"><input type="radio" onchange="this.closest('dialog').querySelector('button').disabled=false">Create and link to a new artist ID</label>
                <button disabled onclick="document.querySelector('#saved').textContent=document.querySelector('#artist-name').value;this.closest('dialog').close()">Submit</button></template>`);
            expect(await page.locator('dialog').getByRole('button', { name: 'Submit' }).count()).toBe(0);
            await setSearchField(page, 'Primary artists', name);
            expect(await page.evaluate(() => (window as any).created)).toBe(true);
            expect(await page.locator('#saved').innerText()).toBe(name);
            expect(await page.locator('dialog').isVisible()).toBe(false);
        } finally { await browser.close(); }
    }, 30000);
    it("selects and verifies SoundOn's create-new-artist profile option", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<dialog open><label class="semi-radio"><input type="radio" name="spotify-profile" onchange="document.querySelector('button').disabled=document.querySelectorAll('input:checked').length!==2">Create and link to a new artist ID</label>
                <label class="semi-radio"><input type="radio" name="apple-profile" onchange="document.querySelector('button').disabled=document.querySelectorAll('input:checked').length!==2">Create and link to a new artist ID</label><button disabled>Submit</button></dialog>`);
            const dialog = page.locator("dialog");
            expect(await selectCreateArtistProfile(dialog)).toBe(true);
            expect(await dialog.locator('input[type="radio"]:checked').count()).toBe(2);
            expect(await dialog.getByRole("button", { name: "Submit" }).isEnabled()).toBe(true);
        } finally { await browser.close(); }
    }, 10000);
    it("commits pre-release time through the SoundOn option instead of transient input text", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<section id="card"><div id="field"><span>Pre-release time</span>
                <input type="text" value=""><button role="combobox" onclick="document.querySelector('[role=option]').hidden=false">Select time</button></div>
                <div role="option" hidden onclick="document.querySelector('[role=combobox]').textContent='12:00 AM';this.hidden=true;document.querySelector('#card').dataset.committed='00:00'">12:00 AM</div></section>`);
            await setScopedTime(page.locator('#card'), 'Pre-release time');
            expect(await page.locator('#card').getAttribute('data-committed')).toBe('00:00');
        } finally { await browser.close(); }
    }, 10000);
    it("reports field errors instead of bypassing them with Later", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<button onclick="document.querySelector('.semi-modal-wrap').hidden=false">Next</button>
                <div class="semi-modal-wrap" hidden><h3>4 errors detected</h3>
                <button onclick="window.bypassed=true">Later</button>
                <button onclick="this.parentElement.hidden=true;document.querySelector('[role=alert]').hidden=false">Fix now</button></div>
                <div role="alert" hidden>Primary artist must have a performing role</div>`);
            await expect(advanceWizardStep(page)).rejects.toThrow("SOUNDON_FORM_VALIDATION:4 errors detected:Primary artist must have a performing role");
            expect(await page.evaluate(() => (window as any).bypassed)).toBeUndefined();
        } finally { await browser.close(); }
    }, 15000);

    it("does not reject a valid form", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent("<p>Track information</p>");
            await expect(assertNoValidationErrors(page)).resolves.toBeUndefined();
        } finally { await browser.close(); }
    }, 15000);
});
