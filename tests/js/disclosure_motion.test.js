/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import DisclosureController from '../../assets/controllers/disclosure_controller.js';
import ModalController from '../../assets/controllers/modal_controller.js';
import FlashController from '../../assets/controllers/flash_controller.js';

let application;
let animations;
let reducedMotion;

beforeEach(() => {
    animations = [];
    reducedMotion = false;
    vi.stubGlobal('matchMedia', () => ({ matches: reducedMotion }));
    Element.prototype.animate = vi.fn(() => {
        const animation = { cancel: vi.fn(), finished: Promise.resolve() };
        animations.push(animation);
        return animation;
    });
    application = Application.start();
    application.register('disclosure', DisclosureController);
    application.register('modal', ModalController);
    application.register('flash', FlashController);
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    vi.restoreAllMocks();
    delete Element.prototype.animate;
    vi.unstubAllGlobals();
});

async function mount(markup, identifier) {
    document.body.innerHTML = markup;
    await new Promise((resolve) => setTimeout(resolve, 0));
    return application.getControllerForElementAndIdentifier(
        document.body.firstElementChild,
        identifier,
    );
}

async function disclosure(tag = 'div') {
    return mount(
        `<${tag} data-controller="disclosure" class="disclosure-open" open>
            <summary data-disclosure-target="trigger">Toggle</summary>
            <section class="open" data-disclosure-target="content">Content</section>
        </${tag}>`,
        'disclosure',
    );
}

it.each(['div', 'details'])(
    'reverses an interrupted collapse on %s',
    async (tag) => {
        const controller = await disclosure(tag);
        Object.defineProperty(controller.contentTarget, 'scrollHeight', {
            value: 100,
        });
        const event = { preventDefault() {} };
        controller.toggle(event);
        expect(controller.disclosureElement.open).toBe(true);
        vi.spyOn(
            controller.contentTarget,
            'getBoundingClientRect',
        ).mockReturnValue({ height: 37 });
        vi.spyOn(window, 'getComputedStyle').mockReturnValue({
            opacity: '0.4',
        });
        controller.toggle(event);
        expect(animations[0].cancel).toHaveBeenCalledOnce();
        expect(controller.contentTarget.animate).toHaveBeenLastCalledWith(
            expect.objectContaining({
                height: ['37px', '100px'],
                opacity: [0.4, 1],
            }),
            expect.anything(),
        );
        animations[1].onfinish();
        expect(controller.disclosureElement.open).toBe(true);
        expect(controller.contentTarget.classList.contains('open')).toBe(true);
        controller.toggle(event);
        animations[2].onfinish();
        expect(controller.disclosureElement.open).toBe(false);
    },
);

it('reports the requested expanded state before collapse finishes', async () => {
    const controller = await disclosure();
    controller.toggle({ preventDefault() {} });
    expect(controller.triggerTargets[0].getAttribute('aria-expanded')).toBe(
        'false',
    );
});

it.each(['div', 'details'])(
    'reverses an interrupted expansion on %s',
    async (tag) => {
        const controller = await disclosure(tag);
        const event = { preventDefault() {} };
        controller.toggle(event);
        animations[0].onfinish();
        controller.toggle(event);
        vi.spyOn(
            controller.contentTarget,
            'getBoundingClientRect',
        ).mockReturnValue({ height: 23 });
        vi.spyOn(window, 'getComputedStyle').mockReturnValue({
            opacity: '0.3',
        });
        controller.toggle(event);
        expect(animations[1].cancel).toHaveBeenCalledOnce();
        expect(controller.contentTarget.animate).toHaveBeenLastCalledWith(
            expect.objectContaining({
                height: ['23px', '0px'],
                opacity: [0.3, 0],
            }),
            expect.anything(),
        );
        animations[2].onfinish();
        expect(controller.disclosureElement.open).toBe(false);
        expect(controller.contentTarget.classList.contains('open')).toBe(false);
    },
);

it('honors a reduced-motion change during a transition', async () => {
    const controller = await disclosure();
    controller.toggle({ preventDefault() {} });
    reducedMotion = true;
    controller.toggle({ preventDefault() {} });
    expect(animations[0].cancel).toHaveBeenCalledOnce();
    expect(animations).toHaveLength(1);
    expect(controller.disclosureElement.open).toBe(true);
    expect(controller.animation).toBeNull();
});

it.each(['div', 'details'])(
    'skips disclosure motion on %s when requested',
    async (tag) => {
        const controller = await disclosure(tag);
        reducedMotion = true;
        controller.toggle({ preventDefault() {} });
        expect(controller.disclosureElement.open).toBe(false);
        controller.toggle({ preventDefault() {} });
        expect(controller.disclosureElement.open).toBe(true);
        expect(animations).toHaveLength(0);
    },
);

it('opens and closes a reduced-motion dialog without WAAPI', async () => {
    const controller = await mount(
        '<div data-controller="modal"><dialog data-modal-target="dialog"></dialog></div>',
        'modal',
    );
    const dialog = controller.dialogTarget;
    dialog.showModal = vi.fn(() => {
        dialog.open = true;
    });
    dialog.close = vi.fn(() => {
        dialog.open = false;
    });
    dialog.getAnimations = () => [];
    reducedMotion = true;
    controller.open();
    expect(dialog.open).toBe(true);
    controller.close();
    await Promise.resolve();
    expect(dialog.open).toBe(false);
    expect(animations).toHaveLength(0);
});

it('removes a reduced-motion flash without WAAPI', async () => {
    const controller = await mount(
        '<div data-controller="flash">Saved</div>',
        'flash',
    );
    reducedMotion = true;
    controller.dismiss();
    expect(controller.element.isConnected).toBe(false);
    expect(animations).toHaveLength(0);
});
