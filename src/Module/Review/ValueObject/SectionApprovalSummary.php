<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * Every section of a version, paired with whether the reader approved it.
 *
 * One object rather than two parallel arrays, because the panel shows the rows
 * and the tab shows a count of the approved ones. Both are wrong the moment the
 * two lists drift apart.
 */
final readonly class SectionApprovalSummary
{
    /**
     * @param list<DocumentHeading> $headings
     * @param array<string, true>   $approvedByHeadingId keyed by heading id, absent while the section is unapproved
     */
    public function __construct(
        public array $headings,
        public array $approvedByHeadingId,
    ) {
    }

    public function approvedCount(): int
    {
        return \count($this->approvedByHeadingId);
    }

    /**
     * The list as the toolbar panel shows it: one row per section, in document order.
     *
     * A heading with no derivable label falls back to its id, which is what the
     * link points at and is unique within the version.
     *
     * @return list<array{headingId: string, level: int, label: string, approved: bool}>
     */
    public function rows(): array
    {
        return array_map(fn (DocumentHeading $heading): array => [
            'headingId' => $heading->id,
            'level' => $heading->level,
            'label' => '' === $heading->text ? $heading->id : $heading->text,
            'approved' => isset($this->approvedByHeadingId[$heading->id]),
        ], $this->headings);
    }
}
