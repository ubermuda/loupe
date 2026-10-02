// Mirrors App\Observability\SentryEventScrubber: a page URL can carry a
// secret, so no path or query leaves the browser. Asset URLs stay whole.
const URL_PATTERN = /\b[a-z][a-z0-9+.-]*:\/\/[^\s"'<>]+/gi;
const EMAIL_PATTERN = /[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi;
const URL_DATA_KEYS = new Set([
    'url',
    'http.url',
    'http.query',
    'http.fragment',
    'http.request.header.referer',
    'lcp.url',
    'browser.web_vital.lcp.url',
]);
const SELECTOR_DATA_KEYS = new Set([
    'lcp.element',
    'browser.web_vital.lcp.element',
    'browser.web_vital.inp.target',
]);
// The SDK fills these from the scope, which holds the page path.
const ROUTE_DATA_KEYS = new Set(['sentry.transaction', 'sentry.segment.name']);
const DROPPED_CRUMB_CATEGORIES = new Set([
    'fetch',
    'xhr',
    'navigation',
    'console',
]);
const SELECTOR_ATTRIBUTE = /\[[a-z-]+=".*?"\](?=\[|\s>\s|$)/g;

function origin(url) {
    try {
        const parsed = new URL(url);
        return parsed.host === ''
            ? '[url]'
            : `${parsed.protocol}//${parsed.host}`;
    } catch {
        return '[url]';
    }
}

export function redact(text) {
    return text
        .replace(URL_PATTERN, (url) => origin(url))
        .replace(EMAIL_PATTERN, '[email]');
}

function scrubLocation(text) {
    try {
        const parsed = new URL(text);
        if (
            parsed.pathname.startsWith('/assets/') &&
            parsed.search === '' &&
            parsed.hash === ''
        ) {
            return text;
        }
    } catch {
        // Not a URL: redact what it holds.
    }

    return redact(text);
}

// The SDK builds a selector from tag, id, classes, then the values of
// aria-label, type, name, title and alt, which can hold user content.
export function scrubSelector(selector) {
    const stripped = selector.replace(SELECTOR_ATTRIBUTE, '');

    return stripped.includes('"') ? '[Filtered]' : stripped;
}

function isSelectorKey(key) {
    return (
        SELECTOR_DATA_KEYS.has(key) ||
        /^(browser\.web_vital\.)?cls\.source\.\d+$/.test(key)
    );
}

function scrubData(data, route) {
    for (const [key, value] of Object.entries(data)) {
        if (ROUTE_DATA_KEYS.has(key)) {
            if (route) {
                data[key] = route;
            } else {
                delete data[key];
            }
        } else if (URL_DATA_KEYS.has(key) || key.startsWith('url.')) {
            delete data[key];
        } else if (typeof value === 'string') {
            data[key] = isSelectorKey(key)
                ? scrubSelector(value)
                : scrubLocation(value);
        }
    }
}

export function scrubSpan(span, route = null) {
    if (span.data) {
        scrubData(span.data, route);
    }
    if (typeof span.description === 'string') {
        const op = span.op ?? '';
        if (op === 'http.client') {
            span.description = span.description.split(' ')[0];
        } else if (
            op.startsWith('ui.interaction') ||
            op.startsWith('ui.webvital')
        ) {
            span.description = scrubSelector(span.description);
        } else {
            span.description = scrubLocation(span.description);
        }
    }

    return span;
}

export function keepBreadcrumb(crumb) {
    if (DROPPED_CRUMB_CATEGORIES.has(crumb.category)) {
        return null;
    }
    if (
        crumb.category?.startsWith('ui.') &&
        typeof crumb.message === 'string'
    ) {
        crumb.message = scrubSelector(crumb.message);
    }

    return crumb;
}

function scrubFrames(stacktrace) {
    for (const frame of stacktrace?.frames ?? []) {
        for (const key of ['filename', 'abs_path']) {
            if (typeof frame[key] === 'string') {
                frame[key] = scrubLocation(frame[key]);
            }
        }
    }
}

// The SDK names an error event after the page path, so the route replaces it.
export function scrubEvent(event, route = null) {
    if (route) {
        event.transaction = route;
    } else if (event.type !== 'transaction') {
        delete event.transaction;
    }
    if (event.request) {
        delete event.request.url;
        delete event.request.query_string;
        delete event.request.headers?.Referer;
    }
    if (typeof event.message === 'string') {
        event.message = redact(event.message);
    }
    for (const key of ['message', 'formatted']) {
        if (typeof event.logentry?.[key] === 'string') {
            event.logentry[key] = redact(event.logentry[key]);
        }
    }
    for (const exception of event.exception?.values ?? []) {
        if (typeof exception.value === 'string') {
            exception.value = redact(exception.value);
        }
        scrubFrames(exception.stacktrace);
    }
    scrubFrames(event.stacktrace);
    for (const crumb of event.breadcrumbs ?? []) {
        if (typeof crumb.message === 'string') {
            crumb.message = redact(crumb.message);
        }
        if (crumb.data) {
            delete crumb.data.url;
            delete crumb.data.from;
            delete crumb.data.to;
        }
    }

    return event;
}

export function scrubTransaction(event, route) {
    if (event.contexts?.trace?.data) {
        scrubData(event.contexts.trace.data, route);
    }
    for (const span of event.spans ?? []) {
        scrubSpan(span, route);
    }

    return event;
}
