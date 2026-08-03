<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\ValueObject\DiffKind;
use App\Module\Review\ValueObject\DiffLine;
use App\Module\Review\ValueObject\DiffRefusal;
use App\Module\Review\ValueObject\DiffSegment;
use App\Module\Review\ValueObject\DocumentDiff;
use Jfcherng\Diff\Differ;
use Jfcherng\Diff\Options\DifferOptions;
use Jfcherng\Diff\Options\RendererOptions;
use Jfcherng\Diff\Renderer\Html\JsonHtml;
use Jfcherng\Diff\Renderer\RendererConstant;
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
     * stop helping and the block is shown as a clean delete followed by a clean
     * insert. Headings sit near the boundary, so two similar heading rewrites
     * can still be treated differently — that is the heuristic, not this number.
     *
     * The library's own `mergeThreshold` option cannot be used: only its
     * `Combined` renderer reads it, and that renderer emits a presentation-only
     * HTML table with no way back to either source.
     */
    private const float MERGE_THRESHOLD = 0.7;

    /**
     * Bounds beyond which a diff is refused rather than attempted. The line pass
     * is quadratic in line count and the word pass in each line's length, so
     * either shape runs away on a document well inside the 1 MB a version may
     * hold: measured against the bare library, 10 000 short lines take 14.4s and
     * 100 lines of 5 000 characters take 7.4s, where refusing takes under 3ms.
     */
    private const int MAX_LINES = 2_000;
    private const int MAX_WORD_WORK = 300_000_000;

    /** A {@see DiffRefusal} in place of a diff when the pair cannot be compared. */
    public function diff(string $oldSource, string $newSource): DocumentDiff|DiffRefusal
    {
        if ($this->holdsSentinel($oldSource) || $this->holdsSentinel($newSource)) {
            return DiffRefusal::UnsupportedCharacters;
        }

        $oldLines = explode("\n", $oldSource);
        $newLines = explode("\n", $newSource);

        if (!$this->withinBounds($oldLines) || !$this->withinBounds($newLines)) {
            return DiffRefusal::TooLarge;
        }

        $differ = new Differ(
            $oldLines,
            $newLines,
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

    /** @param list<string> $lines */
    private function withinBounds(array $lines): bool
    {
        if (count($lines) > self::MAX_LINES) {
            return false;
        }

        $work = 0;
        foreach ($lines as $line) {
            $work += strlen($line) ** 2;
            if ($work > self::MAX_WORD_WORK) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the source holds one of the private-use sequences the diff library
     * reserves as its own markers. It consumes rather than escapes them — its
     * line delimiter splits one source line into two, and its mark closures
     * vanish while rendering the text between them as a change that never
     * happened — so the diff would describe neither stored version.
     *
     * Stripping them first is worse, not better: two versions differing only in
     * such a character would then compare as identical, and nothing on the page
     * would say so.
     */
    private function holdsSentinel(string $source): bool
    {
        // The library types these constants as bare `array`, so their elements
        // arrive as mixed.
        /** @var list<string> $sentinels */
        $sentinels = [RendererConstant::IMPLODE_DELIMITER, ...RendererConstant::HTML_CLOSURES, Differ::LINE_NO_EOL];

        return array_any($sentinels, static fn (string $sentinel): bool => str_contains($source, $sentinel));
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
                fn (string $line): DiffLine => DiffLine::unchanged($this->decode($line)),
                $new,
            ),
            SequenceMatcher::OP_INS => array_map(
                fn (string $line): DiffLine => DiffLine::inserted($this->decode($line)),
                $new,
            ),
            SequenceMatcher::OP_DEL => array_map(
                fn (string $line): DiffLine => DiffLine::deleted($this->decode($line)),
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
                ...array_map(fn (string $line): DiffLine => DiffLine::deleted($this->stripMarks($line)), $old),
                ...array_map(fn (string $line): DiffLine => DiffLine::inserted($this->stripMarks($line)), $new),
            ];
        }

        $lines = [];
        foreach ($old as $index => $oldLine) {
            $lines[] = new DiffLine(DiffKind::Deleted, $this->segments($oldLine, DiffKind::Deleted));
            $lines[] = new DiffLine(DiffKind::Inserted, $this->segments($new[$index], DiffKind::Inserted));
        }

        return $lines;
    }

    /**
     * Share of the block's characters that changed. The library's `Combined`
     * renderer scores the same thing by doubling an unchanged length read off
     * one side, which under-counts whenever word gluing leaves the two sides'
     * unchanged runs at different lengths.
     *
     * @param list<string> $old
     * @param list<string> $new
     */
    private function changedRatio(array $old, array $new): float
    {
        $oldLine = implode("\n", $old);
        $newLine = implode("\n", $new);

        $changed = $this->markedLength($oldLine, 'del') + $this->markedLength($newLine, 'ins');
        $total = strlen($this->stripMarks($oldLine)) + strlen($this->stripMarks($newLine));

        return $changed / ($total + 1);
    }

    /**
     * Total length inside a line's `<del>`/`<ins>` runs. Decoded first, so it is
     * on the same scale as the line it is divided by: a changed `&` is one
     * character, not the five of `&amp;`.
     */
    private function markedLength(string $line, string $tag): int
    {
        preg_match_all('#<'.$tag.'>(.*?)</'.$tag.'>#us', $line, $matches);

        return array_sum(array_map(fn (string $run): int => strlen($this->decode($run)), $matches[1]));
    }

    /**
     * Splits a marked line into runs, tagging each with `$changed` or
     * `DiffKind::Unchanged`.
     *
     * @return list<DiffSegment>
     */
    private function segments(string $line, DiffKind $changed): array
    {
        $tag = DiffKind::Deleted === $changed ? 'del' : 'ins';
        $parts = preg_split(
            '#(<'.$tag.'>.*?</'.$tag.'>)#us',
            $line,
            -1,
            \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY,
        );
        if (false === $parts) {
            return [new DiffSegment(DiffKind::Unchanged, $this->decode($line))];
        }

        $segments = [];
        foreach ($parts as $part) {
            $isMarked = str_starts_with($part, '<'.$tag.'>');
            $text = $isMarked ? substr($part, strlen($tag) + 2, -(strlen($tag) + 3)) : $part;
            if ('' === $text) {
                continue;
            }
            $segments[] = new DiffSegment($isMarked ? $changed : DiffKind::Unchanged, $this->decode($text));
        }

        return $segments;
    }

    /** The line's own text, with the diff markup removed but its content kept. */
    private function stripMarks(string $line): string
    {
        return $this->decode(str_replace(['<del>', '</del>', '<ins>', '</ins>'], '', $line));
    }

    /**
     * Undoes the renderer's HTML escaping. Escaping happens before the marks are
     * inserted, which is why a literal `<del>` in the Markdown cannot be
     * mistaken for one — by this point it reads `&lt;del&gt;`.
     */
    private function decode(string $text): string
    {
        return htmlspecialchars_decode($text, \ENT_NOQUOTES);
    }
}
