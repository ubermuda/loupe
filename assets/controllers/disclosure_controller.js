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
        // For a real <details>, `.open` already reflects the native attribute
        // at connect time (a real boolean). A plain wrapper div has no such
        // reflection — `.open` starts undefined even when the server rendered
        // it already open (e.g. list_projects.html.twig re-renders the create
        // form open after a failed submission). Derive the initial state from
        // that server-rendered class so the first click toggles correctly
        // instead of re-expanding an already-open panel.
        if (undefined === this.element.open) {
            this.element.open =
                this.element.classList.contains('disclosure-open');
        }
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
        // `.open` on this.element only reflects natively for a real <details>
        // element. For a plain wrapper div (e.g. list_projects.html.twig's
        // "New project" disclosure), visibility is driven entirely by these
        // two classes — .disclosure-open on the wrapper, .open on the content
        // target — which app.css keys its `display` toggle off of.
        this.element.classList.add('disclosure-open');
        const content = this.contentTarget;
        content.classList.add('open');
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
            this.element.classList.remove('disclosure-open');
            content.classList.remove('open');
            content.style.height = '';
            this.animation = null;
        };
    }
}
