import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['tab', 'panel', 'filter'];

    select(event) {
        const name = event.params.name;

        for (const tab of this.tabTargets) {
            const selected = tab.dataset.reviewMarginNameParam === name;
            tab.classList.toggle(
                'lp-review-margin-tabs__item--active',
                selected,
            );
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
        }
        for (const panel of this.panelTargets) {
            panel.hidden = panel.dataset.marginPanel !== name;
        }

        this.filterTarget.hidden = name !== 'comments';
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
