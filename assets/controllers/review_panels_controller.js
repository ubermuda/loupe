import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'loupe.review.panels';
const PANEL_ORDER = ['decisions', 'comments', 'outline'];
const DEFAULT_PANELS = ['decisions'];
const MENU_GAP = 6;
const MENU_MARGIN = 8;

export default class extends Controller {
    static targets = [
        'button',
        'panel',
        'filter',
        'filterMenu',
        'thread',
        'option',
        'empty',
        'openCount',
        'filterLabel',
        'inTextTitle',
        'inTextCount',
    ];

    // `compare` opens the outline over the stored choice, and `columns` starts
    // with every panel hidden. Neither writes the choice the document reads.
    static values = { mode: { type: String, default: 'document' } };

    connect() {
        this.onFilterScroll = () => {
            this.filterTarget.open = false;
        };
        this.onFilterResize = () => this.placeFilter();
        this.topbarObserver = new ResizeObserver(() => this.#alignTopbar());
        this.topbarObserver.observe(this.element);
        const stored = this.modeValue === 'columns' ? [] : this.#storedPanels();
        if (stored !== null) {
            for (const button of this.buttonTargets) {
                const name = button.dataset.reviewPanelsNameParam;
                this.#show(name, stored.includes(name));
            }
        }
        if (this.modeValue === 'compare') {
            this.#show('outline', true);
        }

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

    toggle(event) {
        const button = event.currentTarget;
        if (this.#isDisabled(button)) {
            return;
        }
        const name = event.params.name;
        const open = button.getAttribute('aria-pressed') !== 'true';
        this.#show(name, open);
        this.#remember(name, open);
        window.dispatchEvent(new Event('resize'));
    }

    reveal(event) {
        const button = this.buttonTargets.find(
            (each) => each.dataset.reviewPanelsNameParam === 'comments',
        );
        if (button === undefined || this.#isDisabled(button)) {
            return;
        }
        if (button.getAttribute('aria-pressed') !== 'true') {
            this.#show('comments', true);
            this.#remember('comments', true);
        }
        // A filter that hides the thread would open the panel on nothing.
        const thread = event?.detail?.thread;
        if (
            thread != null &&
            !this.#filtersOf(thread).includes(this.activeFilter)
        ) {
            this.activeFilter = 'all';
            this.dispatch('filter', { detail: { filter: this.activeFilter } });
            this.refreshFilter();
        }
        window.dispatchEvent(new Event('resize'));
    }

    #remember(name, open) {
        if (this.modeValue !== 'document') {
            return;
        }
        // Stored choices for a panel this page lacks or disables stay as they
        // were, so a page without Comments does not close it on another page.
        const panels = new Set(this.#storedPanels() ?? DEFAULT_PANELS);
        if (open) {
            panels.add(name);
        } else {
            panels.delete(name);
        }
        try {
            window.localStorage.setItem(
                STORAGE_KEY,
                JSON.stringify(PANEL_ORDER.filter((each) => panels.has(each))),
            );
        } catch {
            // Storage can be blocked. The toggle still works on this page.
        }
    }

    filter(event) {
        this.activeFilter = event.params.filter;
        this.dispatch('filter', { detail: { filter: this.activeFilter } });
        this.refreshFilter();
        this.filterTarget.open = false;
        this.filterTarget.querySelector('summary').focus();
    }

    disconnect() {
        this.#unwatchFilter();
        this.topbarObserver.disconnect();
        document
            .querySelector('.lp-topbar')
            ?.style.removeProperty('--review-panels-inset');
    }

    // The top bar's review action reads this inset to end where the panel column ends.
    #alignTopbar() {
        const topbar = document.querySelector('.lp-topbar');
        const panels = this.element.querySelector('.lp-review-panels');
        if (topbar === null || panels === null) {
            return;
        }
        const inset =
            topbar.getBoundingClientRect().right -
            panels.getBoundingClientRect().right;
        topbar.style.setProperty(
            '--review-panels-inset',
            `${Math.max(0, Math.round(inset))}px`,
        );
    }

    /**
     * The panel column scrolls, so a menu inside it would be clipped. The menu
     * is fixed instead, hung from its toggle. A scroll closes it, and a resize
     * hangs it again.
     */
    placeFilter() {
        if (!this.hasFilterMenuTarget) {
            return;
        }
        const menu = this.filterMenuTarget;
        if (!this.filterTarget.open) {
            menu.removeAttribute('data-placed');
            this.#unwatchFilter();

            return;
        }
        menu.style.left = '0px';
        menu.style.top = '0px';
        const origin = menu.getBoundingClientRect();
        const toggle = this.filterTarget
            .querySelector('summary')
            .getBoundingClientRect();
        const left = Math.max(
            MENU_MARGIN,
            Math.min(
                toggle.right - origin.width,
                window.innerWidth - origin.width - MENU_MARGIN,
            ),
        );
        let top = toggle.bottom + MENU_GAP;
        const above = toggle.top - MENU_GAP - origin.height;
        if (
            top + origin.height > window.innerHeight - MENU_MARGIN &&
            above >= MENU_MARGIN
        ) {
            top = above;
        }
        top = Math.max(
            MENU_MARGIN,
            Math.min(top, window.innerHeight - origin.height - MENU_MARGIN),
        );
        // A transformed ancestor moves the fixed origin, so offset from where 0,0 landed.
        menu.style.left = `${left - origin.left}px`;
        menu.style.top = `${top - origin.top}px`;
        menu.setAttribute('data-placed', '');
        window.addEventListener('scroll', this.onFilterScroll, true);
        window.addEventListener('resize', this.onFilterResize);
    }

    #unwatchFilter() {
        window.removeEventListener('scroll', this.onFilterScroll, true);
        window.removeEventListener('resize', this.onFilterResize);
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
            const matches = this.#filtersOf(thread);
            for (const match of matches) {
                counts[match]++;
            }
            thread.hidden = !matches.includes(this.activeFilter);
        }

