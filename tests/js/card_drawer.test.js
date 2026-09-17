/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import CardDrawerController from '../../assets/controllers/card_drawer_controller.js';

let application;
let controller;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

beforeEach(async () => {
    document.body.innerHTML = `<div data-controller="card-drawer">
        <button id="invoker">Open card</button>
        <dialog open data-card-drawer-target="dialog">
            <div data-card-drawer-target="loading" hidden><button>Close</button></div>
            <div data-card-drawer-target="error" hidden><button>Retry</button></div>
            <turbo-frame data-card-drawer-target="frame"><form><textarea>Draft reply</textarea></form></turbo-frame>
        </dialog>
    </div>`;
    application = Application.start();
    application.register('card-drawer', CardDrawerController);
    await settle();
    controller = application.getControllerForElementAndIdentifier(
        document.querySelector('[data-controller]'),
        'card-drawer',
    );
});

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
});

it('leaves nested reply errors and drafts under the reply controller', () => {
    const form = controller.frameTarget.querySelector('form');
    const event = {
        target: form,
        preventDefault: vi.fn(),
        detail: { fetchResponse: { succeeded: false, isHTML: true } },
    };
    controller.received(event);
    controller.failed(event);
    expect(event.preventDefault).not.toHaveBeenCalled();
    expect(controller.errorTarget.hidden).toBe(true);
    expect(controller.frameTarget.hidden).toBe(false);
    expect(form.querySelector('textarea').value).toBe('Draft reply');
});

it('shows frame errors, focuses Retry, and starts a fresh frame load', () => {
    const event = { target: controller.frameTarget, preventDefault: vi.fn() };
    controller.failed(event);
    expect(event.preventDefault).toHaveBeenCalledOnce();
    expect(controller.errorTarget.hidden).toBe(false);
    expect(controller.loadingTarget.hidden).toBe(true);
    expect(controller.frameTarget.hidden).toBe(true);
    expect(document.activeElement.textContent).toBe('Retry');
    controller.frameTarget.reload = vi.fn();
    controller.retry();
    expect(controller.frameTarget.reload).toHaveBeenCalledOnce();
    expect(controller.errorTarget.hidden).toBe(true);
    expect(controller.loadingTarget.hidden).toBe(false);
    expect(document.activeElement.textContent).toBe('Close');
});

it('ignores late failure and load events after closing the drawer', () => {
    controller.dialogTarget.removeAttribute('open');
    const invoker = document.getElementById('invoker');
    invoker.focus();
    const event = { target: controller.frameTarget, preventDefault: vi.fn() };
    controller.failed(event);
    controller.loaded(event);
    expect(controller.errorTarget.hidden).toBe(true);
    expect(document.activeElement).toBe(invoker);
});
