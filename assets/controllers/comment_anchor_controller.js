import { Controller } from '@hotwired/stimulus';

/**
 * Handles text selection within the review document, showing a comment composer
 * positioned near the selection, and POSTing the new comment to the server.
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
 *
 * CSRF: the comment-action token id is stateless (SameOriginCsrfTokenManager).
 * For same-origin fetch() the browser automatically sends Sec-Fetch-Site: same-origin,
 * which satisfies origin validation. We still include the csrf_token value (which for
 * stateless tokens equals the cookie name "csrf-token") as _csrf_token in the body
 * so the ValidateCsrfTokenListener can call isTokenValid() without a length error.
 */
export default class extends Controller {
    static targets = ['doc', 'composer', 'composerBody', 'composerError'];

    static values = {
        addCommentUrl: String,
        csrfToken: String,
    };

    #selectionStart = 0;
    #selectionLength = 0;

    connect() {
        this.#hideComposer();
    }

    /**
     * Called on mouseup within the doc area. Reads the current selection,
     * computes the offset within the doc container's text content, and shows
     * the composer near the selection.
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

        this.#selectionStart = start;
        this.#selectionLength = length;

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
     * Submits the comment composer form via fetch().
     */
    async submitComposer(event) {
        event.preventDefault();

        const body = this.composerBodyTarget.value.trim();
        if (!body) {
            return;
        }

        if (this.hasComposerErrorTarget) {
            this.composerErrorTarget.textContent = '';
        }

        const formData = new URLSearchParams();
        formData.set('start', String(this.#selectionStart));
        formData.set('length', String(this.#selectionLength));
        formData.set('body', body);
        // Include the csrf_token value so ValidateCsrfTokenListener can call isTokenValid().
        // For stateless tokens this equals the cookie name; origin validation (Sec-Fetch-Site)
        // carries the request for same-origin fetch() calls.
        formData.set('_csrf_token', this.csrfTokenValue);

        try {
            const response = await fetch(this.addCommentUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData.toString(),
            });

            if (response.status === 201) {
                // Simple first cut: reload the page so the sidebar updates with the new thread.
                // Task 15 e2e covers this flow; a DOM-insert optimisation can follow.
                window.location.reload();
            } else {
                const data = await response.json();
                const message = data?.errors
                    ? Object.values(data.errors).join(', ')
                    : 'Failed to add comment.';
                if (this.hasComposerErrorTarget) {
                    this.composerErrorTarget.textContent = message;
                }
            }
        } catch {
            if (this.hasComposerErrorTarget) {
                this.composerErrorTarget.textContent =
                    'Network error. Please try again.';
            }
        }
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
        }
        this.composerBodyTarget.focus();
    }

    #hideComposer() {
        if (this.hasComposerTarget) {
            this.composerTarget.hidden = true;
        }
    }
}
