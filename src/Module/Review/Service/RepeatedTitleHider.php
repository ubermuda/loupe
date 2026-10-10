<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\ValueObject\DocumentHeading;

/**
 * Hides a leading `<h1>` that repeats the document title the page head shows.
 *
 * It marks the heading rather than removing it: comment anchors are measured
 * against the pane's textContent, so the text must stay in the markup.
 */
final readonly class RepeatedTitleHider
{
    private const string LEADING_H1 = '~^(\s*<h1)((?:\s[^>]*)?)>(.*?)</h1>~s';

    /**
     * @param list<DocumentHeading> $headings
     *
     * @return array{string, list<DocumentHeading>} the marked HTML, and the headings without the hidden one
     */
    public function hide(string $html, array $headings, string $title): array
    {
        if (1 !== preg_match(self::LEADING_H1, $html, $match)
            || $this->normalise(DisplayLabel::fromHtml($match[3])) !== $this->normalise($title)) {
            return [$html, $headings];
        }

        $marked = $match[1].$match[2].' data-repeated-title>'.substr($html, \strlen($match[1].$match[2]) + 1);
        $id = 1 === preg_match('~\bid="([^"]*)"~', $match[2], $idMatch)
            ? html_entity_decode($idMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : null;

        return [$marked, array_values(array_filter($headings, static fn (DocumentHeading $heading): bool => $heading->id !== $id))];
    }

    private function normalise(string $text): string
    {
        return mb_strtolower(trim(preg_replace('~\s+~u', ' ', $text) ?? $text));
    }
}
