import {
    hasHub,
    reset as resetMercure,
    subscribe,
    watchConnection,
} from './mercure.js';

/**
 * One event layer for live changes. A handler gets a change from the hub and
 * a change this page emits through one path, and `own` tells it whether this
 * page made the change. The server echoes the X-Loupe-Origin header back as
 * the `origin` of each hub message.
 */

const ORIGIN_HEADER = 'X-Loupe-Origin';
const GRACE_MILLISECONDS = 5000;

const localHandlers = new Map();
const statusListeners = new Set();
let pageOrigin;
let graceTimeout;
let paused = false;
let reported;

/**
 * @param {string|string[]} types
 * @param {(change: object) => void} handler
 * @param {{onReconnect?: Function, onOpen?: Function, onError?: Function}} options
 * @returns {() => void} removes the handler
 */
export function on(types, handler, options = {}) {
    const local = (detail) => handler(detail);
    const typeList = [types].flat();
    typeList.forEach((type) => {
        if (!localHandlers.has(type)) {
            localHandlers.set(type, new Set());
        }
        localHandlers.get(type).add(local);
    });

    let opened = false;
    const unsubscribe = subscribe(
        types,
        (data) =>
            handler({
                ...data,
                local: false,
                own: data.origin === originId(),
            }),
        {
            onOpen: () => {
                if (opened) {
                    options.onReconnect?.();
                }
                opened = true;
                options.onOpen?.();
            },
            onError: options.onError,
        },
    );

    return () => {
        typeList.forEach((type) => localHandlers.get(type)?.delete(local));
        unsubscribe();
    };
}

/** Delivers a change this page made to its own handlers, at once. */
export function emit(type, detail = {}) {
    [...(localHandlers.get(type) ?? [])].forEach((local) =>
        local({ ...detail, type, local: true, own: true }),
    );
}

/**
 * Calls the listener at once and on each change with 'live', 'paused' or 'off'.
 *
 * @param {(state: string) => void} listener
 * @returns {() => void} removes the listener
 */
export function status(listener) {
    report();
    statusListeners.add(listener);
    listener(reported);

    return () => statusListeners.delete(listener);
}

export function originId() {
    pageOrigin ??= createOrigin();

    return pageOrigin;
}

function createOrigin() {
    // randomUUID exists only in a secure context.
    if (typeof globalThis.crypto?.randomUUID === 'function') {
        return globalThis.crypto.randomUUID();
    }

    return Array.from({ length: 4 }, () =>
        Math.random().toString(36).slice(2, 10).padEnd(8, '0'),
    ).join('-');
}

function onConnection(state) {
    if (state === 'down') {
        if (graceTimeout === undefined && !paused) {
            graceTimeout = setTimeout(() => {
                graceTimeout = undefined;
                paused = true;
                report();
            }, GRACE_MILLISECONDS);
        }
    } else {
        clearTimeout(graceTimeout);
        graceTimeout = undefined;
        paused = false;
    }
    report();
}

function report() {
    const next = !hasHub() ? 'off' : paused ? 'paused' : 'live';
    if (next === reported) {
        return;
    }
    reported = next;
    [...statusListeners].forEach((listener) => listener(next));
}

if (typeof document !== 'undefined') {
    document.addEventListener('turbo:load', report);
    document.addEventListener('turbo:before-fetch-request', (event) => {
        const { url, fetchOptions } = event.detail ?? {};
        if (!url || !fetchOptions) {
            return;
        }
        if (
            new URL(url, window.location.href).origin !== window.location.origin
        ) {
            return;
        }
        fetchOptions.headers ??= {};
        fetchOptions.headers[ORIGIN_HEADER] = originId();
    });
    watchConnection(onConnection);
}

/** Forgets every handler, the status and the connection, for tests. */
export function reset() {
    resetMercure();
    localHandlers.clear();
    statusListeners.clear();
    clearTimeout(graceTimeout);
    graceTimeout = undefined;
    paused = false;
    reported = undefined;
    pageOrigin = undefined;
    watchConnection(onConnection);
}
