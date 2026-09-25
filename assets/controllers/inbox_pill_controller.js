import { Controller } from '@hotwired/stimulus';
import { on } from '../lib/live.js';

/**
 * Reloads the sidebar pill when the hub reports a change to this project's open
 * count, and on each open of the connection for any change made while it was
 * not listening, the first open included. One topic carries every project of
 * the user, so a change to another project is ignored.
 */
export default class extends Controller {
    static targets = ['frame'];
    static values = { project: String, url: String };

    connect() {
        this.unsubscribe = on(
            'inbox.open_count_changed',
            (data) => {
                if (data?.projectId === this.projectValue) {
                    this.reload();
                }
            },
            {
                onOpen: () => {
                    this.reload();
                    this.element.setAttribute('data-inbox-pill-connected', '');
                },
                onError: () =>
                    this.element.removeAttribute('data-inbox-pill-connected'),
            },
        );
    }

    disconnect() {
        this.unsubscribe?.();
        this.unsubscribe = undefined;
        this.element.removeAttribute('data-inbox-pill-connected');
    }

    keepPill(event) {
        // A failed reload has no frame to show, and Turbo would write "Content missing" into the link.
        event.preventDefault();
    }

    reload() {
        // A new load cancels the one in flight, so the last change wins.
        if (this.frameTarget.getAttribute('src') === null) {
            this.frameTarget.src = this.urlValue;
        } else {
            this.frameTarget.reload();
        }
    }
}
