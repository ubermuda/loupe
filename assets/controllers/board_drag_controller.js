/* stimulusFetch: 'eager' */
import { Controller } from '@hotwired/stimulus';

/**
 * Drag and drop for the board.
 *
 * A drop moves the card in the page at once, then posts. The server stays the
 * authority: its answer replaces the whole board, so a prediction that was
 * wrong is corrected, and a request that fails puts the card back where it came
 * from and says so. The board never keeps an order the database refused.
 *
 * The whole card is the handle. A press becomes a drag only after the pointer
 * travels far enough, so a click on the title still opens the card.
 *
 * Eagerly loaded, and it marks the board ready when it connects. Dragging is the
 * board's primary gesture, and a lazily fetched controller leaves a window in
 * which a card can be grabbed and nothing happens.
 */

/**
 * Pixels the pointer must travel before a press becomes a drag. Above the jitter
 * of a mouse click, below the distance a person reads as movement.
 */
const DRAG_THRESHOLD = 5;

export default class extends Controller {
    static targets = ['card', 'group', 'moveForm', 'message'];

    connect() {
        this.pointerId = null;
        this.pressedCard = null;
        this.draggedCard = null;
        this.placeholder = null;
        this.originGroup = null;
        this.originNextCard = null;
        this.originIndex = -1;
        this.pendingForm = null;
        this.swallowClick = false;

        this.onPointerMove = (event) => this.pointerMove(event);
        this.onPointerUp = (event) => this.pointerUp(event);
        this.onKeydown = (event) => {
            if (event.key === 'Escape') {
                this.abandon();
            }
        };
        // A drag that ends on the title would otherwise open the card it just
        // moved, because a click follows the pointerup.
        this.onClick = (event) => {
            if (!this.swallowClick) {
                return;
            }
            this.swallowClick = false;
            event.preventDefault();
            event.stopPropagation();
        };

        this.element.addEventListener('click', this.onClick, true);
        this.element.dataset.boardDragReady = 'true';
    }

    disconnect() {
        this.abandon();
        this.element.removeEventListener('click', this.onClick, true);
        delete this.element.dataset.boardDragReady;
    }

