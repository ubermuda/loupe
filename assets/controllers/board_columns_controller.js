import { Controller } from '@hotwired/stimulus';

/**
 * Reorders the board's columns by dragging a header's grip.
 *
 * The column moves in the page while it is dragged. A drop writes the new
 * order into the board's reorder form and submits it, so Turbo carries the
 * request and the CSRF controller stamps the token. The server answers with a
 * redirect to the board, which replaces this one. A drag that ends away from
 * the board, or a request that fails, puts the column back.
 *
 * Native drag and drop, because a column has no rank to compute inside a
 * group: the order of the sections is the whole result. The header menu offers
 * the same move to a keyboard.
 */
export default class extends Controller {
    static targets = ['column', 'form', 'order'];

    start(event) {
        const column = event.target.closest(
            '[data-board-columns-target="column"]',
        );
        if (column === null) {
            return;
        }

        this.dragged = column;
        this.originNext = column.nextElementSibling;
        this.originalOrder = this.currentOrder();
        this.dropped = false;

        // Firefox starts no drag without data.
        event.dataTransfer.setData('text/plain', column.dataset.columnId);
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setDragImage(column, 16, 16);
        column.classList.add('lp-board__column--dragging');
    }

    over(event) {
        if (!this.dragged) {
            return;
        }
        event.preventDefault();

        const others = this.columnTargets.filter(
            (column) => column !== this.dragged,
        );
        const before = others.find((column) => {
            const rectangle = column.getBoundingClientRect();

            return event.clientX < rectangle.left + rectangle.width / 2;
        });

        if (before !== undefined) {
            if (before.previousElementSibling !== this.dragged) {
                before.before(this.dragged);
            }
        } else if (others.length > 0) {
            others[others.length - 1].after(this.dragged);
        }
    }

    drop(event) {
        if (!this.dragged) {
            return;
        }
        event.preventDefault();
        this.dropped = true;

        const order = this.currentOrder();
        if (order === this.originalOrder) {
            return;
        }

        const column = this.dragged;
        const origin = this.originNext;
        const finished = (submitEnd) => {
            this.formTarget.removeEventListener('turbo:submit-end', finished);
            if (!submitEnd.detail.success) {
                this.restore(column, origin);
            }
        };

        this.orderTarget.value = order;
        this.formTarget.addEventListener('turbo:submit-end', finished);
        this.formTarget.requestSubmit();
    }

    end() {
        if (!this.dragged) {
            return;
        }

        this.dragged.classList.remove('lp-board__column--dragging');
        if (!this.dropped) {
            this.restore(this.dragged, this.originNext);
        }
        this.dragged = null;
    }

    restore(column, origin) {
        if (!column.isConnected) {
            return;
        }
        if (origin !== null && origin.isConnected) {
            origin.before(column);
        } else {
            column.parentElement.append(column);
        }
    }

    currentOrder() {
        return this.columnTargets
            .map((column) => column.dataset.columnId)
            .join(',');
    }
}
