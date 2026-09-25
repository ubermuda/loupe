/** @vitest-environment jsdom */
import { afterEach, beforeAll, describe, expect, it } from 'vitest';
import {
    BACKEND,
    bootWidget,
    hostCount,
    modeKeyFor,
    ok,
    openPanel,
    panelRoot,
    rejected,
    resetWidget,
    settle,
    ACCESS_TOKEN,
} from './support/widget_harness.js';

let originalHistory;

beforeAll(() => {
    originalHistory = {
        pushState: window.history.pushState,
        replaceState: window.history.replaceState,
    };
});

afterEach(() => resetWidget(originalHistory));

/** The critical panel's detail line, which names the fix for this rejection. */
function fatalDetail() {
    openPanel();

    return panelRoot().getElementById('lp-fatal').querySelector('.lp-fatal-sub')
        .textContent;
}

/** Whether the collapsed launcher shows its rejected-token badge. */
function alerting() {
    return (
        panelRoot().getElementById('lp-launch-alert').style.display !== 'none'
    );
}

/** Opens the composer for a new page note, as the Note action does. */
function openNote() {
    const root = panelRoot();
    root.getElementById('lp-launch-main').click();
    root.getElementById('general').click();

    return root;
}

async function write(root, text) {
    const textarea = root.getElementById('lp-textarea');
    textarea.value = text;
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
}

/** The body of the one feedback save the widget sent. */
function feedbackSave(fetchMock) {
    const save = fetchMock.mock.calls.find(
        ([url, init]) =>
            init &&
            init.method === 'POST' &&
            url.endsWith('/api/board/feedback'),
    );

    return save ? JSON.parse(save[1].body) : null;
}

const storedMode = () =>
    JSON.parse(window.localStorage.getItem(modeKeyFor()) || 'null');

const targetText = (root) =>
    root.getElementById('lp-context').querySelector('.lp-context-label')
        .textContent;

const CARD = '01a0cafe-0000-7000-8000-000000000001';

