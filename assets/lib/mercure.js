import {
    generateCsrfHeaders,
    generateCsrfToken,
    removeCsrfToken,
} from '../controllers/csrf_protection_controller.js';

/**
 * One Mercure connection per page, shared by every feature that listens.
 *
 * The server decides the topics: a template calls mercure_subscribe(), and the
 * layout renders the allowed ones into #mercure-subscriptions with the hub URL
 * and the renewal form. A feature only names the message types it handles.
 *
 * An SSE frame from the hub names no topic, so a message goes to each
 * subscription whose types list the JSON `type` of its data. A publisher keeps
 * that `type` in the JSON: `Update::$type` renames the SSE event, and a renamed
 * event never reaches the `message` listener.
 */

const PAGE_ELEMENT_ID = 'mercure-subscriptions';
const FIRST_RETRY_MILLISECONDS = 1000;
const LAST_RETRY_MILLISECONDS = 60000;

const subscriptions = new Set();
let source;
let openHub;
let openTopics = [];
let isOpen = false;
let lastEventId;
let flushTimeout;
let retryTimeout;
let retryDelay = FIRST_RETRY_MILLISECONDS;
let generation = 0;

/**
 * subscribe(types, handler, options) listens on every topic of the page.
 * subscribe(topic, types, handler, options) listens only while that topic is.
 *
 * @param {...*} args
 * @returns {() => void} removes the subscription
 */
export function subscribe(...args) {
    const scoped = typeof args[1] !== 'function';
    const [topic, types, handler, options] = scoped
        ? args
        : [undefined, ...args];
    const subscription = {
        topic,
        types: [types].flat(),
        handler,
        onOpen: options?.onOpen,
        onError: options?.onError,
        opened: false,
    };
    subscriptions.add(subscription);
    scheduleFlush();

    return () => {
        if (subscriptions.delete(subscription)) {
            scheduleFlush();
        }
    };
}

function scheduleFlush() {
    // Controllers connect and disconnect in bursts, and the burst reopens once.
    clearTimeout(flushTimeout);
    flushTimeout = setTimeout(flush, 0);
}

function readPage() {
    const form = document.getElementById(PAGE_ELEMENT_ID);
    if (!form || !form.dataset.hub) {
        return null;
    }
    const topics = [...form.querySelectorAll('input[data-mercure-topic]')]
        .map((input) => input.value)
        .sort();

    return { form, hub: form.dataset.hub, topics };
}

function flush() {
    const page = subscriptions.size > 0 ? readPage() : null;
    if (page === null || page.topics.length === 0) {
        close();
        lastEventId = undefined;
        retryDelay = FIRST_RETRY_MILLISECONDS;
        return;
    }

    const connecting = source !== undefined || retryTimeout !== undefined;
    if (
        connecting &&
        page.hub === openHub &&
        sameTopics(page.topics, openTopics)
    ) {
        // A late subscriber on an open connection still needs its open.
        if (isOpen) {
            notifyOpen((s) => !s.opened);
        }
        return;
    }

    close();
    open(page);
}

function sameTopics(left, right) {
    return (
        left.length === right.length &&
        left.every((topic, index) => topic === right[index])
    );
}

function open(page) {
    const url = new URL(page.hub, window.location.href);
    page.topics.forEach((topic) => url.searchParams.append('topic', topic));
    // A fresh EventSource sends no Last-Event-ID header, so the hub reads it here.
    if (lastEventId !== undefined && lastEventId !== '') {
        url.searchParams.set('lastEventID', lastEventId);
    }

    const current = ++generation;
    openHub = page.hub;
    openTopics = page.topics;
    source = new EventSource(url.toString(), { withCredentials: true });
    source.addEventListener('open', () => {
        if (current !== generation) {
            return;
        }
        isOpen = true;
        retryDelay = FIRST_RETRY_MILLISECONDS;
        subscriptions.forEach((s) => (s.opened = false));
        notifyOpen(() => true);
    });
    source.addEventListener('message', (event) => {
        if (current !== generation) {
            return;
        }
        if (event.lastEventId) {
            lastEventId = event.lastEventId;
        }
        dispatch(event.data);
    });
    // A hub that is down only means no updates, so the error stays silent.
    source.addEventListener('error', () => {
        if (current === generation) {
            retry();
        }
    });
}

function listening(subscription) {
    return (
        subscription.topic === undefined ||
        openTopics.includes(subscription.topic)
    );
}

function notifyOpen(filter) {
    [...subscriptions]
        .filter(listening)
        .filter(filter)
        .forEach((subscription) => {
            subscription.opened = true;
            subscription.onOpen?.();
        });
}

function dispatch(raw) {
    let data;
    try {
        data = JSON.parse(raw);
    } catch {
        return;
    }
    [...subscriptions]
        .filter(listening)
        .filter((s) => s.types.includes(data?.type))
        .forEach((s) => s.handler?.(data));
}

function close() {
    generation++;
    clearTimeout(retryTimeout);
    retryTimeout = undefined;
    source?.close();
    source = undefined;
    openHub = undefined;
    openTopics = [];
    isOpen = false;
}

function retry() {
    const hub = openHub;
    const topics = openTopics;
    close();
    openHub = hub;
    openTopics = topics;
    subscriptions.forEach((s) => (s.opened = false));
    [...subscriptions].filter(listening).forEach((s) => s.onError?.());

    const current = generation;
    retryTimeout = setTimeout(async () => {
        // The cookie is shared and its token expires, so each reconnect renews it.
        await renew();
        if (current !== generation) {
            return;
        }
        retryTimeout = undefined;
        openHub = undefined;
        openTopics = [];
        flush();
    }, retryDelay);
    retryDelay = Math.min(retryDelay * 2, LAST_RETRY_MILLISECONDS);
}

async function renew() {
    const page = readPage();
    if (page === null) {
        return;
    }

    const { form } = page;
    generateCsrfToken(form);
    const body = new URLSearchParams();
    form.querySelectorAll('input[name]').forEach((input) =>
        body.append(input.name, input.value),
    );
    try {
        const response = await fetch(form.action, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                ...generateCsrfHeaders(form),
            },
        });
        if (response.ok) {
            // A topic the server refuses now would only fail again at the hub.
            const { topics } = await response.json();
            form.querySelectorAll('input[data-mercure-topic]').forEach(
                (input) => topics.includes(input.value) || input.remove(),
            );
        }
    } catch {
        // The next attempt tries again.
    } finally {
        removeCsrfToken(form);
    }
}

if (typeof document !== 'undefined') {
    // A Turbo visit swaps the page element, and with it the topics.
    document.addEventListener('turbo:load', () => {
        if (subscriptions.size > 0) {
            scheduleFlush();
        }
    });
}

/** Forgets every subscription and the connection, for tests. */
export function reset() {
    clearTimeout(flushTimeout);
    subscriptions.clear();
    close();
    lastEventId = undefined;
    retryDelay = FIRST_RETRY_MILLISECONDS;
}
