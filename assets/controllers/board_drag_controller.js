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

/** How close to a column's edge the pointer scrolls its cards, and how fast. */
const EDGE_BAND = 56;
const EDGE_STEP = 14;

export default class extends Controller {
    static targets = ['card', 'group', 'moveForm', 'message'];

    connect() {
        this.pointerId = null;
        this.pressedCard = null;
        this.draggedCard = null;
        this.placeholder = null;
        this.ghost = null;
        this.originGroup = null;
        this.originNextCard = null;
        this.originIndex = -1;
        this.pendingForm = null;
        this.swallowClick = false;
        this.scrollFrame = null;
        this.scrollGroup = null;
        this.pointerY = 0;

        this.onScrollFrame = () => {
            this.scrollFrame = null;
            const group = this.scrollGroup;
            if (this.draggedCard === null || group === null) {
                return;
            }
            const step = this.edgeStep(group);
            if (step === 0) {
                return;
            }
            const before = group.scrollTop;
            group.scrollTop = before + step;
            if (group.scrollTop !== before) {
                this.markPlaceIn(group, this.pointerY);
            }
            this.scrollFrame = requestAnimationFrame(this.onScrollFrame);
        };
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
        this.ghost = this.ghostOf(card);
        card.after(this.ghost);

        card.classList.add('lp-board-card--dragging');
        card.style.width = `${rectangle.width}px`;
        card.style.left = `${rectangle.left}px`;
        card.style.top = `${rectangle.top}px`;
        this.draggedCard = card;
        this.element.classList.add('lp-board--dragging');
        // Turbo reads this on an ancestor, so no card under the pointer
        // prefetches while the drag runs.
        this.element.dataset.turboPrefetch = 'false';
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

        this.pointerY = event.clientY;
        const group = this.groupUnder(event.clientX, event.clientY);
        if (group !== null) {
            this.markPlaceIn(group, event.clientY);
        }
        this.followEdge(group);
    }

    /**
     * A column shows the cards that fit, so a drag to a card below the fold
     * scrolls that column while the pointer rests near its edge.
     */
    followEdge(group) {
        this.scrollGroup = group;
        if (group === null || this.edgeStep(group) === 0) {
            this.stopScrolling();

            return;
        }
        if (this.scrollFrame === null) {
            this.scrollFrame = requestAnimationFrame(this.onScrollFrame);
        }
    }

    edgeStep(group) {
        const box = group.getBoundingClientRect();
        const fromTop = this.pointerY - box.top;
        const fromBottom = box.bottom - this.pointerY;
        const room = group.scrollHeight - group.clientHeight;
        const speed = (depth) =>
            Math.ceil(
                ((EDGE_BAND - Math.max(depth, 0)) / EDGE_BAND) * EDGE_STEP,
            );

        if (
            fromTop < EDGE_BAND &&
            fromTop > -EDGE_BAND &&
            group.scrollTop > 0
        ) {
            return -speed(fromTop);
        }
        if (
            fromBottom < EDGE_BAND &&
            fromBottom > -EDGE_BAND &&
            group.scrollTop < room - 1
        ) {
            return speed(fromBottom);
        }

        return 0;
    }

    stopScrolling() {
        if (this.scrollFrame !== null) {
            cancelAnimationFrame(this.scrollFrame);
            this.scrollFrame = null;
        }
    }

