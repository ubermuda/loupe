/* stimulusFetch: 'eager' */
import { Controller } from '@hotwired/stimulus';

/**
 * Drag and drop for the board.
 *
 * The server stays the authority. A drop never reorders the board itself: it
 * fills the dragged card's own move form and submits it, and the Turbo Stream
 * that comes back replaces the board with what the database holds. A drag that
 * is abandoned, refused or interrupted therefore leaves the page showing the
 * order it already had, never an invented one.
 *
 * The move form is also the keyboard and no-JS path, so this adds a faster way
 * to reach an endpoint that is reachable without it.
 *
 * Eagerly loaded, and it marks the board ready when it connects. Dragging is the
 * board's primary gesture, and a lazily fetched controller leaves a window in
 * which a card can be grabbed and nothing happens.
 */
export default class extends Controller {
    static targets = ['card', 'group', 'moveForm'];

    connect() {
        this.pointerId = null;
        this.draggedCard = null;
        this.placeholder = null;
        this.originGroup = null;
        this.originIndex = -1;

        this.onPointerMove = (event) => this.pointerMove(event);
        this.onPointerUp = (event) => this.pointerUp(event);
        this.onKeydown = (event) => {
            if (event.key === 'Escape') {
                this.abandon();
            }
        };

        this.element.dataset.boardDragReady = 'true';
    }

    disconnect() {
        this.abandon();
        delete this.element.dataset.boardDragReady;
    }

    start(event) {
        if (this.draggedCard !== null) {
            return;
        }
        if (event.pointerType === 'mouse' && event.button !== 0) {
            return;
        }

        const card = event.target.closest('[data-board-drag-target="card"]');
        const group =
            card === null
                ? null
                : card.closest('[data-board-drag-target="group"]');
        if (group === null) {
            return;
        }

        event.preventDefault();

        const rectangle = card.getBoundingClientRect();
        this.pointerId = event.pointerId;
        this.originGroup = group;
        this.originIndex = this.allCardsIn(group).indexOf(card);
        this.grabOffsetX = event.clientX - rectangle.left;
        this.grabOffsetY = event.clientY - rectangle.top;

        this.placeholder = document.createElement('div');
        this.placeholder.className = 'lp-board__placeholder';
        this.placeholder.style.height = `${rectangle.height}px`;
        card.after(this.placeholder);

        card.classList.add('lp-board-card--dragging');
        card.style.width = `${rectangle.width}px`;
        card.style.left = `${rectangle.left}px`;
        card.style.top = `${rectangle.top}px`;
        this.draggedCard = card;
        this.element.classList.add('lp-board--dragging');

        window.addEventListener('pointermove', this.onPointerMove);
        window.addEventListener('pointerup', this.onPointerUp);
        window.addEventListener('pointercancel', this.onPointerUp);
        window.addEventListener('keydown', this.onKeydown);
    }

    pointerMove(event) {
        if (this.draggedCard === null || event.pointerId !== this.pointerId) {
            return;
        }

        event.preventDefault();
        this.draggedCard.style.left = `${event.clientX - this.grabOffsetX}px`;
        this.draggedCard.style.top = `${event.clientY - this.grabOffsetY}px`;

        const group = this.groupUnder(event.clientX, event.clientY);
        if (group === null) {
            return;
        }

        // A card that leaves its group takes the end of the one it joins,
        // whatever the pointer is over: the handler re-grades or re-columns it
        // and appends. Showing an insertion point it will not honour would
        // promise an order the answer then contradicts.
        if (group !== this.originGroup) {
            group.append(this.placeholder);

            return;
        }

        const others = this.otherCardsIn(group);
        const before = others.find((element) => {
            const rectangle = element.getBoundingClientRect();

            return event.clientY < rectangle.top + rectangle.height / 2;
        });

        if (before === undefined) {
            group.append(this.placeholder);
        } else {
            before.before(this.placeholder);
        }
    }

    pointerUp(event) {
        if (this.draggedCard === null || event.pointerId !== this.pointerId) {
            return;
        }

        const card = this.draggedCard;
        // The release point decides, not the placeholder: the placeholder stays
        // where the pointer last was over a group, so letting go away from the
        // board would otherwise commit that last hovered spot instead of
        // abandoning the drag.
        const overGroup = this.groupUnder(event.clientX, event.clientY);
        const group =
            overGroup === null
                ? null
                : this.placeholder.closest('[data-board-drag-target="group"]');
        // Counted among the other cards only, which is the rank the move
        // endpoint expects: the dragged card is spliced back in at that index.
        const position = group === null ? -1 : this.rankOfPlaceholder(group);

        this.abandon();
        this.submitMove(card, group, position);
    }

    /**
     * Fills the card's own move form and submits it, which lets Turbo carry the
     * request and the eager CSRF controller stamp the token. A hand-rolled fetch
     * would have to re-implement both.
     */
    submitMove(card, group, position) {
        if (group === null) {
            return;
        }

        const form = card.querySelector('[data-board-drag-target="moveForm"]');
        if (form === null) {
            return;
        }

        const status = form.querySelector('select[name$="[status]"]');
        const priority = form.querySelector('select[name$="[priority]"]');
        const rank = form.querySelector('input[name$="[position]"]');
        if (status === null || priority === null || rank === null) {
            return;
        }

        const wantedStatus = group.dataset.status;
        const wantedPriority = group.dataset.priority;
        const rankable = group.dataset.rankable === '1';
        const staysInGroup = group === this.originGroup;
        const samePlace = staysInGroup && position === this.originIndex;

        if (samePlace) {
            return;
        }

        status.value = wantedStatus;
        // A column that keeps no rank grades nothing either, so the card holds
        // the grade it already had rather than taking an empty one.
        if (wantedPriority !== '') {
            priority.value = wantedPriority;
        }
        // A rank is only sent for a move inside one group. The handler appends
        // on every other move, so sending one would be a number it discards.
        rank.value =
            staysInGroup && rankable && position >= 0 ? String(position) : '';

        form.requestSubmit();
    }

    /** Restores the page to the state it was in before the drag started. */
    abandon() {
        window.removeEventListener('pointermove', this.onPointerMove);
        window.removeEventListener('pointerup', this.onPointerUp);
        window.removeEventListener('pointercancel', this.onPointerUp);
        window.removeEventListener('keydown', this.onKeydown);

        if (this.draggedCard !== null) {
            this.draggedCard.classList.remove('lp-board-card--dragging');
            this.draggedCard.style.width = '';
            this.draggedCard.style.left = '';
            this.draggedCard.style.top = '';
        }
        if (this.placeholder !== null) {
            this.placeholder.remove();
        }
        this.element.classList.remove('lp-board--dragging');

        this.pointerId = null;
        this.draggedCard = null;
        this.placeholder = null;
    }

    groupUnder(x, y) {
        return (
            this.groupTargets.find((group) => {
                const rectangle = group.getBoundingClientRect();

                return (
                    x >= rectangle.left &&
                    x <= rectangle.right &&
                    y >= rectangle.top - 16 &&
                    y <= rectangle.bottom + 16
                );
            }) ?? null
        );
    }

    rankOfPlaceholder(group) {
        return Array.from(
            group.querySelectorAll(
                '[data-board-drag-target="card"], .lp-board__placeholder',
            ),
        )
            .filter((element) => element !== this.draggedCard)
            .indexOf(this.placeholder);
    }

    allCardsIn(group) {
        return Array.from(
            group.querySelectorAll('[data-board-drag-target="card"]'),
        );
    }

    otherCardsIn(group) {
        return this.allCardsIn(group).filter(
            (element) => element !== this.draggedCard,
        );
    }
}
