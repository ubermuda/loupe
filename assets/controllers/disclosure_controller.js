import { Controller } from '@hotwired/stimulus';

/*
 * Animates a native <details> open/close with WAAPI. CSS transitions on a
 * <details> stop firing after the first cycle, so the height/opacity tween is
 * driven here instead. The controller intercepts the <summary> click, animates
 * the content target, and toggles the `open` attribute around the animation.
 *
 * Usage:
 *   <details data-controller="disclosure">
 *     <summary data-action="click->disclosure#toggle"> ... </summary>
 *     <div data-disclosure-target="content" class="overflow-hidden"> ... </div>
 *   </details>
 */
export default class extends Controller {
    static targets = ['content'];

    connect() {
        this.animation = null;
    }

    toggle(event) {
        event.preventDefault();
        if (this.animation) {
            this.animation.cancel();
        }
        if (this.element.open) {
            this.collapse();
        } else {
            this.expand();
        }
    }

    expand() {
        this.element.open = true;
        const content = this.contentTarget;
        this.animation = content.animate(
            { height: ['0px', `${content.scrollHeight}px`], opacity: [0, 1] },
            { duration: 200, easing: 'ease' },
        );
        this.animation.onfinish = () => {
            content.style.height = 'auto';
            this.animation = null;
        };
    }

    collapse() {
        const content = this.contentTarget;
        this.animation = content.animate(
            { height: [`${content.scrollHeight}px`, '0px'], opacity: [1, 0] },
            { duration: 200, easing: 'ease' },
        );
        this.animation.onfinish = () => {
            this.element.open = false;
            content.style.height = '';
            this.animation = null;
        };
    }
}
