/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import BoardFilterController from '../../assets/controllers/board_filter_controller.js';
import BoardViewController from '../../assets/controllers/board_view_controller.js';

let application;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

function card(id, title) {
    return `<article id="board-card-${id}" data-board-filter-target="card" data-card-title="${title}"></article>`;
}

beforeEach(async () => {
    document.body.innerHTML = `<div id="board" data-controller="board-filter board-view"
            data-action="turbo:frame-render@document->board-filter#filter board:placed@document->board-filter#filter turbo:frame-render@document->board-view#restore">
        <input data-board-filter-target="query">
        <span data-board-filter-target="count" data-one="1 card" data-many="%count% cards">2 cards</span>
        <button data-board-view-target="boardButton" aria-pressed="true"></button>
        <button data-board-view-target="listButton" aria-pressed="false"></button>
        <p data-board-filter-target="empty" hidden></p>
        <div id="columns" data-board-view-target="board">${card('a', 'Alpha')}${card('b', 'Beta')}</div>
        <div id="list" data-board-view-target="list" hidden></div>
    </div>`;
    application = Application.start();
    application.register('board-filter', BoardFilterController);
    application.register('board-view', BoardViewController);
    await settle();
});

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
});

const query = () =>
    document.querySelector('[data-board-filter-target="query"]');
const count = () =>
    document.querySelector('[data-board-filter-target="count"]');

async function search(text) {
    query().value = text;
    const controller = application.getControllerForElementAndIdentifier(
        document.getElementById('board'),
        'board-filter',
    );
    controller.filter();
    await settle();
}

it('applies the query to a card that arrives later', async () => {
    await search('alp');
    document
        .getElementById('columns')
        .insertAdjacentHTML('beforeend', card('c', 'Gamma'));
    await settle();

    expect(document.getElementById('board-card-c').hidden).toBe(true);
    expect(count().textContent).toBe('1 card');
});

it('applies the query again after a card is placed', async () => {
    await search('alp');
    const beta = document.getElementById('board-card-b');
    beta.hidden = false;
    beta.dispatchEvent(new CustomEvent('board:placed', { bubbles: true }));

    expect(beta.hidden).toBe(true);
});

it('applies the query again after the frame renders', async () => {
    await search('alp');
    document.getElementById('board-card-b').hidden = false;
    document.dispatchEvent(new CustomEvent('turbo:frame-render'));

    expect(document.getElementById('board-card-b').hidden).toBe(true);
});

it('keeps the list view after the frame renders', async () => {
    const controller = application.getControllerForElementAndIdentifier(
        document.getElementById('board'),
        'board-view',
    );
    controller.showList();
    document.getElementById('columns').hidden = false;
    document.getElementById('list').hidden = true;
    document.dispatchEvent(new CustomEvent('turbo:frame-render'));

    expect(document.getElementById('columns').hidden).toBe(true);
    expect(document.getElementById('list').hidden).toBe(false);
});

it('restores the list view when the board connects under a kept toolbar', async () => {
    const board = document.getElementById('board');
    board
        .querySelector('[data-board-view-target="listButton"]')
        .setAttribute('aria-pressed', 'true');
    board
        .querySelector('[data-board-view-target="boardButton"]')
        .setAttribute('aria-pressed', 'false');
    const copy = board.cloneNode(true);
    board.replaceWith(copy);
    await settle();

    expect(document.getElementById('columns').hidden).toBe(true);
    expect(document.getElementById('list').hidden).toBe(false);
});
