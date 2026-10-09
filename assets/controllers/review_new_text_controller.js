import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'loupe.review.newText';

export default class extends Controller {
    static values = {
        url: String,
        noPreviousMessage: String,
        refusedMessage: String,
        errorMessage: String,
    };

    connect() {
        this.title = this.element.getAttribute('title') ?? '';
        this.anchors = null;
        this.request = 0;
        if (!this.#isDisabled() && this.#remembered()) {
            this.#turn(true);
        }
    }

    disconnect() {
        this.request++;
    }

    toggle() {
        if (this.#isDisabled()) {
            return;
        }
        const on = this.element.getAttribute('aria-checked') !== 'true';
        this.#remember(on);
        this.#turn(on);
    }

    async #turn(on) {
        this.element.setAttribute('aria-checked', on ? 'true' : 'false');
        const request = ++this.request;
        this.element.setAttribute('title', this.title);
        if (!on) {
            this.#paint([]);
            return;
        }
        try {
            this.anchors ??= await this.#load();
        } catch {
            if (request === this.request) {
                this.#off(this.errorMessageValue, false);
            }
            return;
        }
        if (request !== this.request) {
            return;
        }
        if (this.anchors.reason !== null) {
            const message =
                this.anchors.reason === 'no-previous-version'
                    ? this.noPreviousMessageValue
                    : this.refusedMessageValue;
            this.#off(message, true);
            return;
        }
        this.#paint(this.anchors.anchors);
    }

    async #load() {
        // A read of the passages, not a mutation, so no form is involved.
        // eslint-disable-next-line no-restricted-syntax
        const response = await fetch(this.urlValue, {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) {
            throw new Error(`New text request failed: ${response.status}`);
        }
        const body = await response.json();

        return { anchors: body.anchors ?? [], reason: body.reason ?? null };
    }

    // The stored choice stays as it was, so a version that cannot be compared
    // does not turn the highlight off for the next one.
    #off(message, disabled) {
        this.element.setAttribute('aria-checked', 'false');
        if (disabled) {
            this.element.setAttribute('aria-disabled', 'true');
        }
        this.element.setAttribute('title', message);
        this.#paint([]);
    }

    #paint(anchors) {
        this.dispatch('paint', { detail: { anchors } });
    }

    // aria-disabled rather than disabled, so the button keeps its tooltip.
    #isDisabled() {
        return this.element.getAttribute('aria-disabled') === 'true';
    }

    #remembered() {
        try {
            return window.localStorage.getItem(STORAGE_KEY) === '1';
        } catch {
            return false;
        }
    }

    #remember(on) {
        try {
            if (on) {
                window.localStorage.setItem(STORAGE_KEY, '1');
            } else {
                window.localStorage.removeItem(STORAGE_KEY);
            }
        } catch {
            // Storage can be blocked. The switch still works on this page.
        }
    }
}
