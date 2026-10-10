/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import CommentAnchorController from '../../assets/controllers/comment_anchor_controller.js';

let application;
let opened;
let passageTop;
const nativeMatches = Element.prototype.matches;

const nextFrame = () =>
    new Promise((resolve) => requestAnimationFrame(() => resolve()));
const settle = async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
    await nextFrame();
    await nextFrame();
};
const closedEvent = () =>
    Object.assign(new Event('toggle'), { newState: 'closed' });

beforeEach(() => {
    opened = [];
    passageTop = 100;
    window.localStorage.clear();
    window.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    };
    HTMLElement.prototype.showPopover = function () {
        opened.push(this);
        this.dataset.open = 'true';
    };
    HTMLElement.prototype.hidePopover = function () {
        delete this.dataset.open;
    };
    Element.prototype.matches = function (selector) {
        return selector === ':popover-open'
            ? this.dataset.open === 'true'
            : nativeMatches.call(this, selector);
    };
    // jsdom lays nothing out, so every passage reports one line box.
    Range.prototype.getClientRects = () => [
        { top: 100, bottom: 120, left: 50, right: 90 },
    ];
    Range.prototype.getBoundingClientRect = () => ({
        top: passageTop,
        bottom: passageTop + 20,
        left: 50,
        right: 90,
    });
    Object.defineProperty(document.documentElement, 'clientWidth', {
        configurable: true,
        get: () => 1024,
    });
    application = Application.start();
    application.register('comment-anchor', CommentAnchorController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await new Promise((resolve) => setTimeout(resolve, 0));
    application.stop();
    delete HTMLElement.prototype.showPopover;
    delete HTMLElement.prototype.hidePopover;
    Element.prototype.matches = nativeMatches;
    delete Range.prototype.getClientRects;
    delete Range.prototype.getBoundingClientRect;
    delete Element.prototype.scrollIntoView;
});

function row(id) {
    return `<button type="button" class="lp-comment-row" id="comment-row-${id}"
        data-comment-anchor-target="row" data-thread-id="comment-thread-${id}"
        aria-expanded="false"
        data-action="pointerdown->comment-anchor#pressRow click->comment-anchor#openRow"></button>`;
}

function card(id, quote, status = 'pending') {
    return `<div id="comment-thread-${id}" popover="auto" data-comment-anchor-target="thread"
        data-anchor-quote="${quote}" data-anchor-prefix="" data-anchor-suffix=""
        data-anchor-status="${status}" data-anchor-kind="comment"></div>`;
}

async function mount({
    earlyStatus = 'pending',
    markers = false,
    resolvedToggle = false,
} = {}) {
    document.body.innerHTML = `<main class="lp-main"><div data-controller="comment-anchor">
        ${resolvedToggle ? '<button type="button" data-resolved-toggle data-label-hide="Hide resolved" data-label-show="Show resolved" hidden>Hide resolved</button>' : ''}
        <div data-comment-anchor-target="block">
            ${markers ? '<div class="lp-review-doc__head"></div><div data-comment-anchor-target="markers"></div>' : ''}
            <form data-comment-anchor-target="composer" hidden>
                <textarea data-comment-anchor-target="composerBody"></textarea>
            </form>
            <div data-comment-anchor-target="doc" data-action="click->comment-anchor#onDocClick mousemove->comment-anchor#onDocMousemove">Alpha beta gamma delta</div>
            <div data-comment-anchor-target="margin">${row('late')}${row('early')}</div>
            <div id="comment-threads">${card('early', 'beta', earlyStatus)}${card('late', 'delta')}</div>
        </div>
    </div></main>`;
    await settle();
}

const rowOf = (id) => document.getElementById(`comment-row-${id}`);
const cardOf = (id) => document.getElementById(`comment-thread-${id}`);

it('sorts the rows in passage order', async () => {
    await mount();
    const order = [
        ...document.querySelectorAll('[data-comment-anchor-target="row"]'),
    ].map((each) => each.id);
    expect(order).toEqual(['comment-row-early', 'comment-row-late']);
});

it('opens a thread from its row under the first line of its passage', async () => {
    await mount();
    rowOf('early').click();

    expect(opened).toEqual([cardOf('early')]);
    expect(cardOf('early').style.top).toBe('128px');
    expect(cardOf('early').style.left).toBe('50px');
    expect(rowOf('early').classList).toContain('lp-comment-row--active');
    expect(rowOf('early').getAttribute('aria-expanded')).toBe('true');
});

it('closes the open thread when its row is pressed again', async () => {
    await mount();
    rowOf('early').click();
    rowOf('early').click();

    expect(cardOf('early').dataset.open).toBeUndefined();
    expect(rowOf('early').classList).not.toContain('lp-comment-row--active');
    expect(rowOf('early').getAttribute('aria-expanded')).toBe('false');
});

it('unmarks the row when the browser closes the popover', async () => {
    await mount();
    rowOf('early').click();
    cardOf('early').dispatchEvent(closedEvent());

    expect(rowOf('early').classList).not.toContain('lp-comment-row--active');
    rowOf('early').click();
    expect(opened).toEqual([cardOf('early'), cardOf('early')]);
});

it('opens the thread again when a stream replaces its card', async () => {
    await mount();
    rowOf('early').click();
    const fresh = cardOf('early').cloneNode(true);
    delete fresh.dataset.open;
    cardOf('early').replaceWith(fresh);
    await settle();

    expect(opened.at(-1)).toBe(fresh);
    expect(fresh.dataset.open).toBe('true');
    expect(rowOf('early').classList).toContain('lp-comment-row--active');
});

it('opens nothing again after its thread is deleted', async () => {
    await mount();
    rowOf('early').click();
    cardOf('early').remove();
    await settle();

    document
        .getElementById('comment-threads')
        .insertAdjacentHTML('beforeend', card('early', 'beta'));
    await settle();

    expect(opened).toHaveLength(1);
});

