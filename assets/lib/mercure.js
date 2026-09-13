/**
 * One Mercure connection per page, shared by every controller that listens.
 *
 * An SSE frame from the hub names no topic, so a message goes to each
 * subscription whose `types` lists the JSON `type` of its data. A publisher
 * keeps that `type` in the JSON: `Update::$type` renames the SSE event, and a
 * renamed event never reaches the `message` listener.
 */

const FIRST_RETRY_MILLISECONDS = 1000;
const LAST_RETRY_MILLISECONDS = 60000;

const subscriptions = new Set();
let source;
let openTopics = [];
let isOpen = false;
let lastEventId;
let flushTimeout;
let retryTimeout;
let retryDelay = FIRST_RETRY_MILLISECONDS;
let generation = 0;

/**
 * @param {string} topic
 * @param {{
 *     hub: string,
 *     authorize?: string,
 *     types: string[],
 *     onMessage?: (data: object) => void,
 *     onOpen?: () => void,
 *     onError?: () => void,
 * }} options
 * @returns {() => void} removes the subscription
 */
export function subscribe(topic, options) {
    const subscription = { topic, opened: false, ...options };
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

function flush() {
    const topics = currentTopics();
    if (topics.length === 0) {
        close();
        lastEventId = undefined;
        retryDelay = FIRST_RETRY_MILLISECONDS;
        return;
    }

    const connecting = source !== undefined || retryTimeout !== undefined;
    if (connecting && sameTopics(topics, openTopics)) {
        // A late subscriber on an open connection still needs its open.
        if (isOpen) {
            notifyOpen((s) => !s.opened);
        }
        return;
    }

    close();
    open(topics);
}

function sameTopics(left, right) {
    return (
        left.length === right.length &&
        left.every((topic, index) => topic === right[index])
    );
}

function open(topics) {
    const url = new URL(hubOf(), window.location.href);
    topics.forEach((topic) => url.searchParams.append('topic', topic));
    // A fresh EventSource sends no Last-Event-ID header, so the hub reads it here.
    if (lastEventId !== undefined && lastEventId !== '') {
        url.searchParams.set('lastEventID', lastEventId);
    }

    const current = ++generation;
    openTopics = topics;
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

function hubOf() {
    return [...subscriptions][0].hub;
}

function notifyOpen(filter) {
    [...subscriptions].filter(filter).forEach((subscription) => {
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
        .filter((s) => openTopics.includes(s.topic))
        .filter((s) => (s.types ?? []).includes(data?.type))
        .forEach((s) => s.onMessage?.(data));
}

function close() {
    generation++;
    clearTimeout(retryTimeout);
    retryTimeout = undefined;
    source?.close();
    source = undefined;
    openTopics = [];
    isOpen = false;
}

function retry() {
    const topics = openTopics;
    close();
    openTopics = topics;
    subscriptions.forEach((s) => {
        s.opened = false;
        s.onError?.();
    });

    const current = generation;
    retryTimeout = setTimeout(async () => {
        // The cookie is shared and its token expires, so each reconnect renews it.
        for (const authorize of authorizeUrls()) {
            try {
                await fetch(authorize, { credentials: 'same-origin' });
            } catch {
                // The next attempt tries again.
            }
        }
        if (current !== generation) {
            return;
        }
        retryTimeout = undefined;
        const latest = currentTopics();
        if (latest.length > 0) {
            open(latest);
        }
    }, retryDelay);
    retryDelay = Math.min(retryDelay * 2, LAST_RETRY_MILLISECONDS);
}

function currentTopics() {
    return [...new Set([...subscriptions].map((s) => s.topic))].sort();
}

function authorizeUrls() {
    return [
        ...new Set(
            [...subscriptions]
                .map((s) => s.authorize)
                .filter((authorize) => authorize !== undefined),
        ),
    ];
}

/** Forgets every subscription and the connection, for tests. */
export function reset() {
    clearTimeout(flushTimeout);
    subscriptions.clear();
    close();
    lastEventId = undefined;
    retryDelay = FIRST_RETRY_MILLISECONDS;
}
