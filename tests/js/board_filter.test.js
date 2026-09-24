/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import BoardFilterController from '../../assets/controllers/board_filter_controller.js';

let application;

beforeEach(() => {
    application = Application.start();
    application.register('board-filter', BoardFilterController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await new Promise((resolve) => setTimeout(resolve, 0));
    application.stop();
});

async function mount() {
    document.body.innerHTML = `<div data-controller="board-filter">
        <input data-board-filter-target="query" data-action="input->board-filter#filter">
        <span data-board-filter-target="count" data-one="1 card" data-many="%count% cards">2 cards</span>
        <p data-board-filter-target="empty" hidden>No card matches.</p>
        <section data-lane="epic-a">
            <header data-board-filter-target="laneHead" data-card-title="Checkout epic">#1 Checkout epic</header>
            <article data-board-filter-target="card" data-card-title="Pay with a card">Pay</article>
        </section>
        <section data-lane="other">
            <article data-board-filter-target="card" data-card-title="Fix the footer">Footer</article>
        </section>
    </div>`;
    await new Promise((resolve) => setTimeout(resolve, 0));
}

function search(text) {
    const query = document.querySelector('[data-board-filter-target="query"]');
    query.value = text;
    query.dispatchEvent(new Event('input', { bubbles: true }));
}

const count = () =>
    document.querySelector('[data-board-filter-target="count"]').textContent;
const empty = () =>
    document.querySelector('[data-board-filter-target="empty"]').hidden;

it('counts an epic whose lane title matches once and keeps its lane', async () => {
    await mount();

    search('checkout');

    expect(count()).toBe('1 card');
    expect(empty()).toBe(true);
    expect(document.querySelector('[data-lane="epic-a"]').hidden).toBe(false);
    expect(
        document.querySelector('[data-board-filter-target="laneHead"]').hidden,
    ).toBe(false);
    for (const card of document.querySelectorAll(
        '[data-board-filter-target="card"]',
    )) {
        expect(card.hidden).toBe(true);
    }
});

it('counts matching cards and a matching lane title together', async () => {
    await mount();

    search('c');

    expect(count()).toBe('2 cards');
});

it('keeps a lane header whose title does not match, and says when nothing matches', async () => {
    await mount();

    search('nothing like this');

    expect(count()).toBe('0 cards');
    expect(empty()).toBe(false);
    expect(
        document.querySelector('[data-board-filter-target="laneHead"]').hidden,
    ).toBe(false);
});
