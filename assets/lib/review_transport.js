/*
 * Where a comment goes once the reviewer has written it.
 *
 * The review screen posts it and lets the server return a Turbo Stream that
 * inserts the card. The landing page's try-it demo has no backend and no
 * account, so it builds the card itself from a prototype the server rendered
 * into a <template>. Both speak the same three verbs, which is what lets one
 * controller drive both.
 */

/** Posts through the Symfony forms already on the review screen. */
export class ServerTransport {
    constructor(controller) {
        this.controller = controller;
    }

    // The anchor and body are already in the form's own fields — startComment
    // and startSuggestion put them there — so submitting is the whole job.
    comment() {
        this.controller.composerTarget.requestSubmit();
    }

    suggestion() {
        this.controller.suggestComposerTarget.requestSubmit();
    }

    strike(anchor) {
        const controller = this.controller;
        controller.strikeQuoteTarget.value = anchor.quote;
        controller.strikePrefixTarget.value = anchor.prefix;
        controller.strikeSuffixTarget.value = anchor.suffix;
        // requestSubmit(), not submit(): only the former fires the submit event
        // Turbo listens for, so submit() would trigger a full page navigation.
        controller.strikeFormTarget.requestSubmit();
    }
}

/**
 * Builds the card in the page and keeps nothing. Reload and the marks are gone —
 * which is the point: the demo has to be able to say honestly that none of it
 * was saved.
 *
 * The markup comes from a prototype per kind, rendered by CommentCard so the
 * demo card is the review screen's card rather than a copy of it. Filling is
 * therefore text and attributes only; no markup is assembled here.
 */
export class DemoTransport {
    constructor(controller) {
        this.controller = controller;
    }

    comment(anchor, body) {
        this.#add('comment', anchor, { body });
    }

    suggestion(anchor, replacement, body) {
        this.#add('suggestion', anchor, { body, replacement });
    }

    strike(anchor) {
        this.#add('strike', anchor, {});
    }

    #add(kind, anchor, { body = '', replacement = '' }) {
        const prototype = this.controller.prototypeFor(kind);
        if (prototype === null) {
            return;
        }

        const card = prototype.content.firstElementChild.cloneNode(true);
        card.dataset.anchorQuote = anchor.quote;
        card.dataset.anchorPrefix = anchor.prefix;
        card.dataset.anchorSuffix = anchor.suffix;

        // A strike and a rewording both show the passage struck; only a plain
        // comment quotes it as it stands.
        const struck = card.querySelector('.lp-comment-quote--struck del');
        const plain = card.querySelector(
            '.lp-comment-quote:not(.lp-comment-quote--struck):not(.lp-comment-quote--inserted)',
        );
        if (struck !== null) {
            struck.textContent = anchor.quote;
        }
        if (plain !== null) {
            plain.textContent = anchor.quote;
        }

        const inserted = card.querySelector('.lp-comment-quote--inserted ins');
        if (inserted !== null) {
            inserted.textContent = replacement;
        }

        // The prototype carries placeholder body text so there is an element to
        // fill; an empty body means the card should not show one at all.
        const bodyElement = card.querySelector('.lp-comment-body');
        if (bodyElement !== null) {
            if (body === '') {
                bodyElement.remove();
            } else {
                bodyElement.textContent = body;
            }
        }

        this.controller.marginTarget.append(card);
    }
}
