import { Controller } from '@hotwired/stimulus';
import { prefersReducedMotion } from '../lib/smooth_scroll.js';

/*
 * Dismisses a flash message. No timer on purpose: the flash scrolls away with
 * the content rather than floating over it, so it never needs to time out.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    dismiss() {
        if (prefersReducedMotion()) {
            this.element.remove();
            return;
        }
        const animation = this.element.animate(
            [
                { opacity: 1, transform: 'translateY(0)' },
                { opacity: 0, transform: 'translateY(-4px)' },
            ],
            { duration: 120, easing: 'ease-out', fill: 'forwards' },
        );
        animation.finished.then(() => this.element.remove()).catch(() => {});
    }
}
