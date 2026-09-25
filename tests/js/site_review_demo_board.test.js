/** @vitest-environment jsdom */
import { afterEach, beforeAll, describe, expect, it } from 'vitest';
import {
    bootWidget,
    panelRoot,
    resetWidget,
    settle,
} from './support/widget_harness.js';

/**
 * The landing page runs the widget over itself with `data-demo` and no token,
 * against an in-memory transport that never reaches a server. So `board.enabled`
 * cannot reach it, and the demo carries a board of its own.
 *
 * Before that board existed, every /api/board/ path fell through to the comment
 * store: the picker listed nothing, and creating a card pushed a comment and
 * used its id as the marker, producing `card:undefined`.
 */
function openComposer() {
    const root = panelRoot();
    root.getElementById('lp-launch-main').click();
    root.getElementById('general').click();

    return root;
}

function chooseMode(root, mode) {
    root.querySelector(`#lp-picker-modes [data-mode="${mode}"]`).click();
}

async function write(root, text) {
    const textarea = root.getElementById('lp-textarea');
    textarea.value = text;
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
}

const cardRows = (root) => [
    ...root.querySelectorAll('#lp-picker-list .lp-picker-row'),
];

let originalHistory;

beforeAll(() => {
    originalHistory = {
        pushState: window.history.pushState,
        replaceState: window.history.replaceState,
    };
});

afterEach(() => resetWidget(originalHistory));

describe('the demo board', () => {
    it('asks where notes go before the first one, without a server', async () => {
        const fetchMock = bootWidget({ demo: true });
        await settle();

        const root = openComposer();
        await settle();

        expect(root.getElementById('lp-picker').style.display).toBe('block');
        expect(
            [...root.querySelectorAll('#lp-picker-modes [data-mode]')].map(
                (button) => button.dataset.mode,
            ),
        ).toEqual(['per-note', 'per-review', 'epic']);
        expect(root.getElementById('lp-save').disabled).toBe(true);
        // The whole point of demo mode: it reaches no network at all.
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('offers open cards for one review card, and narrows them', async () => {
        bootWidget({ demo: true });
        await settle();

        const root = openComposer();
        chooseMode(root, 'per-review');
        await settle();
        expect(cardRows(root)[0].textContent).toContain('Checkout button');

        const search = root.getElementById('lp-picker-search');
        search.value = 'pricing';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 300));
        await settle();

        expect(cardRows(root)).toHaveLength(1);
        expect(cardRows(root)[0].textContent).toContain('Pricing table');
    });

    it('offers epics alone in epic mode', async () => {
        bootWidget({ demo: true });
        await settle();

        const root = openComposer();
        chooseMode(root, 'epic');
        await settle();

        expect(cardRows(root).map((row) => row.textContent)).toEqual([
            '#4Launch review',
        ]);
    });

    it('creates a review card from the prefilled title', async () => {
        bootWidget({ demo: true });
        await settle();

        const root = openComposer();
        chooseMode(root, 'per-review');
        await settle();
        expect(root.getElementById('lp-picker-title').value).toBe('Review: /');

        root.getElementById('lp-picker-create').click();
        await settle();

        const label = root.querySelector('.lp-context-label').textContent;
        // The number is the demo board's own, and the title is the prefill.
        expect(label).toBe('#5 Review: /');
        // The bug this replaced produced exactly this string.
        expect(label).not.toContain('undefined');
        expect(root.getElementById('lp-picker').style.display).toBe('none');
    });

    it('saves a note as its own card and names that card', async () => {
        const fetchMock = bootWidget({ demo: true });
        await settle();

        const root = openComposer();
        chooseMode(root, 'per-note');
        await settle();
        await write(root, 'Dark mode please\nThe footer glares at night.');
        root.getElementById('lp-save').click();
        await settle();

        expect(root.getElementById('lp-last-card').textContent).toBe(
            'Saved as #5 Dark mode please',
        );
        expect(root.getElementById('lp-head-count').textContent).toBe('1');
        expect(fetchMock).not.toHaveBeenCalled();
        // The next visit asks again, because the demo keeps nothing.
        expect(window.localStorage.length).toBe(0);
    });
});
