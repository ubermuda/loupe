import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'tab',
        'panel',
        'filter',
        'thread',
        'option',
        'empty',
        'count',
    ];

    connect() {
        this.activeFilter = this.element.classList.contains(
            'lp-review-block--hide-resolved',
        )
            ? 'open'
            : 'all';
        this.refreshFilter();
    }

    threadTargetConnected() {
        this.refreshFilter();
    }

    threadTargetDisconnected() {
        this.refreshFilter();
    }

    emptyTargetConnected() {
        this.refreshFilter();
    }

    select(event) {
        if (this.isDisabled(event.currentTarget)) {
            return;
        }
        this.selectTab(event.params.name);
        this.revealTab(event.currentTarget);
    }

    // A disabled tab keeps its place in the row and its tooltip, so the arrows
    // step over it rather than landing on a panel that cannot open.
    isDisabled(tab) {
        return tab.getAttribute('aria-disabled') === 'true';
    }

    navigate(event) {
        const tabs = this.tabTargets;
        const index = tabs.indexOf(event.currentTarget);
        const step = (from, delta) => {
            for (let hop = 1; hop <= tabs.length; hop++) {
                const candidate =
                    (from + delta * hop + tabs.length * hop) % tabs.length;
                if (!this.isDisabled(tabs[candidate])) {
                    return candidate;
                }
            }
            return null;
        };
        let nextIndex;

        switch (event.key) {
            case 'ArrowRight':
                nextIndex = step(index, 1);
                break;
            case 'ArrowLeft':
                nextIndex = step(index, -1);
                break;
            case 'Home':
                nextIndex = step(-1, 1);
                break;
            case 'End':
                nextIndex = step(tabs.length, -1);
                break;
            default:
                return;
        }

        event.preventDefault();
        if (null === nextIndex) {
            return;
        }
        const tab = tabs[nextIndex];
        this.selectTab(tab.dataset.reviewMarginNameParam);
        tab.focus({ preventScroll: true });
        this.revealTab(tab);
    }

    revealTab(tab) {
        const tablist = tab.closest('[role="tablist"]');
        // Only a list that scrolls needs revealing. Scrolling one that fits
        // moved the row by a pixel on every switch, which reads as a flicker.
        if (tablist.scrollWidth <= tablist.clientWidth) {
            return;
        }
        tablist.parentElement.scrollIntoView({
            block: 'nearest',
            inline: 'nearest',
            behavior: 'instant',
        });
        const tabBounds = tab.getBoundingClientRect();
        const listBounds = tablist.getBoundingClientRect();
        tablist.scrollBy({
            left:
                tabBounds.left -
                listBounds.left +
                (tabBounds.width - listBounds.width) / 2,
            behavior: 'instant',
        });
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

        if (this.hasFilterTarget) {
            // Hidden by visibility, not by [hidden]: taking it out of the
            // layout shifted the whole row sideways on every tab change.
            this.filterTarget.classList.toggle(
                'lp-review-margin-filter--inactive',
                name !== 'comments',
            );
            this.filterTarget.open = false;
        }
        window.dispatchEvent(new Event('resize'));
    }

    filter(event) {
        this.activeFilter = event.params.filter;
        this.dispatch('filter', { detail: { filter: this.activeFilter } });
        this.refreshFilter();
        this.filterTarget.open = false;
        this.filterTarget.querySelector('summary').focus();
    }

    resolvedFilter(event) {
        this.activeFilter = event.detail.hidden ? 'open' : 'all';
        this.refreshFilter();
    }

    closeFilter(event) {
        if (!this.filterTarget.open) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        this.filterTarget.open = false;
        this.filterTarget.querySelector('summary').focus();
    }

    refreshFilter() {
        if (!this.hasFilterTarget || !this.activeFilter) {
            return;
        }
        const counts = { all: 0, open: 0, resolved: 0, unanchored: 0 };

        for (const thread of this.threadTargets) {
            const status = thread.dataset.anchorStatus;
            const matches = [
                'all',
                status === 'resolved' ? 'resolved' : 'open',
            ];
            if (thread.dataset.commentOrphaned === 'true') {
                matches.push('unanchored');
            }
            for (const match of matches) {
                counts[match]++;
            }
            thread.hidden = !matches.includes(this.activeFilter);
        }

        for (const option of this.optionTargets) {
            const name = option.dataset.reviewMarginFilterParam;
            option.setAttribute(
                'aria-pressed',
                name === this.activeFilter ? 'true' : 'false',
            );
            option.querySelector('[data-filter-count]').textContent =
                counts[name];
        }
        for (const count of this.countTargets) {
            count.textContent = counts.all;
        }
        this.filterTarget
            .querySelector('summary')
            .classList.toggle(
                'lp-review-margin-tabs__item--filtered',
                this.activeFilter !== 'open',
            );
        for (const empty of this.emptyTargets) {
            empty.hidden = counts[this.activeFilter] !== 0;
            for (const message of empty.querySelectorAll(
                '[data-empty-filter]',
            )) {
                // Either the document holds no comments at all, or a filter
                // is hiding the ones it holds.
                message.hidden =
                    (message.dataset.emptyFilter === 'all') !==
                    (counts.all === 0);
            }
        }
        for (const group of this.element.querySelectorAll(
            '.lp-general-comments, .lp-orphan-group',
        )) {
            group.hidden = !group.querySelector(
                '.lp-comment-thread:not([hidden])',
            );
        }
        window.dispatchEvent(new Event('resize'));
    }
}
