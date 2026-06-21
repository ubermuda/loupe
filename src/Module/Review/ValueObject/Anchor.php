<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where a comment is attached in a document, described by content + surrounding
 * context instead of a fragile DOM/CSS selector or a bare character offset.
 *
 * The quote (and its prefix/suffix context) is matched against
 * DocumentVersion::plainText() — which equals the doc container's `textContent`
 * in the browser — so an anchor can be relocated after the document is edited
 * and is unaffected by markup changes.
 *
 * Server and browser locate an anchor INDEPENDENTLY from these strings
 * (server: AnchorService; browser: comment_anchor_controller's #findRange).
 * Only the strings are the source of truth; the position is recomputed on each
 * side against the identical text basis. An empty quote means an untargeted
 * (un-anchored) comment.
 */
#[ORM\Embeddable]
final readonly class Anchor
{
    public function __construct(
        // The quote is a verbatim excerpt of the document and can be sentence- or
        // paragraph-length, so it must not be capped at VARCHAR(255).
        #[ORM\Column(type: Types::TEXT)]
        public string $quote,

        #[ORM\Column(length: 255)]
        public string $prefix,

        #[ORM\Column(length: 255)]
        public string $suffix,

        // Byte offset of the quote's start within plainText() — a cached "how far
        // down the document this comment sits". Two SERVER-SIDE uses; it is never
        // sent to the browser:
        //   1. Sort key — CommentRepository orders threads by it so the sidebar
        //      lists them in reading order (top of the document first).
        //   2. Reanchoring tiebreaker — AnchorService::resolve() prefers the
        //      occurrence nearest the old offset when a revised document repeats
        //      the same quote.
        // The browser does NOT use this for positioning: it re-finds the quote in
        // the live DOM and reads real pixel rects. Mapping this byte offset to a
        // DOM position would reintroduce the PHP-byte vs JS-UTF16 drift that
        // content anchoring exists to avoid.
        #[ORM\Column]
        public int $offsetHint,
    ) {
    }

    /**
     * An untargeted (un-anchored) comment: empty quote/context is the storage
     * sentinel for "not attached to any span of the document".
     */
    public static function unanchored(): self
    {
        return new self('', '', '', 0);
    }
}
