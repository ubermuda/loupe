/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import PanelTabsController from '../../assets/controllers/panel_tabs_controller.js';

let application;

beforeEach(() => {
    window.history.replaceState({}, '', '/');
    vi.stubGlobal(
        'requestAnimationFrame',
        vi.fn(() => 23),
    );
    vi.stubGlobal('cancelAnimationFrame', vi.fn());
    Element.prototype.scrollIntoView = vi.fn();
    application = Application.start();
    application.register('panel-tabs', PanelTabsController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await new Promise((resolve) => setTimeout(resolve, 0));
    application.stop();
    delete Element.prototype.scrollIntoView;
    vi.unstubAllGlobals();
});

async function mount(frameSource = null) {
    const markup = `<div data-controller="panel-tabs">
        <button data-panel-tabs-target="tab" data-panel-tab="overview">Overview</button>
        <button data-panel-tabs-target="tab" data-panel-tab="conversation">Conversation</button>
        <section data-panel-tabs-target="panel" data-panel-panel="overview">Overview</section>
        <section data-panel-tabs-target="panel" data-panel-panel="conversation" hidden>
            <article id="item-7">Request</article>
        </section>
    </div>`;
    document.body.innerHTML = frameSource
        ? `<turbo-frame src="${frameSource}">${markup}</turbo-frame>`
        : markup;
    await new Promise((resolve) => setTimeout(resolve, 0));
    return application.getControllerForElementAndIdentifier(
        document.querySelector('[data-controller="panel-tabs"]'),
        'panel-tabs',
    );
}

it('reveals the panel containing a fragment before scrolling to its target', async () => {
    window.history.replaceState({}, '', '/?tab=overview#item-7');
    const controller = await mount();
    expect(controller.panelTargets[1].hidden).toBe(false);
    expect(controller.tabTargets[1].getAttribute('aria-selected')).toBe('true');
    expect(Element.prototype.scrollIntoView).not.toHaveBeenCalled();
    requestAnimationFrame.mock.calls[0][0]();
    expect(
        document.getElementById('item-7').scrollIntoView,
    ).toHaveBeenCalledWith({
        block: 'start',
        behavior: 'instant',
    });
});

it('reveals a new same-page fragment without reconnecting', async () => {
    const controller = await mount();
    expect(controller.panelTargets[1].hidden).toBe(true);
    window.history.replaceState({}, '', '/#item-7');
    window.dispatchEvent(new HashChangeEvent('hashchange'));
    expect(controller.panelTargets[1].hidden).toBe(false);
    expect(requestAnimationFrame).toHaveBeenCalledOnce();
});

it('clears the request fragment when the user selects another tab', async () => {
    window.history.replaceState({}, '', '/#item-7');
    const controller = await mount();
    controller.select({ currentTarget: controller.tabTargets[0] });
    expect(controller.panelTargets[1].hidden).toBe(true);
    expect(window.location.hash).toBe('');
    expect(window.location.search).toBe('?tab=overview');
    expect(cancelAnimationFrame).toHaveBeenLastCalledWith(23);
});

it('leaves the selected tab and scrolling unchanged for an outside fragment', async () => {
    window.history.replaceState({}, '', '/?tab=conversation#outside');
    const controller = await mount();
    document.body.insertAdjacentHTML(
        'beforeend',
        '<aside id="outside">Outside</aside>',
    );
    window.dispatchEvent(new HashChangeEvent('hashchange'));
    expect(controller.panelTargets[1].hidden).toBe(false);
    expect(requestAnimationFrame).not.toHaveBeenCalled();
});

it('uses the frame source and ignores the surrounding page fragment', async () => {
    window.history.replaceState({}, '', '/?tab=conversation#item-7');
    const controller = await mount('/card?tab=overview');
    expect(controller.panelTargets[1].hidden).toBe(true);
    window.dispatchEvent(new HashChangeEvent('hashchange'));
    expect(controller.panelTargets[1].hidden).toBe(true);
    expect(requestAnimationFrame).not.toHaveBeenCalled();
});

it('cancels pending scrolling and removes the hash listener when disconnected', async () => {
    window.history.replaceState({}, '', '/#item-7');
    const controller = await mount();
    controller.element.remove();
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(cancelAnimationFrame).toHaveBeenLastCalledWith(23);
    requestAnimationFrame.mockClear();
    window.dispatchEvent(new HashChangeEvent('hashchange'));
    expect(requestAnimationFrame).not.toHaveBeenCalled();
});
