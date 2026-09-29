import { Controller } from '@hotwired/stimulus';

/**
 * Changes the rank of a Backlog row by a drag on its handle. The row moves in
 * the list at once, and the drop submits the row's hidden form with the
 * visible row below it, or the visible row above it when it lands last. A
 * refused rank puts the row back, and its stream answer says why. One drag
 * waits for the last to settle.
 */

// Above mouse jitter, below what a person reads as a drag.
const THRESHOLD_PIXELS = 5;
const DRAGGING_CLASS = 'lp-backlog-row--dragging';
const STREAM_TYPE = 'text/vnd.turbo-stream.html';

export default class extends Controller {
    static targets = ['row'];

    connect() {
        this.drag = null;
        this.pending = null;
    }

    disconnect() {
        this.#stopListening();
        this.drag = null;
    }

    press(event) {
        if (event.button !== 0 || this.drag !== null || this.pending !== null) {
            return;
        }
        const grip = event.currentTarget;
        const row = grip.closest('[data-backlog-rank-target="row"]');
        if (row === null || !this.element.contains(row)) {
            return;
        }
        event.preventDefault();
        this.drag = {
            row,
            grip,
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            started: false,
            originNext: row.nextElementSibling,
        };
        grip.setPointerCapture?.(event.pointerId);
        this.onPointerMove = (moveEvent) => this.move(moveEvent);
        this.onPointerUp = (upEvent) => this.release(upEvent);
        // A cancel is the browser taking the gesture back, so it never commits.
        this.onPointerCancel = (cancelEvent) => this.cancel(cancelEvent);
        // Moving the row drops the grip's pointer capture, so the window listens.
        window.addEventListener('pointermove', this.onPointerMove);
        window.addEventListener('pointerup', this.onPointerUp);
        window.addEventListener('pointercancel', this.onPointerCancel);
    }

    move(event) {
        const drag = this.drag;
        if (drag === null || event.pointerId !== drag.pointerId) {
            return;
        }
        if (!drag.started) {
            const distance = Math.hypot(
                event.clientX - drag.startX,
                event.clientY - drag.startY,
            );
            if (distance < THRESHOLD_PIXELS) {
                return;
            }
            drag.started = true;
            drag.row.classList.add(DRAGGING_CLASS);
        }
        const below = this.#rowBelow(event.clientY, drag.row);
        if (below === null) {
            if (this.#rows().at(-1) !== drag.row) {
                this.element.append(drag.row);
            }
        } else if (below.previousElementSibling !== drag.row) {
            below.before(drag.row);
        }
    }

    release(event) {
        const drag = this.drag;
        if (drag === null || event.pointerId !== drag.pointerId) {
            return;
        }
        this.#stopListening();
        this.drag = null;
        drag.row.classList.remove(DRAGGING_CLASS);
        if (!drag.started || drag.row.nextElementSibling === drag.originNext) {
            return;
        }
        this.#submit(drag.row, drag.originNext);
    }

    cancel(event) {
        const drag = this.drag;
        if (drag === null || event.pointerId !== drag.pointerId) {
            return;
        }
        this.#stopListening();
        this.drag = null;
        drag.row.classList.remove(DRAGGING_CLASS);
        this.#restore(drag.row, drag.originNext);
    }

    #submit(row, originNext) {
        const form = row.querySelector('form[data-backlog-rank-target="form"]');
        if (form === null) {
            this.#restore(row, originNext);

            return;
        }
        const rows = this.#rows();
        const index = rows.indexOf(row);
        const below = rows[index + 1] ?? null;
        const above = below === null ? (rows[index - 1] ?? null) : null;
        form.querySelector('[data-backlog-rank-field="before"]').value =
            below?.dataset.cardId ?? '';
        form.querySelector('[data-backlog-rank-field="after"]').value =
            above?.dataset.cardId ?? '';

        row.setAttribute('aria-busy', 'true');
        this.pending = row;
        // An error page must not replace the Backlog page; the row goes back instead.
        const onResponse = (event) => {
            const response = event.detail?.fetchResponse;
            const type = response?.contentType ?? '';
            if (!response?.succeeded && !type.startsWith(STREAM_TYPE)) {
                event.preventDefault();
            }
        };
        const onEnd = (event) => {
            form.removeEventListener('turbo:before-fetch-response', onResponse);
            form.removeEventListener('turbo:submit-end', onEnd);
            row.removeAttribute('aria-busy');
            this.pending = null;
            if (!event.detail?.success) {
                this.#restore(row, originNext);
            }
        };
        form.addEventListener('turbo:before-fetch-response', onResponse);
        form.addEventListener('turbo:submit-end', onEnd);
        form.requestSubmit();
    }

    /** Back before the row that followed it, never by index, because other rows may have moved. */
    #restore(row, originNext) {
        if (!row.isConnected) {
            return;
        }
        if (originNext !== null && originNext.isConnected) {
            originNext.before(row);
        } else {
            this.element.append(row);
        }
    }

    #rowBelow(pointerY, dragged) {
        return (
            this.#rows().find((row) => {
                if (row === dragged) {
                    return false;
                }
                const box = row.getBoundingClientRect();

                return box.top + box.height / 2 > pointerY;
            }) ?? null
        );
    }

    #rows() {
        return this.rowTargets.filter(
            (row) => row.parentElement === this.element,
        );
    }

    #stopListening() {
        const grip = this.drag?.grip;
        if (!grip) {
            return;
        }
        window.removeEventListener('pointermove', this.onPointerMove);
        window.removeEventListener('pointerup', this.onPointerUp);
        window.removeEventListener('pointercancel', this.onPointerCancel);
        if (grip.hasPointerCapture?.(this.drag.pointerId)) {
            grip.releasePointerCapture(this.drag.pointerId);
        }
    }
}
