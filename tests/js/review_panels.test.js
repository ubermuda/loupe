/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import ReviewPanelsController from '../../assets/controllers/review_panels_controller.js';

const STORAGE_KEY = 'loupe.review.panels';
let application;

beforeEach(() => {
    window.localStorage.clear();
    application = Application.start();
    application.register('review-panels', ReviewPanelsController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await new Promise((resolve) => setTimeout(resolve, 0));
    application.stop();
    window.localStorage.clear();
});

function button(name, { pressed = false, disabled = false } = {}) {
    return `<button type="button" data-review-panels-target="button"
        data-review-panels-name-param="${name}"
        data-action="review-panels#toggle"
        aria-pressed="${pressed}"${disabled ? ' aria-disabled="true"' : ''}>${name}</button>`;
}

async function mount({
    decisionsDisabled = false,
    hideResolved = false,
    mode = null,
} = {}) {
    document.body.innerHTML = `<div data-controller="review-panels" data-action="comment-anchor:reveal->review-panels#reveal"${hideResolved ? ' class="lp-review-block--hide-resolved"' : ''}${mode ? ` data-review-panels-mode-value="${mode}"` : ''}>
        ${button('decisions', { pressed: !decisionsDisabled, disabled: decisionsDisabled })}
        ${button('comments')}
        ${button('outline')}
        <section data-review-panels-target="panel" data-review-panel="decisions"${decisionsDisabled ? ' hidden' : ''}>Decisions</section>
        <section data-review-panels-target="panel" data-review-panel="comments" hidden>
            <details data-review-panels-target="filter">
                <summary>Filter</summary>
                <button type="button" data-review-panels-target="option" data-review-panels-filter-param="open" data-action="review-panels#filter">Open <span data-filter-count>0</span></button>
                <button type="button" data-review-panels-target="option" data-review-panels-filter-param="resolved" data-action="review-panels#filter">Resolved <span data-filter-count>0</span></button>
                <button type="button" data-review-panels-target="option" data-review-panels-filter-param="unanchored" data-action="review-panels#filter">Unanchored <span data-filter-count>0</span></button>
                <button type="button" data-review-panels-target="option" data-review-panels-filter-param="all" data-action="review-panels#filter">All <span data-filter-count>0</span></button>
            </details>
            <div data-review-panels-target="empty" hidden>
                <p data-empty-filter="all">No comments</p>
                <p data-empty-filter="filtered">None match</p>
            </div>
            <div class="lp-orphan-group" id="orphan-group">
                <button type="button" class="lp-comment-row" data-review-panels-target="thread" data-anchor-status="addressed" data-comment-orphaned="true" id="orphan-thread"></button>
            </div>
            <button type="button" class="lp-comment-row" data-review-panels-target="thread" data-anchor-status="pending" id="open-thread"></button>
            <button type="button" class="lp-comment-row" data-review-panels-target="thread" data-anchor-status="resolved" id="resolved-thread"></button>
        </section>
        <section data-review-panels-target="panel" data-review-panel="outline" hidden>Outline</section>
    </div>`;
    await new Promise((resolve) => setTimeout(resolve, 0));
    return document.querySelector('[data-controller="review-panels"]');
}

const buttonFor = (name) =>
    document.querySelector(`[data-review-panels-name-param="${name}"]`);
const panelFor = (name) =>
    document.querySelector(`[data-review-panel="${name}"]`);
const openPanels = () =>
    ['decisions', 'comments', 'outline'].filter(
        (name) =>
            !panelFor(name).hidden &&
            buttonFor(name).getAttribute('aria-pressed') === 'true',
    );

it('keeps the server default when nothing is stored', async () => {
    await mount();
    expect(openPanels()).toEqual(['decisions']);
    expect(buttonFor('comments').getAttribute('aria-pressed')).toBe('false');
    expect(panelFor('outline').hidden).toBe(true);
});

it('switches a panel on and off from its button', async () => {
    await mount();
    buttonFor('comments').click();
    expect(openPanels()).toEqual(['decisions', 'comments']);
    buttonFor('decisions').click();
    expect(openPanels()).toEqual(['comments']);
    expect(panelFor('decisions').hidden).toBe(true);
    expect(buttonFor('decisions').getAttribute('aria-pressed')).toBe('false');
});

it('remembers the open panels in a fixed order', async () => {
    await mount();
    buttonFor('outline').click();
    buttonFor('comments').click();
    expect(JSON.parse(window.localStorage.getItem(STORAGE_KEY))).toEqual([
        'decisions',
        'comments',
        'outline',
    ]);
    buttonFor('decisions').click();
    expect(JSON.parse(window.localStorage.getItem(STORAGE_KEY))).toEqual([
        'comments',
        'outline',
    ]);
});

it('restores the stored panels on connect', async () => {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(['outline']));
    await mount();
    expect(openPanels()).toEqual(['outline']);
    expect(panelFor('decisions').hidden).toBe(true);
});

