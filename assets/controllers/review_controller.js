import { Controller } from '@hotwired/stimulus';

/**
 * Handles reply and resolve interactions in the review sidebar.
 *
 * Reply and resolve calls POST via fetch() to their respective routes.
 * The CSRF token for the comment-action token id is stateless; for same-origin
 * fetch() calls the browser sends Sec-Fetch-Site: same-origin which satisfies
 * origin validation. We still include the _csrf_token body parameter so the
 * ValidateCsrfTokenListener can call isTokenValid() without a length check failure.
 *
 * After a successful reply or resolve we do a simple page reload as a first cut;
 * the full browser loop is verified in the Task 15 e2e test.
 *
 * Verdict buttons are inside a plain HTML form (method="POST") and are submitted
 * normally — no JS intervention needed.
 */
export default class extends Controller {
    static values = {
        csrfToken: String,
    };

    /**
     * Submits a reply to a comment thread.
     *
     * Expected data attributes on the triggering element:
     *   data-review-reply-url-param   — the POST URL for app_comment_reply
     *   data-review-body-param        — the reply body text
     */
    async reply(event) {
        event.preventDefault();

        const url = event.params['replyUrl'];
        const body = event.params['body'];

        if (!url || !body) {
            return;
        }

        await this.#post(url, { body });
    }

    /**
     * Resolves a comment thread.
     *
     * Expected data attribute on the triggering element:
     *   data-review-resolve-url-param  — the POST URL for app_comment_resolve
     */
    async resolve(event) {
        event.preventDefault();

        const url = event.params['resolveUrl'];
        if (!url) {
            return;
        }

        await this.#post(url, {});
    }

    /**
     * Submits a reply from an inline reply form in the sidebar.
     * The reply body is read from the nearest input[data-reply-input] within the
     * closest .bp-comment-thread ancestor.
     */
    async submitReply(event) {
        event.preventDefault();

        const thread = event.target.closest('.bp-comment-thread');
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
     * Resolves a comment from a resolve button in the sidebar.
     * The URL is read from data-resolve-url on the button.
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
        formData.set('_csrf_token', this.csrfTokenValue);
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
            }
        } catch {
            // Network error — silently fail; the page state is unchanged.
        }
    }
}
