/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import MasterDetailController from '../../assets/controllers/master_detail_controller.js';

let application;

beforeEach(() => {
    window.history.replaceState({}, '', '/');
    application = Application.start();
    application.register('master-detail', MasterDetailController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await new Promise((resolve) => setTimeout(resolve, 0));
    application.stop();
});

async function mount(selected = '') {
    document.body.innerHTML = `<div data-controller="master-detail" data-master-detail-selected-value="${selected}">
        <button data-master-detail-target="trigger" data-master-detail-id="first">First</button>
        <button data-master-detail-target="trigger" data-master-detail-id="second">Second</button>
        <section data-master-detail-target="panel" data-master-detail-id="first">First</section>
        <section data-master-detail-target="panel" data-master-detail-id="second" hidden>Second</section>
    </div>`;
    await new Promise((resolve) => setTimeout(resolve, 0));
    return application.getControllerForElementAndIdentifier(
        document.querySelector('[data-controller="master-detail"]'),
        'master-detail',
    );
}

it('shows the refused submission panel instead of the previous URL fragment', async () => {
    window.history.replaceState({}, '', '/#first');
    const controller = await mount('second');
    expect(controller.panelTargets[0].hidden).toBe(true);
    expect(controller.panelTargets[1].hidden).toBe(false);
    expect(controller.triggerTargets[1].getAttribute('aria-current')).toBe(
        'true',
    );
    expect(window.location.hash).toBe('#first');
});

it('uses the fragment when no refused submission selects a panel', async () => {
    window.history.replaceState({}, '', '/#second');
    const controller = await mount();
    expect(controller.panelTargets[0].hidden).toBe(true);
    expect(controller.panelTargets[1].hidden).toBe(false);
});

it('defaults to the first panel and lets a later selection update the fragment', async () => {
    const controller = await mount();
    expect(controller.panelTargets[0].hidden).toBe(false);
    expect(controller.panelTargets[1].hidden).toBe(true);
    controller.select({ currentTarget: controller.triggerTargets[1] });
    expect(controller.panelTargets[0].hidden).toBe(true);
    expect(controller.panelTargets[1].hidden).toBe(false);
    expect(window.location.hash).toBe('#second');
});