it('ignores a stored value it cannot read', async () => {
    window.localStorage.setItem(STORAGE_KEY, '{not json');
    await mount();
    expect(openPanels()).toEqual(['decisions']);
});

it('never opens a disabled panel and keeps its stored choice for other pages', async () => {
    window.localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify(['decisions', 'outline']),
    );
    await mount({ decisionsDisabled: true });
    expect(openPanels()).toEqual(['outline']);

    buttonFor('decisions').click();
    expect(panelFor('decisions').hidden).toBe(true);
    expect(buttonFor('decisions').getAttribute('aria-pressed')).toBe('false');

    buttonFor('comments').click();
    expect(JSON.parse(window.localStorage.getItem(STORAGE_KEY))).toEqual([
        'decisions',
        'comments',
        'outline',
    ]);
});

it('counts the threads per filter and shows the open ones when resolved are hidden', async () => {
    await mount({ hideResolved: true });
    const counts = Object.fromEntries(
        [
            ...document.querySelectorAll(
                '[data-review-panels-target="option"]',
            ),
        ].map((option) => [
            option.dataset.reviewPanelsFilterParam,
            option.querySelector('[data-filter-count]').textContent,
        ]),
    );
    expect(counts).toEqual({
        open: '2',
        resolved: '1',
        unanchored: '1',
        all: '3',
    });
    expect(document.getElementById('resolved-thread').hidden).toBe(true);
    expect(document.getElementById('open-thread').hidden).toBe(false);
});

it('filters the threads and tells the comment controller', async () => {
    const element = await mount();
    const events = [];
    element.addEventListener('review-panels:filter', (event) =>
        events.push(event.detail.filter),
    );
    document
        .querySelector('[data-review-panels-filter-param="unanchored"]')
        .click();
    expect(events).toEqual(['unanchored']);
    expect(document.getElementById('orphan-thread').hidden).toBe(false);
    expect(document.getElementById('open-thread').hidden).toBe(true);
    expect(
        document
            .querySelector('[data-review-panels-filter-param="unanchored"]')
            .getAttribute('aria-pressed'),
    ).toBe('true');
});

it('opens Comments and remembers it when the comment controller reveals a thread', async () => {
    const element = await mount();
    element.dispatchEvent(
        new CustomEvent('comment-anchor:reveal', { bubbles: true }),
    );
    expect(openPanels()).toEqual(['decisions', 'comments']);
    expect(JSON.parse(window.localStorage.getItem(STORAGE_KEY))).toEqual([
        'decisions',
        'comments',
    ]);

    element.dispatchEvent(
        new CustomEvent('comment-anchor:reveal', { bubbles: true }),
    );
    expect(openPanels()).toEqual(['decisions', 'comments']);
});

it('shows every thread when the active filter hides the revealed one', async () => {
    const element = await mount({ hideResolved: true });
    const events = [];
    element.addEventListener('review-panels:filter', (event) =>
        events.push(event.detail.filter),
    );
    const thread = document.getElementById('resolved-thread');
    expect(thread.hidden).toBe(true);
    element.dispatchEvent(
        new CustomEvent('comment-anchor:reveal', {
            bubbles: true,
            detail: { thread },
        }),
    );
    expect(thread.hidden).toBe(false);
    expect(events).toEqual(['all']);
});

it('shows a new thread that the active filter would hide', async () => {
    const element = await mount();
    document
        .querySelector('[data-review-panels-filter-param="resolved"]')
        .click();
    const thread = document.createElement('article');
    thread.dataset.anchorStatus = 'pending';
    element.dispatchEvent(
        new CustomEvent('comment-anchor:reveal', {
            bubbles: true,
            detail: { thread },
        }),
    );
    const all = document.querySelector(
        '[data-review-panels-filter-param="all"]',
    );
    expect(all.getAttribute('aria-pressed')).toBe('true');
});

it('keeps the filter when the revealed thread already shows', async () => {
    const element = await mount({ hideResolved: true });
    const events = [];
    element.addEventListener('review-panels:filter', (event) =>
        events.push(event.detail.filter),
    );
    element.dispatchEvent(
        new CustomEvent('comment-anchor:reveal', {
            bubbles: true,
            detail: { thread: document.getElementById('open-thread') },
        }),
    );
    expect(events).toEqual([]);
    expect(document.getElementById('resolved-thread').hidden).toBe(true);
});

