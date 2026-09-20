import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['trigger', 'panel'];
    static values = { selected: String };

    connect() {
        const selected = this.selectedValue || window.location.hash.slice(1);
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

        let selectedTrigger = null;
        this.triggerTargets.forEach((trigger) => {
            const selected = trigger.dataset.masterDetailId === id;
            trigger.setAttribute('aria-current', selected ? 'true' : 'false');
            if (selected) {
                selectedTrigger = trigger;
            }
        });
        this.panelTargets.forEach((panel) => {
            panel.hidden = panel.dataset.masterDetailId !== id;
        });

        if (updateUrl) {
            window.history.replaceState(window.history.state, '', `#${id}`);
        }

        // A filter selects the first row it keeps, which can sit above the
        // list's scroll position.
        selectedTrigger?.scrollIntoView?.({ block: 'nearest' });
    }
}
