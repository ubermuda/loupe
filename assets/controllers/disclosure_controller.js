import { Controller } from '@hotwired/stimulus';

/**
 * Toggles the 'open' class on a target element.
 * Use for collapsible sections like the RSVP form.
 *
 * Usage:
 *   <div data-controller="disclosure">
 *     <button data-action="click->disclosure#toggle">Toggle</button>
 *     <div data-disclosure-target="content" class="...collapsible...">...</div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['content'];

    toggle() {
        const isOpen = this.contentTarget.classList.toggle('open');
        this.element.classList.toggle('disclosure-open', isOpen);
    }

    // Force-collapse from outside, e.g. after a Live Component save where the
    // re-render alone doesn't undo a runtime-added `.open` class.
    close() {
        this.contentTarget.classList.remove('open');
        this.element.classList.remove('disclosure-open');
    }
}
