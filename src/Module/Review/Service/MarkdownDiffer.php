<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\ValueObject\DiffKind;
use App\Module\Review\ValueObject\DiffLine;
use App\Module\Review\ValueObject\DiffSegment;
use App\Module\Review\ValueObject\DocumentDiff;
use Jfcherng\Diff\Differ;
use Jfcherng\Diff\Options\DifferOptions;
use Jfcherng\Diff\Options\RendererOptions;
use Jfcherng\Diff\Renderer\Html\JsonHtml;
use Jfcherng\Diff\SequenceMatcher;

/**
 * Word-level diff of two documents' Markdown sources.
 *
 * The Markdown is compared, never the rendered HTML: a source edit that
 * renders identically (a reference link inlined, a bullet marker swapped, a
 * paragraph reflowed) is invisible in the output, and a paragraph turned into
 * a list item becomes a whole-block delete plus insert with `<ins>` runs
 * straddling element boundaries.
 */
final readonly class MarkdownDiffer
{
    /**
     * Above this share of a replaced block's characters changing, word marks
     * stop helping and the block is shown as a clean delete followed by a
     * clean insert. Measured on real edits: a one-word swap scores 0.08, a
     * reworded clause 0.25, a half-rewritten sentence 0.62, a heading whose
     * text is replaced 0.83, and prose sharing nothing with what it replaces
     * 0.97.
     *
     * The library's own `mergeThreshold` option cannot be used here — only its
     * `Combined` renderer reads it, and that renderer emits a presentation-only
     * HTML table with no way back to either source.
     */
    private const float MERGE_THRESHOLD = 0.7;

    public function diff(string $oldSource, string $newSource): DocumentDiff
    {
        $differ = new Differ(
            explode("\n", $oldSource),
            explode("\n", $newSource),
            new DifferOptions(
                // The diff replaces the document on screen rather than sitting
                // beside it, so every line is shown — there are no collapsed
                // context gaps to expand, and reading a version back out of the
                // diff requires that no line was dropped.
                context: Differ::CONTEXT_ALL,
                fullContextIfIdentical: true,
            ),
        );

        $renderer = new JsonHtml(new RendererOptions(
            detailLevel: 'word',
            lineNumbers: false,
            separateBlock: false,
            showHeader: false,
            // A space joins two marked runs that are adjacent apart from it, so
            // "delta instead" is one insertion rather than two; a hyphen does the
            // same inside a compound word. Neither ever absorbs unchanged text —
            // only runs separated by nothing but glue are joined.
            wordGlues: ['-', ' '],
        ));

        /** @var list<list<array{tag: int, old: array{lines: array<int, string>}, new: array{lines: array<int, string>}}>> $changes */
        $changes = $renderer->getChanges($differ);

        $lines = [];
        foreach ($changes as $hunk) {
            foreach ($hunk as $block) {
                foreach ($this->linesForBlock($block) as $line) {
                    $lines[] = $line;
                }
            }
        }

        return new DocumentDiff($lines);
    }

    /**
     * @param array{tag: int, old: array{lines: array<int, string>}, new: array{lines: array<int, string>}} $block
     *
     * @return list<DiffLine>
     */
    private function linesForBlock(array $block): array
    {
        $old = array_values($block['old']['lines']);
        $new = array_values($block['new']['lines']);

        return match ($block['tag']) {
            // An equal block carries the same lines under both keys; taking both
            // would silently double every unchanged region of the document.
            SequenceMatcher::OP_EQ => array_map(
                static fn (string $line): DiffLine => DiffLine::unchanged(self::decode($line)),
                $new,
            ),
            SequenceMatcher::OP_INS => array_map(
                static fn (string $line): DiffLine => DiffLine::inserted(self::decode($line)),
                $new,
            ),
            SequenceMatcher::OP_DEL => array_map(
                static fn (string $line): DiffLine => DiffLine::deleted(self::decode($line)),
                $old,
            ),
            SequenceMatcher::OP_REP => $this->linesForReplacement($old, $new),
            // Dropping a block would shorten the reconstructed source with nothing
            // to show for it, and the matcher only ever emits the four above.
            default => throw new \LogicException(sprintf('Unknown diff operation %d.', $block['tag'])),
        };
    }

    /**
     * @param list<string> $old
     * @param list<string> $new
     *
     * @return list<DiffLine>
     */
    private function linesForReplacement(array $old, array $new): array
    {
        // The library only marks words when both sides have the same line count;
        // otherwise the lines arrive unmarked and there is nothing to weigh.
        $marked = count($old) === count($new) && $this->changedRatio($old, $new) <= self::MERGE_THRESHOLD;

        if (!$marked) {
            return [
                ...array_map(static fn (string $line): DiffLine => DiffLine::deleted(self::stripMarks($line)), $old),
                ...array_map(static fn (string $line): DiffLine => DiffLine::inserted(self::stripMarks($line)), $new),
            ];
        }

        $lines = [];
        foreach ($old as $index => $oldLine) {
            $lines[] = new DiffLine(DiffKind::Deleted, self::segments($oldLine, DiffKind::Deleted));
            $lines[] = new DiffLine(DiffKind::Inserted, self::segments($new[$index], DiffKind::Inserted));
        }

        return $lines;
    }

    /**
     * Share of the block's characters that changed, on the scale the library's
     * own `Combined` renderer uses for the same decision.
     *
     * @param list<string> $old
     * @param list<string> $new
     */
    private function changedRatio(array $old, array $new): float
    {
        $oldLine = implode("\n", $old);
        $newLine = implode("\n", $new);

        $changed = self::markedLength($oldLine, 'del') + self::markedLength($newLine, 'ins');
        $total = strlen(self::stripMarks($oldLine)) + strlen(self::stripMarks($newLine));

        return $changed / ($total + 1);
    }

    /** Total length of the text inside `<del>`/`<ins>` runs of one line. */
    private static function markedLength(string $line, string $tag): int
    {
        preg_match_all('#<'.$tag.'>(.*?)</'.$tag.'>#us', $line, $matches);

        return array_sum(array_map(strlen(...), $matches[1]));
    }

    /**
     * Splits a marked line into runs, tagging each with `$changed` or
     * `DiffKind::Unchanged`.
     *
     * @return list<DiffSegment>
     */
    private static function segments(string $line, DiffKind $changed): array
    {
        $tag = DiffKind::Deleted === $changed ? 'del' : 'ins';
        $parts = preg_split(
            '#(<'.$tag.'>.*?</'.$tag.'>)#us',
            $line,
            -1,
            \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY,
        );
        if (false === $parts) {
            return [new DiffSegment(DiffKind::Unchanged, self::decode($line))];
        }

        $segments = [];
        foreach ($parts as $part) {
            $isMarked = str_starts_with($part, '<'.$tag.'>');
            $text = $isMarked ? substr($part, strlen($tag) + 2, -(strlen($tag) + 3)) : $part;
            if ('' === $text) {
                continue;
            }
            $segments[] = new DiffSegment($isMarked ? $changed : DiffKind::Unchanged, self::decode($text));
        }

        return $segments;
    }

    /** The line's own text, with the diff markup removed but its content kept. */
    private static function stripMarks(string $line): string
    {
        return self::decode(str_replace(['<del>', '</del>', '<ins>', '</ins>'], '', $line));
    }

    /**
     * Undoes the renderer's HTML escaping. Escaping happens before the marks are
     * inserted, which is why a literal `<del>` in the Markdown cannot be
     * mistaken for one — by this point it reads `&lt;del&gt;`.
     */
    private static function decode(string $text): string
    {
        return htmlspecialchars_decode($text, \ENT_NOQUOTES);
    }
}
