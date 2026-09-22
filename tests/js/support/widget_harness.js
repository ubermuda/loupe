import { readFileSync } from 'node:fs';
import { vi } from 'vitest';

const SOURCE = readFileSync('public/site-review/widget.js', 'utf8');

export const BACKEND = 'https://loupe.example';
export const PROJECT = '01a0baf7-f542-7ce5-9b75-9dcbf05cf8c9';
export const ACCESS_TOKEN = 'wt_test_token';

/** Where the widget keeps the grant of one backend and project. */
export function storageKeyFor(project) {
    return `loupe-site-review:oauth:${BACKEND}:${project}`;
}

/** Puts a live grant where the widget reads one, so a boot starts signed in. */
export function signIn(project = PROJECT, accessToken = ACCESS_TOKEN) {
    window.sessionStorage.setItem(
        storageKeyFor(project),
        JSON.stringify({
            accessToken,
            refreshToken: 'wt_test_refresh',
            expiresAt: Date.now() + 3600 * 1000,
        }),
    );
}

/**
 * Boots public/site-review/widget.js in jsdom.
 *
 * The widget is a classic script wrapped in an IIFE and exports nothing, so a
 * test reaches its behaviour the way a page does: through the DOM it builds and
 * the requests it makes. `new Function` runs the source with the jsdom globals,
 * which is what `document.currentScript` and the script tag stand in for.
 *
 * jsdom has no matchMedia, no canvas context and no Range geometry, and the
 * widget reads all three before it paints. None takes part in what these tests
 * assert.
 */
export function bootWidget({
    respond,
    demo = false,
    context = null,
    project = PROJECT,
    signedIn = true,
} = {}) {
    window.matchMedia = () => ({
        matches: false,
        addEventListener() {},
        removeEventListener() {},
    });
    HTMLCanvasElement.prototype.getContext = () =>
        new Proxy({}, { get: () => () => {}, set: () => true });
    // jsdom has no Range geometry either, which the quote offer positions from.
    Range.prototype.getBoundingClientRect = () => ({
        top: 0,
        bottom: 0,
        left: 0,
        right: 0,
        width: 0,
        height: 0,
    });

    const script = document.createElement('script');
    script.src = `${BACKEND}/site-review/widget.js`;
    // The landing page's demo names no project and swaps the transport out.
    if (demo) script.setAttribute('data-demo', '');
    // Every other embed names its project. The reviewer signs in with OAuth.
    if (project !== null) script.setAttribute('data-project', project);
    if (!demo && project !== null && signedIn) signIn(project);
    // The marker a preview page proposes. Absent on an ordinary deployment.
    if (context !== null) script.setAttribute('data-context', context);
    Object.defineProperty(document, 'currentScript', {
        value: script,
        configurable: true,
    });
    // In the document as well, because the widget re-reads the tag after an SPA
    // navigation rather than keeping the element it booted from.
    document.head.appendChild(script);

    const fetchMock = vi.fn(async (...request) => respond(...request));
    globalThis.fetch = fetchMock;

    new Function(SOURCE)();

    return fetchMock;
}

/** Lets the boot load, its error handling and the follow-up render all settle. */
export async function settle() {
    await new Promise((resolve) => setTimeout(resolve, 0));
    await new Promise((resolve) => setTimeout(resolve, 0));
}

/** A fetch reply the widget's serverApi() accepts. */
export function ok(payload) {
    return { ok: true, status: 200, json: async () => payload };
}

/**
 * A fetch reply the widget's serverApi() rejects. Pass no `body` for the
 * unparseable case, which the widget must survive without a code.
 */
export function rejected(status, body) {
    return {
        ok: false,
        status,
        json: async () => {
            if (body === undefined) {
                throw new SyntaxError('Unexpected token < in JSON');
            }
            return body;
        },
    };
}

/** The widget's panel shadow root, the first host it attaches to <html>. */
export function panelRoot() {
    const host = [...document.documentElement.children].find(
        (element) => element.shadowRoot,
    );

    return host ? host.shadowRoot : null;
}

/** How many shadow hosts the widget has attached to <html>. */
export function hostCount() {
    return [...document.documentElement.children].filter(
        (element) => element.shadowRoot,
    ).length;
}

/** Opens the panel, which is where the critical state renders its message. */
export function openPanel() {
    panelRoot().getElementById('lp-launch-main').click();
}

/**
 * Undoes a boot, so the next one in the same file starts clean. The widget
 * wraps history.pushState/replaceState on every run, and its idempotency guard
 * would refuse a second run outright.
 */
export function resetWidget(history) {
    delete window.__loupeSiteReviewLoaded;
    // jsdom keeps the URL between tests in a file, and the widget only reacts
    // to a change. A navigation test landing where a previous one left off saw
    // no change at all and passed or failed on test order.
    history.replaceState.call(window.history, {}, '', '/');
    document.head
        .querySelectorAll('script[src*="site-review/widget.js"]')
        .forEach((element) => element.remove());
    [...document.documentElement.children]
        .filter((element) => element !== document.head)
        .filter((element) => element !== document.body)
        .forEach((element) => element.remove());
    window.history.pushState = history.pushState;
    window.history.replaceState = history.replaceState;
}
