import { Controller } from '@hotwired/stimulus';

/**
 * Hands a hover over the whole card to Turbo's prefetch, which otherwise only
 * answers a hover over the title link. Turbo keeps its own delay and cache.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['link'];

    enter() {
        if (this.element.closest('.lp-board--dragging')) {
            return;
        }
        this.#handOver('mouseenter');
    }

    /**
     * Only the pointer leaving cancels. A click focuses the link and then
     * navigates, so a leave on blur would drop the prefetch it is about to use.
     */
    leave() {
        this.#handOver('mouseleave');
    }

    #handOver(type) {
        // Turbo listens on the document in the capture phase, so an event that
        // does not bubble still reaches it.
        this.linkTarget.dispatchEvent(new MouseEvent(type, { bubbles: false }));
    }
}
