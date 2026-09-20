/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import CardDrawerController from '../../assets/controllers/card_drawer_controller.js';

let application;
let controller;
let dialog;
let completions;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

beforeEach(async () => {
    completions = [];
    vi.stubGlobal('matchMedia', () => ({ matches: false }));
    document.body.innerHTML = `<div data-controller="card-drawer">
        <a id="invoker" href="/card">Open card</a>
        <turbo-frame id="board-frame"></turbo-frame>
        <dialog open data-card-drawer-target="dialog">
            <div data-card-drawer-target="loading" tabindex="-1" hidden>Loading</div>
            <div data-card-drawer-target="error" hidden><button>Retry</button></div>
            <turbo-frame data-card-drawer-target="frame"><form><textarea>Draft reply</textarea></form></turbo-frame>
        </dialog>
    </div>`;
    dialog = document.querySelector('dialog');
    dialog.showModal = vi.fn(() => {
        dialog.open = true;
    });
    dialog.show = vi.fn(() => {
        dialog.open = true;
    });
    dialog.close = vi.fn(() => {
        dialog.open = false;
        dialog.dispatchEvent(new Event('close'));
    });
    dialog.getAnimations = () => [];
    dialog.animate = vi.fn(() => ({
        finished: new Promise((resolve) => completions.push(resolve)),
        cancel: vi.fn(),
    }));
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
    vi.unstubAllGlobals();
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
    // The loading state offers no control, so the status itself takes focus.
    expect(document.activeElement).toBe(controller.loadingTarget);
});

it('ignores late failure and load events after closing the drawer', () => {
    dialog.open = false;
    const invoker = document.getElementById('invoker');
    invoker.focus();
    const event = { target: controller.frameTarget, preventDefault: vi.fn() };
    controller.failed(event);
    controller.loaded(event);
    expect(controller.errorTarget.hidden).toBe(true);
    expect(document.activeElement).toBe(invoker);
});

it('slides the drawer in when a card link opens it', () => {
    dialog.open = false;
    const invoker = document.getElementById('invoker');
    controller.prepare({ currentTarget: invoker });
    // Wide enough for the board beside it, so the drawer is modeless.
    expect(dialog.show).toHaveBeenCalledOnce();
    expect(dialog.showModal).not.toHaveBeenCalled();
    expect(dialog.animate.mock.calls[0][0]).toEqual([
        { transform: 'translateX(100%)' },
        { transform: 'translateX(0)' },
    ]);
    expect(controller.loadingTarget.hidden).toBe(false);
});

it('keeps the card on screen until the slide out ends, then resets and refocuses', async () => {
    dialog.open = false;
    const invoker = document.getElementById('invoker');
    controller.prepare({ currentTarget: invoker });
    controller.loaded({ target: controller.frameTarget });
    controller.close();
    expect(dialog.open).toBe(true);
    expect(controller.frameTarget.querySelector('textarea')).not.toBeNull();
    completions[1]();
    await settle();
    expect(dialog.open).toBe(false);
    expect(controller.frameTarget.childElementCount).toBe(0);
    expect(document.activeElement).toBe(invoker);
});

it('returns focus to the same link when a board reload replaced the invoker', async () => {
    dialog.open = false;
    const invoker = document.getElementById('invoker');
    controller.prepare({ currentTarget: invoker });
    const replacement = invoker.cloneNode(true);
    replacement.id = 'replacement';
    controller.close();
    // The board reload lands while the drawer is still sliding out.
    invoker.replaceWith(replacement);
    completions[1]();
    await settle();
    expect(document.activeElement).toBe(replacement);
});

it('announces a save in the drawer so the board can reload', () => {
    const saved = vi.fn();
    window.addEventListener('card-drawer:saved', saved);
    const cardForm = document.createElement('form');
    cardForm.dataset.cardDrawerSavesCard = '';
    const replyForm = controller.frameTarget.querySelector('form');
    controller.submitted({ target: cardForm, detail: { success: true } });
    controller.submitted({ target: cardForm, detail: { success: false } });
    // An answer or a reply changes nothing on the board, so it reloads nothing.
    controller.submitted({ target: replyForm, detail: { success: true } });
    window.removeEventListener('card-drawer:saved', saved);
    expect(saved).toHaveBeenCalledOnce();
});

it('keeps the focus of a reader already typing in the loaded content', () => {
    controller.frameTarget.insertAdjacentHTML(
        'beforeend',
        '<button type="button">Post reply</button>',
    );
    const textarea = controller.frameTarget.querySelector('textarea');
    textarea.focus();
    controller.loaded({ target: controller.frameTarget });
    expect(document.activeElement).toBe(textarea);
});

it('keeps the drawer modal where the page beside it has no room', () => {
    vi.stubGlobal('innerWidth', 900);
    dialog.open = false;
    controller.prepare({ currentTarget: document.getElementById('invoker') });
    expect(dialog.showModal).toHaveBeenCalledOnce();
    expect(dialog.show).not.toHaveBeenCalled();
});

it('closes a modeless drawer on Escape, which reports no cancel event', () => {
    dialog.open = false;
    controller.prepare({ currentTarget: document.getElementById('invoker') });
    expect(dialog.open).toBe(true);
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(controller.closeRequest).not.toBeNull();
});
