<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * The headings of a Markdown-source diff, plus where each one's anchor goes.
 *
 * One walk answers both questions, so the rail rows and the ids on the page
 * cannot disagree. The position is the pair of loop counters the template
 * already has, and nothing about the line's text is read twice.
 */
final readonly class SourceHeadingIndex
{
    /**
     * @param list<DocumentHeading>          $headings
     * @param array<int, array<int, string>> $ids      the anchor of the line at [group][line]
     */
    public function __construct(
        public array $headings,
        private array $ids,
    ) {
    }

    public function idAt(int $group, int $line): ?string
    {
        return $this->ids[$group][$line] ?? null;
    }
}
