import {
    keepBreadcrumb,
    scrubEvent,
    scrubSpan,
    scrubTransaction,
} from './sentry_scrub.js';

function meta(name) {
    return document.querySelector(`meta[name="${name}"]`)?.content ?? null;
}

// The SDK loads from a classic deferred script, which runs before the module
// scripts that follow it, so it runs before this module.
export function initSentry(Sentry = window.Sentry) {
    const dsn = meta('sentry-browser-dsn');
    if (!Sentry || !dsn) {
        return false;
    }
    const route = meta('loupe-route') || null;

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
                    name: route ?? context.op,
                }),
            }),
            Sentry.breadcrumbsIntegration({ console: false }),
        ],
        beforeSend: (event) => scrubEvent(event, route),
        beforeSendTransaction: (event) =>
            scrubTransaction(scrubEvent(event, route), route),
        beforeSendSpan: Sentry.withStaticSpan((span) => scrubSpan(span, route)),
        beforeBreadcrumb: keepBreadcrumb,
    });
    // The page load wrote the path into the scope during init.
    Sentry.getCurrentScope().setTransactionName(route ?? undefined);

    return true;
}

initSentry();
