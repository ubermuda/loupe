import { Controller } from '@hotwired/stimulus';
import { fanLayout, fanRoom } from '../lib/deck_fan.js';

/** The space between two fanned cards, and between the fan and the lane button. */
const SLOT_GAP = 10;
const CHEVRON_GAP = 8;

/**
 * Fits the Up next deck's fan to its lane head. The fan runs left from the
 * deck, so it shows the cards that fit before the lane buttons, or before the
 * edge of the strip once they scroll out of view. A "+N more"
 * tile for the rest takes the first slot, under the pointer, and shifts the
 * cards one slot left. CSS draws the pile and the fan itself.
 */
export default class extends Controller {
    static targets = ['card', 'more'];
    static values = { count: Number, more: String };

    connect() {
        // A lane head morph drops the style this controller wrote.
        this.onMorph = (event) => {
            if (event.target === this.element) {
                this.layout();
            }
        };
        this.element.addEventListener('turbo:morph-element', this.onMorph);
        this.resizeObserver = new ResizeObserver(() => this.layout());
        this.resizeObserver.observe(
            this.element.closest('.lp-board-lane__head') ?? this.element,
        );
        // The deck stays pinned while the strip scrolls, so the fan's room changes.
        this.strip = this.element.closest('.lp-board__columns--lanes');
        this.scrollFrame = null;
        this.onScroll = () => {
            this.scrollFrame ??= requestAnimationFrame(() => {
                this.scrollFrame = null;
                this.layout();
            });
        };
        if (this.strip) {
            this.resizeObserver.observe(this.strip);
            this.strip.addEventListener('scroll', this.onScroll, {
                passive: true,
            });
        }
    }

    disconnect() {
        this.element.removeEventListener('turbo:morph-element', this.onMorph);
        this.resizeObserver?.disconnect();
        this.strip?.removeEventListener('scroll', this.onScroll);
        if (this.scrollFrame !== null) {
            cancelAnimationFrame(this.scrollFrame);
            this.scrollFrame = null;
        }
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
        const head = this.element.closest('.lp-board-lane__head');
        const buttons =
            head?.querySelector(':scope > form') ??
            head?.querySelector('.lp-board-lane__collapse');
        const slot = cards[0].offsetWidth + SLOT_GAP;
        if (!buttons || cards[0].offsetWidth === 0) {
            return;
        }
        const room = fanRoom(
            this.element.getBoundingClientRect().right,
            buttons.getBoundingClientRect().right,
            this.strip?.getBoundingClientRect().left ?? -Infinity,
            CHEVRON_GAP,
        );
        const slots = Math.floor((room + SLOT_GAP) / slot);
        const { shown, more } = fanLayout(slots, cards.length, this.countValue);

        cards.forEach((card, index) =>
            card.classList.toggle('lp-deck__card--spare', index >= shown),
        );
        this.moreTarget.hidden = more === 0;
        this.moreTarget.textContent = this.moreValue.replace(
            '%count%',
            String(more),
        );
        const tiles = shown + (more > 0 ? 1 : 0);
        this.element.style.setProperty('--deck-slot', `${slot}px`);
        this.element.style.setProperty('--deck-shift', more > 0 ? '1' : '0');
        this.element.style.setProperty(
            '--deck-reach',
            `${tiles * slot - SLOT_GAP}px`,
        );
    }
}
