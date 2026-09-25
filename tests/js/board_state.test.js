/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import BoardFilterController from '../../assets/controllers/board_filter_controller.js';
import BoardViewController from '../../assets/controllers/board_view_controller.js';

let application;

// jsdom has no layout, so it does not define scrollIntoView.
Element.prototype.scrollIntoView ??= () => {};

beforeEach(() => {
    application = Application.start();
    application.register('board-filter', BoardFilterController);
    application.register('board-view', BoardViewController);
});

afterEach(async () => {
    vi.restoreAllMocks();
    document.body.replaceChildren();
    await Promise.resolve();
    application.stop();
});

function boardHtml() {
    return `<div id="board" data-controller="board-filter board-view">
        <div data-action="focusin->board-filter#revealField">
            <input data-board-filter-target="query" data-action="input->board-filter#filter">
        </div>
        <span data-board-filter-target="count" data-one="1 card" data-many="%count% cards"></span>
        <button data-board-view-target="boardButton" aria-pressed="true"></button>
        <button data-board-view-target="listButton" aria-pressed="false"></button>
        <p data-board-filter-target="empty" hidden></p>
        <div data-board-view-target="board">
            <article data-board-filter-target="card" data-card-title="Fix login"></article>
            <article data-board-filter-target="card" data-card-title="Write docs"></article>
        </div>
        <div data-board-view-target="list" hidden>
            <a data-board-filter-target="row" data-card-title="Fix login"></a>
            <a data-board-filter-target="row" data-card-title="Write docs"></a>
        </div>
    </div>`;
}

async function mount() {
    document.body.innerHTML = `<turbo-frame id="board-frame">${boardHtml()}</turbo-frame>`;
    await Promise.resolve();
    return document.querySelector('turbo-frame');
}

async function rerender(frame, eventType = 'turbo:before-frame-render') {
    frame.dispatchEvent(new CustomEvent(eventType, { bubbles: true }));
    frame.innerHTML = boardHtml();
    await Promise.resolve();
}

async function replaceBoard() {
    const board = document.getElementById('board');
    board.dispatchEvent(
        new CustomEvent('turbo:before-stream-render', { bubbles: true }),
    );
    const template = document.createElement('template');
    template.innerHTML = boardHtml();
    board.replaceWith(template.content.firstElementChild);
    await Promise.resolve();
}

function spyOnScrollIntoView() {
    return vi.spyOn(Element.prototype, 'scrollIntoView');
}

async function type(text) {
    const input = document.querySelector('[data-board-filter-target="query"]');
    input.value = text;
    input.dispatchEvent(new Event('input'));
    await Promise.resolve();
    return input;
}

function visibleTitles(selector) {
    return [...document.querySelectorAll(selector)]
        .filter((element) => !element.hidden)
        .map((element) => element.dataset.cardTitle);
}

it('keeps the filter query across a re-render of the board', async () => {
    const frame = await mount();
    await type('login');

    await rerender(frame);

    const input = document.querySelector('[data-board-filter-target="query"]');
    expect(input.value).toBe('login');
    expect(visibleTitles('[data-board-filter-target="card"]')).toEqual([
        'Fix login',
    ]);
    expect(visibleTitles('[data-board-filter-target="row"]')).toEqual([
        'Fix login',
    ]);
    expect(
        document.querySelector('[data-board-filter-target="count"]')
            .textContent,
    ).toBe('1 card');
});

it('restores focus and caret when the search input had focus', async () => {
    const frame = await mount();
    const input = await type('login');
    input.focus();
    input.setSelectionRange(2, 4);

    await rerender(frame, 'turbo:before-stream-render');

    const newInput = document.querySelector(
        '[data-board-filter-target="query"]',
    );
    expect(newInput).not.toBe(input);
    expect(document.activeElement).toBe(newInput);
    expect([newInput.selectionStart, newInput.selectionEnd]).toEqual([2, 4]);
});

it('leaves focus alone when the search input did not have focus', async () => {
    const frame = await mount();
    await type('login');
    const outside = document.createElement('input');
    document.body.append(outside);
    outside.focus();

    await rerender(frame);

    expect(document.activeElement).toBe(outside);
});

it('does not scroll to the search input when it restores focus', async () => {
    const frame = await mount();
    const input = await type('login');
    input.focus();
    const scrollIntoView = spyOnScrollIntoView();

    await rerender(frame, 'turbo:before-stream-render');

    expect(document.activeElement).not.toBe(input);
    expect(document.activeElement.dataset.boardFilterTarget).toBe('query');
    expect(scrollIntoView).not.toHaveBeenCalled();
});

it('scrolls to the search input when the user focuses it', async () => {
    await mount();
    const scrollIntoView = spyOnScrollIntoView();

    document.querySelector('[data-board-filter-target="query"]').focus();

    expect(scrollIntoView).toHaveBeenCalledOnce();
});

it('keeps the query and the list view when a stream replaces the board', async () => {
    await mount();
    await type('login');
    application
        .getControllerForElementAndIdentifier(
            document.getElementById('board'),
            'board-view',
        )
        .showList();
    const oldBoard = document.getElementById('board');

    await replaceBoard();

    expect(document.getElementById('board')).not.toBe(oldBoard);
    expect(
        document.querySelector('[data-board-filter-target="query"]').value,
    ).toBe('login');
    expect(visibleTitles('[data-board-filter-target="row"]')).toEqual([
        'Fix login',
    ]);
    expect(
        document.querySelector('[data-board-view-target="list"]').hidden,
    ).toBe(false);
});

it('keeps the list view across a re-render of the board', async () => {
    const frame = await mount();
    application
        .getControllerForElementAndIdentifier(
            document.getElementById('board'),
            'board-view',
        )
        .showList();

    await rerender(frame);

    expect(
        document.querySelector('[data-board-view-target="board"]').hidden,
    ).toBe(true);
    expect(
        document.querySelector('[data-board-view-target="list"]').hidden,
    ).toBe(false);
    expect(
        document
            .querySelector('[data-board-view-target="listButton"]')
            .getAttribute('aria-pressed'),
    ).toBe('true');
});

it('starts a new board frame with no query and the board view', async () => {
    const frame = await mount();
    await type('login');
    application
        .getControllerForElementAndIdentifier(
            document.getElementById('board'),
            'board-view',
        )
        .showList();
    frame.remove();
    await Promise.resolve();

    await mount();

    expect(
        document.querySelector('[data-board-filter-target="query"]').value,
    ).toBe('');
    expect(
        document.querySelector('[data-board-view-target="list"]').hidden,
    ).toBe(true);
});
