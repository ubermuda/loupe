import { Controller } from '@hotwired/stimulus';

/**
 * Handles text selection within the review document: positions the floating
 * comment composer near the selection and fills its hidden offset fields. The
 * composer is a real <form> — Turbo submits it and applies the returned stream,
 * so this controller does no fetch()/CSRF work of its own.
 *
 * Offset basis: we walk the text nodes of the document container and sum their
 * `.textContent.length` values. This matches PHP's `DocumentVersion::plainText()`
 * (strip_tags then html_entity_decode), which is the same string the browser
 * exposes as `element.textContent`.
 *
 * We deliberately do NOT use `innerText` — it collapses whitespace and would
 * desync from the PHP basis.
 *
 * Known v1 limitation: PHP's AnchorService uses byte offsets (strlen/substr) while
 * JavaScript string offsets are UTF-16 code units. These agree for ASCII content
 * but diverge on multibyte (emoji, CJK, etc.) text. Anchoring is reliable for
 * ASCII in v1.
 */
export default class extends Controller {
    static targets = [
        'doc',
        'composer',
        'composerBody',
        'composerError',
        'start',
        'length',
    ];

    connect() {
        this.#hideComposer();
    }

    /**
     * Called on mouseup within the doc area. Reads the current selection,
     * computes the offset within the doc container's text content, fills the
     * composer's hidden fields, and shows it near the selection.
     */
    onDocMouseup(event) {
        const selection = window.getSelection();

        if (!selection || selection.isCollapsed || selection.rangeCount === 0) {
            this.#hideComposer();
            return;
        }

        const range = selection.getRangeAt(0);

        // Ensure the selection is within our doc target
        if (!this.docTarget.contains(range.commonAncestorContainer)) {
            this.#hideComposer();
            return;
        }

        const start = this.#getTextOffset(
            this.docTarget,
            range.startContainer,
            range.startOffset,
        );
        const end = this.#getTextOffset(
            this.docTarget,
            range.endContainer,
            range.endOffset,
        );
        const length = end - start;

        if (length <= 0) {
            this.#hideComposer();
            return;
        }

        this.startTarget.value = String(start);
        this.lengthTarget.value = String(length);

        this.#showComposerNearSelection(range);
    }

    /**
     * Public action: hide the composer (used by the Cancel button via data-action).
     */
    hideComposer(event) {
        event?.preventDefault();
        this.#hideComposer();
    }

    /**
     * Public action: on a successful Turbo submission the new thread has been
     * inserted by the returned stream, so clear and hide the composer. On
     * failure the composer stays open showing the streamed error.
     */
    onSubmitEnd(event) {
        if (!event.detail?.success) {
            return;
        }

        this.composerBodyTarget.value = '';
        if (this.hasComposerErrorTarget) {
            this.composerErrorTarget.textContent = '';
        }
        this.#hideComposer();
    }

    /**
     * Computes the character offset of (node, offsetInNode) relative to the
     * start of the containerEl by walking all text nodes in document order
     * and summing their lengths until we reach the target node.
     *
     * @param {Element} containerEl  The root element to measure from.
     * @param {Node}    targetNode   The node where the selection boundary is.
     * @param {number}  offsetInNode The character offset within targetNode.
     * @returns {number} The total character offset from the container's start.
     */
    #getTextOffset(containerEl, targetNode, offsetInNode) {
        const walker = document.createTreeWalker(
            containerEl,
            NodeFilter.SHOW_TEXT,
            null,
        );
        let offset = 0;

        let node = walker.nextNode();
        while (node !== null) {
            if (node === targetNode) {
                offset += offsetInNode;
                return offset;
            }
            offset += node.textContent.length;
            node = walker.nextNode();
        }

        // Fallback: target node not found (shouldn't happen if containment check passed)
        return offset;
    }

    #showComposerNearSelection(range) {
        if (!this.hasComposerTarget) {
            return;
        }

        const rect = range.getBoundingClientRect();
        const docRect = this.docTarget.getBoundingClientRect();

        const top = rect.bottom - docRect.top + 8;
        const left = Math.max(0, rect.left - docRect.left);

        const wasHidden = this.composerTarget.hidden;
        this.composerTarget.style.top = `${top}px`;
        this.composerTarget.style.left = `${left}px`;
        this.composerTarget.hidden = false;
        // Only clear an in-progress draft when the composer is newly shown.
        // Re-positioning on a second selection within the same open session
        // preserves whatever the user has typed so far.
        if (wasHidden) {
            this.composerBodyTarget.value = '';
            if (this.hasComposerErrorTarget) {
                this.composerErrorTarget.textContent = '';
            }
        }
        this.composerBodyTarget.focus();
    }

    #hideComposer() {
        if (this.hasComposerTarget) {
            this.composerTarget.hidden = true;
        }
    }
}
