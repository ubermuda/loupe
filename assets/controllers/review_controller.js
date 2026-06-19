import { Controller } from '@hotwired/stimulus';
import { csrfTokenForField } from './csrf_protection_controller.js';

/**
 * Handles reply and resolve interactions in the review sidebar.
 *
 * Both actions POST via fetch() to their respective routes. The comment-action
 * token id uses Symfony's stateless double-submit CSRF: the value rendered into
 * csrfTokenValue is only a seed, so each request must run csrfTokenForField() to
 * generate a random token and set the matching cookie (same mechanism as the
 * comment composer in comment_anchor_controller.js).
 *
 * After a successful reply or resolve we reload the page so the sidebar updates.
 *
 * Verdict buttons are inside a plain HTML form (method="POST") and are submitted
 * normally — no JS intervention needed.
 */
export default class extends Controller {
    static values = {
        csrfToken: String,
    };

    /**
     * Submits a reply from an inline reply form in the sidebar.
     * Reads the textarea via [data-reply-input] inside the closest .bp-comment-thread,
     * and the POST URL from data-reply-url on the button.
     */
    async submitReply(event) {
        event.preventDefault();

        const thread = event.currentTarget.closest('.bp-comment-thread');
        const input = thread?.querySelector('[data-reply-input]');
        const url = event.currentTarget.dataset.replyUrl;

        if (!input || !url) {
            return;
        }

        const body = input.value.trim();
        if (!body) {
            return;
        }

        await this.#post(url, { body });
    }

    /**
     * Resolves a comment thread.
     * Reads the POST URL from data-resolve-url on the button.
     */
    async resolveComment(event) {
        event.preventDefault();

        const url = event.currentTarget.dataset.resolveUrl;
        if (!url) {
            return;
        }

        await this.#post(url, {});
    }

    async #post(url, extraParams) {
        const formData = new URLSearchParams();
        formData.set('_csrf_token', csrfTokenForField(this.csrfTokenValue));
        for (const [key, value] of Object.entries(extraParams)) {
            formData.set(key, value);
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData.toString(),
            });

            if (response.ok) {
                window.location.reload();
            } else {
                console.error(
                    `[review] POST failed: HTTP ${response.status} ${response.statusText} (${url})`,
                );
                window.alert(
                    'Something went wrong. Please refresh and try again.',
                );
            }
        } catch (err) {
            console.error('[review] Network error during POST:', err);
            window.alert(
                'Network error. Please check your connection and try again.',
            );
        }
    }
}
