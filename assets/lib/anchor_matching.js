/**
 * Text-only half of the review document's anchor matching.
 *
 * An anchor is a verbatim `quote` plus the text around it (`prefix`/`suffix`).
 * Finding it again is pure string work, so it lives here and needs no DOM. The
 * DOM half stays in comment_anchor_controller, which maps the offset this
 * returns onto a Range.
 *
 * Every window is measured in codepoints, matching AnchorService on the server.
 * A plain slice counts UTF-16 code units, so it builds a shorter window around
 * an emoji and can halve a surrogate pair.
 */

/** Characters of surrounding text captured on each side of a quote. */
export const CONTEXT = 32;

/** Leading/trailing characters of a context window used to confirm a match. */
export const FINGERPRINT = 8;

/** The last `count` codepoints of `text` ending at the UTF-16 offset `index`. */
export function before(text, index, count) {
    return Array.from(text.slice(Math.max(0, index - count * 2), index))
        .slice(-count)
        .join('');
}

/** The first `count` codepoints of `text` starting at the UTF-16 offset `index`. */
export function after(text, index, count) {
    return Array.from(text.slice(index, index + count * 2))
        .slice(0, count)
        .join('');
}

/**
 * UTF-16 offset of the occurrence of `quote` in `fullText` whose surrounding
 * text best matches the anchor's fingerprints. Returns null when the quote is
 * empty or absent.
 *
 * Each side that matches scores one point, and the earliest occurrence keeps a
 * tie. AnchorService::locate() ranks the same way, so both sides of the wire
 * settle on the same occurrence of a repeated quote.
 */
export function bestQuoteStart(fullText, quote, prefix, suffix) {
    if (quote === '') {
        return null;
    }

    const occurrences = [];
    let from = fullText.indexOf(quote);
    while (from !== -1) {
        occurrences.push(from);
        from = fullText.indexOf(quote, from + 1);
    }
    if (occurrences.length === 0) {
        return null;
    }

    const score = (start) => {
        let value = 0;
        if (
            prefix !== '' &&
            before(fullText, start, CONTEXT).endsWith(
                before(prefix, prefix.length, FINGERPRINT),
            )
        ) {
            value += 1;
        }
        if (
            suffix !== '' &&
            after(fullText, start + quote.length, CONTEXT).startsWith(
                after(suffix, 0, FINGERPRINT),
            )
        ) {
            value += 1;
        }
        return value;
    };
    occurrences.sort((a, b) => score(b) - score(a) || a - b);

    return occurrences[0];
}