it('leaves the composer open when Escape closes a thread', async () => {
    await mount();
    const composer = document.querySelector(
        '[data-comment-anchor-target="composer"]',
    );
    rowOf('early').click();
    composer.hidden = false;
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(composer.hidden).toBe(false);

    cardOf('early').hidePopover();
    cardOf('early').dispatchEvent(closedEvent());
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(composer.hidden).toBe(true);
});

it('opens the thread the URL hash names and scrolls its passage into view', async () => {
    const scrolled = [];
    Element.prototype.scrollIntoView = function () {
        scrolled.push(this);
    };
    window.location.hash = '#comment-thread-late';
    try {
        await mount();
    } finally {
        window.location.hash = '';
        delete Element.prototype.scrollIntoView;
    }

    expect(opened).toEqual([cardOf('late')]);
    expect(rowOf('late').getAttribute('aria-expanded')).toBe('true');
    expect(scrolled).toEqual([
        document.querySelector('[data-comment-anchor-target="doc"]'),
    ]);
});

it('opens nothing when the URL hash names no thread of the page', async () => {
    window.location.hash = '#comment-thread-missing';
    try {
        await mount();
    } finally {
        window.location.hash = '';
    }

    expect(opened).toEqual([]);
});

it('asks for the Comments panel when the Undo notice of a delete arrives', async () => {
    await mount();
    const reveals = [];
    document.addEventListener('comment-anchor:reveal', (event) =>
        reveals.push(event.detail),
    );
    const stream = document.createElement('turbo-stream');
    stream.setAttribute('target', 'comment-recovery');
    document.body.append(stream);
    stream.dispatchEvent(
        new Event('turbo:before-stream-render', { bubbles: true }),
    );
    const other = document.createElement('turbo-stream');
    other.setAttribute('target', 'comment-rows');
    document.body.append(other);
    other.dispatchEvent(
        new Event('turbo:before-stream-render', { bubbles: true }),
    );

    expect(reveals).toEqual([{ thread: null }]);
});

it('scrolls an off-screen passage into view before its row opens the thread', async () => {
    await mount();
    const scrolled = [];
    Element.prototype.scrollIntoView = function () {
        scrolled.push(this);
    };
    passageTop = 2000;
    rowOf('early').click();

    expect(scrolled).toEqual([
        document.querySelector('[data-comment-anchor-target="doc"]'),
    ]);
    expect(opened).toEqual([cardOf('early')]);
});

it('scrolls to the quote when its tall paragraph still hides it', async () => {
    await mount();
    Element.prototype.scrollIntoView = () => {};
    const pane = document.querySelector('.lp-main');
    const scrolledBy = [];
    pane.scrollBy = (options) => scrolledBy.push(options.top);
    passageTop = 2000;
    Range.prototype.getClientRects = () => [
        { top: 2000, bottom: 2020, left: 50, right: 90, height: 20 },
    ];
    rowOf('early').click();

    expect(scrolledBy).toEqual([2000 - (window.innerHeight - 20) / 2]);
    expect(opened).toEqual([cardOf('early')]);
});

it('leaves the page still when the row of a visible passage opens its thread', async () => {
    await mount();
    const scrolled = [];
    Element.prototype.scrollIntoView = function () {
        scrolled.push(this);
    };
    rowOf('early').click();

    expect(scrolled).toEqual([]);
    expect(opened).toEqual([cardOf('early')]);
});

it('skips the passage of a resolved thread the Open filter hides', async () => {
    await mount({ earlyStatus: 'resolved' });
    document.querySelector('[data-comment-anchor-target="doc"]').dispatchEvent(
        new MouseEvent('click', {
            bubbles: true,
            clientX: 60,
            clientY: 110,
        }),
    );

    expect(opened).toEqual([cardOf('late')]);
});

it('shows a hand over a passage that opens a thread', async () => {
    await mount();
    const doc = document.querySelector('[data-comment-anchor-target="doc"]');
    const move = (clientX) =>
        doc.dispatchEvent(
            new MouseEvent('mousemove', {
                bubbles: true,
                clientX,
                clientY: 110,
            }),
        );

    move(60);
    await nextFrame();
    expect(doc.classList).toContain('lp-comment-anchor-hovered');

    move(500);
    await nextFrame();
    expect(doc.classList).not.toContain('lp-comment-anchor-hovered');
});

it.each([
    ['', {}],
    [' beside the resolved toggle', { resolvedToggle: true }],
])(
    'draws the gutter markers once%s, and their redraw starts no new layout',
    async (_, options) => {
        const offsetParent = Object.getOwnPropertyDescriptor(
            HTMLElement.prototype,
            'offsetParent',
        );
        const boundingBox = Element.prototype.getBoundingClientRect;
        Object.defineProperty(HTMLElement.prototype, 'offsetParent', {
            configurable: true,
            get() {
                return document.querySelector('.lp-main');
            },
        });
        Element.prototype.getBoundingClientRect = function () {
            return this.matches('.lp-main')
                ? { top: 0, left: 0, right: 1200, width: 1200 }
                : { top: 0, left: 0, right: 600, width: 600 };
        };
        try {
            await mount({ markers: true, ...options });
            const layer = document.querySelector(
                '[data-comment-anchor-target="markers"]',
            );
            const marker = layer.firstElementChild;
            expect(marker).not.toBeNull();

            await nextFrame();
            await nextFrame();

            expect(layer.firstElementChild).toBe(marker);
        } finally {
            Object.defineProperty(
                HTMLElement.prototype,
                'offsetParent',
                offsetParent,
            );
            Element.prototype.getBoundingClientRect = boundingBox;
        }
    },
);