describe('the mode', () => {
    it('is asked for before the first note, and the draft is kept', async () => {
        const fetchMock = bootWidget({
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        const root = openNote();
        await write(root, 'The hero image is blurry');

        expect(root.getElementById('lp-picker').style.display).toBe('block');
        expect(root.getElementById('lp-save').disabled).toBe(true);
        root.querySelector('[data-mode="per-note"]').click();
        await settle();

        expect(storedMode()).toEqual({ mode: 'per-note', cardId: null });
        expect(root.getElementById('lp-picker').style.display).toBe('none');
        expect(root.getElementById('lp-textarea').value).toBe(
            'The hero image is blurry',
        );
        expect(fetchMock.mock.calls[0][0]).toBe(
            `${BACKEND}/api/site-review/review`,
        );
    });

    it('per note sends a new card and names the card it became', async () => {
        const fetchMock = bootWidget({
            mode: { mode: 'per-note', cardId: null },
            respond: (url, init) =>
                init.method === 'POST'
                    ? ok({
                          commentId: 'c1',
                          cardId: CARD,
                          number: 12,
                          label: '#12 The hero image is blurry',
                          url: `${BACKEND}/projects/p/board/cards/${CARD}`,
                      })
                    : ok({ comments: [], context: null }),
        });
        await settle();

        const root = openNote();
        expect(targetText(root)).toBe('A new card for each note');
        await write(root, 'The hero image is blurry');
        root.getElementById('lp-save').click();
        await settle();

        expect(feedbackSave(fetchMock).target).toEqual({ newCard: {} });
        const last = root.getElementById('lp-last-card');
        expect(last.textContent).toBe('Saved as #12 The hero image is blurry');
        expect(last.querySelector('a').href).toBe(
            `${BACKEND}/projects/p/board/cards/${CARD}`,
        );
    });

    it('per review is remembered, checked at boot and sent by card id', async () => {
        const fetchMock = bootWidget({
            mode: { mode: 'per-review', cardId: CARD },
            respond: (url, init) =>
                init.method === 'POST'
                    ? ok({
                          commentId: 'c1',
                          cardId: CARD,
                          number: 3,
                          label: '#3 Review: /',
                          url: null,
                      })
                    : ok({
                          comments: [],
                          context: { label: '#3 Review: /', url: null },
                      }),
        });
        await settle();

        expect(fetchMock.mock.calls[0][0]).toBe(
            `${BACKEND}/api/site-review/review?context=${encodeURIComponent(`card:${CARD}`)}`,
        );
        const root = openNote();
        expect(targetText(root)).toBe('#3 Review: /');
        expect(root.getElementById('lp-picker').style.display).toBe('none');
        await write(root, 'Spacing is off');
        root.getElementById('lp-save').click();
        await settle();

        expect(feedbackSave(fetchMock).target).toEqual({ cardId: CARD });
        expect(root.getElementById('lp-last-card').style.display).toBe('none');
    });

    it('epic mode checks an epic and puts each note under it', async () => {
        const fetchMock = bootWidget({
            mode: { mode: 'epic', cardId: CARD },
            respond: (url, init) =>
                init.method === 'POST'
                    ? ok({
                          commentId: 'c1',
                          cardId: 'x',
                          number: 9,
                          label: '#9 Spacing',
                          url: null,
                      })
                    : ok({
                          comments: [],
                          context: { label: '#2 Launch review', url: null },
                      }),
        });
        await settle();

        expect(fetchMock.mock.calls[0][0]).toContain(
            encodeURIComponent(`epic:${CARD}`),
        );
        const root = openNote();
        expect(targetText(root)).toBe('Cards under #2 Launch review');
        await write(root, 'Spacing');
        root.getElementById('lp-save').click();
        await settle();

        expect(feedbackSave(fetchMock).target).toEqual({
            newCard: { parentCardId: CARD },
        });
    });

    it('is asked for again when the boot load refuses the stored card', async () => {
        bootWidget({
            mode: { mode: 'per-review', cardId: CARD },
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        expect(storedMode()).toBeNull();
        const root = openNote();
        expect(root.getElementById('lp-picker').style.display).toBe('block');
    });

    it('is kept when the board is off, which says nothing about the card', async () => {
        bootWidget({
            mode: { mode: 'per-review', cardId: CARD },
            respond: () =>
                ok({ comments: [], context: null, feedbackAvailable: false }),
        });
        await settle();

        expect(storedMode()).toEqual({ mode: 'per-review', cardId: CARD });
    });

    it('is asked for again, with the draft kept, when a save names a closed card', async () => {
        const fetchMock = bootWidget({
            mode: { mode: 'per-review', cardId: CARD },
            respond: () =>
                ok({
                    comments: [],
                    context: { label: '#3 Review: /', url: null },
                }),
        });
        await settle();

        const root = openNote();
        await write(root, 'Keep this text');
        fetchMock.mockImplementation(async () =>
            rejected(422, { error: 'target_closed' }),
        );
        root.getElementById('lp-save').click();
        await settle();

        expect(storedMode()).toBeNull();
        expect(root.getElementById('lp-picker').style.display).toBe('block');
        expect(root.getElementById('lp-textarea').value).toBe('Keep this text');
        expect(root.getElementById('lp-error').textContent).toContain(
            'Choose where your notes go',
        );
    });
});

describe('the delivery id', () => {
    /** Every feedback save the widget sent, in order. */
    const feedbackSaves = (fetchMock) =>
        fetchMock.mock.calls
            .filter(
                ([url, init]) =>
                    init &&
                    init.method === 'POST' &&
                    url.endsWith('/api/board/feedback'),
            )
            .map(([, init]) => JSON.parse(init.body));

    it('is kept for a retry to the same target and renewed for another one', async () => {
        const fetchMock = bootWidget({
            mode: { mode: 'per-review', cardId: CARD },
            respond: () =>
                ok({
                    comments: [],
                    context: { label: '#3 Review: /', url: null },
                }),
        });
        await settle();

        const root = openNote();
        await write(root, 'Lost in transit');
        fetchMock.mockImplementation(async (url, init) =>
            init && init.method === 'POST'
                ? rejected(500, {})
                : ok({ comments: [], context: null }),
        );
        root.getElementById('lp-save').click();
        await settle();
        root.getElementById('lp-save').click();
        await settle();

        root.getElementById('lp-context')
            .querySelector('.lp-context-label')
            .click();
        await settle();
        root.querySelector('[data-mode="per-note"]').click();
        await settle();
        root.getElementById('lp-save').click();
        await settle();

        const saves = feedbackSaves(fetchMock);
        expect(saves.map((save) => save.target)).toEqual([
            { cardId: CARD },
            { cardId: CARD },
            { newCard: {} },
        ]);
        expect(saves[1].deliveryId).toBe(saves[0].deliveryId);
        expect(saves[2].deliveryId).not.toBe(saves[0].deliveryId);
    });
});

describe('the mode picker', () => {
    it('hands focus back to the draft after a choice and after Back', async () => {
        bootWidget({ respond: () => ok({ comments: [], context: null }) });
        await settle();

        const root = openNote();
        const textarea = root.getElementById('lp-textarea');
        root.querySelector('[data-mode="per-review"]').click();
        await settle();
        expect(root.activeElement).toBe(
            root.getElementById('lp-picker-search'),
        );

        root.getElementById('lp-picker-back').click();
        await settle();
        expect(root.activeElement).toBe(textarea);

        root.querySelector('[data-mode="per-note"]').click();
        await settle();
        expect(root.activeElement).toBe(textarea);
    });

    it('closes on Escape and keeps the draft', async () => {
        bootWidget({
            mode: { mode: 'per-note', cardId: null },
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        const root = openNote();
        await write(root, 'Do not lose this');
        root.getElementById('lp-context').querySelector('button').click();
        await settle();
        root.querySelector('[data-mode="per-review"]').click();
        await settle();

        root.getElementById('lp-picker-search').dispatchEvent(
            new KeyboardEvent('keydown', {
                key: 'Escape',
                bubbles: true,
                composed: true,
            }),
        );
        await settle();

        expect(root.getElementById('lp-picker').style.display).toBe('none');
        expect(root.getElementById('lp-composer').style.opacity).toBe('1');
        expect(root.getElementById('lp-textarea').value).toBe(
            'Do not lose this',
        );
        // Reopening starts from the modes, not from the card search.
        root.getElementById('lp-context').querySelector('button').click();
        await settle();
        expect(root.getElementById('lp-picker-modes').style.display).toBe('');
    });

    it('keeps a choice made while the boot load is still in flight', async () => {
        // The boot load asks about the stored card. A reviewer who picks
        // another mode before it answers owns the choice, so the answer about
        // the old card must neither clear nor relabel it.
        let answerBoot;
        bootWidget({
            mode: { mode: 'per-review', cardId: CARD },
            respond: () =>
                new Promise((resolve) => {
                    answerBoot = resolve;
                }),
        });
        await settle();

        const root = openNote();
        root.getElementById('lp-context').querySelector('button').click();
        await settle();
        root.querySelector('[data-mode="per-note"]').click();
        await settle();

        answerBoot(ok({ comments: [], context: null }));
        await settle();

        expect(storedMode()).toEqual({ mode: 'per-note', cardId: null });
        expect(targetText(root)).toBe('A new card for each note');
        expect(root.getElementById('lp-picker').style.display).toBe('none');
    });
});

describe('the board switched off', () => {
    it('disables the composer and says why at boot', async () => {
        bootWidget({
            respond: () =>
                ok({ comments: [], context: null, feedbackAvailable: false }),
        });
        await settle();

        const root = openNote();

        expect(targetText(root)).toBe('Turn on the board to use site review');
        // Read-only, not disabled, so a draft can still be copied out.
        expect(root.getElementById('lp-textarea').disabled).toBe(false);
        expect(root.getElementById('lp-textarea').readOnly).toBe(true);
        expect(root.getElementById('lp-save').disabled).toBe(true);
        expect(root.getElementById('lp-picker').style.display).toBe('none');
    });

    it('says so when a save learns it', async () => {
        const fetchMock = bootWidget({
            mode: { mode: 'per-note', cardId: null },
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        const root = openNote();
        await write(root, 'Too late');
        fetchMock.mockImplementation(async () =>
            rejected(409, { error: 'board_disabled' }),
        );
        root.getElementById('lp-save').click();
        await settle();

        expect(root.getElementById('lp-error').textContent).toContain(
            'Turn on the board to use site review',
        );
        expect(targetText(root)).toBe('Turn on the board to use site review');
        expect(root.getElementById('lp-textarea').value).toBe('Too late');
        expect(root.getElementById('lp-textarea').disabled).toBe(false);
        expect(root.getElementById('lp-textarea').readOnly).toBe(true);
    });
});

describe('a stale copy of the widget', () => {
    it('is told to reload', async () => {
        const fetchMock = bootWidget({
            mode: { mode: 'per-note', cardId: null },
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        const root = openNote();
        await write(root, 'From an old tab');
        fetchMock.mockImplementation(async () =>
            rejected(410, { error: 'widget_outdated' }),
        );
        root.getElementById('lp-save').click();
        await settle();

        expect(root.getElementById('lp-error').textContent).toContain(
            'Reload the page to update the review widget.',
        );
    });
});

describe('a preview page that names its card', () => {
    it('sends every note to that card and shows no picker', async () => {
        const fetchMock = bootWidget({
            context: `card:${CARD}`,
            mode: { mode: 'per-note', cardId: null },
            respond: (url, init) =>
                init.method === 'POST'
                    ? ok({
                          commentId: 'c1',
                          cardId: CARD,
                          number: 7,
                          label: '#7 Preview',
                          url: null,
                      })
                    : ok({
                          comments: [],
                          context: { label: '#7 Preview', url: null },
                      }),
        });
        await settle();

        const root = openNote();
        expect(targetText(root)).toBe('#7 Preview');
        expect(
            root.getElementById('lp-context').querySelector('button'),
        ).toBeNull();
        expect(root.getElementById('lp-picker').style.display).toBe('none');
        await write(root, 'On the preview');
        root.getElementById('lp-save').click();
        await settle();

        expect(feedbackSave(fetchMock).target).toEqual({ cardId: CARD });
        expect(feedbackSave(fetchMock).context).toBe(`card:${CARD}`);
        // The lock belongs to this page. The reviewer's own choice stays.
        expect(storedMode()).toEqual({ mode: 'per-note', cardId: null });
    });

    it('says so, and refuses notes, when that card is closed or gone', async () => {
        bootWidget({
            context: `card:${CARD}`,
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        const root = openNote();

        expect(targetText(root)).toContain('closed or gone');
        expect(root.getElementById('lp-picker').style.display).toBe('none');
        expect(root.getElementById('lp-save').disabled).toBe(true);
    });
});

describe('deleting a note', () => {
    it('goes through the feedback path, so an untouched card goes with it', async () => {
        const fetchMock = bootWidget({
            respond: (url, init) =>
                init.method === 'DELETE'
                    ? ok({ cardDeleted: true })
                    : ok({
                          comments: [
                              {
                                  id: 'c1',
                                  body: 'a note',
                                  url: 'about:blank',
                                  anchors: [],
                              },
                          ],
                          context: null,
                      }),
        });
        await settle();

        const root = panelRoot();
        root.getElementById('lp-launch-main').click();
        root.getElementById('lp-clear').click();
        root.getElementById('lp-clear-yes').click();
        await settle();

        const deleted = fetchMock.mock.calls.find(
            ([, init]) => init && init.method === 'DELETE',
        );
        expect(deleted[0]).toBe(`${BACKEND}/api/board/feedback/c1`);
    });
});

describe('a token revoked while the picker is open', () => {
    it('shows the critical panel rather than a stuck Loading', async () => {
        const fetchMock = bootWidget({
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        const root = openNote();
        await settle();

        fetchMock.mockImplementation(async () =>
            rejected(403, { error: 'token_not_bound_to_site' }),
        );
        root.querySelector('[data-mode="per-review"]').click();
        await settle();

        expect(root.getElementById('lp-fatal').style.display).not.toBe('none');
    });
});

describe('navigating to a page that names a different card', () => {
    /** Turbo swaps the tag after the URL changes, so the marker lands late. */
    async function navigate(to, marker) {
        const tag = document.querySelector(
            'script[src*="site-review/widget.js"]',
        );
        window.history.pushState({}, '', to);
        // The swap happens after pushState, which is the whole difficulty.
        if (marker === null) tag.removeAttribute('data-context');
        else tag.setAttribute('data-context', marker);
        await new Promise((resolve) => setTimeout(resolve, 300));
        await settle();
    }

    it('re-resolves against the new page marker', async () => {
        const fetchMock = bootWidget({
            context: 'card:first',
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        await navigate('/second', 'card:second');

        const asked = fetchMock.mock.calls.map(([url]) => url);
        expect(asked.some((url) => url.includes('context=card%3Asecond'))).toBe(
            true,
        );
    });

    it('sends the new page card the moment the marker changes', async () => {
        // A reviewer who saves while the new page's card is still resolving
        // must not file against the page they have already left.
        let release;
        const fetchMock = bootWidget({
            context: 'card:first',
            respond: () =>
                ok({
                    comments: [],
                    context: { label: '#1 First page card', url: null },
                }),
        });
        await settle();

        // The next answer never arrives, which is the window under test.
        fetchMock.mockImplementation(
            () => new Promise((resolve) => (release = resolve)),
        );
        await navigate('/second', 'card:second');
        expect(release).toBeTypeOf('function');

        const root = openNote();
        await write(root, 'Saved while the new page was still resolving');
        fetchMock.mockImplementation(async () =>
            ok({
                commentId: 'c9',
                cardId: 'second',
                number: 2,
                label: '#2',
                url: null,
            }),
        );
        root.getElementById('lp-save').click();
        await settle();

        expect(feedbackSave(fetchMock).target).toEqual({ cardId: 'second' });
    });

    it('drops the lock when the new page names no card', async () => {
        const fetchMock = bootWidget({
            context: 'card:first',
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();
        fetchMock.mockClear();

        await navigate('/plain', null);

        const asked = fetchMock.mock.calls.map(([url]) => url);
        expect(asked.length).toBeGreaterThan(0);
        expect(asked.every((url) => !url.includes('context='))).toBe(true);
        expect(openNote().getElementById('lp-picker').style.display).toBe(
            'block',
        );
    });
});

describe('boot', () => {
    it('reads the backend from its own src and sends the stored grant', async () => {
        const fetchMock = bootWidget({ respond: () => ok({ comments: [] }) });
        await settle();

        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toBe(`${BACKEND}/api/site-review/review`);
        expect(options.method).toBe('GET');
        expect(options.headers.Authorization).toBe(`Bearer ${ACCESS_TOKEN}`);
    });

    it('attaches its own shadow roots to the document element', async () => {
        bootWidget({ respond: () => ok({ comments: [] }) });
        await settle();

        expect(hostCount()).toBe(2);
        expect(panelRoot().getElementById('lp-launcher')).not.toBeNull();
    });

    it('runs once however often the script tag is executed', async () => {
        bootWidget({ respond: () => ok({ comments: [] }) });
        bootWidget({ respond: () => ok({ comments: [] }) });
        await settle();

        expect(hostCount()).toBe(2);
    });

    it('shows the comment count and no alert on a good load', async () => {
        bootWidget({
            respond: () =>
                ok({
                    comments: [
                        {
                            id: 1,
                            body: 'a note',
                            url: 'about:blank',
                            anchors: [],
                        },
                    ],
                }),
        });
        await settle();

        expect(alerting()).toBe(false);
        expect(panelRoot().getElementById('lp-launch-count').textContent).toBe(
            '1',
        );
    });
});

describe('a refused credential', () => {
    it('asks the reviewer to sign in again after a 401 it cannot refresh', async () => {
        bootWidget({ respond: () => rejected(401) });
        await settle();
        await settle();
        openPanel();

        expect(panelRoot().getElementById('lp-fatal').style.display).toBe(
            'block',
        );
        expect(panelRoot().getElementById('lp-sign-in')).not.toBeNull();
    });

    it('names the missing scope for insufficient_scope', async () => {
        bootWidget({
            respond: () => rejected(403, { error: 'insufficient_scope' }),
        });
        await settle();

        expect(fatalDetail()).toContain('does not cover site review');
    });

    it('names the account for a 403 with no code', async () => {
        bootWidget({ respond: () => rejected(403) });
        await settle();

        expect(fatalDetail()).toContain('cannot review this project');
    });

    it('hides the comment list behind the critical panel', async () => {
        bootWidget({ respond: () => rejected(403) });
        await settle();
        openPanel();

        expect(panelRoot().getElementById('lp-main').style.display).toBe(
            'none',
        );
        expect(panelRoot().getElementById('lp-fatal').style.display).toBe(
            'block',
        );
    });
});

describe('a transient failure', () => {
    it('leaves a 500 dismissible rather than fatal', async () => {
        bootWidget({ respond: () => rejected(500) });
        await settle();

        expect(alerting()).toBe(false);
        openPanel();
        expect(panelRoot().getElementById('lp-fatal').style.display).toBe(
            'none',
        );
    });

    it('leaves a 429 dismissible rather than fatal', async () => {
        bootWidget({ respond: () => rejected(429, { error: 'too_many' }) });
        await settle();

        expect(alerting()).toBe(false);
    });
});

describe('the quote offer', () => {
    /** Selects the whole of a paragraph added under `wrapper`. */
    async function selectTextIn(wrapper) {
        const paragraph = document.createElement('p');
        paragraph.textContent = 'A sentence the reviewer selects.';
        wrapper.appendChild(paragraph);
        const range = document.createRange();
        range.selectNodeContents(paragraph.firstChild);
        const selection = document.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        document.dispatchEvent(new Event('selectionchange'));
        // The widget reads the selection in a frame, and jsdom runs those on a
        // 16ms timer that settle() does not reach.
        await new Promise((resolve) => setTimeout(resolve, 50));
    }

    /** The offer lives in the overlay root, not the panel's. */
    function quoteButton() {
        return [...document.documentElement.children]
            .filter((element) => element.shadowRoot)
            .map((element) => element.shadowRoot.getElementById('lp-quote-btn'))
            .find((element) => element != null);
    }

    it('is offered on an ordinary page', async () => {
        bootWidget({ respond: () => ok({ comments: [] }) });
        await settle();
        openPanel();
        await selectTextIn(document.body);

        expect(quoteButton().style.display).toBe('inline-flex');
    });

    it('survives an event that lands while pick mode owns the pointer', async () => {
        bootWidget({ respond: () => ok({ comments: [] }) });
        await settle();
        openPanel();
        await selectTextIn(document.body);
        panelRoot().getElementById('target').click();
        await settle();
        expect(quoteButton().style.display).toBe('none');

        // The drag's own trailing selectionchange lands here on a loaded
        // machine. Pick mode hides the offer and owes it back, so the event
        // must not take the pick away.
        document.dispatchEvent(new Event('selectionchange'));
        await new Promise((resolve) => setTimeout(resolve, 50));
        document.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        await settle();

        expect(quoteButton().style.display).toBe('inline-flex');
    });

    it('stays away inside an element that opts out', async () => {
        bootWidget({ respond: () => ok({ comments: [] }) });
        await settle();
        openPanel();
        const wrapper = document.createElement('div');
        wrapper.setAttribute('data-site-review-quotes', 'off');
        document.body.appendChild(wrapper);
        await selectTextIn(wrapper);

        expect(quoteButton().style.display).toBe('none');
    });
});

describe('a stored selector that carries a stale class', () => {
    /** The chip that names how many of a comment's elements were found. */
    function chipText() {
        openPanel();

        return panelRoot().querySelector('.lp-chip').textContent;
    }

    function twoSections() {
        document.body.innerHTML =
            '<div class="panel">' +
            '<section class="row">first</section>' +
            '<section class="row">second</section>' +
            '</div>';
    }

    function withAnchors(anchors) {
        return {
            respond: () =>
                ok({
                    comments: [
                        {
                            id: 1,
                            body: 'a note',
                            url: location.href,
                            anchors,
                        },
                    ],
                }),
        };
    }

    it('still finds the element the classes no longer name', async () => {
        // `active` was on the section while the reviewer had it open. The page
        // drops it on the next visit, and the exact selector then misses an
        // element that is still there.
        twoSections();
        bootWidget(
            withAnchors([
                {
                    selector: 'div.panel > section.row.active:nth-of-type(1)',
                    text: 'first',
                },
                {
                    selector: 'div.panel > section.row:nth-of-type(2)',
                    text: 'second',
                },
            ]),
        );
        await settle();

        expect(chipText()).toBe('2 elements');
    });

    it('refuses a relaxed selector that names more than one element', async () => {
        // Dropping the classes here leaves `div.panel > section`, which both
        // sections answer. A guess between them would anchor the comment to
        // whichever came first.
        twoSections();
        bootWidget(
            withAnchors([
                { selector: 'div.panel > section.gone', text: 'first' },
                {
                    selector: 'div.panel > section.row:nth-of-type(2)',
                    text: 'second',
                },
            ]),
        );
        await settle();

        expect(chipText()).toBe('1 of 2 elements');
    });
    it('keeps a class that escapes a child combinator', async () => {
        // CSS.escape writes a Tailwind class such as [&>svg]:hidden with an
        // escaped `>`. Splitting the selector there would cut the class in two
        // and every relaxed candidate would be nonsense.
        document.body.innerHTML =
            '<div class="panel">' +
            '<section class="[&>svg]:hidden">first</section>' +
            '<section class="row">second</section>' +
            '</div>';
        bootWidget(
            withAnchors([
                {
                    selector:
                        'div.panel > section.\\[\\&\\>svg\\]\\:hidden.active:nth-of-type(1)',
                    text: 'first',
                },
                {
                    selector: 'div.panel > section.row:nth-of-type(2)',
                    text: 'second',
                },
            ]),
        );
        await settle();

        expect(chipText()).toBe('2 elements');
    });
    it('refuses a unique match that no longer reads like the target', async () => {
        // The anchored section is gone, and its :nth-of-type() seat now belongs
        // to a section that says something else. Uniqueness alone would draw
        // the comment on it.
        twoSections();
        bootWidget(
            withAnchors([
                {
                    selector: 'div.panel > section.row.active:nth-of-type(1)',
                    text: 'a heading that is no longer here',
                },
                {
                    selector: 'div.panel > section.row:nth-of-type(2)',
                    text: 'second',
                },
            ]),
        );
        await settle();

        expect(chipText()).toBe('1 of 2 elements');
    });
});
