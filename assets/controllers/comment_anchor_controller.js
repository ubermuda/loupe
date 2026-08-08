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
 *
 * The same painting path serves the agent's own marks, which carry an anchor and
 * nothing else — no body, no thread. They get their own rung and their own colour
 * so a reviewer can tell the agent's marks from their own at a glance.
 */
export default class extends Controller {
    static targets = [
        'doc',
        'block',
        'margin',
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
        'agentHighlight',
    ];

    // Anchor highlight names keyed by a thread's data-anchor-status. Each maps to
    // a registered CSS Custom Highlight so pending/addressed/resolved anchors tint
    // differently (see the ::highlight() rules in app.css).
    static STATUS_HIGHLIGHTS = {
        pending: 'lp-anchor-pending',
        addressed: 'lp-anchor-addressed',
        resolved: 'lp-anchor-resolved',
    };

    // Painted INSTEAD of the status highlight, not in addition to it — see
    // #highlightAnchors. A passage marked for deletion is not also an open
    // question.
    static STRUCK_HIGHLIGHT = 'lp-anchor-struck';

    // A rewording is a different KIND of mark, not a different state of one, so
    // it declares its own tint and outranks the status rung — an open comment
    // and an open suggestion must not look identical.
    static SUGGESTION_HIGHLIGHT = 'lp-anchor-suggestion';

    // Passages the agent flagged as worth reading first. Kept out of
    // STATUS_HIGHLIGHTS on purpose: that map is keyed by a comment thread's
    // status, and listing the agent rung there would make its colour reachable
    // from a data-anchor-status value.
    static AGENT_HIGHLIGHT = 'lp-agent-highlight';

    // Painted while the pointer is over a card or over the passage it points
    // at, so the pair can be told apart from the other five in a crowded
    // margin. Hovering either end rings both.
    static HOVER_HIGHLIGHT = 'lp-anchor-hover';

    // Resolution order for a span several rungs cover. Without explicit values it
    // is whichever Highlight was registered last, which is incidental.
    //
    // Verified in Chrome: `color` goes to the highest-priority rung declaring
    // it, decorations are additive, but backgrounds STACK in priority order and
    // only replace by being opaque. A higher-priority `transparent` therefore
    // paints nothing and leaves the tint below showing — which is why
    // #highlightAnchors routes a strike away from its status rung instead.
    //
    // The ladder reads: agent advisory < the thread's own state < the edit the
    // reviewer asked for on it < the selection being composed right now.
    static PRIORITY = {
        agent: 0,
        status: 1,
        suggestion: 2,
        struck: 3,
        hover: 4,
        active: 5,
    };

    static CONTEXT = 32;

    // Clearance between one comment card and the next when a passage carries
    // more comments than the space beside it can hold.
    static CARD_GAP = 12;

    // Strike is the only action that completes without a form, so it is the only
    // one worth a keystroke — one for Comment or Suggest would still leave a
    // composer to fill in.
    static STRIKE_KEY = 's';

    static values = { hideResolved: Boolean };

    // Where the resolved-comments preference is remembered. It is a view
    // preference rather than document state, so it belongs to the reader and
    // follows them across documents.
    static HIDE_RESOLVED_KEY = 'loupe:review:hide-resolved';

