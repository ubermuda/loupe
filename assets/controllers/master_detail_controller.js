import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['trigger', 'panel'];

    connect() {
        const selected = window.location.hash.slice(1);
        this.show(
            selected || this.triggerTargets[0]?.dataset.masterDetailId,
            false,
        );
    }

    select(event) {
        this.show(event.currentTarget.dataset.masterDetailId, true);
    }

    show(id, updateUrl) {
        if (!id) {
            return;
        }

        this.triggerTargets.forEach((trigger) => {
            const selected = trigger.dataset.masterDetailId === id;
            trigger.setAttribute('aria-current', selected ? 'true' : 'false');
        });
        this.panelTargets.forEach((panel) => {
            panel.hidden = panel.dataset.masterDetailId !== id;
        });

        if (updateUrl) {
            window.history.replaceState(window.history.state, '', `#${id}`);
        }
    }
}
