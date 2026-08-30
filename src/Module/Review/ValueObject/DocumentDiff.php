<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * A word-level comparison of two documents' Markdown sources, as an ordered
 * list of source lines.
 *
 * Both sources are recoverable from the diff alone, which is far cheaper to
 * guarantee here than to retrofit onto markup that only described how a diff
 * looks. Server-side only: the rendered pane's `textContent` is neither source,
 * since deleted and inserted lines interleave and line breaks are block layout
 * rather than newlines.
 */
final readonly class DocumentDiff
{
    /** @param list<DiffLine> $lines */
    public function __construct(
        public array $lines,
    ) {
    }

    public function hasChanges(): bool
    {
        return array_any($this->lines, fn ($line) => DiffKind::Unchanged !== $line->kind);
    }

    /** The older version's Markdown source, rebuilt from the diff. */
    public function oldSource(): string
    {
        return $this->reconstruct(static fn (DiffKind $kind): bool => $kind->isInOld());
    }

    /** The newer version's Markdown source, rebuilt from the diff. */
    public function newSource(): string
    {
        return $this->reconstruct(static fn (DiffKind $kind): bool => $kind->isInNew());
    }

    /** @param callable(DiffKind): bool $keep */
    private function reconstruct(callable $keep): string
    {
        $lines = [];
        foreach ($this->lines as $line) {
            if (!$keep($line->kind)) {
                continue;
            }

            $text = '';
            foreach ($line->segments as $segment) {
                if ($keep($segment->kind)) {
                    $text .= $segment->text;
                }
            }
            $lines[] = $text;
        }

        return implode("\n", $lines);
    }
}
