import { Controller } from '@hotwired/stimulus';

/**
 * Drives the review document's commenting UX.
 *
 * Capture: selecting text shows a small floating toolbar (not the composer, so
 * selecting/copying is never hijacked). Clicking "Comment" opens the composer
 * and highlights the selection. The anchor is sent as the verbatim selected
 * `quote` plus surrounding `prefix`/`suffix`, all sliced from the document
 * container's `textContent` — which equals PHP's `DocumentVersion::plainText()`
 * (strip_tags + html_entity_decode). Because the exact string crosses the wire
 * (no character offsets), the server finds it byte-for-byte; this sidesteps the
 * JS-UTF16 vs PHP-byte drift that previously garbled quotes after multibyte
 * characters.
 *
 * Display: each existing thread's anchor is highlighted in the document (CSS
 * Custom Highlight API — no DOM mutation, so `textContent` stays intact) and the
 * thread card is positioned vertically near its anchor. Positioning degrades to
 * normal document flow on any failure.
 */
export default class extends Controller {
    static targets = [
        'doc',
        'composer',
        'composerBody',
        'composerError',
        'quote',
        'prefix',
        'suffix',
        'toolbar',
        'thread',
    ];

    static CONTEXT = 32;
    static THREAD_GAP = 12;

    connect() {
        this.pendingSelection = null;
        this.#hideToolbar();
        this.#hideComposer();
        this.#registerHighlights();

        this.scheduledLayout = null;
        this.onResize = () => this.#scheduleLayout();
        window.addEventListener('resize', this.onResize);

        // Re-measure once layout settles (connect() fires before layout during
        // Turbo navigation, when getBoundingClientRect would read zeros).
        this.resizeObserver = new ResizeObserver(() => this.#scheduleLayout());
        this.resizeObserver.observe(this.docTarget);

        // Turbo Streams swap the thread list (add/delete replace the whole
        // #comment-threads container; reply/resolve replace a single thread), so
        // observe the stable controller root — observing the container itself
        // would miss its own replacement and stop firing after the first change.
        this.threadObserver = new MutationObserver(() =>
            this.#scheduleLayout(),
        );
        this.threadObserver.observe(this.element, {
            childList: true,
            subtree: true,
        });
    }

    disconnect() {
        window.removeEventListener('resize', this.onResize);
        this.resizeObserver?.disconnect();
        this.threadObserver?.disconnect();
        if (this.scheduledLayout !== null) {
            cancelAnimationFrame(this.scheduledLayout);
        }
        this.anchorHighlight?.clear();
        this.activeHighlight?.clear();
    }

    /**
     * On mouseup inside the document text, capture the selection's anchor and
     * show the floating toolbar. Clicks on the toolbar/composer (inside
     * .bp-review-doc but outside the doc text) are ignored, so they never hide
     * the toolbar or clobber the pending selection.
     */
    onDocMouseup(event) {
        if (!this.docTarget.contains(event.target)) {
            return;
        }

        const selection = window.getSelection();
        if (!selection || selection.isCollapsed || selection.rangeCount === 0) {
            this.#hideToolbar();
            return;
        }

        const range = selection.getRangeAt(0);
        if (!this.docTarget.contains(range.commonAncestorContainer)) {
            this.#hideToolbar();
            return;
        }

        const anchor = this.#extractAnchor(range);
        if (anchor === null) {
            this.#hideToolbar();
            return;
        }

        this.pendingSelection = { ...anchor, range: range.cloneRange() };
        this.#showToolbarNear(range);
    }

    /** Toolbar action: open the composer for the captured selection. */
    startComment(event) {
        event?.preventDefault();
        if (this.pendingSelection === null) {
            return;
        }

        this.quoteTarget.value = this.pendingSelection.quote;
        this.prefixTarget.value = this.pendingSelection.prefix;
        this.suffixTarget.value = this.pendingSelection.suffix;

        this.#setActiveHighlight(this.pendingSelection.range);
        this.#hideToolbar();
        this.#showComposerNear(this.pendingSelection.range);
    }

    /** Sidebar action: open the composer for a comment with no anchor. */
    startUntargeted(event) {
        event?.preventDefault();

        this.pendingSelection = null;
        this.quoteTarget.value = '';
        this.prefixTarget.value = '';
        this.suffixTarget.value = '';

        this.#clearActiveHighlight();
        this.#hideToolbar();
        this.#showComposerUntargeted();
    }

    /** Composer action: cancel (Cancel button). */
    hideComposer(event) {
        event?.preventDefault();
        this.pendingSelection = null;
        this.#clearActiveHighlight();
        this.#hideComposer();
    }

    /**
     * After a successful Turbo submission the new thread has been inserted by the
     * returned stream; clear and hide the composer, then re-layout. On failure
     * the composer stays open showing the streamed error.
     */
    onSubmitEnd(event) {
        if (!event.detail?.success) {
            return;
        }

        this.composerBodyTarget.value = '';
        if (this.hasComposerErrorTarget) {
            this.composerErrorTarget.textContent = '';
        }
        this.pendingSelection = null;
        this.#clearActiveHighlight();
        this.#hideComposer();
        this.#scheduleLayout();
    }

    // ── Anchor extraction ───────────────────────────────────────────────────

    /**
     * Builds {quote, prefix, suffix} for a selection by slicing the doc
     * container's textContent at the selection's character offsets. Returns null
     * for an empty selection.
     */
    #extractAnchor(range) {
        const start = this.#textOffset(range.startContainer, range.startOffset);
        const end = this.#textOffset(range.endContainer, range.endOffset);
        if (end <= start) {
            return null;
        }

        const fullText = this.docTarget.textContent;
        const context = this.constructor.CONTEXT;

        return {
            quote: fullText.slice(start, end),
            prefix: fullText.slice(Math.max(0, start - context), start),
            suffix: fullText.slice(end, end + context),
        };
    }

