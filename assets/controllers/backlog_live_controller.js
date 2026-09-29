import { Controller } from '@hotwired/stimulus';
import { on } from '../lib/live.js';

/**
 * Shows the reload notice of the Backlog page when another person or an agent
 * changes any card or column of the board, in the Backlog or not. A change
 * this page made is its own, so it shows nothing. A reconnect shows it too,
 * because the hub may have lost the changes made while the page was away.
 */
export default class extends Controller {
    static targets = ['notice'];

    connect() {
        this.unsubscribe = on(
            ['board.card_changed', 'board.columns_changed'],
            (change) => this.receive(change),
            { onReconnect: () => this.show() },
        );
    }

    disconnect() {
        this.unsubscribe?.();
    }

    receive(change) {
        if (change.own) {
            return;
        }
        this.show();
    }

    show() {
        if (this.hasNoticeTarget) {
            this.noticeTarget.hidden = false;
        }
    }
}
