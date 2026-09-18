import { Controller } from '@hotwired/stimulus';
import { prefersReducedMotion } from '../lib/smooth_scroll.js';

// Native details transitions stop firing after the first cycle; WAAPI supports repeated toggles.
export default class extends Controller {
    // `autofocus` is opt-in: the same controller collapses read-only context
    // rows, and stealing the caret when one of those opens would be wrong.
    static targets = ['content', 'autofocus', 'container', 'trigger'];

    get disclosureElement() {
        return this.hasContainerTarget ? this.containerTarget : this.element;
    }

    connect() {
        this.animation = null;
        // A real <details> reflects `.open` at connect time; a plain wrapper div
        // does not — `.open` is undefined even when the server rendered it open.
        // Derive the initial state from the server-rendered class, or the first
        // click re-expands an already-open panel.
        if (undefined === this.disclosureElement.open) {
            this.disclosureElement.open =
                this.disclosureElement.classList.contains('disclosure-open');
        }
        this.expanded = this.disclosureElement.open;
        this.syncExpandedState();
    }

    syncExpandedState() {
        if (this.disclosureElement instanceof HTMLDetailsElement) {
            return;
        }
        for (const trigger of this.triggerTargets) {
            trigger.setAttribute('aria-expanded', String(this.expanded));
        }
    }

    toggle(event) {
        event.preventDefault();
        let current;
        if (this.animation) {
            current = {
                height: this.contentTarget.getBoundingClientRect().height,
                opacity: Number(
                    window.getComputedStyle(this.contentTarget).opacity,
                ),
            };
            this.animation.cancel();
            this.animation = null;
        }
        if (this.expanded) {
            this.collapse(current);
        } else {
            this.expand(current);
        }
    }

    expand(current = { height: 0, opacity: 0 }) {
        this.expanded = true;
        this.disclosureElement.open = true;
        this.syncExpandedState();
        // `.open` on this.element only reflects natively for a real <details>
        // element. For a plain wrapper div (e.g. list_projects.html.twig's
        // "New project" disclosure), visibility is driven entirely by these
        // two classes — .disclosure-open on the wrapper, .open on the content
        // target — which app.css keys its `display` toggle off of.
        this.disclosureElement.classList.add('disclosure-open');
        const content = this.contentTarget;
        content.classList.add('open');
        const finish = () => {
            content.style.height = 'auto';
            this.animation = null;
        };
        if (prefersReducedMotion()) {
            finish();
        } else {
            this.animation = content.animate(
                {
                    height: [
                        `${current.height}px`,
                        `${content.scrollHeight}px`,
                    ],
                    opacity: [current.opacity, 1],
                },
                { duration: 200, easing: 'ease' },
            );
            this.animation.onfinish = finish;
        }
        if (this.hasAutofocusTarget) {
            this.autofocusTarget.focus();
        }
    }

    collapse(
        current = { height: this.contentTarget.scrollHeight, opacity: 1 },
    ) {
        this.expanded = false;
        this.syncExpandedState();
        const content = this.contentTarget;
        const finish = () => {
            this.disclosureElement.open = false;
            this.disclosureElement.classList.remove('disclosure-open');
            content.classList.remove('open');
            content.style.height = '';
            this.animation = null;
        };
        if (prefersReducedMotion()) {
            finish();
        } else {
            this.animation = content.animate(
                {
                    height: [`${current.height}px`, '0px'],
                    opacity: [current.opacity, 0],
                },
                { duration: 200, easing: 'ease' },
            );
            this.animation.onfinish = finish;
        }
    }
}
