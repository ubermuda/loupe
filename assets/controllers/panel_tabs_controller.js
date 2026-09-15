import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['tab', 'panel'];
    static values = { active: { type: String, default: 'overview' } };

    connect() {
        const requestedTab = new URL(window.location.href).searchParams.get(
            'tab',
        );
        this.show(requestedTab || this.activeValue, false);
    }

    select(event) {
        this.show(event.currentTarget.dataset.panelTab, true);
    }

    keydown(event) {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
            return;
        }

        event.preventDefault();
        const currentIndex = this.tabTargets.indexOf(event.currentTarget);
        const nextIndex =
            event.key === 'Home'
                ? 0
                : event.key === 'End'
                  ? this.tabTargets.length - 1
                  : (currentIndex +
                        (event.key === 'ArrowRight' ? 1 : -1) +
                        this.tabTargets.length) %
                    this.tabTargets.length;

        this.tabTargets[nextIndex].focus();
        this.show(this.tabTargets[nextIndex].dataset.panelTab, true);
    }

    show(name, updateUrl) {
        const selectedTab =
            this.tabTargets.find((tab) => tab.dataset.panelTab === name) ||
            this.tabTargets[0];
        if (!selectedTab) {
            return;
        }

        this.tabTargets.forEach((tab) => {
            const selected = tab === selectedTab;
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.tabIndex = selected ? 0 : -1;
        });
        this.panelTargets.forEach((panel) => {
            panel.hidden =
                panel.dataset.panelPanel !== selectedTab.dataset.panelTab;
        });

        if (updateUrl) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', selectedTab.dataset.panelTab);
            window.history.replaceState(window.history.state, '', url);
        }
    }
}
