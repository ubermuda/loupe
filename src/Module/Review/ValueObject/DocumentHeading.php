<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * One heading of a rendered document version.
 *
 * Read back out of DocumentVersion::$renderedHtml rather than parsed from the
 * Markdown source, so $offset lands in DocumentVersion::plainText() — the basis
 * comment anchors are measured against.
 */
final readonly class DocumentHeading
{
    public function __construct(
        /** 1 for `<h1>` through 6 for `<h6>`. */
        public int $level,
        /** The `id` MarkdownRenderer gave the heading, without the `#`. */
        public string $id,
        /**
         * A label for display, NOT a slice of plainText(): it is trimmed, its inner
         * whitespace is collapsed, and an image contributes its `alt` text, which is
         * in an attribute and so is absent from plainText() entirely. Read the slice
         * at $offset when the exact characters matter. Empty when the heading is an
         * image with no `alt` — there is then nothing to label it with.
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
