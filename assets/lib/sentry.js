import {
    keepBreadcrumb,
    scrubEvent,
    scrubSpan,
    scrubTransaction,
} from './sentry_scrub.js';
import { enableSpans } from './sentry_spans.js';

function meta(name) {
    return document.querySelector(`meta[name="${name}"]`)?.content ?? null;
}

// beforeStartSpan names a page load after its route, and a Turbo visit can
// change the route before the transaction ends. Any name that is not a route
// or an op, such as one that holds a path, takes the current route instead.
function transactionName(name, route) {
    return typeof name === 'string' && /^[a-z0-9_.]+$/i.test(name)
        ? name
        : route;
}

// The SDK loads from a classic deferred script, which runs before the module
// scripts that follow it, so it runs before this module.
export function initSentry(Sentry = window.Sentry) {
    const dsn = meta('sentry-browser-dsn');
    if (!Sentry || !dsn) {
        return false;
    }
    // Turbo swaps the head meta tags on each visit, so read the route late.
    const route = () => meta('loupe-route') || null;

    Sentry.init({
        dsn,
        release: meta('sentry-release') ?? undefined,
        environment: meta('sentry-environment'),
        tracesSampleRate: Number(meta('sentry-browser-traces-sample-rate')),
        // The default 'stream' lifecycle ignores beforeSendTransaction.
        traceLifecycle: 'static',
        // These replace sendDefaultPii, which SDK 11 no longer reads. Headers
        // stay on for User-Agent; scrubEvent drops the Referer.
        dataCollection: {
            userInfo: false,
            cookies: false,
            urlQueryParams: false,
        },
        integrations: [
            Sentry.browserTracingIntegration({
                instrumentNavigation: false,
                beforeStartSpan: (context) => ({
                    ...context,
                    name: route() ?? context.op,
                }),
            }),
            Sentry.breadcrumbsIntegration({ console: false }),
        ],
        beforeSend: (event) => scrubEvent(event, route()),
        beforeSendTransaction: (event) => {
            const name =
                transactionName(event.transaction, route()) ??
                event.contexts?.trace?.op ??
                'transaction';

            return scrubTransaction(scrubEvent(event, name), name);
        },
        beforeSendSpan: Sentry.withStaticSpan((span) =>
            scrubSpan(span, route()),
        ),
        beforeBreadcrumb: keepBreadcrumb,
    });
    // The page load wrote the path into the scope during init.
    const nameScope = () =>
        Sentry.getCurrentScope().setTransactionName(route() ?? undefined);
    nameScope();
    document.addEventListener('turbo:load', nameScope);
    enableSpans(Sentry);

    return true;
}

initSentry();
