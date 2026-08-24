/*
 * Where a comment goes once it is written: the review screen posts it, the
 * landing page's demo builds the card itself. Same verbs, so one controller
 * drives both.
 */

/** Posts through the Symfony forms already on the review screen. */
export class ServerTransport {
    constructor(controller) {
        this.controller = controller;
    }

    // The anchor and body are already in the form's own fields — startComment
    // and startSuggestion put them there — so submitting is the whole job.
    comment(anchor, body, submitter) {
        this.controller.composerTarget.requestSubmit(submitter);
    }

    suggestion(anchor, replacement, body, submitter) {
        this.controller.suggestComposerTarget.requestSubmit(submitter);
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
 * Builds the card in the page and keeps nothing; a reload clears it. The markup
 * comes from a CommentCard prototype per kind, so filling is text and
 * attributes only — no markup is assembled here.
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
