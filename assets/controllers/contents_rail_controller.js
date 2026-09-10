import { Controller } from '@hotwired/stimulus';
import { scrollerFor, smoothScrollTo } from '../lib/smooth_scroll.js';

const ACTIVATION_OFFSET = 72;

/**
 * The contents and decisions rail beside a document. It scrolls the reader to a
 * section rather than jumping there, and marks the section they are in.
 *
 * The rail stands outside the metadata bar, so it cannot borrow that
 * controller's own jump. A bare `#hash` link here is a Turbo visit: the reader
 * lands on the heading and is taken back to the top a moment later.
 *
 * Usage:
 *   <aside data-controller="contents-rail">
 *     <a href="#heading-x" data-action="click->contents-rail#jump"
 *        data-contents-rail-target="link"> ... </a>
 */
export default class extends Controller {
    static targets = ['link'];
    static classes = ['current'];

    connect() {
        this.cancelScroll = () => {};
        this.observer = null;
        this.headings = [];
        this.#observeHeadings();
    }

    disconnect() {
        this.cancelScroll();
        this.observer?.disconnect();
        this.observer = null;
        // Turbo caches the page as it stands, so a marker left here would come
        // back on the restored snapshot with nothing driving it.
        this.#markCurrent(null);
    }

    jump(event) {
        const target = this.#targetOf(event.currentTarget);
        if (null === target) {
            return;
        }

        event.preventDefault();
        this.cancelScroll();
        this.cancelScroll = smoothScrollTo(target, { align: 'start' });
        this.#markCurrent(event.currentTarget.getAttribute('href')?.slice(1));
    }

    #targetOf(link) {
        const id = link.getAttribute('href')?.slice(1);

        return id === undefined || '' === id
            ? null
            : document.getElementById(id);
    }

    /**
     * The current section is the last heading the reader has passed, not a
     * heading that happens to be on screen: a section longer than the viewport
     * leaves no heading visible at all, and marking nothing for most of a long
     * section is worse than marking none.
     *
     * The observer is a trigger rather than the answer. It fires when a heading
     * crosses the top of the pane, which is exactly when the answer changes;
     * between two crossings there is nothing to recompute.
     */
    #observeHeadings() {
        this.headings = this.linkTargets
            .map((link) => this.#targetOf(link))
            .filter((heading) => null !== heading);

        if (0 === this.headings.length) {
            return;
        }

        this.#markCurrent(this.#passedHeading()?.id ?? null);

        if (!('IntersectionObserver' in window)) {
            return;
        }

        // The pane scrolls, not the window, so name it as the root: rootMargin
        // is measured against the root, and against the viewport it would mean
        // a band the pane does not have.
        const scroller = scrollerFor(this.headings[0]);
        const root =
            scroller === document.scrollingElement ||
            scroller === document.documentElement
                ? null
                : scroller;

        this.observer = new IntersectionObserver(
            () => this.#markCurrent(this.#passedHeading()?.id ?? null),
            { root, rootMargin: '0px 0px -85% 0px', threshold: 0 },
        );

        for (const heading of this.headings) {
            this.observer.observe(heading);
        }
    }

    /**
     * The activation line sits a sticky bar's height below the pane's top edge,
     * which is the same offset the headings carry as `scroll-margin-top`.
     */
    #passedHeading() {
        const scroller = scrollerFor(this.headings[0]);
        const isDocument =
            scroller === document.scrollingElement ||
            scroller === document.documentElement;
        const line =
            (isDocument ? 0 : scroller.getBoundingClientRect().top) +
            ACTIVATION_OFFSET;

        let passed = null;
        for (const heading of this.headings) {
            if (heading.getBoundingClientRect().top > line) {
                break;
            }
            passed = heading;
        }

        return passed ?? this.headings[0];
    }

    #markCurrent(id) {
        for (const link of this.linkTargets) {
            const isCurrent =
                null !== id && link.getAttribute('href') === '#' + id;
            link.classList.toggle(
                'lp-review-contents__link--current',
                isCurrent,
            );
            if (isCurrent) {
                link.setAttribute('aria-current', 'true');
            } else {
                link.removeAttribute('aria-current');
            }
        }
    }
}
