/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, expect, it } from 'vitest';
import AutosearchController from '../../assets/controllers/autosearch_controller.js';

let application;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

async function mount(checked) {
    application = Application.start();
    application.register('autosearch', AutosearchController);
    document.body.innerHTML = `<form data-controller="autosearch">
        <input type="search" name="search" value="">
        <input type="checkbox" name="archived" value="1"${checked ? ' checked' : ''}>
        <a data-autosearch-target="clearButton">Clear</a>
    </form>`;
    await settle();

    return document.querySelector('[data-autosearch-target="clearButton"]');
}

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
});

it('treats an unchecked switch as no filter', async () => {
    const clear = await mount(false);
    expect(clear.classList.contains('pointer-events-none')).toBe(true);
});

it('treats a checked switch as an active filter', async () => {
    const clear = await mount(true);
    expect(clear.classList.contains('pointer-events-none')).toBe(false);
});
