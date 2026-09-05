<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\ValueObject\DocumentHeading;

/**
 * Digests each section of a rendered version, so an approval can say which text
 * it approved.
 *
 * A section runs from one heading's offset to the NEXT heading's offset,
 * whatever the two levels are, and the last one runs to the end of the text.
 * Text before the first heading belongs to no section and is never hashed.
 *
 * The digest covers the slice of DocumentVersion::plainText(), never
 * DocumentHeading::$text: that label is trimmed and its whitespace is collapsed,
 * so it does not equal the text at its own offset, and an edit inside the
 * heading would leave the digest unchanged.
 */
final readonly class SectionHasher
{
    /**
     * @param list<DocumentHeading> $headings in document order, as HeadingExtractor returns them
     *
     * @return array<string, string> heading id to sha256 digest, in lower-case hexadecimal
     */
    public function hashes(string $renderedHtml, array $headings): array
    {
        $plainText = DocumentVersion::plainTextOf($renderedHtml);

        $hashes = [];
        foreach ($headings as $index => $heading) {
            $next = $headings[$index + 1] ?? null;
            $section = null === $next
                ? mb_substr($plainText, $heading->offset, null, 'UTF-8')
                : mb_substr($plainText, $heading->offset, $next->offset - $heading->offset, 'UTF-8');

            $hashes[$heading->id] = hash('sha256', $section);
        }

        return $hashes;
    }
}
