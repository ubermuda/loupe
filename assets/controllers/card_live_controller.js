import { Controller } from '@hotwired/stimulus';
import { morphElements } from '@hotwired/turbo';
import { on } from '../lib/live.js';

/** A move often arrives with the automation's next change close behind it. */
export const DEBOUNCE_MILLISECONDS = 300;

/**
 * Morphs the card in again when the Mercure hub reports a change to it or to
 * a worker run, and after each reconnect for any change it missed. An open
 * dialog holds the update until it closes. A form with unsaved input, a flash
 * and an open disclosure keep their state through the morph.
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
        if (
            change.type === 'board.card_changed' &&
            (change.cardId !== this.cardIdValue || change.change === 'deleted')
        ) {
            return;
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
        const url = new URL(this.urlValue, window.location.href);
        url.searchParams.set('tab', this.activeTab());
        // A frame response leaves the subscriber cookie of the host page alone.
        const headers = { Accept: 'text/html' };
        if (this.frameValue) {
            headers['Turbo-Frame'] = 'card-drawer-frame';
        }

        let html;
        try {
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
                (target instanceof HTMLFormElement && hasUnsavedInput(target))
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
