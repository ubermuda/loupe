import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['tab', 'panel', 'filter'];

    select(event) {
        this.selectTab(event.params.name);
    }

    navigate(event) {
        const index = this.tabTargets.indexOf(event.currentTarget);
        let nextIndex;

        switch (event.key) {
            case 'ArrowRight':
                nextIndex = (index + 1) % this.tabTargets.length;
                break;
            case 'ArrowLeft':
                nextIndex =
                    (index - 1 + this.tabTargets.length) %
                    this.tabTargets.length;
                break;
            case 'Home':
                nextIndex = 0;
                break;
            case 'End':
                nextIndex = this.tabTargets.length - 1;
                break;
            default:
                return;
        }

        event.preventDefault();
        const tab = this.tabTargets[nextIndex];
        this.selectTab(tab.dataset.reviewMarginNameParam);
        tab.focus();
    }

    selectTab(name) {
        for (const tab of this.tabTargets) {
            const selected = tab.dataset.reviewMarginNameParam === name;
            tab.classList.toggle(
                'lp-review-margin-tabs__item--active',
                selected,
            );
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.tabIndex = selected ? 0 : -1;
        }
        for (const panel of this.panelTargets) {
            panel.hidden = panel.dataset.marginPanel !== name;
        }

        this.filterTarget.hidden = name !== 'comments';
        this.filterTarget.open = false;
        window.dispatchEvent(new Event('resize'));
    }

    filter(event) {
        const filter = event.params.filter;
        const threads = this.element.querySelectorAll('.lp-comment-thread');

        for (const thread of threads) {
            const status = thread.dataset.anchorStatus;
            const visible =
                filter === 'all' ||
                (filter === 'open' && status !== 'resolved') ||
                (filter === 'resolved' && status === 'resolved') ||
                (filter === 'unanchored' &&
                    thread.dataset.commentOrphaned === 'true');
            thread.hidden = !visible;
        }

        this.filterTarget.open = false;
        window.dispatchEvent(new Event('resize'));
    }
}
