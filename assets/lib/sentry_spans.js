const SUBMIT = 'ui.turbo.submit';
const VISIT = 'navigation';
const STREAM_TYPE = 'text/vnd.turbo-stream.html';
const CAP_MILLISECONDS = 10000;
const RENDER_SIGNALS = [
    'turbo:before-stream-render',
    'turbo:render',
    'turbo:frame-render',
];

let Sentry = null;
let listeners = null;
let open = null;

// The SDK reads a number above 9999999999 as epoch milliseconds and any
// smaller number as seconds, so a bare performance.now() is wrong.
export function now() {
    return performance.timeOrigin + performance.now();
}

function attributes(at) {
    const result = {
        'loupe.tab_age_ms': Math.round(at - performance.timeOrigin),
    };
    if (performance.memory) {
        result['loupe.heap_mb'] = Math.round(
            performance.memory.usedJSHeapSize / 1048576,
        );
    }

    return result;
}

function route(fallback) {
    return (
        document.querySelector('meta[name="loupe-route"]')?.content || fallback
    );
}

function afterPaint(callback) {
    if (document.visibilityState === 'hidden') {
        setTimeout(() => setTimeout(callback, 0), 0);
    } else {
        requestAnimationFrame(() => setTimeout(callback, 0));
    }
}

// Without its own trace, a root span joins the trace of the page load.
function startRoot(options) {
    return Sentry.startNewTrace(() => Sentry.startInactiveSpan(options));
}

function finish(interaction, timestamp) {
    if (open !== interaction) {
        return;
    }
    open = null;
    clearTimeout(interaction.cap);
    if (timestamp === undefined) {
        interaction.span.end();
    } else {
        interaction.span.end(timestamp);
    }
}

function begin(op) {
    if (open) {
        finish(open);
    }
    const startTime = now();
    const span = startRoot({
        op,
        name: route(op),
        attributes: attributes(startTime),
    });
    Sentry.setActiveSpanInBrowser(span);
    const interaction = { span, op, lastSignal: startTime, awaiting: false };
    interaction.cap = setTimeout(
        () => finish(interaction, interaction.lastSignal),
        CAP_MILLISECONDS,
    );
    open = interaction;
}

function signal(op) {
    if (open?.op !== op) {
        return null;
    }
    open.lastSignal = now();

    return open;
}

function finishAfterPaint(interaction) {
    interaction.awaiting = false;
    afterPaint(() => finish(interaction));
}

function rendersBody(fetchResponse) {
    return (
        Boolean(fetchResponse?.isHTML) ||
        Boolean(fetchResponse?.contentType?.startsWith(STREAM_TYPE))
    );
}

function onSubmitEnd(event) {
    const interaction = signal(SUBMIT);
    if (!interaction) {
        return;
    }
    if (rendersBody(event.detail?.fetchResponse)) {
        interaction.awaiting = true;
    } else {
        finishAfterPaint(interaction);
    }
}

function onRender() {
    if (open?.awaiting) {
        finishAfterPaint(signal(SUBMIT));
    }
}

function onLoad() {
    const interaction = signal(VISIT);
    if (interaction) {
        Sentry.updateSpanName(interaction.span, route(VISIT));
        finishAfterPaint(interaction);
    }
}

export function enableSpans(sentry) {
    Sentry = sentry;
    if (listeners) {
        return;
    }
    listeners = new AbortController();
    const options = { signal: listeners.signal };
    const listen = (type, handler) =>
        document.addEventListener(type, handler, options);

    listen('turbo:submit-start', () => begin(SUBMIT));
    listen('turbo:submit-end', onSubmitEnd);
    RENDER_SIGNALS.forEach((type) => listen(type, onRender));
    listen('turbo:visit', () => begin(VISIT));
    listen('turbo:load', onLoad);
    listen('turbo:fetch-request-error', () => open && finish(open));
}

export function traceLive(type, startTime, result) {
    if (!Sentry || typeof result?.then !== 'function') {
        return;
    }
    const record = () =>
        afterPaint(() =>
            startRoot({
                op: 'ui.live',
                name: type,
                startTime,
                attributes: attributes(startTime),
            }).end(),
        );
    result.then(record, record);
}

export function reset() {
    if (open) {
        clearTimeout(open.cap);
    }
    listeners?.abort();
    listeners = null;
    open = null;
    Sentry = null;
}
