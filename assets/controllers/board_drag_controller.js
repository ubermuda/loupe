/* stimulusFetch: 'eager' */
import { Controller } from '@hotwired/stimulus';
import { emit } from '../lib/live.js';

/**
 * Drag and drop for the board.
 *
 * A drop moves the card in the page at once, then posts. The server stays the
 * authority: its answer places the card where the database holds it, so a
 * prediction that was wrong is corrected, and a request that fails puts the card
 * back where it came from and says so. The board never keeps an order the
 * database refused.
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

const STREAM_TYPE = 'text/vnd.turbo-stream.html';
const MANAGED_OFFER_HEADER = 'X-Card-Managed-Offer';
const REFUSAL_HEADER = 'X-Card-Move-Refusal';

/** A group that takes a card and shows none, such as the Backlog button. */
const isBucket = (group) => group?.dataset.boardBucket !== undefined;

const angleOf = (rotate) => (['', 'none'].includes(rotate) ? '0deg' : rotate);

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
        this.stopAwaitingPlacement = null;
        this.swallowClick = false;
        this.scrollFrame = null;
        this.scrollGroup = null;
        this.pointerY = 0;
        this.openDeck = null;
        this.landing = null;

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
                const card = this.draggedCard;
                const lifted = this.liftOf(card);
                this.abandon();
                this.landInDeck(card, lifted);
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

        // A drop on a deck keeps its fan open until the pointer leaves it.
        this.onDeckPointerMove = (event) => {
            const deck = this.openDeck;
            const area = deck === null ? null : this.dropArea(deck);
            if (
                area !== null &&
                deck.isConnected &&
                event.clientX >= area.left &&
                event.clientX <= area.right &&
                event.clientY >= area.top &&
                event.clientY <= area.bottom
            ) {
                deck.classList.add('lp-deck--open');

                return;
            }
            this.closeDeck();
        };
        // A lane head morph drops the class the drop wrote.
        this.onDeckMorph = (event) => {
            if (event.target === this.openDeck) {
                this.openDeck.classList.add('lp-deck--open');
            }
        };

        this.element.addEventListener('click', this.onClick, true);
        this.markReady();
    }

    /** A morph drops attributes the server did not render, this one included. */
    markReady() {
        this.element.dataset.boardDragReady = 'true';
    }

    disconnect() {
        this.abandon();
        this.closeDeck();
        this.stopAwaitingPlacement?.();
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
        // A press in an interactive tooltip selects its text or follows its link.
        if (event.target.closest('.lp-tooltip--interactive') !== null) {
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
        this.closeDeck();

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
        // A card grabbed while it still lands would jump by its fan offset.
        this.landing?.cancel();
        this.landing = null;
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
        this.markBucket(group);
        // A release over no group drops nothing, so no marker says otherwise.
        if (group === null) {
            this.placeholder.remove();
        } else {
            this.markPlaceIn(group, event.clientY);
        }
        this.followEdge(isBucket(group) ? null : group);
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
        if (isBucket(group)) {
            this.placeholder.remove();

            return;
        }
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
        // A bucket keeps no order, so a card dropped back on the bucket it came
        // from stays put. A deck card dropped on the Backlog button stays too.
        const moves =
            group !== null &&
            !(
                group === this.originGroup &&
                (position === this.originIndex || isBucket(group))
            ) &&
            !(
                isBucket(group) &&
                group.dataset.lane === undefined &&
                group.dataset.column === this.originGroup?.dataset.column
            );

        // A bucket shows no card, so the card stays in the DOM, hidden, and its form can submit.
        if (moves && isBucket(group)) {
            card.classList.add('lp-board-card--sent');
        } else if (moves) {
            this.placeholder.replaceWith(card);
        }

        // Armed only when the click that follows will reach the board. A
        // release outside it sends the click to a shared ancestor instead, and
        // an armed flag would then swallow the next click on the board.
        this.swallowClick = this.element.contains(event.target);
        const lifted = this.liftOf(card);
        this.abandon();
        if (group?.classList.contains('lp-deck')) {
            this.holdDeckOpen(group);
        }
        this.landInDeck(card, lifted);

        if (moves) {
            this.submitMove(card, group, position, origin, lanePayload);
        }
    }

    /**
     * The fan of a deck stays open on hover only, and the card that returns to
     * its slot leaves the pointer over no card for a moment. So a drop on a
     * deck holds the fan open until the pointer leaves it.
     */
    holdDeckOpen(deck) {
        this.closeDeck();
        this.openDeck = deck;
        deck.classList.add('lp-deck--open');
        window.addEventListener('pointermove', this.onDeckPointerMove);
        document.addEventListener('turbo:morph-element', this.onDeckMorph);
    }

    /** Where the lifted card sits and how it leans, read before the drag lets it go. */
    liftOf(card) {
        if (card === null) {
            return null;
        }

        return {
            box: card.getBoundingClientRect(),
            rotate: getComputedStyle(card).rotate,
        };
    }

    /**
     * A deck card that stays in a deck travels from where it was let go to its
     * slot. Without this it reappears at the pile and slides out from there.
     */
    landInDeck(card, lifted) {
        if (
            lifted === null ||
            !card.isConnected ||
            card.closest('.lp-deck') === null ||
            card.classList.contains('lp-board-card--sent') ||
            typeof card.animate !== 'function' ||
            window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
        ) {
            return;
        }

        card.style.transition = 'none';
        const box = card.getBoundingClientRect();
        const style = getComputedStyle(card);
        const [x = '0px', y = '0px'] = ['', 'none'].includes(style.translate)
            ? []
            : style.translate.split(' ');
        const rotate = angleOf(style.rotate);
        const deltaX =
            lifted.box.left + lifted.box.width / 2 - (box.left + box.width / 2);
        const deltaY =
            lifted.box.top + lifted.box.height / 2 - (box.top + box.height / 2);
        const easing =
            style.getPropertyValue('--ease-deck').trim() || 'ease-out';
        const opacity = style.opacity;
        card.style.removeProperty('transition');

        this.landing = card.animate(
            [
                {
                    translate: `calc(${x} + ${deltaX}px) calc(${y} + ${deltaY}px)`,
                    rotate: angleOf(lifted.rotate),
                    opacity: 1,
                },
                { translate: `${x} ${y}`, rotate, opacity },
            ],
            { duration: 320, easing },
        );
    }

    closeDeck() {
        window.removeEventListener('pointermove', this.onDeckPointerMove);
        document.removeEventListener('turbo:morph-element', this.onDeckMorph);
        this.openDeck?.classList.remove('lp-deck--open');
        this.openDeck = null;
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
     * Names the board's one hidden move form after the card, fills it and
     * submits it, which lets Turbo carry the request and the eager CSRF
     * controller stamp the token. A hand-rolled fetch would have to
     * re-implement both.
     *
     * The card has already moved in the page. Any answer but a 2xx stream is
     * kept from rendering, so no error page replaces the board, and the card
     * goes back. A success is a board-place stream for this card, which
     * renders after `turbo:submit-end`, so the next drag waits for it.
     * A refusal that asks to make the card unmanaged keeps the card where it
     * landed while the person answers, and a yes sends the move once more.
     */
    submitMove(card, group, position, origin, lanePayload) {
        if (!this.hasMoveFormTarget) {
            return;
        }
        const form = this.moveFormTarget;
        this.nameMoveForm(form, card.dataset.cardId);

        const column = form.querySelector('select[name$="[column]"]');
        const rank = form.querySelector('input[name$="[position]"]');
        if (column === null || rank === null) {
            return;
        }

        const rankable = '1' === group.dataset.rankable;

        // A column added live is missing from a select rendered before it.
        // The server still checks the column against the project.
        const columnId = group.dataset.column;
        if (![...column.options].some((option) => option.value === columnId)) {
            const label =
                group
                    .closest('.lp-board__column')
                    ?.getAttribute('aria-label') ?? columnId;
            column.add(new Option(label, columnId));
        }
        column.value = columnId;
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
        const unmanage = form.querySelector('input[name$="[unmanage]"]');
        if (unmanage !== null) {
            unmanage.checked = false;
        }

        let refused = false;
        let offer = null;
        let refusal = null;
        const answered = (event) => {
            const response = event.detail.fetchResponse;
            if (
                !response.succeeded ||
                !(response.contentType ?? '').startsWith(STREAM_TYPE)
            ) {
                refused = true;
                offer = response.header?.(MANAGED_OFFER_HEADER) ?? null;
                refusal = response.header?.(REFUSAL_HEADER) ?? null;
                event.preventDefault();
            }
        };
        const finished = (event) => {
            form.removeEventListener('turbo:before-fetch-response', answered);
            form.removeEventListener('turbo:submit-end', finished);
            if (event.detail.success && !refused) {
                this.awaitPlacement(card);
                this.redrawLaneHeads(group, origin.group);

                return;
            }
            if (offer !== null && unmanage !== null && !unmanage.checked) {
                // After Turbo ends this submission, which it does once this event returns.
                const question = offer;
                const answer = refusal;
                setTimeout(() => {
                    if (window.confirm(question)) {
                        unmanage.checked = true;
                        send();

                        return;
                    }
                    this.release(card);
                    this.restore(card, origin, answer);
                });

                return;
            }
            this.release(card);
            this.restore(card, origin);
        };
        const send = () => {
            refused = false;
            offer = null;
            refusal = null;
            form.addEventListener('turbo:before-fetch-response', answered);
            form.addEventListener('turbo:submit-end', finished);
            form.requestSubmit();
        };

        this.pendingForm = form;
        card.setAttribute('aria-busy', 'true');
        send();
    }

    /**
     * The form renders under a placeholder card id, in its action, its field
     * names and its field ids. Each drop swaps the last id for this card's.
     */
    nameMoveForm(form, cardId) {
        const previous = form.dataset.moveCardId;
        const swap = (value, before, after) =>
            value.replace(
                `${before}${previous}${after}`,
                `${before}${cardId}${after}`,
            );
        form.setAttribute(
            'action',
            swap(form.getAttribute('action'), '/', '/'),
        );
        for (const field of form.elements) {
            field.name = swap(field.name, '_', '[');
            if (field.id !== '') {
                field.id = swap(field.id, '_', '_');
            }
        }
        form.dataset.moveCardId = cardId;
    }

    /**
     * The answer places the card alone, so the heads of the lanes it left and
     * joined, with their deck and progress, redraw as for a change of the
     * epic. A page with no hub hears of no such change otherwise.
     */
    redrawLaneHeads(...groups) {
        const epics = new Set(
            groups
                .map((group) => group?.dataset.lane)
                .filter((lane) => lane !== undefined && lane !== 'other'),
        );
        epics.forEach((cardId) =>
            emit('board.card_changed', { cardId, change: 'updated' }),
        );
    }

    /**
     * Holds the drag lock until the stream has placed the card. A drag begun
     * before that would mark a place the placement is about to shift.
     */
    awaitPlacement(card) {
        // A deck card has an id of its own, so the id of the card comes from its data.
        const cardId = card.dataset.cardId;
        this.stopAwaitingPlacement = () => {
            document.removeEventListener('board:placed', placed);
            document.removeEventListener('board:place-missed', placed);
            this.stopAwaitingPlacement = null;
        };
        const placed = (event) => {
            if (event.detail?.cardId !== cardId) {
                return;
            }
            this.release(card);
        };
        document.addEventListener('board:placed', placed);
        document.addEventListener('board:place-missed', placed);
    }

    release(card) {
        this.stopAwaitingPlacement?.();
        this.pendingForm = null;
        card.removeAttribute('aria-busy');
    }

    /** Puts a card back where the drag took it from, and says why, or that it moved back. */
    restore(card, origin, message = null) {
        card.classList.remove('lp-board-card--sent');
        if (!this.element.isConnected || !card.isConnected) {
            return;
        }

        if (origin.before !== null && origin.before.isConnected) {
            origin.before.before(card);
        } else if (origin.group.isConnected) {
            origin.group.append(card);
        }

        this.showMessage(message);
    }

    showMessage(message = null) {
        if (!this.hasMessageTarget) {
            return;
        }

        this.messageTarget.textContent =
            message ?? this.messageTarget.dataset.message;
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
        this.markBucket(null);
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
        // A deck card's ghost marks the slot of the fan the card left.
        if (card.dataset.deckIndex !== undefined) {
            ghost.style.setProperty('--deck-order', card.dataset.deckIndex);
        }
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
     * cells, and takes no drop. A search can show its cells, never its deck.
     */
    accepts(group) {
        if (
            group.closest(
                '.lp-board-lane--collapsed:not(.lp-board-lane--revealed)',
            ) !== null ||
            (group.classList.contains('lp-deck') &&
                group.closest('.lp-board-lane--collapsed') !== null)
        ) {
            return false;
        }

        return (
            group.dataset.lane === undefined ||
            'other' === group.dataset.lane ||
            'true' !== this.pressedCard?.dataset.cardLane
        );
    }

    /** Shows which bucket a release would drop the card in, if any. */
    markBucket(group) {
        this.groupTargets
            .filter(isBucket)
            .forEach((bucket) =>
                bucket.classList.toggle(
                    'lp-board-backlog--over',
                    bucket === group,
                ),
            );
    }

    /**
     * The drop target under the pointer. A column takes a drop a little past
     * its edge, and a bucket does not, so a drop at the top of a column never
     * lands in a bucket just above it.
     */
    groupUnder(x, y) {
        return (
            this.groupTargets.find((group) => {
                if (!this.accepts(group)) {
                    return false;
                }
                const rectangle = this.dropArea(group);
                const slack = isBucket(group) ? 0 : 16;

                return (
                    x >= rectangle.left &&
                    x <= rectangle.right &&
                    y >= rectangle.top - slack &&
                    y <= rectangle.bottom + slack
                );
            }) ?? null
        );
    }

    /** A fanned deck takes a drop over every card it shows, not only over its pile. */
    dropArea(group) {
        const area = (
            group.closest('.lp-board__column') ?? group
        ).getBoundingClientRect();
        if (!group.classList.contains('lp-deck')) {
            return area;
        }
        let { left, top, right, bottom } = area;
        group.querySelectorAll('.lp-deck__card').forEach((card) => {
            if (
                card === this.draggedCard ||
                card.hidden ||
                card.classList.contains('lp-deck__card--spare')
            ) {
                return;
            }
            const box = card.getBoundingClientRect();
            // The ghost and any card CSS hides take no room.
            if (box.width === 0) {
                return;
            }
            left = Math.min(left, box.left);
            top = Math.min(top, box.top);
            right = Math.max(right, box.right);
            bottom = Math.max(bottom, box.bottom);
        });

        return { left, top, right, bottom };
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
