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
 *
 * Three actions share that one captured selection. Comment and Suggest open a
 * composer; Strike submits a hidden form outright, which is what lets it also be
 * a single keypress.
 */
export default class extends Controller {
    static targets = [
        'doc',
        'composer',
        'composerBody',
        'composerError',
        'quote',
        'quotePreview',
        'prefix',
        'suffix',
        'suggestComposer',
        'suggestComposerError',
        'suggestQuote',
        'suggestQuotePreview',
        'suggestPrefix',
        'suggestSuffix',
        'suggestReplacement',
        'suggestBody',
        'strikeForm',
        'strikeQuote',
        'strikePrefix',
        'strikeSuffix',
        'actionError',
        'toolbar',
        'thread',
    ];

    // Anchor highlight names keyed by a thread's data-anchor-status. Each maps to
    // a registered CSS Custom Highlight so pending/addressed/resolved anchors tint
    // differently (see the ::highlight() rules in app.css).
    static STATUS_HIGHLIGHTS = {
        pending: 'lp-anchor-pending',
        addressed: 'lp-anchor-addressed',
        resolved: 'lp-anchor-resolved',
    };

    // Painted in addition to the status highlight rather than instead of it, so a
    // struck passage keeps its status tint and gains the line-through.
    static STRUCK_HIGHLIGHT = 'lp-anchor-struck';

    static CONTEXT = 32;

    // Strike is the only action that completes without a form, so it is the only
    // one worth a keystroke — one for Comment or Suggest would still leave a
    // composer to fill in.
    static STRIKE_KEY = 's';

    connect() {
        this.pendingSelection = null;
        this.strikeInFlight = false;
        this.#hideToolbar();
        this.#hideComposer();
        this.#registerHighlights();

        this.scheduledLayout = null;
        this.onResize = () => this.#scheduleLayout();
        window.addEventListener('resize', this.onResize);

        // Bound on the document, not on the pane: a mouse selection inside a plain
        // div leaves activeElement on <body>, so keydown never reaches an element
        // action here.
        this.onKeydown = (event) => this.#onKeydown(event);
        document.addEventListener('keydown', this.onKeydown);

        // Re-measure once layout settles (connect() fires before layout during
        // Turbo navigation, when getBoundingClientRect would read zeros).
        this.resizeObserver = new ResizeObserver(() => this.#scheduleLayout());
        this.resizeObserver.observe(this.docTarget);

        // Turbo Streams swap the thread list (add/delete/resolve replace the
        // whole #comment-threads container; reply replaces a single thread), so
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
        document.removeEventListener('keydown', this.onKeydown);
        this.resizeObserver?.disconnect();
        this.threadObserver?.disconnect();
        if (this.scheduledLayout !== null) {
            cancelAnimationFrame(this.scheduledLayout);
        }
        this.anchorHighlight?.clear();
        this.activeHighlight?.clear();
        this.struckHighlight?.clear();
        for (const highlight of Object.values(this.statusHighlights ?? {})) {
            highlight.clear();
        }
    }

    /**
     * On mouseup inside the document text, capture the selection's anchor and
     * show the floating toolbar. Clicks on the toolbar/composer (inside
     * .lp-review-doc but outside the doc text) are ignored, so they never hide
     * the toolbar or clobber the pending selection.
     *
     * Every other outcome DISCARDS the captured anchor rather than merely hiding
     * the toolbar. The two must not be able to disagree: the strike shortcut reads
     * pendingSelection and not the toolbar, so a click that clears the selection
     * would otherwise leave `s` armed against a passage the reviewer can no longer
     * see highlighted.
     */
    onDocMouseup(event) {
        if (!this.docTarget.contains(event.target)) {
            return;
        }

        const selection = window.getSelection();
        if (!selection || selection.isCollapsed || selection.rangeCount === 0) {
            this.#clearPendingSelection();
            return;
        }

        const range = selection.getRangeAt(0);
        if (!this.docTarget.contains(range.commonAncestorContainer)) {
            this.#clearPendingSelection();
            return;
        }

        const anchor = this.#extractAnchor(range);
        if (anchor === null) {
            this.#clearPendingSelection();
            return;
        }

        this.pendingSelection = { ...anchor, range: range.cloneRange() };
        this.#showToolbarNear(range);
    }

    #clearPendingSelection() {
        this.pendingSelection = null;
        this.#hideToolbar();
    }

