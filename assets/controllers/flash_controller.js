import { Controller } from '@hotwired/stimulus';

/*
 * Dismisses a flash message. There is no timer: the flash sits at the top of
 * the paper panel and scrolls away with the content, so it never covers
 * anything and does not need to time itself out. It goes when the reader says
 * so.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    dismiss() {
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
