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
        this.headings = [];
        this.scroller = null;
        this.onScroll = () => {};
        this.frame = null;
        this.#watchHeadings();
    }

    disconnect() {
        this.cancelScroll();
        this.#unwatchHeadings();
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
     * The current section is the last heading the reader has passed, not one
     * that happens to be on screen: a section taller than the pane leaves no
     * heading visible at all, and marking nothing through a long section is
     * worse than marking none.
     *
     * A scroll listener rather than an IntersectionObserver. An observer only
     * reports a crossing of its own root box, which is never exactly the
     * activation line, so the row changed a little before or a little after the
     * heading really passed. Measuring is one pass over a handful of elements,
     * throttled to one per frame.
     */
    #watchHeadings() {
        this.headings = this.linkTargets
            .map((link) => this.#targetOf(link))
            .filter((heading) => null !== heading);

        if (0 === this.headings.length) {
            return;
        }

        this.scroller = scrollerFor(this.headings[0]);
        this.onScroll = () => {
            if (null !== this.frame) {
                return;
            }
            this.frame = requestAnimationFrame(() => {
                this.frame = null;
                this.#markCurrent(this.#passedHeading()?.id ?? null);
            });
        };

        this.scroller.addEventListener('scroll', this.onScroll, {
            passive: true,
        });
        window.addEventListener('resize', this.onScroll, { passive: true });
        this.#markCurrent(this.#passedHeading()?.id ?? null);
    }

    #unwatchHeadings() {
        if (null !== this.frame) {
            cancelAnimationFrame(this.frame);
            this.frame = null;
        }
        this.scroller?.removeEventListener('scroll', this.onScroll);
        window.removeEventListener('resize', this.onScroll);
    }

    /**
     * The activation line sits a sticky bar's height below the pane's top edge,
     * which is the same offset the headings carry as `scroll-margin-top`.
     */
    #passedHeading() {
        const isDocument =
            this.scroller === document.scrollingElement ||
            this.scroller === document.documentElement;
        const line =
            (isDocument ? 0 : this.scroller.getBoundingClientRect().top) +
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
