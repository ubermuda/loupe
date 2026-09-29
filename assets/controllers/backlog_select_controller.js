import { Controller } from '@hotwired/stimulus';

/**
 * Drives the row checkboxes of the Backlog page: the bulk bar shows while a
 * row is ticked, and the header box selects every row of the page. A row that
 * a stream removes takes its checkbox with it, so the count follows.
 */
export default class extends Controller {
    static targets = ['box', 'all', 'bar', 'count'];

    connect() {
        this.update();
    }

    boxTargetConnected() {
        this.update();
    }

    boxTargetDisconnected() {
        this.update();
    }

    toggleAll() {
        const checked = this.hasAllTarget && this.allTarget.checked;
        this.boxTargets.forEach((box) => {
            box.checked = checked;
        });
        this.update();
    }

    clear() {
        this.boxTargets.forEach((box) => {
            box.checked = false;
        });
        this.update();
    }

    update() {
        const total = this.boxTargets.length;
        const ticked = this.boxTargets.filter((box) => box.checked).length;
        if (this.hasBarTarget) {
            this.barTarget.hidden = ticked === 0;
        }
        if (this.hasCountTarget) {
            this.countTarget.textContent = String(ticked);
        }
        if (this.hasAllTarget) {
            this.allTarget.checked = total > 0 && ticked === total;
            this.allTarget.indeterminate = ticked > 0 && ticked < total;
        }
    }
}
