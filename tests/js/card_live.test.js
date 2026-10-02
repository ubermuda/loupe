/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const live = vi.hoisted(() => ({ subscriptions: [] }));

vi.mock('../../assets/lib/live.js', () => ({
    on: (types, handler, options) => {
        const subscription = { types, handler, options, removed: false };
        live.subscriptions.push(subscription);
        return () => {
            subscription.removed = true;
        };
    },
}));

const {
    default: CardLiveController,
    DEBOUNCE_MILLISECONDS,
    RETRY_MILLISECONDS,
    READ_TIMEOUT_MILLISECONDS,
} = await import('../../assets/controllers/card_live_controller.js');

const CARD = '0199aaaa-0000-7000-8000-000000000001';
const URL_PATH = `/projects/p/board/cards/${CARD}`;

let application;
let responses;
let connects;

class CountingController extends CardLiveController {
    connect() {
        connects += 1;
        super.connect();
    }
}

function cardHtml({
    column = 'Next',
    tab = 'overview',
    frame = false,
    flash = '',
    feedback = '<details class="lp-feedback"><summary>Entry</summary>Body</details>',
    body = '',
    history = '',
} = {}) {
    const tabs = ['overview', 'details', 'history']
        .map(
            (name) =>
                `<button type="button" role="tab" id="card-tab-${name}" data-panel-tabs-target="tab" data-panel-tab="${name}" aria-selected="${name === tab}">${name}</button>`,
        )
        .join('');
    const panels = ['overview', 'details', 'history']
        .map(
            (name) =>
                `<section id="card-panel-${name}" role="tabpanel" data-panel-panel="${name}"${name === tab ? '' : ' hidden'}>${name === 'overview' ? feedback : name === 'history' ? history : ''}</section>`,
        )
        .join('');

    return `<div class="lp-card-drawer" data-card-drawer-card-id="${CARD}" data-controller="card-live" data-card-live-card-id-value="${CARD}" data-card-live-url-value="${URL_PATH}" data-card-live-frame-value="${frame}">
        ${flash}
        <header class="lp-card-drawer__header"><span class="lp-tag" data-column>${column}</span></header>
        <div class="lp-card-drawer__body">
            <div class="lp-tabs" role="tablist">${tabs}</div>
            ${panels}
            ${body}
        </div>
        <footer><div data-controller="modal"><dialog id="delete"><form data-card-delete></form></dialog><form data-other-form></form></div></footer>
    </div>`;
}

function page(html) {
    return `<!doctype html><html><body><main>${html}</main></body></html>`;
}

function answer(html, ok = true) {
    responses.push({ ok, text: async () => page(html) });
}

async function mount(options = {}) {
    document.body.innerHTML = `<main>${cardHtml(options)}</main>`;
    await vi.advanceTimersByTimeAsync(0);

    return document.querySelector('[data-card-drawer-card-id]');
}

function subscription() {
    expect(live.subscriptions).toHaveLength(1);
    return live.subscriptions[0];
}

function cardChanged(change = {}) {
    return subscription().handler({
        type: 'board.card_changed',
        cardId: CARD,
        change: 'moved',
        local: false,
        own: false,
        ...change,
    });
}

