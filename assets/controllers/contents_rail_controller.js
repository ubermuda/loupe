import { Controller } from '@hotwired/stimulus';
import { scrollerFor, smoothScrollTo } from '../lib/smooth_scroll.js';

const ACTIVATION_OFFSET = 72;
const ARRIVED_CLASS = 'lp-arrived';
const FLASH_MS = 2600;

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
        this.flashTimer = 0;
        this.flashed = null;
        this.asked = null;
        this.jumping = false;
        this.#watchHeadings();
    }

    disconnect() {
        this.cancelScroll();
        this.#unwatchHeadings();
        // Turbo caches the page as it stands, so a marker left here would come
        // back on the restored snapshot with nothing driving it.
        this.#markCurrent(null);
    }

    /**
     * A stream swaps these rows out when a section is approved, which takes the
     * marker with them. Re-measuring restores it.
     */
    linkTargetConnected() {
        this.onScroll?.();
    }

    jump(event) {
        const target = this.#targetOf(event.currentTarget);
        if (null === target) {
            return;
        }

        event.preventDefault();
        this.cancelScroll();
        this.asked = target.id;
        this.jumping = true;
        this.#markCurrent(target.id);
        this.cancelScroll = smoothScrollTo(target, {
            align: 'start',
            onDone: () => {
                this.jumping = false;
                this.#flash(target);
            },
        });
    }

    /**
     * Names the heading the reader was taken to. Without it a scroll of a few
     * hundred pixels ends with no sign of which of the headings on screen was
     * the one asked for.
     */
    #flash(heading) {
        // The whole head, so the approval control travels with its heading.
        const head = heading.closest('.lp-section-head') ?? heading;
        clearTimeout(this.flashTimer);
        this.flashed?.classList.remove(ARRIVED_CLASS);
        head.classList.add(ARRIVED_CLASS);
        this.flashed = head;
        this.flashTimer = setTimeout(() => {
            head.classList.remove(ARRIVED_CLASS);
            this.flashed = null;
        }, FLASH_MS);
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
        clearTimeout(this.flashTimer);
        this.flashed?.classList.remove(ARRIVED_CLASS);
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
        const top = isDocument ? 0 : this.scroller.getBoundingClientRect().top;
        const line = top + ACTIVATION_OFFSET;

        // The end of the document cannot be scrolled past, so the last few
        // headings never reach the line and would never be current however far
        // the reader goes. At the bottom the answer is the last heading on
        // screen, which is what asking for one of them scrolls to.
        const atBottom =
            this.scroller.scrollTop >=
            this.scroller.scrollHeight - this.scroller.clientHeight - 1;
        // Not while the jump is still running: every frame of it is short of
        // the bottom, and clearing there would drop the answer before arrival.
        if (!atBottom && !this.jumping) {
            this.asked = null;
        } else if (null !== this.asked) {
            // A click is an answer, where the bottom rule is only a guess. It
            // stands until the reader leaves the bottom of their own accord.
            const asked = this.headings.find(
                (heading) => heading.id === this.asked,
            );
            if (undefined !== asked) {
                return asked;
            }
        }
        const limit = atBottom ? top + this.scroller.clientHeight : line;

        let passed = null;
        for (const heading of this.headings) {
            if (heading.getBoundingClientRect().top > limit) {
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