    /** Toolbar action: open the comment composer for the captured selection. */
    startComment(event) {
        event?.preventDefault();
        if (this.pendingSelection === null) {
            return;
        }

        this.quoteTarget.value = this.pendingSelection.quote;
        this.prefixTarget.value = this.pendingSelection.prefix;
        this.suffixTarget.value = this.pendingSelection.suffix;
        if (this.hasQuotePreviewTarget) {
            this.quotePreviewTarget.textContent = this.pendingSelection.quote;
        }

        this.#setActiveHighlight(this.pendingSelection.range);
        this.#hideToolbar();
        this.#showComposerNear(
            this.composerTarget,
            this.pendingSelection.range,
        );
        this.composerErrorTarget.textContent = '';
        this.composerBodyTarget.value = '';
        this.composerBodyTarget.focus();
    }

    /**
     * Toolbar action: open the rewording composer. The replacement field starts as
     * the selected text so the reviewer edits the passage rather than retyping it.
     */
    startSuggestion(event) {
        event?.preventDefault();
        if (this.pendingSelection === null || !this.hasSuggestComposerTarget) {
            return;
        }

        this.suggestQuoteTarget.value = this.pendingSelection.quote;
        this.suggestPrefixTarget.value = this.pendingSelection.prefix;
        this.suggestSuffixTarget.value = this.pendingSelection.suffix;
        this.suggestQuotePreviewTarget.textContent =
            this.pendingSelection.quote;

        this.#setActiveHighlight(this.pendingSelection.range);
        this.#hideToolbar();
        this.#showComposerNear(
            this.suggestComposerTarget,
            this.pendingSelection.range,
        );
        this.suggestComposerErrorTarget.textContent = '';
        this.suggestBodyTarget.value = '';
        this.suggestReplacementTarget.value = this.pendingSelection.quote;
        this.suggestReplacementTarget.focus();
        this.suggestReplacementTarget.select();
    }

    /**
     * Toolbar action (and the `s` shortcut): strike the captured selection. No
     * composer, no field — fill the hidden form and post it.
     */
    strike(event) {
        event?.preventDefault();
        if (this.pendingSelection === null || !this.hasStrikeFormTarget) {
            return;
        }
        // Nothing clears pendingSelection until the response lands, so without this
        // a double-tap (or a second click) posts the same passage twice. Reopened by
        // onSubmitEnd, which Turbo fires on failure as well as success.
        if (this.strikeInFlight) {
            return;
        }
        this.strikeInFlight = true;

        this.strikeQuoteTarget.value = this.pendingSelection.quote;
        this.strikePrefixTarget.value = this.pendingSelection.prefix;
        this.strikeSuffixTarget.value = this.pendingSelection.suffix;

        this.#hideToolbar();
        this.#hideComposer();
        // requestSubmit(), not submit(): only the former fires the submit event
        // Turbo listens for, so submit() would trigger a full page navigation.
        this.strikeFormTarget.requestSubmit();
    }

    /**
     * Single-key shortcut for striking. Ignored while a modifier is held or the
     * caret is in a field, so it never eats a typed character; and ignored with no
     * live selection, since a strike with no target means nothing.
     */
    #onKeydown(event) {
        if (event.key !== this.constructor.STRIKE_KEY) {
            return;
        }
        // Auto-repeat fires keydown continuously while the key is held, and each one
        // would be a separate strike on the same passage.
        if (event.repeat) {
            return;
        }
        if (event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }
        const active = document.activeElement;
        if (
            active &&
            (active.tagName === 'INPUT' ||
                active.tagName === 'TEXTAREA' ||
                active.isContentEditable)
        ) {
            return;
        }
        // An open composer means the reviewer already chose a different action for
        // this selection; striking it from under them would discard what they typed.
        if (this.#anyComposerOpen()) {
            return;
        }
        this.strike(event);
    }

    #anyComposerOpen() {
        return (
            (this.hasComposerTarget && !this.composerTarget.hidden) ||
            (this.hasSuggestComposerTarget &&
                !this.suggestComposerTarget.hidden)
        );
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
     * returned stream; clear and hide the composers, then re-layout. On failure
     * the composer stays open showing the streamed error.
     */
    onSubmitEnd(event) {
        // Released before the success check: a rejected strike must leave the action
        // usable, or one failure disables striking for the rest of the page's life.
        this.strikeInFlight = false;

        if (!event.detail?.success) {
            return;
        }

        this.composerBodyTarget.value = '';
        if (this.hasComposerErrorTarget) {
            this.composerErrorTarget.textContent = '';
        }
        if (this.hasSuggestComposerTarget) {
            this.suggestReplacementTarget.value = '';
            this.suggestBodyTarget.value = '';
            this.suggestComposerErrorTarget.textContent = '';
        }
        if (this.hasActionErrorTarget) {
            this.actionErrorTarget.textContent = '';
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
     *
     * Deliberately re-locates by quote+context rather than using the anchor's
     * stored offsetHint: that's a PHP codepoint offset, and walking it with JS
     * (UTF-16 code units) would drift on any astral character — the kind of bug
     * content anchoring exists to avoid. The server doesn't even send offsetHint
     * to the client.
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
        window.CSS.highlights.set('lp-anchor', this.anchorHighlight);
        window.CSS.highlights.set('lp-anchor-active', this.activeHighlight);

        // Per-status anchor highlights (pending amber, addressed green, resolved
        // faint). Registered alongside — never replacing — lp-anchor/-active.
        this.statusHighlights = {};
        for (const [status, name] of Object.entries(
            this.constructor.STATUS_HIGHLIGHTS,
        )) {
            const highlight = new window.Highlight();
            this.statusHighlights[status] = highlight;
            window.CSS.highlights.set(name, highlight);
        }

        this.struckHighlight = new window.Highlight();
        window.CSS.highlights.set(
            this.constructor.STRUCK_HIGHLIGHT,
            this.struckHighlight,
        );
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
        } catch {
            this.anchorHighlight?.clear();
        }
    }

    /**
     * Repaints the per-status anchor highlights. Each thread's resolved range is
     * added to the Highlight matching its data-anchor-status (pending / addressed
     * / resolved); the threads themselves flow normally in the grouped ladder —
     * cards are no longer absolutely positioned against their anchors.
     */
    #highlightAnchors() {
        if (!this.statusHighlights) {
            return;
        }
        for (const highlight of Object.values(this.statusHighlights)) {
            highlight.clear();
        }
        this.struckHighlight?.clear();
        for (const thread of this.threadTargets) {
            const status = thread.dataset.anchorStatus ?? 'pending';
            const highlight =
                this.statusHighlights[status] ?? this.statusHighlights.pending;
            const range = this.#findRange(
                thread.dataset.anchorQuote ?? '',
                thread.dataset.anchorPrefix ?? '',
                thread.dataset.anchorSuffix ?? '',
            );
            if (range === null) {
                continue;
            }
            highlight.add(range);
            if (thread.dataset.anchorKind === 'strike') {
                this.struckHighlight?.add(range);
            }
        }
    }

    // ── Toolbar / composer positioning ──────────────────────────────────────

    /**
     * Origin (viewport coords) of the absolute-positioning containing block for
     * the toolbar/composer: the padding-box top-left of .lp-review-doc (their
     * offset parent). Using the inner doc element here would be wrong by that
     * element's offset within the padded .lp-review-doc.
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
        // The offset parent (.lp-review-doc) is also the internal scroll
        // container: an absolutely-positioned child's `top` is measured from the
        // padding-box in scrolled content space, so add scrollTop to keep the
        // toolbar/composer glued to the selection after the pane is scrolled.
        const scrollTop = this.docTarget.parentElement.scrollTop;
        return {
            top: rect.bottom - origin.top + scrollTop + gap,
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
    #showComposerNear(panel, range) {
        this.#hideComposer();
        panel.classList.remove('lp-comment-composer--untargeted');
        panel.hidden = false; // unhide so offsetWidth is measurable
        const base =
            this.toolbarPosition ??
            this.#anchorBelowSelection(range, panel.offsetWidth);
        const host = this.docTarget.parentElement;
        const maxLeft = Math.max(0, host.clientWidth - panel.offsetWidth);
        panel.style.top = `${base.top}px`;
        panel.style.left = `${Math.min(base.left, maxLeft)}px`;
    }

    #showComposerUntargeted() {
        if (!this.hasComposerTarget) {
            return;
        }
        this.#hideComposer();
        this.composerTarget.classList.add('lp-comment-composer--untargeted');
        this.composerTarget.style.top = '';
        this.composerTarget.style.left = '';
        this.composerTarget.hidden = false;
        this.composerBodyTarget.value = '';
        if (this.hasComposerErrorTarget) {
            this.composerErrorTarget.textContent = '';
        }
        this.composerBodyTarget.focus();
    }

    #hideComposer() {
        for (const panel of [
            this.hasComposerTarget ? this.composerTarget : null,
            this.hasSuggestComposerTarget ? this.suggestComposerTarget : null,
        ]) {
            if (panel === null) {
                continue;
            }
            panel.hidden = true;
            panel.classList.remove('lp-comment-composer--untargeted');
        }
    }
}