    /**
     * Character offset of (node, offsetInNode) from the start of the doc
     * container, summing text-node lengths in document order. Matches the basis
     * the server uses (plainText()).
     */
    #textOffset(targetNode, offsetInNode) {
        const walker = document.createTreeWalker(
            this.docTarget,
            NodeFilter.SHOW_TEXT,
            null,
        );
        let offset = 0;
        let node = walker.nextNode();
        while (node !== null) {
            if (node === targetNode) {
                return offset + offsetInNode;
            }
            offset += node.textContent.length;
            node = walker.nextNode();
        }
        return offset;
    }

    /**
     * Finds a DOM Range for an anchor's quote in the live document, picking the
     * occurrence whose surrounding text best matches prefix/suffix (mirrors the
     * server's locate()). Returns null if the quote is absent.
     */
    #findRange(quote, prefix, suffix) {
        if (quote === '') {
            return null;
        }

        const fullText = this.docTarget.textContent;
        const occurrences = [];
        let from = fullText.indexOf(quote);
        while (from !== -1) {
            occurrences.push(from);
            from = fullText.indexOf(quote, from + 1);
        }
        if (occurrences.length === 0) {
            return null;
        }

        const context = this.constructor.CONTEXT;
        const score = (start) => {
            let value = 0;
            const before = fullText.slice(Math.max(0, start - context), start);
            const after = fullText.slice(
                start + quote.length,
                start + quote.length + context,
            );
            if (prefix !== '' && before.endsWith(prefix.slice(-8))) {
                value += 1;
            }
            if (suffix !== '' && after.startsWith(suffix.slice(0, 8))) {
                value += 1;
            }
            return value;
        };
        occurrences.sort((a, b) => score(b) - score(a) || a - b);

        const start = occurrences[0];
        return this.#rangeForTextSpan(start, start + quote.length);
    }

    /** Maps a [start, end) span of the doc's textContent to a DOM Range. */
    #rangeForTextSpan(start, end) {
        const walker = document.createTreeWalker(
            this.docTarget,
            NodeFilter.SHOW_TEXT,
            null,
        );
        const range = document.createRange();
        let offset = 0;
        let startSet = false;
        let node = walker.nextNode();
        while (node !== null) {
            const length = node.textContent.length;
            if (!startSet && start <= offset + length) {
                range.setStart(node, start - offset);
                startSet = true;
            }
            if (startSet && end <= offset + length) {
                range.setEnd(node, end - offset);
                return range;
            }
            offset += length;
            node = walker.nextNode();
        }
        return null;
    }

    // ── Highlighting (CSS Custom Highlight API) ─────────────────────────────

    #highlightsSupported() {
        return (
            typeof window.Highlight !== 'undefined' &&
            typeof window.CSS !== 'undefined' &&
            window.CSS.highlights
        );
    }

    #registerHighlights() {
        if (!this.#highlightsSupported()) {
            return;
        }
        this.anchorHighlight = new window.Highlight();
        this.activeHighlight = new window.Highlight();
        window.CSS.highlights.set('bp-anchor', this.anchorHighlight);
        window.CSS.highlights.set('bp-anchor-active', this.activeHighlight);
    }

    #setActiveHighlight(range) {
        if (!this.activeHighlight) {
            return;
        }
        this.activeHighlight.clear();
        this.activeHighlight.add(range);
    }

    #clearActiveHighlight() {
        this.activeHighlight?.clear();
    }

    // ── Layout: highlight anchors + position threads ────────────────────────

    #scheduleLayout() {
        if (this.scheduledLayout !== null) {
            cancelAnimationFrame(this.scheduledLayout);
        }
        this.scheduledLayout = requestAnimationFrame(() => {
            this.scheduledLayout = null;
            this.#layout();
        });
    }

    #layout() {
        try {
            this.#highlightAnchors();
            this.#positionThreads();
        } catch {
            this.#resetThreadPositions();
        }
    }

    #highlightAnchors() {
        if (!this.anchorHighlight) {
            return;
        }
        this.anchorHighlight.clear();
        for (const thread of this.threadTargets) {
            // Resolved threads are settled — don't highlight their anchor.
            if (thread.classList.contains('bp-comment-thread--resolved')) {
                continue;
            }
            const range = this.#findRange(
                thread.dataset.anchorQuote ?? '',
                thread.dataset.anchorPrefix ?? '',
                thread.dataset.anchorSuffix ?? '',
            );
            if (range !== null) {
                this.anchorHighlight.add(range);
            }
        }
    }

    /**
     * Positions each thread card near the vertical centre of its anchor, pushing
     * later cards down to avoid overlap. Threads without a locatable anchor flow
     * after the positioned ones. Any failure reverts to normal flow.
     */
    #positionThreads() {
        const container = this.#threadsContainer();
        if (!container || this.threadTargets.length === 0) {
            return;
        }

        const containerTop = container.getBoundingClientRect().top;
        const placements = this.threadTargets.map((thread) => {
            const range = this.#findRange(
                thread.dataset.anchorQuote ?? '',
                thread.dataset.anchorPrefix ?? '',
                thread.dataset.anchorSuffix ?? '',
            );
            const desiredTop = range
                ? range.getBoundingClientRect().top - containerTop
                : null;
            return { thread, desiredTop };
        });

        placements.sort((a, b) => {
            if (a.desiredTop === null) return b.desiredTop === null ? 0 : 1;
            if (b.desiredTop === null) return -1;
            return a.desiredTop - b.desiredTop;
        });

        container.style.position = 'relative';
        let cursor = 0;
        for (const { thread, desiredTop } of placements) {
            const top = Math.max(desiredTop ?? cursor, cursor);
            thread.style.position = 'absolute';
            thread.style.left = '0';
            thread.style.right = '0';
            thread.style.top = `${top}px`;
            cursor = top + thread.offsetHeight + this.constructor.THREAD_GAP;
        }
        container.style.height = `${cursor}px`;
    }

    #resetThreadPositions() {
        const container = this.#threadsContainer();
        if (container) {
            container.style.position = '';
            container.style.height = '';
        }
        for (const thread of this.threadTargets) {
            thread.style.position = '';
            thread.style.left = '';
            thread.style.right = '';
            thread.style.top = '';
        }
    }

    #threadsContainer() {
        return this.element.querySelector('.bp-comment-threads');
    }

    // ── Toolbar / composer positioning ──────────────────────────────────────

    /**
     * Origin (viewport coords) of the absolute-positioning containing block for
     * the toolbar/composer: the padding-box top-left of .bp-review-doc (their
     * offset parent). Using the inner doc element here would be wrong by that
     * element's offset within the padded .bp-review-doc.
     */
    #positioningOrigin() {
        const host = this.docTarget.parentElement;
        const rect = host.getBoundingClientRect();
        return {
            top: rect.top + host.clientTop,
            left: rect.left + host.clientLeft,
        };
    }

    /**
     * Position (relative to the offset parent) just below the selection's LAST
     * line, right-aligned to where the selection ends there. Anchoring to the
     * last client rect — not the bounding box, whose right edge is the widest
     * line — keeps it next to the actual end of a multi-line selection. The
     * element must be visible so its width is measurable.
     */
    #anchorBelowSelection(range, width) {
        const rects = range.getClientRects();
        const rect = rects.length
            ? rects[rects.length - 1]
            : range.getBoundingClientRect();
        const origin = this.#positioningOrigin();
        const gap = 10;
        return {
            top: rect.bottom - origin.top + gap,
            left: Math.max(0, rect.right - origin.left - width),
        };
    }

    #showToolbarNear(range) {
        if (!this.hasToolbarTarget) {
            return;
        }
        this.toolbarTarget.hidden = false; // unhide so offsetWidth is measurable
        const pos = this.#anchorBelowSelection(
            range,
            this.toolbarTarget.offsetWidth,
        );
        this.toolbarPosition = pos; // reused so the composer opens in this spot
        this.toolbarTarget.style.top = `${pos.top}px`;
        this.toolbarTarget.style.left = `${pos.left}px`;
    }

    #hideToolbar() {
        if (this.hasToolbarTarget) {
            this.toolbarTarget.hidden = true;
        }
    }

    // Opens with its top-left at the toolbar's top-left (the composer is wider, so
    // it extends rightward from there), clamped to stay within the doc column.
    #showComposerNear(range) {
        if (!this.hasComposerTarget) {
            return;
        }
        this.composerTarget.classList.remove('bp-comment-composer--untargeted');
        this.composerTarget.hidden = false; // unhide so offsetWidth is measurable
        const base =
            this.toolbarPosition ??
            this.#anchorBelowSelection(range, this.composerTarget.offsetWidth);
        const host = this.docTarget.parentElement;
        const maxLeft = Math.max(
            0,
            host.clientWidth - this.composerTarget.offsetWidth,
        );
        this.composerTarget.style.top = `${base.top}px`;
        this.composerTarget.style.left = `${Math.min(base.left, maxLeft)}px`;
        this.#openComposer();
    }

    #showComposerUntargeted() {
        if (!this.hasComposerTarget) {
            return;
        }
        this.composerTarget.classList.add('bp-comment-composer--untargeted');
        this.composerTarget.style.top = '';
        this.composerTarget.style.left = '';
        this.#openComposer();
    }

    #openComposer() {
        this.composerBodyTarget.value = '';
        if (this.hasComposerErrorTarget) {
            this.composerErrorTarget.textContent = '';
        }
        this.composerTarget.hidden = false;
        this.composerBodyTarget.focus();
    }

    #hideComposer() {
        if (this.hasComposerTarget) {
            this.composerTarget.hidden = true;
            this.composerTarget.classList.remove(
                'bp-comment-composer--untargeted',
            );
        }
    }
}