    /**
     * Puts the drop marker where a release at this point would land the card.
     *
     * A terminal column sorts by completion and keeps no rank, so a card
     * dropped there takes the end whatever the pointer is over. Every other
     * column honours the marker, in its own column and across columns alike.
     */
    markPlaceIn(group, clientY) {
        if ('1' !== group.dataset.rankable) {
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
        let lanePayload = null;
        if (group !== null) {
            this.markPlaceIn(group, event.clientY);
            // Counted among the other cards only, which is the rank the move
            // endpoint expects: the card is spliced back in at that index.
            position = this.rankOfPlaceholder(group);
            // Read now, while the dragged card is still told apart from the
            // cards around the marker.
            if (group.dataset.lane !== undefined) {
                lanePayload = this.lanePayload(group, position);
            }
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
            this.submitMove(card, group, position, origin, lanePayload);
        }
    }

    /**
     * What a drop in a lane cell sends instead of a rank. A cell shows part of
     * a column, so the server ranks the card from the card next to it. The
     * parent changes only when the lane does, so a child whose lane is off
     * keeps its parent while it moves inside "Other cards".
     */
    lanePayload(group, position) {
        const others = this.otherCardsIn(group);
        const below = others[position] ?? null;
        const above = below === null ? (others[position - 1] ?? null) : null;
        const from = this.originGroup.dataset.lane;
        const to = group.dataset.lane;

        let parent = to;
        if (from === to) {
            parent = '';
        } else if ('other' === to) {
            parent = 'none';
        }

        return {
            parent,
            beforeCardId: below?.dataset.cardId ?? '',
            afterCardId: above?.dataset.cardId ?? '',
        };
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
    submitMove(card, group, position, origin, lanePayload) {
        const form = card.querySelector('[data-board-drag-target="moveForm"]');
        if (form === null) {
            return;
        }

        const column = form.querySelector('select[name$="[column]"]');
        const rank = form.querySelector('input[name$="[position]"]');
        if (column === null || rank === null) {
            return;
        }

        const rankable = '1' === group.dataset.rankable;

        column.value = group.dataset.column;
        // A terminal column keeps no rank, so it takes none. Every other column
        // lands the card where the marker stood.
        rank.value =
            lanePayload === null && rankable && position >= 0
                ? String(position)
                : '';
        // Written on every drop, so a refused drop leaves no stale value behind.
        for (const name of ['parent', 'beforeCardId', 'afterCardId']) {
            const field = form.querySelector(`input[name$="[${name}]"]`);
            if (field !== null) {
                field.value = lanePayload?.[name] ?? '';
            }
        }

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
        if (this.ghost !== null) {
            this.ghost.remove();
        }
        this.element.classList.remove('lp-board--dragging');
        delete this.element.dataset.turboPrefetch;
        this.stopScrolling();
        this.scrollGroup = null;

        this.pointerId = null;
        this.pressedCard = null;
        this.draggedCard = null;
        this.placeholder = null;
        this.ghost = null;
        this.originNextCard = null;
    }

    /**
     * A faded, inert copy of the card that holds its slot for the length of the
     * drag. It carries no id, data attribute or form, so no controller, rank
     * count or query mistakes it for the card.
     */
    ghostOf(card) {
        const ghost = card.cloneNode(true);
        ghost.classList.remove('lp-board-card--dragging');
        ghost.classList.add('lp-board__ghost');
        ghost.inert = true;
        ghost.setAttribute('aria-hidden', 'true');
        ghost.querySelectorAll('form').forEach((form) => form.remove());
        for (const element of [ghost, ...ghost.querySelectorAll('*')]) {
            for (const { name } of Array.from(element.attributes)) {
                if (name === 'id' || name.startsWith('data-')) {
                    element.removeAttribute(name);
                }
            }
        }

        return ghost;
    }

    /**
     * Whether a drop may land in this group. An epic has no parent, so an
     * epic card lands in "Other cards" only. A collapsed lane hides its
     * cells, and takes no drop.
     */
    accepts(group) {
        if (
            group.closest(
                '.lp-board-lane--collapsed:not(.lp-board-lane--revealed)',
            ) !== null
        ) {
            return false;
        }

        return (
            group.dataset.lane === undefined ||
            'other' === group.dataset.lane ||
            'epic' !== this.pressedCard?.dataset.cardType
        );
    }

    /** The drop target under the pointer. */
    groupUnder(x, y) {
        return (
            this.groupTargets.find((group) => {
                if (!this.accepts(group)) {
                    return false;
                }
                const rectangle = (
                    group.closest('.lp-board__column') ?? group
                ).getBoundingClientRect();

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
