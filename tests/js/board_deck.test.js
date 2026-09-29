/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import BoardDeckController from '../../assets/controllers/board_deck_controller.js';
import { fanLayout } from '../../assets/lib/deck_fan.js';

describe('fanLayout', () => {
    it('shows every card when the fan has room for them all', () => {
        expect(fanLayout(5, 5, 5)).toEqual({ shown: 5, more: 0 });
    });

    it('ends a fan with too little room on a tile that counts the rest', () => {
        expect(fanLayout(5, 8, 12)).toEqual({ shown: 4, more: 8 });
    });

    it('counts the Backlog cards the deck did not load', () => {
        expect(fanLayout(12, 8, 12)).toEqual({ shown: 8, more: 4 });
    });

    it('always shows the top card, however narrow the row', () => {
        expect(fanLayout(0, 3, 3)).toEqual({ shown: 1, more: 2 });
        expect(fanLayout(1, 1, 1)).toEqual({ shown: 1, more: 0 });
    });
});

let application;

beforeEach(() => {
    globalThis.ResizeObserver ??= class {
        observe() {}
        disconnect() {}
    };
    application = Application.start();
    application.register('board-deck', BoardDeckController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await new Promise((resolve) => setTimeout(resolve, 0));
    application.stop();
});

function deckMarkup(loaded, total) {
    const cards = Array.from(
        { length: loaded },
        (unused, index) =>
            `<article class="lp-deck__card" data-deck-index="${index}" data-board-deck-target="card"></article>`,
    ).join('');

    return `<header class="lp-board-lane__head">
        <button class="lp-board-lane__collapse"></button>
        <div class="lp-deck" data-controller="board-deck"
             data-board-deck-count-value="${total}"
             data-board-deck-more-value="+%count% more">
            ${cards}
            <a class="lp-deck__card lp-deck__more" data-board-deck-target="more"${total > loaded ? '' : ' hidden'}>+${total - loaded} more</a>
        </div>
    </header>`;
}

/** jsdom lays nothing out, so each test says how much room the fan has. */
function giveRoom(slots) {
    const slot = 222;
    const deck = document.querySelector('.lp-deck');
    deck.getBoundingClientRect = () => ({ left: 1000 - 212, right: 1000 });
    document.querySelector('.lp-board-lane__collapse').getBoundingClientRect =
        () => ({ left: 0, right: 1000 - slots * slot - 8 + 10 });
    document.querySelectorAll('.lp-deck__card').forEach((card) => {
        Object.defineProperty(card, 'offsetWidth', { value: 212 });
    });
}

async function mount(loaded, total, slots) {
    document.body.innerHTML = deckMarkup(loaded, total);
    giveRoom(slots);
    await new Promise((resolve) => setTimeout(resolve, 0));
    const controller = application.getControllerForElementAndIdentifier(
        document.querySelector('.lp-deck'),
        'board-deck',
    );
    controller.layout();
}

it('hides the cards that do not fit and shifts the fan for the tile', async () => {
    await mount(8, 12, 5);

    const spare = [...document.querySelectorAll('.lp-deck__card--spare')].map(
        (card) => card.dataset.deckIndex,
    );
    const more = document.querySelector('.lp-deck__more');
    expect(spare).toEqual(['4', '5', '6', '7']);
    expect(more.hidden).toBe(false);
    expect(
        document
            .querySelector('.lp-deck')
            .style.getPropertyValue('--deck-shift'),
    ).toBe('1');
    expect(more.textContent).toBe('+8 more');
    expect(
        document
            .querySelector('.lp-deck')
            .style.getPropertyValue('--deck-reach'),
    ).toBe(`${5 * 222 - 10}px`);
});

it('shows every card and no tile when they all fit', async () => {
    await mount(5, 5, 6);

    expect(document.querySelectorAll('.lp-deck__card--spare')).toHaveLength(0);
    expect(document.querySelector('.lp-deck__more').hidden).toBe(true);
    expect(
        document
            .querySelector('.lp-deck')
            .style.getPropertyValue('--deck-shift'),
    ).toBe('0');
});

it('fits the fan again after a morph resets the deck to the server markup', async () => {
    await mount(8, 12, 5);
    const deck = document.querySelector('.lp-deck');
    deck.removeAttribute('style');

    deck.dispatchEvent(
        new CustomEvent('turbo:morph-element', { bubbles: true }),
    );

    expect(deck.style.getPropertyValue('--deck-reach')).toBe(
        `${5 * 222 - 10}px`,
    );
});
