<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * One heading of a rendered document version.
 *
 * Read back out of DocumentVersion::$renderedHtml rather than parsed from the
 * Markdown source, so the text is the same text DocumentVersion::plainText()
 * exposes — the basis comment anchors are measured against.
 */
final readonly class DocumentHeading
{
    public function __construct(
        /** 1 for `<h1>` through 6 for `<h6>`. */
        public int $level,
        /** The `id` MarkdownRenderer gave the heading, without the `#`. */
        public string $id,
        /**
         * Trimmed for display, so it is NOT guaranteed to equal the slice of
         * plainText() at $offset — read the slice itself when the exact characters
         * matter.
         */
        public string $text,
        /**
         * Character offset of the heading's text within
         * DocumentVersion::plainText(), which is what makes a heading usable as a
         * structural position and not only as a link target. Repeated heading text
         * is told apart by this and by $id, never by $text alone.
         */
        public int $offset,
    ) {
    }
}