    connect() {
        this.pendingSelection = null;
        this.strikeInFlight = false;
        this.hoveredThread = null;
        this.hoverProbeScheduled = false;
        this.#restoreHideResolved();
        this.#hideToolbar();
        this.#hideComposer();
        this.#registerHighlights();

        this.scheduledLayout = null;
        this.onResize = () => this.#scheduleLayout();
        window.addEventListener('resize', this.onResize);

        // Web fonts land after first layout and change every line box, so a
        // measurement taken before they arrive places every card a few pixels
        // out. Guarded: document.fonts is absent in some embedded webviews.
        document.fonts?.ready
            .then(() => this.#scheduleLayout())
            .catch(() => {});

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
        this.agentHighlight?.clear();
        this.hoverHighlight?.clear();
        this.suggestionHighlight?.clear();
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
        // Escape backs out of whatever is open, in the order a reader would
        // expect to undo it: the composer they are filling in first, then the
        // toolbar that opened it.
        if (event.key === 'Escape') {
            if (this.#anyComposerOpen()) {
                this.hideComposer(event);
            } else {
                this.#clearPendingSelection();
            }
            return;
        }

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
        // composedPath()[0], not document.activeElement: the site-review widget
        // composes in a shadow root, and activeElement stops at its host — so the
        // field test passed and this shortcut ate every `s` the reviewer typed.
        // The widget loads on every authenticated page, so both are always live.
        const active = event.composedPath()[0] ?? document.activeElement;
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
        const priority = this.constructor.PRIORITY;

        this.anchorHighlight = new window.Highlight();
        this.activeHighlight = new window.Highlight();
        this.activeHighlight.priority = priority.active;
        window.CSS.highlights.set('lp-anchor', this.anchorHighlight);
        window.CSS.highlights.set('lp-anchor-active', this.activeHighlight);

        // Per-status anchor highlights (pending amber, addressed green, resolved
        // faint). Registered alongside — never replacing — lp-anchor/-active.
        this.statusHighlights = {};
        for (const [status, name] of Object.entries(
            this.constructor.STATUS_HIGHLIGHTS,
        )) {
            const highlight = new window.Highlight();
            highlight.priority = priority.status;
            this.statusHighlights[status] = highlight;
            window.CSS.highlights.set(name, highlight);
        }

        this.struckHighlight = new window.Highlight();
        this.struckHighlight.priority = priority.struck;
        window.CSS.highlights.set(
            this.constructor.STRUCK_HIGHLIGHT,
            this.struckHighlight,
        );

        this.suggestionHighlight = new window.Highlight();
        this.suggestionHighlight.priority = priority.suggestion;
        window.CSS.highlights.set(
            this.constructor.SUGGESTION_HIGHLIGHT,
            this.suggestionHighlight,
        );

        this.agentHighlight = new window.Highlight();
        this.agentHighlight.priority = priority.agent;
        window.CSS.highlights.set(
            this.constructor.AGENT_HIGHLIGHT,
            this.agentHighlight,
        );

        this.hoverHighlight = new window.Highlight();
        this.hoverHighlight.priority = priority.hover;
        window.CSS.highlights.set(
            this.constructor.HOVER_HIGHLIGHT,
            this.hoverHighlight,
        );
    }

    /**
     * Card action: ring this card and tint the passage it points at.
     *
     * The tint is a Highlight rather than an outline because ::highlight()
     * supports neither outline nor box-shadow — a ring around the anchor text is
     * simply not expressible through that API, so the pairing is carried by a
     * stronger fill on one end and the ring on the other.
     */
    focusThread(event) {
        this.#setHoveredThread(event.currentTarget);
    }

    /** Card action: the pointer left, so drop both ends of the pairing. */
    blurThread(event) {
        // Guarded on identity rather than clearing unconditionally: moving from
        // a card straight onto a different passage fires this leave AFTER the
        // probe has already set the new pair, and an unguarded clear would undo
        // it.
        if (this.hoveredThread === event.currentTarget) {
            this.#setHoveredThread(null);
        }
    }

    /** The single owner of which card/passage pair is currently lit. */
    #setHoveredThread(thread) {
        if (this.hoveredThread === thread) {
            return;
        }
        this.hoveredThread?.classList.remove('lp-comment-thread--active');
        this.hoveredThread = thread;
        this.hoverHighlight?.clear();
        if (thread === null) {
            return;
        }
        thread.classList.add('lp-comment-thread--active');
        const range = this.anchorRanges?.get(thread);
        if (range !== undefined) {
            this.hoverHighlight?.add(range);
        }
    }

    /**
     * The same pairing driven from the other end: the pointer is over the prose,
     * so find which anchor (if any) is under it and ring that card.
     *
     * Hit-tested against each anchor's line boxes rather than by walking up from
     * event.target, because the anchors are painted with the Highlight API and
     * so have no element of their own to have been the target.
     */
    onDocMousemove(event) {
        if (this.hoverProbeScheduled) {
            return;
        }
        this.hoverProbeScheduled = true;
        const { clientX, clientY } = event;
        requestAnimationFrame(() => {
            this.hoverProbeScheduled = false;
            try {
                this.#probeAnchorAt(clientX, clientY);
            } catch {
                this.#clearAnchorHover();
            }
        });
    }

