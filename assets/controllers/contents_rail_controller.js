import { Controller } from '@hotwired/stimulus';
import { smoothScrollTo } from '../lib/smooth_scroll.js';

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
        this.visible = new Set();
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
     * The heading nearest the top of what is on screen is the one the reader is
     * in. rootMargin pulls the bottom edge up so a heading is "current" while
     * its own section fills the view, rather than only while the heading itself
     * is visible.
     */
    #observeHeadings() {
        if (!('IntersectionObserver' in window)) {
            return;
        }

        const headings = this.linkTargets
            .map((link) => this.#targetOf(link))
            .filter((heading) => null !== heading);

        if (0 === headings.length) {
            return;
        }

        this.observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        this.visible.add(entry.target.id);
                    } else {
                        this.visible.delete(entry.target.id);
                    }
                }

                const current = headings.find((heading) =>
                    this.visible.has(heading.id),
                );
                this.#markCurrent(current?.id ?? null);
            },
            { rootMargin: '-64px 0px -70% 0px', threshold: 0 },
        );

        for (const heading of headings) {
            this.observer.observe(heading);
        }
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
