/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import GlobalSearchController from '../../assets/controllers/global_search_controller.js';

let application;
let controller;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

beforeEach(async () => {
    application = Application.start();
    application.register('global-search', GlobalSearchController);
    document.body.innerHTML = `<div data-controller="global-search">
        <button data-global-search-target="trigger">Search</button>
        <dialog data-global-search-target="dialog">
            <button data-global-search-target="close">Close</button>
            <form data-global-search-target="form"><input data-global-search-target="query"></form>
            <p data-global-search-target="loading">Searching</p>
            <p data-global-search-target="error" hidden>Failed</p>
            <div data-global-search-target="frame" hidden></div>
        </dialog>
    </div>`;
    await settle();
    controller = application.getControllerForElementAndIdentifier(
        document.querySelector('[data-controller]'),
        'global-search',
    );
});

afterEach(async () => {
    vi.useRealTimers();
    document.body.replaceChildren();
    await settle();
    application.stop();
    vi.restoreAllMocks();
});

it('keeps an older query response hidden while the reader types a new query', () => {
    controller.queryTarget.value = 'current';
    controller.loading();
    controller.frameTarget.innerHTML =
        '<section data-search-query="older">Older results</section>';
    controller.loaded();
    expect(controller.frameTarget.hidden).toBe(true);
    expect(controller.loadingTarget.hidden).toBe(false);
    controller.frameTarget.innerHTML =
        '<section data-search-query="current">Current results</section>';
    controller.loaded();
    expect(controller.frameTarget.hidden).toBe(false);
    expect(controller.loadingTarget.hidden).toBe(true);
});

it('debounces typing and cancels a pending debounce on explicit submission', async () => {
    const submit = vi
        .spyOn(controller.formTarget, 'requestSubmit')
        .mockImplementation(() => {});
    vi.useFakeTimers();
    controller.search();
    controller.search();
    await vi.advanceTimersByTimeAsync(300);
    expect(submit).toHaveBeenCalledTimes(1);
    controller.search();
    controller.submitted();
    await vi.advanceTimersByTimeAsync(300);
    expect(submit).toHaveBeenCalledTimes(1);
});

it('accepts a response after the form trims Unicode separators and invisible characters', () => {
    controller.queryTarget.value = '\u200b\u0085\u00a0current\u200b';
    controller.loading();
    controller.frameTarget.innerHTML =
        '<section data-search-query="current">Current results</section>';
    controller.loaded();
    expect(controller.frameTarget.hidden).toBe(false);
    expect(controller.loadingTarget.hidden).toBe(true);
});

it('does not open search over another dialog', () => {
    const click = vi
        .spyOn(controller.triggerTarget, 'click')
        .mockImplementation(() => {});
    const other = document.createElement('dialog');
    other.open = true;
    document.body.append(other);
    const event = { key: 'k', ctrlKey: true, preventDefault: vi.fn() };
    controller.shortcut(event);
    expect(click).not.toHaveBeenCalled();
    expect(event.preventDefault).not.toHaveBeenCalled();
    other.remove();
    controller.shortcut(event);
    expect(click).toHaveBeenCalledTimes(1);
    expect(event.preventDefault).toHaveBeenCalledTimes(1);
});

it.each([
    { succeeded: false, isHTML: true, redirected: false },
    { succeeded: true, isHTML: false, redirected: false },
    { succeeded: true, isHTML: true, redirected: true },
])('shows an error for an unusable response: %j', (fetchResponse) => {
    const event = { detail: { fetchResponse }, preventDefault: vi.fn() };
    controller.received(event);
    expect(event.preventDefault).toHaveBeenCalledTimes(1);
    expect(controller.errorTarget.hidden).toBe(false);
    expect(controller.loadingTarget.hidden).toBe(true);
    expect(controller.frameTarget.hidden).toBe(true);
    controller.loading();
    expect(controller.errorTarget.hidden).toBe(true);
    expect(controller.loadingTarget.hidden).toBe(false);
});