    #probeAnchorAt(clientX, clientY) {
        // Reads the map #layout() built rather than locating each quote again.
        // This runs once per mousemove frame and #findRange() is an indexOf
        // sweep of the whole document plus a TreeWalker, so re-locating here
        // put that cost on every frame on the longest documents — the ones with
        // the most anchors to walk. General comments never enter the map, which
        // is the same set the old loop skipped by hand.
        for (const [thread, range] of this.anchorRanges ?? []) {
            for (const rect of range.getClientRects()) {
                if (
                    clientX >= rect.left &&
                    clientX <= rect.right &&
                    clientY >= rect.top &&
                    clientY <= rect.bottom
                ) {
                    if (this.hoveredThread !== thread) {
                        this.#clearAnchorHover();
                        this.hoveredThread = thread;
                        thread.classList.add('lp-comment-thread--active');
                        this.hoverHighlight?.clear();
                        this.hoverHighlight?.add(range);
                    }
                    return;
                }
            }
        }
        this.#clearAnchorHover();
    }

    #clearAnchorHover() {
        if (this.hoveredThread) {
            this.hoveredThread.classList.remove('lp-comment-thread--active');
            this.hoveredThread = null;
            this.hoverHighlight?.clear();
        }
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

    // The three passes are independently guarded: they read different anchors
    // from different elements, so one unlocatable comment quote must not take
    // every agent mark on the page down with it, nor leave every card unplaced.
    #layout() {
        // Locating a quote means an indexOf sweep of the whole document plus a
        // TreeWalker, so every pass below shares one map rather than each
        // re-locating the same anchors. The pointer probe reads it too, which is
        // what keeps mousemove off that path entirely.
        this.anchorRanges = new Map();
        for (const thread of this.threadTargets) {
            if (thread.dataset.commentGeneral === 'true') {
                continue;
            }
            const range = this.#findRange(
                thread.dataset.anchorQuote ?? '',
                thread.dataset.anchorPrefix ?? '',
                thread.dataset.anchorSuffix ?? '',
            );
            if (range !== null) {
                this.anchorRanges.set(thread, range);
            }
        }

        try {
            this.#highlightAnchors();
        } catch {
            this.anchorHighlight?.clear();
        }
        try {
            this.#highlightAgentMarks();
        } catch {
            this.agentHighlight?.clear();
        }
        try {
            this.#positionThreads();
        } catch {
            this.#releaseThreads();
        }
        // Here as well as in #applyHideResolved, because a Turbo stream that
        // resolves or deletes a thread reaches the controller only through this
        // path.
        this.#syncResolvedToggle();
    }

    /**
     * Places each comment card level with the first line of the passage it is
     * anchored to.
     *
     * Alignment is best effort by design. A running floor keeps each card at
     * least CARD_GAP below the previous one, so in a crowded section a card sits
     * below its passage rather than on top of its neighbour — nothing is ever
     * hidden and nothing overlaps. A card whose quote no longer appears in this
     * version (orphaned) has no line to measure, so it takes the floor.
     *
     * Offsets are measured, never hard-coded: they would be wrong the moment the
     * body font, the measure, or the reader's zoom changes.
     */
    #positionThreads() {
        if (!this.hasMarginTarget || !this.hasBlockTarget) {
            return;
        }

        const anchored = this.threadTargets.filter(
            (thread) =>
                thread.dataset.commentGeneral !== 'true' &&
                // offsetParent is null for a display:none card, which is what
                // hiding resolved threads does. Placing one would advance the
                // floor by a card that is not on screen.
                thread.offsetParent !== null,
        );
        if (anchored.length === 0) {
            // Deleting the last thread, or hiding every resolved one, would
            // otherwise leave the reading column holding the min-height the
            // previous layout reserved for a column of cards that is now empty.
            this.blockTarget.style.minHeight = '';

            return;
        }

        const marginTop = this.marginTarget.getBoundingClientRect().top;
        let floor = 0;

        for (const thread of anchored) {
            // #releaseThreads() pins every card static, and `top` alone means
            // nothing to a static element — so a single failed layout would
            // otherwise flatten the column for the rest of the page's life.
            thread.style.position = '';
            const range = this.anchorRanges.get(thread);
            // getClientRects()[0] is the anchor's FIRST line box. The bounding
            // rect would be the union of every line, whose top is the same but
            // whose height is not — and a card level with a three-line anchor
            // has to align to the line the passage starts on.
            const rects = range === undefined ? [] : range.getClientRects();
            const anchorTop =
                rects.length > 0 ? rects[0].top - marginTop : floor;

            const top = Math.max(anchorTop, floor);
            thread.style.top = `${Math.round(top)}px`;
            floor = top + thread.offsetHeight + this.constructor.CARD_GAP;
        }

        // The cards are out of flow, so a stack running past the end of the
        // prose would otherwise be unreachable — the paper would simply stop
        // scrolling above it.
        const block = this.blockTarget;
        const offsetWithinBlock = this.marginTarget.offsetTop;
        const paddingBottom = parseFloat(getComputedStyle(block).paddingBottom);
        block.style.minHeight = `${Math.ceil(offsetWithinBlock + floor + paddingBottom)}px`;
    }

    /**
     * Toggles resolved threads out of the margin. Hiding them re-runs the
     * layout, so the remaining cards close up rather than leaving the gaps the
     * hidden ones occupied.
     */
    toggleResolved(event) {
        event?.preventDefault();
        this.hideResolvedValue = !this.hideResolvedValue;
        this.#applyHideResolved();
        try {
            window.localStorage.setItem(
                this.constructor.HIDE_RESOLVED_KEY,
                this.hideResolvedValue ? '1' : '0',
            );
        } catch {
            // Private browsing and storage-blocked contexts both throw here.
            // The toggle still works for this page; it just will not be
            // remembered, which is a better outcome than a broken control.
        }
    }

    #restoreHideResolved() {
        try {
            this.hideResolvedValue =
                window.localStorage.getItem(
                    this.constructor.HIDE_RESOLVED_KEY,
                ) === '1';
        } catch {
            this.hideResolvedValue = false;
        }
        this.#applyHideResolved();
    }

    #applyHideResolved() {
        this.element.classList.toggle(
            'lp-review-block--hide-resolved',
            this.hideResolvedValue,
        );
        this.#syncResolvedToggle();
        this.#scheduleLayout();
    }

    /**
     * Shows the toggle only once there is a resolved thread to hide, and keeps
     * its label in step.
     *
     * Driven from the DOM rather than rendered conditionally in Twig: resolving
     * a thread replaces #comment-threads alone, and this control sits outside
     * that fragment, so a page that started with none would never grow one.
     */
    #syncResolvedToggle() {
        const hasResolved =
            this.element.querySelector('.lp-comment-thread--resolved') !== null;
        for (const toggle of this.element.querySelectorAll(
            '[data-resolved-toggle]',
        )) {
            toggle.hidden = !hasResolved;
            toggle.textContent =
                toggle.dataset[
                    this.hideResolvedValue ? 'labelShow' : 'labelHide'
                ];
        }
    }

    /**
     * Returns every card to normal flow — the degraded state on any failure.
     *
     * Clearing `top` alone is not enough: the cards are absolutely positioned by
     * their class, so `top: auto` resolves every one of them to the same static
     * position and they land in a single stack. The position has to be overridden
     * as well for them to fall back into a readable column.
     */
    #releaseThreads() {
        for (const thread of this.threadTargets) {
            thread.style.top = '';
            thread.style.position = 'static';
        }
        if (this.hasBlockTarget) {
            this.blockTarget.style.minHeight = '';
        }
    }

    /**
     * Repaints the per-status anchor highlights. Each thread's resolved range is
     * added to the Highlight matching its data-anchor-status (pending / addressed
     * / resolved). The highlight is what ties a card to its passage: the cards
     * are absolutely positioned beside the anchor they resolved to, so the tint
     * in the prose is the only thing naming which passage that is.
     */
    #highlightAnchors() {
        if (!this.statusHighlights) {
            return;
        }
        for (const highlight of Object.values(this.statusHighlights)) {
            highlight.clear();
        }
        this.struckHighlight?.clear();
        this.suggestionHighlight?.clear();
        for (const thread of this.threadTargets) {
            const status = thread.dataset.anchorStatus ?? 'pending';
            const highlight =
                this.statusHighlights[status] ?? this.statusHighlights.pending;
            const range = this.anchorRanges.get(thread);
            if (range === undefined) {
                continue;
            }

            // A strike takes the struck rung INSTEAD of its status rung: a
            // passage marked for deletion is not also an open question, and
            // backgrounds stack rather than replace (see PRIORITY above).
            const kind = thread.dataset.anchorKind;
            if (kind === 'strike') {
                this.struckHighlight?.add(range);
                continue;
            }

            highlight.add(range);
            if (kind === 'suggestion') {
                this.suggestionHighlight?.add(range);
            }
        }
    }

    /**
     * Paints the agent's marks, re-locating each quote with the same #findRange
     * the comment anchors use.
     *
     * The marks are carried by empty elements outside the doc pane rather than by
     * wrapping the passages themselves: the pane's textContent has to stay
     * byte-identical to the server's plain-text basis, and any element inserted
     * into it would shift every anchor offset after it.
     */
    #highlightAgentMarks() {
        if (!this.agentHighlight) {
            return;
        }
        this.agentHighlight.clear();
        for (const mark of this.agentHighlightTargets) {
            const range = this.#findRange(
                mark.dataset.anchorQuote ?? '',
                mark.dataset.anchorPrefix ?? '',
                mark.dataset.anchorSuffix ?? '',
            );
            if (range !== null) {
                this.agentHighlight.add(range);
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