function stubIntersectionObserver() {
    vi.stubGlobal(
        'IntersectionObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    );
}

function olderLink(id) {
    return `<turbo-frame id="${id}" class="lp-card-history__older"><a href="/older">Show older</a></turbo-frame>`;
}

// A lazy frame waits for an intersection that never comes, so it fetches nothing.
function loadedOlder(id) {
    const frame = document.createElement('turbo-frame');
    frame.id = id;
    frame.className = 'lp-card-history__older';
    frame.setAttribute('loading', 'lazy');
    frame.setAttribute('src', '/older');
    frame.textContent = 'Older entries';

    return frame;
}

async function settle() {
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
}

beforeEach(() => {
    vi.useFakeTimers();
    live.subscriptions = [];
    responses = [];
    connects = 0;
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => responses.shift() ?? { ok: false }),
    );
    application = Application.start();
    application.register('card-live', CountingController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    application.stop();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

it('listens for card and worker run changes', async () => {
    await mount();
    expect(subscription().types).toEqual([
        'board.card_changed',
        'worker_run.changed',
    ]);
});

it('ignores another card, a change this page made, and a delete in the drawer', async () => {
    const visit = vi.fn();
    vi.stubGlobal('Turbo', { visit });
    await mount({ frame: true });
    cardChanged({ cardId: '0199aaaa-0000-7000-8000-000000000002' });
    cardChanged({ local: true, own: true });
    cardChanged({ change: 'deleted' });
    await settle();
    expect(fetch).not.toHaveBeenCalled();
    expect(visit).not.toHaveBeenCalled();
});

it('loads the page again when the card on the standalone page is deleted', async () => {
    const visit = vi.fn();
    vi.stubGlobal('Turbo', { visit });
    await mount();
    cardChanged({ change: 'deleted' });
    await settle();
    expect(fetch).not.toHaveBeenCalled();
    expect(visit).toHaveBeenCalledWith(window.location.href, {
        action: 'replace',
    });
});

function submit(root, success, selector = '[data-card-delete]') {
    const form = root.querySelector(selector);
    form.dispatchEvent(
        new CustomEvent('turbo:submit-start', { bubbles: true }),
    );
    if (success !== undefined) {
        form.dispatchEvent(
            new CustomEvent('turbo:submit-end', {
                bubbles: true,
                detail: { success },
            }),
        );
    }
}

it('leaves the redirect alone when this page deleted the card', async () => {
    const visit = vi.fn();
    vi.stubGlobal('Turbo', { visit });
    const root = await mount();
    submit(root, undefined);
    cardChanged({ change: 'deleted', own: true });
    submit(root, true);
    cardChanged({ change: 'deleted', own: true });
    await settle();
    expect(fetch).not.toHaveBeenCalled();
    expect(visit).not.toHaveBeenCalled();
});

it('loads the page again on a delete with its origin that this page did not submit', async () => {
    const visit = vi.fn();
    vi.stubGlobal('Turbo', { visit });
    const root = await mount();
    cardChanged({ change: 'deleted', own: true });
    submit(root, false);
    cardChanged({ change: 'deleted', own: true });
    // Another form that stays on the page marks no delete.
    submit(root, true, '[data-other-form]');
    cardChanged({ change: 'deleted', own: true });
    await settle();
    expect(visit).toHaveBeenCalledTimes(3);
});

it('keeps the older history the reader loaded, and updates the newest rows', async () => {
    stubIntersectionObserver();
    const root = await mount({
        tab: 'history',
        history: '<p data-newest>Moved to Next</p>',
    });
    root.querySelector('#card-panel-history').append(loadedOlder('older-a'));
    answer(
        cardHtml({
            tab: 'history',
            history: `<p data-newest>Moved to Done</p>${olderLink('older-a')}`,
        }),
    );
    cardChanged();
    await settle();
    const panel = root.querySelector('#card-panel-history');
    expect(panel.querySelector('[data-newest]').textContent).toBe(
        'Moved to Done',
    );
    expect(panel.textContent).toContain('Older entries');
    expect(panel.querySelector('#older-a').getAttribute('src')).toBe('/older');
});

it('shows the fresh first page when a new row moves where it ends', async () => {
    stubIntersectionObserver();
    const root = await mount({ tab: 'history' });
    root.querySelector('#card-panel-history').append(loadedOlder('older-a'));
    answer(cardHtml({ tab: 'history', history: olderLink('older-b') }));
    cardChanged();
    await settle();
    const panel = root.querySelector('#card-panel-history');
    expect(panel.querySelector('#older-a')).toBeNull();
    expect(panel.querySelector('#older-b').textContent).toBe('Show older');
});

it('drops a read in flight when a newer change comes, and does not retry it', async () => {
    const root = await mount();
    let resolveFirst;
    fetch.mockImplementationOnce(
        () => new Promise((done) => (resolveFirst = done)),
    );
    cardChanged();
    await settle();
    answer(cardHtml({ column: 'Done' }));
    cardChanged();
    await settle();
    expect(fetch.mock.calls[0][1].signal.aborted).toBe(true);
    expect(root.querySelector('[data-column]').textContent).toBe('Done');

    resolveFirst({ ok: false, status: 503 });
    await vi.advanceTimersByTimeAsync(60000);
    expect(fetch).toHaveBeenCalledTimes(2);
    expect(root.querySelector('[data-column]').textContent).toBe('Done');
});

it('stops a read that answers nothing, and tries again', async () => {
    const root = await mount();
    fetch.mockImplementationOnce(
        (url, { signal }) =>
            new Promise((done, fail) =>
                signal.addEventListener('abort', () =>
                    fail(new DOMException('Aborted', 'AbortError')),
                ),
            ),
    );
    cardChanged();
    await settle();
    await vi.advanceTimersByTimeAsync(READ_TIMEOUT_MILLISECONDS);
    expect(fetch.mock.calls[0][1].signal.aborted).toBe(true);

    answer(cardHtml({ column: 'Done' }));
    await vi.advanceTimersByTimeAsync(RETRY_MILLISECONDS[0]);
    expect(fetch).toHaveBeenCalledTimes(2);
    expect(root.querySelector('[data-column]').textContent).toBe('Done');
});

it('tries a failed read again after a growing wait', async () => {
    const root = await mount();
    responses.push({ ok: false, status: 503 });
    cardChanged();
    await settle();
    expect(fetch).toHaveBeenCalledTimes(1);
    fetch.mockRejectedValueOnce(new TypeError('offline'));
    await vi.advanceTimersByTimeAsync(RETRY_MILLISECONDS[0]);
    expect(fetch).toHaveBeenCalledTimes(2);
    answer(cardHtml({ column: 'Done' }));
    await vi.advanceTimersByTimeAsync(RETRY_MILLISECONDS[1]);
    expect(fetch).toHaveBeenCalledTimes(3);
    expect(root.querySelector('[data-column]').textContent).toBe('Done');
});

it('stops after the last retry, and does not retry a card it cannot see', async () => {
    await mount();
    responses.push(
        ...Array.from({ length: 4 }, () => ({ ok: false, status: 500 })),
    );
    cardChanged();
    await settle();
    await vi.advanceTimersByTimeAsync(
        RETRY_MILLISECONDS.reduce((sum, wait) => sum + wait, 0),
    );
    expect(fetch).toHaveBeenCalledTimes(1 + RETRY_MILLISECONDS.length);
    await vi.advanceTimersByTimeAsync(60000);
    expect(fetch).toHaveBeenCalledTimes(1 + RETRY_MILLISECONDS.length);

    responses.push({ ok: false, status: 404 });
    cardChanged();
    await settle();
    await vi.advanceTimersByTimeAsync(60000);
    expect(fetch).toHaveBeenCalledTimes(2 + RETRY_MILLISECONDS.length);
});

it('fetches on the hub echo of a change this reader made elsewhere on the page', async () => {
    await mount();
    answer(cardHtml());
    cardChanged({ own: true });
    await settle();
    expect(fetch).toHaveBeenCalledOnce();
});

it('fetches on a worker run change, which names no card', async () => {
    await mount();
    answer(cardHtml());
    subscription().handler({ type: 'worker_run.changed', local: false });
    await settle();
    expect(fetch).toHaveBeenCalledOnce();
});

it('fetches once for a burst of changes', async () => {
    await mount();
    answer(cardHtml());
    cardChanged();
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS - 1);
    cardChanged();
    cardChanged();
    expect(fetch).not.toHaveBeenCalled();
    await settle();
    expect(fetch).toHaveBeenCalledOnce();
});

it('fetches after a reconnect', async () => {
    await mount();
    answer(cardHtml());
    subscription().options.onReconnect();
    await settle();
    expect(fetch).toHaveBeenCalledOnce();
});

it('asks for the open tab, and morphs the new card in', async () => {
    const root = await mount({ tab: 'history' });
    answer(cardHtml({ tab: 'history', column: 'Done' }));
    cardChanged();
    await settle();

    const [url, options] = fetch.mock.calls[0];
    expect(new URL(url).pathname).toBe(URL_PATH);
    expect(new URL(url).searchParams.get('tab')).toBe('history');
    expect(options.credentials).toBe('same-origin');
    expect(options.headers.Accept).toBe('text/html');
    expect(options.headers['Turbo-Frame']).toBeUndefined();
    expect(options.headers['X-Loupe-Live-Refresh']).toBe('1');
    expect(document.querySelector('[data-card-drawer-card-id]')).toBe(root);
    expect(root.querySelector('[data-column]').textContent).toBe('Done');
    expect(root.querySelector('#card-panel-history').hidden).toBe(false);
    expect(root.querySelector('#card-panel-overview').hidden).toBe(true);
});

it('fetches again when the reader switches tab while a fetch runs', async () => {
    const root = await mount();
    let resolve;
    fetch.mockImplementationOnce(() => new Promise((done) => (resolve = done)));
    cardChanged();
    await settle();
    root.querySelectorAll('[data-panel-tabs-target="tab"]').forEach((tab) =>
        tab.setAttribute(
            'aria-selected',
            String(tab.dataset.panelTab === 'history'),
        ),
    );
    resolve({ ok: true, text: async () => page(cardHtml({ column: 'Done' })) });
    await vi.advanceTimersByTimeAsync(0);
    expect(root.querySelector('[data-column]').textContent).toBe('Next');

    answer(cardHtml({ tab: 'history', column: 'Done' }));
    await settle();
    expect(fetch).toHaveBeenCalledTimes(2);
    expect(new URL(fetch.mock.calls[1][0]).searchParams.get('tab')).toBe(
        'history',
    );
    expect(root.querySelector('[data-column]').textContent).toBe('Done');
    expect(root.querySelector('#card-panel-history').hidden).toBe(false);
});

it('sends the frame header in the drawer, so the host keeps its subscriptions', async () => {
    await mount({ frame: true });
    answer(cardHtml({ frame: true }));
    cardChanged();
    await settle();
    expect(fetch.mock.calls[0][1].headers['Turbo-Frame']).toBe(
        'card-drawer-frame',
    );
});

it('keeps the controller connected through the morph', async () => {
    const root = await mount();
    answer(cardHtml({ column: 'Done' }));
    cardChanged();
    await settle();
    await vi.advanceTimersByTimeAsync(0);
    expect(connects).toBe(1);
    expect(live.subscriptions).toHaveLength(1);

    answer(cardHtml({ column: 'Shipped' }));
    cardChanged();
    await settle();
    expect(root.querySelector('[data-column]').textContent).toBe('Shipped');
});

it('ignores a response that is not OK', async () => {
    const root = await mount();
    answer(cardHtml({ column: 'Done' }), false);
    cardChanged();
    await settle();
    expect(root.querySelector('[data-column]').textContent).toBe('Next');
});

it('holds the update while a dialog is open, and fetches when it closes', async () => {
    const root = await mount();
    const dialog = root.querySelector('#delete');
    dialog.setAttribute('open', '');
    answer(cardHtml({ column: 'Done' }));
    cardChanged();
    await settle();
    expect(fetch).not.toHaveBeenCalled();

    dialog.removeAttribute('open');
    dialog.dispatchEvent(new Event('close'));
    await vi.advanceTimersByTimeAsync(0);
    expect(fetch).toHaveBeenCalledOnce();
    expect(root.querySelector('[data-column]').textContent).toBe('Done');
});

it('does not fetch when a dialog closes with nothing held', async () => {
    const root = await mount();
    root.querySelector('#delete').dispatchEvent(new Event('close'));
    await vi.advanceTimersByTimeAsync(0);
    expect(fetch).not.toHaveBeenCalled();
});

it('keeps a form with unsaved input, and updates the rest', async () => {
    const form = (value) =>
        `<form id="reply"><textarea name="body">${value}</textarea><input type="checkbox" name="notify"><select name="kind"><option>a</option><option>b</option></select></form>`;
    const root = await mount({ body: form('') });
    root.querySelector('textarea').value = 'Draft reply';
    answer(cardHtml({ column: 'Done', body: form('Server text') }));
    cardChanged();
    await settle();

    expect(root.querySelector('textarea').value).toBe('Draft reply');
    expect(root.querySelector('[data-column]').textContent).toBe('Done');
});

it('updates a form with no unsaved input', async () => {
    const form = (value) =>
        `<form id="reply"><textarea name="body">${value}</textarea><select name="kind"><option>a</option><option>b</option></select></form>`;
    const root = await mount({ body: form('') });
    answer(cardHtml({ body: form('Server text') }));
    cardChanged();
    await settle();

    expect(root.querySelector('textarea').value).toBe('Server text');
});

it('counts a changed checkbox or select as unsaved input', async () => {
    const form = (marker) =>
        `<form id="reply" data-marker="${marker}"><input type="checkbox" name="notify"><select name="kind"><option>a</option><option>b</option></select></form>`;
    const root = await mount({ body: form('old') });
    root.querySelector('input[type="checkbox"]').checked = true;
    answer(cardHtml({ body: form('new') }));
    cardChanged();
    await settle();
    expect(root.querySelector('form').dataset.marker).toBe('old');

    root.querySelector('input[type="checkbox"]').checked = false;
    root.querySelector('select').value = 'b';
    answer(cardHtml({ body: form('new') }));
    cardChanged();
    await settle();
    expect(root.querySelector('form').dataset.marker).toBe('old');
});

it('keeps a flash on screen and an open disclosure open', async () => {
    const root = await mount({
        flash: '<div class="lp-flash" role="status">Card saved</div>',
    });
    root.querySelector('details').open = true;
    answer(cardHtml({ column: 'Done' }));
    cardChanged();
    await settle();

    expect(root.querySelector('.lp-flash')?.textContent).toBe('Card saved');
    expect(root.querySelector('details').open).toBe(true);
    expect(root.querySelector('[data-column]').textContent).toBe('Done');
});

it('stops listening and drops an answer that arrives after it disconnects', async () => {
    const root = await mount();
    let resolve;
    fetch.mockImplementationOnce(
        (url, options) =>
            new Promise((done) => {
                resolve = done;
                options.signal.addEventListener('abort', () => done(null));
            }),
    );
    cardChanged();
    await settle();
    const signal = fetch.mock.calls[0][1].signal;

    root.remove();
    await vi.advanceTimersByTimeAsync(0);
    expect(subscription().removed).toBe(true);
    expect(signal.aborted).toBe(true);
    resolve({ ok: true, text: async () => page(cardHtml({ column: 'Done' })) });
    await vi.advanceTimersByTimeAsync(0);
    expect(root.querySelector('[data-column]').textContent).toBe('Next');
});

function tracked(promise) {
    const state = { settled: false };
    promise.then(() => (state.settled = true));

    return state;
}

describe('the promise of a change', () => {
    it('answers none for a change it ignores', async () => {
        vi.stubGlobal('Turbo', { visit: vi.fn() });
        await mount();
        expect(
            cardChanged({ cardId: '0199aaaa-0000-7000-8000-000000000002' }),
        ).toBeUndefined();
        expect(cardChanged({ local: true, own: true })).toBeUndefined();
        expect(cardChanged({ change: 'deleted' })).toBeUndefined();
    });

    it('settles when the morph shows the change, and not before', async () => {
        const root = await mount();
        let resolve;
        fetch.mockImplementationOnce(
            () => new Promise((done) => (resolve = done)),
        );
        const change = tracked(cardChanged());
        await settle();
        expect(change.settled).toBe(false);

        resolve({
            ok: true,
            text: async () => page(cardHtml({ column: 'Done' })),
        });
        await vi.advanceTimersByTimeAsync(0);
        expect(root.querySelector('[data-column]').textContent).toBe('Done');
        expect(change.settled).toBe(true);
    });

    it('keeps an earlier change waiting for the read that a newer change starts', async () => {
        const root = await mount();
        fetch.mockImplementationOnce(
            (url, options) =>
                new Promise((done, fail) =>
                    options.signal.addEventListener('abort', () =>
                        fail(new DOMException('Aborted', 'AbortError')),
                    ),
                ),
        );
        const first = tracked(cardChanged());
        await settle();
        let resolve;
        fetch.mockImplementationOnce(
            () => new Promise((done) => (resolve = done)),
        );
        const second = tracked(cardChanged());
        await settle();
        expect(fetch).toHaveBeenCalledTimes(2);
        expect(first.settled).toBe(false);

        resolve({
            ok: true,
            text: async () => page(cardHtml({ column: 'Done' })),
        });
        await vi.advanceTimersByTimeAsync(0);
        expect(root.querySelector('[data-column]').textContent).toBe('Done');
        expect(first.settled).toBe(true);
        expect(second.settled).toBe(true);
    });

    it('waits through a retry, and settles after the last one fails', async () => {
        await mount();
        responses.push(
            ...Array.from({ length: 4 }, () => ({ ok: false, status: 500 })),
        );
        const change = tracked(cardChanged());
        await settle();
        await vi.advanceTimersByTimeAsync(
            RETRY_MILLISECONDS.slice(0, -1).reduce((sum, wait) => sum + wait),
        );
        expect(change.settled).toBe(false);

        await vi.advanceTimersByTimeAsync(RETRY_MILLISECONDS.at(-1));
        expect(fetch).toHaveBeenCalledTimes(1 + RETRY_MILLISECONDS.length);
        expect(change.settled).toBe(true);
    });

    it('settles on an answer that a retry cannot fix', async () => {
        await mount();
        responses.push({ ok: false, status: 404 });
        const change = tracked(cardChanged());
        await settle();
        expect(change.settled).toBe(true);
    });

    it('waits while an open dialog holds the update, and settles when the read after it closes renders', async () => {
        const root = await mount();
        const dialog = root.querySelector('#delete');
        dialog.setAttribute('open', '');
        const change = tracked(cardChanged());
        await settle();
        await vi.advanceTimersByTimeAsync(60000);
        expect(fetch).not.toHaveBeenCalled();
        expect(change.settled).toBe(false);

        answer(cardHtml({ column: 'Done' }));
        dialog.removeAttribute('open');
        dialog.dispatchEvent(new Event('close'));
        await vi.advanceTimersByTimeAsync(0);
        expect(root.querySelector('[data-column]').textContent).toBe('Done');
        expect(change.settled).toBe(true);
    });

    it('keeps waiting when a dialog opens while a read runs', async () => {
        const root = await mount();
        const dialog = root.querySelector('#delete');
        let resolve;
        fetch.mockImplementationOnce(
            () => new Promise((done) => (resolve = done)),
        );
        const change = tracked(cardChanged());
        await settle();
        dialog.setAttribute('open', '');
        resolve({
            ok: true,
            text: async () => page(cardHtml({ column: 'Done' })),
        });
        await vi.advanceTimersByTimeAsync(0);
        expect(change.settled).toBe(false);

        answer(cardHtml({ column: 'Done' }));
        dialog.removeAttribute('open');
        dialog.dispatchEvent(new Event('close'));
        await vi.advanceTimersByTimeAsync(0);
        expect(root.querySelector('[data-column]').textContent).toBe('Done');
        expect(change.settled).toBe(true);
    });

    it('settles when it disconnects while a dialog holds the update', async () => {
        const root = await mount();
        root.querySelector('#delete').setAttribute('open', '');
        const change = tracked(cardChanged());
        await settle();
        expect(change.settled).toBe(false);

        root.remove();
        await vi.advanceTimersByTimeAsync(0);
        expect(change.settled).toBe(true);
    });

    it('settles when it disconnects with a read in flight', async () => {
        const root = await mount();
        fetch.mockImplementationOnce(() => new Promise(() => {}));
        const change = tracked(cardChanged());
        await settle();
        expect(change.settled).toBe(false);

        root.remove();
        await vi.advanceTimersByTimeAsync(0);
        expect(change.settled).toBe(true);
    });
});
