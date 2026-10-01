import { Controller } from '@hotwired/stimulus';
import { morphElements } from '@hotwired/turbo';
import { on } from '../lib/live.js';

/** A move often arrives with the automation's next change close behind it. */
export const DEBOUNCE_MILLISECONDS = 300;

/**
 * Morphs the card in again on a change to it or to a worker run, and after a
 * reconnect. An open dialog holds the update until it closes. A form with
 * unsaved input, a flash and an open disclosure keep their state.
 */
export default class extends Controller {
    static values = { cardId: String, url: String, frame: Boolean };

    connect() {
        this.held = false;
        // A dialog's close event does not bubble, so the listener captures it.
        this.onDialogClose = () => {
            if (this.held) {
                this.refresh();
            }
        };
        this.element.addEventListener('close', this.onDialogClose, true);
        this.unsubscribe = on(
            ['board.card_changed', 'worker_run.changed'],
            (change) => this.changed(change),
            { onReconnect: () => this.schedule() },
        );
    }

    disconnect() {
        clearTimeout(this.timeout);
        this.request?.abort();
        this.element.removeEventListener('close', this.onDialogClose, true);
        this.unsubscribe?.();
        this.unsubscribe = undefined;
    }

    /**
     * A local change is already on this page, and the card drawer shows a
     * deleted card. The hub echo of an own change still counts, because a drag
     * on the board moves the card the drawer shows.
     */
    changed(change) {
        if (change.local) {
            return;
        }
        if (change.type === 'board.card_changed') {
            if (change.cardId !== this.cardIdValue) {
                return;
            }
            if (change.change === 'deleted') {
                // The standalone page has no drawer to show the delete, so it loads the not-found page.
                if (!this.frameValue) {
                    window.Turbo?.visit(window.location.href, {
                        action: 'replace',
                    });
                }

                return;
            }
        }
        this.schedule();
    }

    schedule() {
        clearTimeout(this.timeout);
        this.timeout = setTimeout(() => this.refresh(), DEBOUNCE_MILLISECONDS);
    }

    async refresh() {
        if (this.holding()) {
            return;
        }
        this.request?.abort();
        const request = new AbortController();
        this.request = request;
        const tab = this.activeTab();
        const url = new URL(this.urlValue, window.location.href);
        url.searchParams.set('tab', tab);
        // The refresh header keeps the subscriber cookie and the waiting flashes.
        const headers = { Accept: 'text/html', 'X-Loupe-Live-Refresh': '1' };
        if (this.frameValue) {
            headers['Turbo-Frame'] = 'card-drawer-frame';
        }

        let html;
        try {
            // eslint-disable-next-line no-restricted-syntax -- a read-only GET of the card to morph, not a mutation.
            const response = await fetch(url.toString(), {
                headers,
                credentials: 'same-origin',
                signal: request.signal,
            });
            if (!response?.ok) {
                return;
            }
            html = await response.text();
        } catch {
            return;
        }
        if (request.signal.aborted || this.holding()) {
            return;
        }
        if (this.activeTab() !== tab) {
            this.schedule();

            return;
        }

        const fresh = [
            ...new DOMParser()
                .parseFromString(html, 'text/html')
                .querySelectorAll('[data-card-drawer-card-id]'),
        ].find(
            (element) => element.dataset.cardDrawerCardId === this.cardIdValue,
        );
        if (fresh) {
            this.morph(fresh);
        }
    }

    holding() {
        this.held = this.element.querySelector('dialog[open]') !== null;

        return this.held;
    }

    activeTab() {
        return (
            this.element.querySelector(
                '[data-panel-tabs-target="tab"][aria-selected="true"]',
            )?.dataset.panelTab ?? 'overview'
        );
    }

    morph(fresh) {
        const keepElement = (event) => {
            const target = event.target;
            if (
                target.classList?.contains('lp-flash') ||
                (target instanceof HTMLFormElement &&
                    hasUnsavedInput(target)) ||
                // A tab where the reader loaded more, such as older history, keeps what it loaded.
                (target.getAttribute?.('role') === 'tabpanel' &&
                    target.querySelector('turbo-frame[src]') !== null)
            ) {
                event.preventDefault();
            }
        };
        const keepOpenDisclosure = (event) => {
            if (
                event.target instanceof HTMLDetailsElement &&
                event.detail.attributeName === 'open' &&
                event.detail.mutationType === 'remove'
            ) {
                event.preventDefault();
            }
        };
        this.element.addEventListener(
            'turbo:before-morph-element',
            keepElement,
            true,
        );
        this.element.addEventListener(
            'turbo:before-morph-attribute',
            keepOpenDisclosure,
            true,
        );
        try {
            morphElements(this.element, fresh, { ignoreActiveValue: true });
        } finally {
            this.element.removeEventListener(
                'turbo:before-morph-element',
                keepElement,
                true,
            );
            this.element.removeEventListener(
                'turbo:before-morph-attribute',
                keepOpenDisclosure,
                true,
            );
        }
    }
}

function hasUnsavedInput(form) {
    return [...form.elements].some((field) => {
        if (field instanceof HTMLSelectElement) {
            const options = [...field.options];
            if (field.multiple) {
                return options.some(
                    (option) => option.selected !== option.defaultSelected,
                );
            }
            const initial =
                options.findLast((option) => option.defaultSelected) ??
                options[0];

            return field.options[field.selectedIndex] !== initial;
        }
        if (field.type === 'checkbox' || field.type === 'radio') {
            return field.checked !== field.defaultChecked;
        }
        if (
            field instanceof HTMLInputElement ||
            field instanceof HTMLTextAreaElement
        ) {
            return field.value !== field.defaultValue;
        }

        return false;
    });
}
