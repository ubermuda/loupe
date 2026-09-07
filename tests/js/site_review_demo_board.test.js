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

let originalHistory;

beforeAll(() => {
    originalHistory = {
        pushState: window.history.pushState,
        replaceState: window.history.replaceState,
    };
});

afterEach(() => resetWidget(originalHistory));

describe('the demo board', () => {
    it('offers cards to attach to, without a server', async () => {
        const fetchMock = bootWidget({ demo: true });
        await settle();

        const root = openComposer();
        root.querySelector('.lp-context-label').click();
        await settle();

        const rows = [...root.querySelectorAll('.lp-picker-row')];
        expect(rows.length).toBeGreaterThan(0);
        expect(rows[0].textContent).toContain('Checkout button');
        // The whole point of demo mode: it reaches no network at all.
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('narrows the list by what the reviewer typed', async () => {
        bootWidget({ demo: true });
        await settle();

        const root = openComposer();
        root.querySelector('.lp-context-label').click();
        await settle();

        const search = root.getElementById('lp-picker-search');
        search.value = 'pricing';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 300));
        await settle();

        const rows = [...root.querySelectorAll('.lp-picker-row')];
        expect(rows).toHaveLength(1);
        expect(rows[0].textContent).toContain('Pricing table');
    });

    it('creates a card and attaches it, rather than a phantom comment', async () => {
        bootWidget({ demo: true });
        await settle();

        const root = openComposer();
        root.querySelector('.lp-context-label').click();
        await settle();

        root.getElementById('lp-picker-search').value = 'Dark mode please';
        root.getElementById('lp-picker-create').click();
        await settle();

        const label = root.querySelector('.lp-context-label').textContent;
        // The number is the demo board's own, and the title is what was typed.
        expect(label).toBe('#4 Dark mode please');
        // The bug this replaced produced exactly this string.
        expect(label).not.toContain('undefined');
    });

    it('lets a reviewer detach again', async () => {
        bootWidget({ demo: true });
        await settle();

        const root = openComposer();
        root.querySelector('.lp-context-label').click();
        await settle();
        root.querySelector('.lp-picker-row').click();
        await settle();
        expect(root.querySelector('.lp-context-label').textContent).toContain(
            'Checkout button',
        );

        root.querySelector('.lp-context-label').click();
        await settle();
        root.getElementById('lp-picker-detach').click();
        await settle();

        expect(root.querySelector('.lp-context-label').textContent).toBe(
            'Attach to a card',
        );
    });
});
