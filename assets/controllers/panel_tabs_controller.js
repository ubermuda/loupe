import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'eager' */
export default class extends Controller {
    static targets = ['tab', 'panel'];
    static values = { active: { type: String, default: 'overview' } };

    connect() {
        this.frame = this.element.closest('turbo-frame[src]');
        const source = this.frame
            ? this.frame.getAttribute('src') || window.location.pathname
            : window.location.href;
        const url = new URL(source, window.location.href);
        const requestedTab = url.searchParams.get('tab');
        this.show(requestedTab || this.activeValue, false);
        this.revealAnchor(url.hash);
        this.onHashChange = () => {
            if (!this.frame) {
                this.revealAnchor(window.location.hash);
            }
        };
        window.addEventListener('hashchange', this.onHashChange);
    }

    disconnect() {
        window.removeEventListener('hashchange', this.onHashChange);
        cancelAnimationFrame(this.anchorFrame);
    }

    revealAnchor(hash) {
        const target = document.getElementById(hash.slice(1));
        const panel = this.panelTargets.find((panel) => panel.contains(target));
        if (!target || !panel) {
            return;
        }

        this.show(panel.dataset.panelPanel, false);
        this.anchorFrame = requestAnimationFrame(() => {
            target.scrollIntoView({ block: 'start', behavior: 'instant' });
        });
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
        cancelAnimationFrame(this.anchorFrame);
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

        if (updateUrl && !this.frame) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', selectedTab.dataset.panelTab);
            url.hash = '';
            window.history.replaceState(window.history.state, '', url);
        }
    }
}