it('keeps a disabled Comments panel closed on a reveal', async () => {
    const element = await mount();
    buttonFor('comments').setAttribute('aria-disabled', 'true');
    element.dispatchEvent(
        new CustomEvent('comment-anchor:reveal', { bubbles: true }),
    );
    expect(panelFor('comments').hidden).toBe(true);
    expect(window.localStorage.getItem(STORAGE_KEY)).toBeNull();
});

it('hides a group whose rows the filter hides', async () => {
    await mount();
    const group = document.getElementById('orphan-group');
    expect(group.hidden).toBe(false);

    document
        .querySelector('[data-review-panels-filter-param="resolved"]')
        .click();
    expect(group.hidden).toBe(true);

    document
        .querySelector('[data-review-panels-filter-param="unanchored"]')
        .click();
    expect(group.hidden).toBe(false);
});

it('opens the outline while comparing and keeps the stored choice', async () => {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(['comments']));
    await mount({ mode: 'compare' });
    expect(openPanels()).toEqual(['comments', 'outline']);

    buttonFor('decisions').click();
    buttonFor('outline').click();
    expect(openPanels()).toEqual(['decisions', 'comments']);
    expect(JSON.parse(window.localStorage.getItem(STORAGE_KEY))).toEqual([
        'comments',
    ]);
});

it('starts the columns with every panel hidden and stores nothing', async () => {
    window.localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify(['decisions', 'outline']),
    );
    await mount({ mode: 'columns' });
    expect(openPanels()).toEqual([]);

    buttonFor('outline').click();
    expect(openPanels()).toEqual(['outline']);
    expect(JSON.parse(window.localStorage.getItem(STORAGE_KEY))).toEqual([
        'decisions',
        'outline',
    ]);
});

it('stores nothing when a comparison reveals the comments', async () => {
    await mount({ mode: 'compare' });
    document
        .querySelector('[data-controller="review-panels"]')
        .dispatchEvent(new CustomEvent('comment-anchor:reveal'));
    expect(openPanels()).toEqual(['decisions', 'comments', 'outline']);
    expect(window.localStorage.getItem(STORAGE_KEY)).toBeNull();
});

it('keeps the Comments button count, its label and the filter summary in step with the threads', async () => {
    const element = await mount({ hideResolved: true });
    const comments = buttonFor('comments');
    comments.dataset.labelTemplate = 'Comments panel, %count% open';
    comments.insertAdjacentHTML(
        'beforeend',
        '<span data-review-panels-target="openCount">9</span>',
    );
    for (const option of element.querySelectorAll(
        '[data-review-panels-target="option"]',
    )) {
        option.dataset.filterLabel = option.dataset.reviewPanelsFilterParam;
    }
    element
        .querySelector('[data-review-panels-target="filter"] summary')
        .insertAdjacentHTML(
            'beforeend',
            '<span data-review-panels-target="filterLabel"></span>',
        );
    await new Promise((resolve) => setTimeout(resolve, 0));

    element.querySelector('#open-thread').remove();
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(
        comments.querySelector('[data-review-panels-target="openCount"]')
            .textContent,
    ).toBe('1');
    expect(comments.getAttribute('aria-label')).toBe('Comments panel, 1 open');
    expect(
        element.querySelector('[data-review-panels-target="filterLabel"]')
            .textContent,
    ).toBe('open · 1');
});

it('hangs the filter menu from its toggle in the window, so no panel clips it', async () => {
    document.body.innerHTML = `<div data-controller="review-panels">
        <details data-review-panels-target="filter" data-action="toggle->review-panels#placeFilter">
            <summary>Filter</summary>
            <div data-review-panels-target="filterMenu"></div>
        </details>
    </div>`;
    await new Promise((resolve) => setTimeout(resolve, 0));
    const filter = document.querySelector('details');
    const menu = document.querySelector(
        '[data-review-panels-target="filterMenu"]',
    );
    filter.querySelector('summary').getBoundingClientRect = () => ({
        top: 100,
        bottom: 126,
        left: 300,
        right: 400,
    });
    menu.getBoundingClientRect = () => ({
        top: 0,
        left: 0,
        width: 200,
        height: 150,
    });

    filter.open = true;
    filter.dispatchEvent(new Event('toggle'));

    expect(menu.style.left).toBe('200px');
    expect(menu.style.top).toBe('132px');
    expect(menu.hasAttribute('data-placed')).toBe(true);

    window.dispatchEvent(new Event('scroll'));

    expect(filter.open).toBe(false);
});
