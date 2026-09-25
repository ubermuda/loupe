/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import BoardDragController from '../../assets/controllers/board_drag_controller.js';

let application;

beforeEach(() => {
    application = Application.start();
    application.register('board-drag', BoardDragController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await new Promise((resolve) => setTimeout(resolve, 0));
    application.stop();
});

/** Every group covers the same box, so only the lane rules can tell them apart. */
async function mount(groups) {
    document.body.innerHTML = `<div data-controller="board-drag">${groups}</div>`;
    for (const group of document.querySelectorAll(
        '[data-board-drag-target="group"]',
    )) {
        group.getBoundingClientRect = () => ({
            left: 0,
            right: 100,
            top: 0,
            bottom: 100,
        });
    }
    await new Promise((resolve) => setTimeout(resolve, 0));

    return application.getControllerForElementAndIdentifier(
        document.querySelector('[data-controller="board-drag"]'),
        'board-drag',
    );
}

function card(id, type = 'feature') {
    return `<article data-board-drag-target="card" data-card-id="${id}" data-card-type="${type}">
        <form data-board-drag-target="moveForm">
            <select name="move[column]"><option value="c1">c1</option><option value="c2">c2</option></select>
            <input name="move[position]">
            <input type="hidden" name="move[parent]">
            <input type="hidden" name="move[beforeCardId]">
            <input type="hidden" name="move[afterCardId]">
        </form>
    </article>`;
}

/** Starts a drag of the card from its group, with the drop marker nowhere yet. */
function drag(controller, cardId) {
    const element = document.querySelector(`[data-card-id="${cardId}"]`);
    controller.pressedCard = element;
    controller.draggedCard = element;
    controller.originGroup = element.closest(
        '[data-board-drag-target="group"]',
    );
    controller.placeholder = document.createElement('div');
    controller.placeholder.className = 'lp-board__placeholder';

    return element;
}

const LANES = `
    <div id="lane-a-c1" data-board-drag-target="group" data-lane="epic-a" data-column="c1" data-rankable="1">
        ${card('mover')}${card('a1')}
    </div>
    <div id="lane-b-c2" data-board-drag-target="group" data-lane="epic-b" data-column="c2" data-rankable="1">
        ${card('b1')}${card('b2')}
    </div>
    <div id="lane-b-c1" data-board-drag-target="group" data-lane="epic-b" data-column="c1" data-rankable="1"></div>
    <div id="other-c1" data-board-drag-target="group" data-lane="other" data-column="c1" data-rankable="1">
        ${card('lone-epic', 'epic')}${card('o1')}
    </div>`;

it('offers every cell of every lane to a card that is not an epic', async () => {
    const controller = await mount(LANES);
    drag(controller, 'mover');

    expect(controller.groupUnder(50, 50)).toBe(
        document.getElementById('lane-a-c1'),
    );
    expect(
        controller.groupTargets.filter((group) => controller.accepts(group))
            .length,
    ).toBe(4);
});

it('offers an epic card the cells of "Other cards" only', async () => {
    const controller = await mount(LANES);
    drag(controller, 'lone-epic');

    expect(
        controller.groupTargets.filter((group) => controller.accepts(group)),
    ).toEqual([document.getElementById('other-c1')]);
    expect(controller.groupUnder(50, 50)).toBe(
        document.getElementById('other-c1'),
    );
});

it('offers no cell of a collapsed lane, until a search reveals it', async () => {
    const controller = await mount(`
        <section class="lp-board-lane lp-board-lane--collapsed">${LANES}</section>`);
    drag(controller, 'mover');

    expect(
        controller.groupTargets.filter((group) => controller.accepts(group)),
    ).toEqual([]);

    document
        .querySelector('.lp-board-lane')
        .classList.add('lp-board-lane--revealed');
    expect(controller.groupUnder(50, 50)).toBe(
        document.getElementById('lane-a-c1'),
    );
});

it('offers every group of a board with no lanes', async () => {
    const controller = await mount(`
        <div id="first" data-board-drag-target="group" data-column="c1"></div>
        <div id="second" data-board-drag-target="group" data-column="c2"></div>`);
    controller.originGroup = document.getElementById('second');

    expect(controller.groupUnder(50, 50)).toBe(
        document.getElementById('first'),
    );
});

it('names the card below the drop and keeps the parent inside its own lane', async () => {
    const controller = await mount(LANES);
    drag(controller, 'mover');
    document.querySelector('[data-card-id="a1"]').after(controller.placeholder);
    const group = document.getElementById('lane-a-c1');

    expect(
        controller.lanePayload(group, controller.rankOfPlaceholder(group)),
    ).toEqual({ parent: '', beforeCardId: '', afterCardId: 'a1' });
});

it('sends the epic of another lane as the parent, with the card below as the neighbour', async () => {
    const controller = await mount(LANES);
    drag(controller, 'mover');
    document
        .querySelector('[data-card-id="b2"]')
        .before(controller.placeholder);
    const group = document.getElementById('lane-b-c2');

    expect(
        controller.lanePayload(group, controller.rankOfPlaceholder(group)),
    ).toEqual({ parent: 'epic-b', beforeCardId: 'b2', afterCardId: '' });
});

it('sends "none" for a drop in "Other cards", and no neighbour for an empty cell', async () => {
    const controller = await mount(LANES);
    drag(controller, 'mover');

    const other = document.getElementById('other-c1');
    other.prepend(controller.placeholder);
    expect(
        controller.lanePayload(other, controller.rankOfPlaceholder(other)),
    ).toEqual({ parent: 'none', beforeCardId: 'lone-epic', afterCardId: '' });

    const empty = document.getElementById('lane-b-c1');
    empty.append(controller.placeholder);
    expect(
        controller.lanePayload(empty, controller.rankOfPlaceholder(empty)),
    ).toEqual({ parent: 'epic-b', beforeCardId: '', afterCardId: '' });
});

it('keeps the parent of a card that moves inside "Other cards"', async () => {
    const controller = await mount(LANES);
    drag(controller, 'o1');
    const other = document.getElementById('other-c1');
    other.prepend(controller.placeholder);

    expect(
        controller.lanePayload(other, controller.rankOfPlaceholder(other)),
    ).toEqual({ parent: '', beforeCardId: 'lone-epic', afterCardId: '' });
});

it('fills the lane fields and leaves the rank empty on a board with lanes', async () => {
    const controller = await mount(LANES);
    const element = document.querySelector('[data-card-id="mover"]');
    const form = element.querySelector('form');
    form.requestSubmit = () => {};

    controller.submitMove(
        element,
        document.getElementById('lane-b-c2'),
        1,
        { group: null, before: null },
        { parent: 'epic-b', beforeCardId: 'b2', afterCardId: '' },
    );

    expect(form.querySelector('[name="move[column]"]').value).toBe('c2');
    expect(form.querySelector('[name="move[position]"]').value).toBe('');
    expect(form.querySelector('[name="move[parent]"]').value).toBe('epic-b');
    expect(form.querySelector('[name="move[beforeCardId]"]').value).toBe('b2');
    expect(form.querySelector('[name="move[afterCardId]"]').value).toBe('');
});

it('sends the rank alone on a board with no lanes', async () => {
    const controller = await mount(`
        <div id="plain" data-board-drag-target="group" data-column="c2" data-rankable="1">${card('mover')}</div>`);
    const element = document.querySelector('[data-card-id="mover"]');
    const form = element.querySelector('form');
    form.requestSubmit = () => {};
    form.querySelector('[name="move[parent]"]').value = 'stale';

    controller.submitMove(
        element,
        document.getElementById('plain'),
        2,
        { group: null, before: null },
        null,
    );

    expect(form.querySelector('[name="move[position]"]').value).toBe('2');
    expect(form.querySelector('[name="move[parent]"]').value).toBe('');
    expect(form.querySelector('[name="move[beforeCardId]"]').value).toBe('');
    expect(form.querySelector('[name="move[afterCardId]"]').value).toBe('');
});
