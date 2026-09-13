import { Controller } from '@hotwired/stimulus';

/**
 * Reloads the board frame when the Mercure hub reports a column change.
 *
 * The hub URL carries the topic, and the board response set the cookie that
 * authorizes it. A hub that is down only means no reload, so errors stay silent.
 */
export default class extends Controller {
    static targets = ['frame'];
    static values = { hub: String, board: String };

    connect() {
        this.source = new EventSource(this.hubValue, { withCredentials: true });
        this.source.addEventListener('open', () => {
            this.element.setAttribute('data-board-refresh-connected', '');
        });
        this.source.addEventListener('error', () => {
            this.element.removeAttribute('data-board-refresh-connected');
        });
        this.source.addEventListener('message', () => this.reload());
    }

    disconnect() {
        this.source?.close();
        this.source = undefined;
        this.element.removeAttribute('data-board-refresh-connected');
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