    press(event) {
        if (this.pressedCard !== null) {
            return;
        }
        // One move at a time. A second drag started before the first answer
        // would rank a card against a position the server has not accepted.
        if (this.pendingForm !== null) {
            return;
        }
        if (event.pointerType === 'mouse' && 0 !== event.button) {
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

        this.swallowClick = false;
        this.clearMessage();

        this.pointerId = event.pointerId;
        this.pressedCard = card;
        this.originGroup = group;
        this.pressX = event.clientX;
        this.pressY = event.clientY;

        window.addEventListener('pointermove', this.onPointerMove);
        window.addEventListener('pointerup', this.onPointerUp);
        window.addEventListener('pointercancel', this.onPointerUp);
        window.addEventListener('keydown', this.onKeydown);
    }

    /** Turns the press into a drag once the pointer has travelled far enough. */
    begin() {
        const card = this.pressedCard;
        const rectangle = card.getBoundingClientRect();

        this.originNextCard = this.cardAfter(card);
        this.originIndex = this.allCardsIn(this.originGroup).indexOf(card);
        this.grabOffsetX = this.pressX - rectangle.left;
        this.grabOffsetY = this.pressY - rectangle.top;

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
    }

    pointerMove(event) {
        if (this.pressedCard === null || event.pointerId !== this.pointerId) {
            return;
        }

        if (this.draggedCard === null) {
            const travelled = Math.hypot(
                event.clientX - this.pressX,
                event.clientY - this.pressY,
            );
            if (travelled < DRAG_THRESHOLD) {
                return;
            }
            this.begin();
        }

        event.preventDefault();
        this.draggedCard.style.left = `${event.clientX - this.grabOffsetX}px`;
        this.draggedCard.style.top = `${event.clientY - this.grabOffsetY}px`;

        const group = this.groupUnder(event.clientX, event.clientY);
        if (group !== null) {
            this.markPlaceIn(group, event.clientY);
        }
    }

    /**
     * Puts the drop marker where a release at this point would land the card.
     *
     * A card that leaves its group takes the end of the one it joins, whatever
     * the pointer is over: the handler re-grades or re-columns it and appends.
     * Marking an insertion point it will not honour would promise an order the
     * answer then contradicts.
     */
    markPlaceIn(group, clientY) {
        if (group !== this.originGroup) {
            group.append(this.placeholder);

            return;
        }

        const before = this.otherCardsIn(group).find((element) => {
            const rectangle = element.getBoundingClientRect();

            return clientY < rectangle.top + rectangle.height / 2;
        });

        if (before === undefined) {
            group.append(this.placeholder);
        } else {
            before.before(this.placeholder);
        }
    }

    pointerUp(event) {
        if (this.pressedCard === null || event.pointerId !== this.pointerId) {
            return;
        }

        // The browser took the gesture back, usually to scroll. It never became
        // a drop, so nothing is submitted.
        const card = 'pointercancel' === event.type ? null : this.draggedCard;
        if (card === null) {
            this.abandon();

            return;
        }

        // The release point decides, and the marker is re-placed from it rather
        // than read where it sits. A pointerup can arrive at a spot no
        // pointermove reported, so trusting the marker would commit the last
        // place the pointer was seen, and a release away from the board would
        // commit that instead of abandoning the drag.
        const group = this.groupUnder(event.clientX, event.clientY);
        let position = -1;
        if (group !== null) {
            this.markPlaceIn(group, event.clientY);
            // Counted among the other cards only, which is the rank the move
            // endpoint expects: the card is spliced back in at that index.
            position = this.rankOfPlaceholder(group);
        }

        const origin = { group: this.originGroup, before: this.originNextCard };
        const moves =
            group !== null &&
            !(group === this.originGroup && position === this.originIndex);

        if (moves) {
            this.placeholder.replaceWith(card);
        }

        // Armed only when the click that follows will reach the board. A
        // release outside it sends the click to a shared ancestor instead, and
        // an armed flag would then swallow the next click on the board.
        this.swallowClick = this.element.contains(event.target);
        this.abandon();

        if (moves) {
            this.submitMove(card, group, position, origin);
        }
    }

    /**
     * Fills the card's own hidden move form and submits it, which lets Turbo
     * carry the request and the eager CSRF controller stamp the token. A
     * hand-rolled fetch would have to re-implement both.
     *
     * The card has already moved in the page. A refusal, a failure or a lost
     * connection puts it back, because `turbo:submit-end` reports all three.
     * A success answers with the whole board, which replaces this one and makes
     * the prediction moot rather than applying it twice.
     */
    submitMove(card, group, position, origin) {
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

        const wantedPriority = group.dataset.priority;
        const rankable = '1' === group.dataset.rankable;
        const staysInGroup = group === origin.group;

        status.value = group.dataset.status;
        // A column that keeps no rank grades nothing either, so the card holds
        // the grade it already had rather than taking an empty one.
        if ('' !== wantedPriority) {
            priority.value = wantedPriority;
        }
        // A rank is only sent for a move inside one group. The handler appends
        // on every other move, so sending one would be a number it discards.
        rank.value =
            staysInGroup && rankable && position >= 0 ? String(position) : '';

        const finished = (event) => {
            form.removeEventListener('turbo:submit-end', finished);
            this.pendingForm = null;
            card.removeAttribute('aria-busy');
            if (event.detail.success) {
                return;
            }
            this.restore(card, origin);
        };

        this.pendingForm = form;
        card.setAttribute('aria-busy', 'true');
        form.addEventListener('turbo:submit-end', finished);
        form.requestSubmit();
    }

    /** Puts a card back where the drag took it from, and says that it moved back. */
    restore(card, origin) {
        if (!this.element.isConnected || !card.isConnected) {
            return;
        }

        if (origin.before !== null && origin.before.isConnected) {
            origin.before.before(card);
        } else if (origin.group.isConnected) {
            origin.group.append(card);
        }

        this.showMessage();
    }

    showMessage() {
        if (!this.hasMessageTarget) {
            return;
        }

        this.messageTarget.textContent = this.messageTarget.dataset.message;
    }

    clearMessage() {
        if (this.hasMessageTarget) {
            this.messageTarget.textContent = '';
        }
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
        this.pressedCard = null;
        this.draggedCard = null;
        this.placeholder = null;
        this.originNextCard = null;
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

    /** The next card in the same group, or null when this one is last. */
    cardAfter(card) {
        const cards = this.allCardsIn(card.parentElement);

        return cards[cards.indexOf(card) + 1] ?? null;
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
