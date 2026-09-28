import { Controller } from '@hotwired/stimulus';
import { fanLayout } from '../lib/deck_fan.js';

/** The space between two fanned cards, and between the fan and the lane button. */
const SLOT_GAP = 10;
const CHEVRON_GAP = 8;

/**
 * Fits the Up next deck's fan to its lane head. The fan runs left from the
 * deck, so it shows the cards that fit before the collapse button, and a
 * "+N more" tile for the rest. CSS draws the pile and the fan itself.
 */
export default class extends Controller {
    static targets = ['card', 'more'];
    static values = { count: Number, more: String };

    connect() {
        this.resizeObserver = new ResizeObserver(() => this.layout());
        this.resizeObserver.observe(
            this.element.closest('.lp-board-lane__head') ?? this.element,
        );
    }

    disconnect() {
        this.resizeObserver?.disconnect();
    }

    /** A lane head morph adds and removes cards. */
    cardTargetConnected() {
        this.layout();
    }

    cardTargetDisconnected() {
        this.layout();
    }

    countValueChanged() {
        this.layout();
    }

    layout() {
        const cards = this.cardTargets;
        if (!this.hasMoreTarget || cards.length === 0) {
            return;
        }
        const collapse = this.element
            .closest('.lp-board-lane__head')
            ?.querySelector('.lp-board-lane__collapse');
        const slot = cards[0].offsetWidth + SLOT_GAP;
        if (!collapse || cards[0].offsetWidth === 0) {
            return;
        }
        const room =
            this.element.getBoundingClientRect().right -
            collapse.getBoundingClientRect().right -
            CHEVRON_GAP;
        const slots = Math.floor((room + SLOT_GAP) / slot);
        const { shown, more } = fanLayout(slots, cards.length, this.countValue);

        cards.forEach((card, index) =>
            card.classList.toggle('lp-deck__card--spare', index >= shown),
        );
        this.moreTarget.hidden = more === 0;
        this.moreTarget.dataset.deckIndex = String(shown);
        this.moreTarget.textContent = this.moreValue.replace(
            '%count%',
            String(more),
        );
        const tiles = shown + (more > 0 ? 1 : 0);
        this.element.style.setProperty('--deck-slot', `${slot}px`);
        this.element.style.setProperty(
            '--deck-reach',
            `${tiles * slot - SLOT_GAP}px`,
        );
    }
}
