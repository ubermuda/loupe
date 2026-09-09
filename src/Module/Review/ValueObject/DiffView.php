<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * Which of the three comparisons of one version pair the reader is looking at.
 *
 * The rendered view reads as the document does. It cannot mark front matter, a
 * link reference definition or a setext underline, because none of those has a
 * place of its own in the output, so a revision that changes only one of them
 * shows nothing. The source view compares the Markdown line by line and shows
 * every one of them, which is why both stay reachable. The side-by-side view
 * pairs the same rendered blocks into two columns, so the older wording sits
 * beside the newer one instead of being read out of inline marks.
 *
 * The choice lives in the request rather than in the browser: the views
 * partition the same edit differently, so only one may be in the page at a time
 * or the jump targets and the count describe the wrong one.
 */
enum DiffView: string
{
    case Rendered = 'rendered';
    case Source = 'source';
    case SideBySide = 'side-by-side';

    /**
     * Falls back to the default rather than refusing, since this comes from a
     * query string. It takes `mixed` because a query string can hold an array:
     * `?view[]=source` makes InputBag::getString() throw, which is a 500 on a
     * URL anyone can type.
     */
    public static function fromRequestValue(mixed $value): self
    {
        return \is_string($value) ? self::tryFrom($value) ?? self::Rendered : self::Rendered;
    }
}