        for (const option of this.optionTargets) {
            const name = option.dataset.reviewPanelsFilterParam;
            option.setAttribute(
                'aria-pressed',
                name === this.activeFilter ? 'true' : 'false',
            );
            option.querySelector('[data-filter-count]').textContent =
                counts[name];
        }
        for (const count of this.openCountTargets) {
            count.textContent = counts.open;
            const button = count.closest('[data-label-template]');
            button?.setAttribute(
                'aria-label',
                button.dataset.labelTemplate.replace(
                    '%count%',
                    String(counts.open),
                ),
            );
        }
        const activeOption = this.optionTargets.find(
            (option) =>
                option.dataset.reviewPanelsFilterParam === this.activeFilter,
        );
        if (this.hasFilterLabelTarget && activeOption !== undefined) {
            this.filterLabelTarget.textContent = `${activeOption.dataset.filterLabel} · ${counts[this.activeFilter]}`;
        }
        const inText = this.threadTargets.filter(
            (thread) =>
                !thread.hidden &&
                thread.dataset.commentOrphaned !== 'true' &&
                thread.closest('.lp-general-comments') === null,
        ).length;
        for (const count of this.inTextCountTargets) {
            count.textContent = inText;
        }
        for (const title of this.inTextTitleTargets) {
            title.hidden = inText === 0;
        }
        this.filterTarget
            .querySelector('summary')
            .classList.toggle(
                'lp-review-filter__toggle--filtered',
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
                '.lp-comment-row:not([hidden])',
            );
        }
        window.dispatchEvent(new Event('resize'));
    }

    #filtersOf(thread) {
        const matches = [
            'all',
            thread.dataset.anchorStatus === 'resolved' ? 'resolved' : 'open',
        ];
        if (thread.dataset.commentOrphaned === 'true') {
            matches.push('unanchored');
        }

        return matches;
    }

    #show(name, open) {
        const button = this.buttonTargets.find(
            (each) => each.dataset.reviewPanelsNameParam === name,
        );
        const visible =
            open && button !== undefined && !this.#isDisabled(button);
        button?.setAttribute('aria-pressed', visible ? 'true' : 'false');
        for (const panel of this.panelTargets) {
            if (panel.dataset.reviewPanel === name) {
                panel.hidden = !visible;
            }
        }
    }

    // aria-disabled rather than disabled, so the button keeps its tooltip.
    #isDisabled(button) {
        return button.getAttribute('aria-disabled') === 'true';
    }

    #storedPanels() {
        try {
            const stored = JSON.parse(window.localStorage.getItem(STORAGE_KEY));
            if (
                Array.isArray(stored) &&
                stored.every((name) => PANEL_ORDER.includes(name))
            ) {
                return stored;
            }
        } catch {
            // Unreadable or blocked storage falls back to the server default.
        }

        return null;
    }
}
