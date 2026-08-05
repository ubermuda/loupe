<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

/**
 * Derives a human-readable label from sanitized HTML.
 *
 * Shared by three callers that must agree. MarkdownRenderer slugs the label
 * into a heading's id and HeadingExtractor shows it in the table of contents —
 * a label those two derived differently would put one section's text on another
 * section's link. DecisionBlockService uses it for decision-block option
 * labels, which is what reaches the agent through document_get_review and what
 * a stored answer is matched against.
 */
final readonly class DisplayLabel
{
    /**
     * An image contributes its `alt`, so `## ![Diagram](d.png)` is navigable
     * instead of collapsing to nothing under strip_tags(). Returns '' when there
     * is nothing to show — an image with no `alt` leaves no text and no filename,
     * because the sanitizer drops a relative `src`.
     */
    public static function fromHtml(string $headingHtml): string
    {
        $withImageAlt = preg_replace_callback(
            '~<img\b[^>]*>~i',
            // Padded, so `Text<img alt="Diagram">` cannot run the two together.
            static fn (array $image): string => ' '.self::altOf($image[0]).' ',
            $headingHtml,
        ) ?? $headingHtml;

        $text = html_entity_decode(strip_tags($withImageAlt), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('~\s+~u', ' ', $text) ?? $text);
    }

    private static function altOf(string $imageTag): string
    {
        // `alt=""` is serialised as a valueless `alt`, which carries no label.
        return 1 === preg_match('~\balt="([^"]*)"~i', $imageTag, $alt) ? $alt[1] : '';
    }
}
