import { Controller } from '@hotwired/stimulus';

/**
 * Reloads the board frame when the Mercure hub reports a column change.
 *
 * The board response sets the cookie that authorizes the subscription. The
 * cookie is shared by every board and its token expires, so each reconnect
 * renews it first, then reloads the frame for any change it missed.
 */

const FIRST_RETRY_MILLISECONDS = 1000;
const LAST_RETRY_MILLISECONDS = 60000;

export default class extends Controller {
    static targets = ['frame'];
    static values = { hub: String, board: String, authorize: String };

    connect() {
        this.connection = {};
        this.hasOpened = false;
        this.retryDelay = FIRST_RETRY_MILLISECONDS;
        this.open();
    }

    disconnect() {
        this.connection = undefined;
        clearTimeout(this.retryTimeout);
        this.source?.close();
        this.source = undefined;
        this.element.removeAttribute('data-board-refresh-connected');
    }

    open() {
        this.source = new EventSource(this.hubValue, { withCredentials: true });
        this.source.addEventListener('open', () => {
            if (this.hasOpened) {
                this.reload();
            }
            this.hasOpened = true;
            this.retryDelay = FIRST_RETRY_MILLISECONDS;
            this.element.setAttribute('data-board-refresh-connected', '');
        });
        this.source.addEventListener('message', () => this.reload());
        // A hub that is down only means no reload, so the error stays silent.
        this.source.addEventListener('error', () => this.retry());
    }

    retry() {
        this.source?.close();
        this.element.removeAttribute('data-board-refresh-connected');
        clearTimeout(this.retryTimeout);
        // A renewal that returns after a disconnect must not open a stream.
        const connection = this.connection;
        this.retryTimeout = setTimeout(async () => {
            try {
                // A GET that renews a cookie changes no data, so there is no form to submit.
                // eslint-disable-next-line no-restricted-syntax
                await fetch(this.authorizeValue, {
                    credentials: 'same-origin',
                });
            } catch {
                // The next attempt tries again.
            }
            if (connection !== undefined && connection === this.connection) {
                this.open();
            }
        }, this.retryDelay);
        this.retryDelay = Math.min(
            this.retryDelay * 2,
            LAST_RETRY_MILLISECONDS,
        );
    }

    reload() {
        // A frame with no src has nothing to reload, and one given a src loads it.
        if (this.frameTarget.getAttribute('src') === null) {
            this.frameTarget.src = this.boardValue;
        } else {
            this.frameTarget.reload();
        }
    }
}
