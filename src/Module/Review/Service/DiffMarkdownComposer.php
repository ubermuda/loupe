<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\ValueObject\DiffKind;
use App\Module\Review\ValueObject\DiffLine;
use App\Module\Review\ValueObject\DiffSegment;
use App\Module\Review\ValueObject\DocumentDiff;

/**
 * Merges a {@see DocumentDiff} into one Markdown source that renders as the
 * whole document with its changes marked.
 *
 * One source and one render pass rather than two renders stitched together:
 * the renderer's heading-id pass then dedupes ids across both versions, and a
 * mark may sit inside an emphasis or link span.
 *
 * Marks carry a nonce as `datetime` — the one attribute the sanitizer keeps on
 * `ins`/`del` — so {@see MarkdownRenderer} can tell them from a `<del>` the
 * document itself wrote and class only its own.
 */
final readonly class DiffMarkdownComposer
{
    /** Mirrors the renderer's, so the block the parser will consume is the one recognised here. */
    private const string FRONT_MATTER_PATTERN = '~^---\R.*?\R---\R~s';

    /** Everything that can open a block on a line, so no mark ever covers one. */
    private const string LEADING_MARKER_PATTERN = '~^(?:[ \t]*(?:#{1,6}[ \t]+|>[ \t]*|[-*+][ \t]+|\d{1,9}[.)][ \t]+|\|[ \t]*))*~';

    private const string INDENTED_CODE_PATTERN = '~^(?: {4}|\t)~';

    /** A line that defines a link's destination for the references elsewhere that name it. */
    private const string REFERENCE_DEFINITION_PATTERN = '~^ {0,3}\[[^\]]*\]:~';

    /**
     * Markdown syntax an inline mark may not cut into, since a mark that opened
     * inside one of these and closed outside it leaves the construct unparseable
     * — a destination becomes literal text rather than a link. Prose *within*
     * these constructs is deliberately absent: a link's label and an emphasised
     * word stay markable, because only their delimiters are syntax. An image is
     * listed whole, since its label renders into the `alt` attribute, where a
     * mark would be escaped and shown as its own markup.
     */
    private const string SYNTAX_PATTERN = <<<'REGEX'
        ~
            (?P<tick>`+) .*? (?P=tick)                  # code span, literal throughout
          | < [^<>]* >                                  # raw HTML tag, or an autolink
          | ! \[ [^\]]* \] (?: \( [^)]* \) | \[ [^\]]* \] )?   # image, label included
          | \] \( [^)]* \)                              # inline link or image destination
          | \] \[ [^\]]* \]                             # reference link
          | \] : .* $                                   # link reference definition
          | \[                                          # link opener
          | \]                                          # shortcut reference closer
          | \*+                                         # emphasis delimiters
          | (?<![\p{L}\p{N}]) _+                        # underscore emphasis, never intra-word
          | _+ (?![\p{L}\p{N}])
          | \\ [^\p{L}\p{N}\s]                          # backslash escape
          | & (?: \#\d{1,7} | \#[xX][0-9a-fA-F]{1,6} | [a-zA-Z][a-zA-Z0-9]{1,31} ) ;
        ~xu
        REGEX;

    public function compose(DocumentDiff $diff, string $nonce): string
    {
        $lines = $diff->lines;
        $out = [];

        $frontMatter = $this->frontMatter($diff->newSource());
        if (null !== $frontMatter) {
            $lines = $this->afterFrontMatter($lines, $frontMatter, $this->frontMatter($diff->oldSource()));
            $out[] = rtrim($frontMatter, "\n");
        }

        foreach ($this->blocks($lines) as $block) {
            foreach ($this->composeBlock($block, $nonce) as $line) {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }

    private function frontMatter(string $source): ?string
    {
        return 1 === preg_match(self::FRONT_MATTER_PATTERN, $source, $matches) ? $matches[0] : null;
    }

    /**
     * Drops the lines that built the new version's front matter, plus the old
     * version's if it had one.
     *
     * The block must sit at byte zero to parse at all, so it can neither be
     * marked nor preceded by one. Old-side lines past the old block's length are
     * kept: a revision that adds front matter and deletes the paragraph that
     * opened the document would otherwise lose that deletion silently.
     *
     * @param list<DiffLine> $lines
     *
     * @return list<DiffLine>
     */
    private function afterFrontMatter(array $lines, string $newBlock, ?string $oldBlock): array
    {
        $newBudget = substr_count($newBlock, "\n");
        $oldBudget = null === $oldBlock ? 0 : substr_count($oldBlock, "\n");

        $kept = [];
        foreach ($lines as $index => $line) {
            $inNew = $line->kind->isInNew();
            $inOld = $line->kind->isInOld();

            if ($inNew && $newBudget > 0) {
                --$newBudget;
                if ($inOld) {
                    --$oldBudget;
                }
            } elseif ($inOld && $oldBudget > 0) {
                --$oldBudget;
            } else {
                $kept[] = $line;
            }

            if ($newBudget <= 0) {
                return [...$kept, ...array_values(\array_slice($lines, $index + 1))];
            }
        }

        return $kept;
    }

    /**
     * Splits the diff into blocks on blank lines, keeping a code fence's own
     * blank lines inside its block.
     *
     * Fence state is tracked per side, since a fence whose opening line changed
     * is open on one side and not the other, and a close must match its opening
     * character and length so that a fence quoted inside a fence cannot end it.
     *
     * @param list<DiffLine> $lines
     *
     * @return list<array{lines: list<DiffLine>, fenced: bool}>
     */
    private function blocks(array $lines): array
    {
        $blocks = [];
        $current = [];
        $fenced = false;
        $oldFence = null;
        $newFence = null;

        foreach ($lines as $line) {
            $text = $this->text($line);
            $inOld = $line->kind->isInOld();
            $inNew = $line->kind->isInNew();

            $inFence = ($inOld && null !== $oldFence) || ($inNew && null !== $newFence);
            $isFenceLine = $inOld && $this->toggleFence($oldFence, $text);
            $isFenceLine = ($inNew && $this->toggleFence($newFence, $text)) || $isFenceLine;

            if (!$inFence && !$isFenceLine && '' === trim($text)) {
                if ([] !== $current) {
                    $blocks[] = ['lines' => $current, 'fenced' => $fenced];
                    $current = [];
                    $fenced = false;
                }
                $blocks[] = ['lines' => [], 'fenced' => false];

                continue;
            }

            $current[] = $line;
            $fenced = $fenced || $inFence || $isFenceLine;
        }

        if ([] !== $current) {
            $blocks[] = ['lines' => $current, 'fenced' => $fenced];
        }

        return $blocks;
    }

    /**
     * Opens or closes one side's fence, reporting whether the line was a fence
     * marker.
     *
     * @param array{char: string, length: int}|null $open
     *
     * @param-out array{char: string, length: int}|null $open
     */
    private function toggleFence(?array &$open, string $text): bool
    {
        if (null === $open) {
            if (1 !== preg_match('#^ {0,3}(`{3,}|~{3,})#', $text, $matches)) {
                return false;
            }
            $open = ['char' => $matches[1][0], 'length' => \strlen($matches[1])];

            return true;
        }

        if (1 !== preg_match(sprintf('#^ {0,3}%s{%d,}[ \t]*$#', preg_quote($open['char'], '#'), $open['length']), $text)) {
            return false;
        }
        $open = null;

        return true;
    }

    /**
     * @param array{lines: list<DiffLine>, fenced: bool} $block
     *
     * @return list<string>
     */
    private function composeBlock(array $block, string $nonce): array
    {
        $lines = $block['lines'];
        if ([] === $lines) {
            return [''];
        }

        if (array_all($lines, static fn (DiffLine $line): bool => DiffKind::Unchanged === $line->kind)) {
            return array_map($this->text(...), $lines);
        }

        $old = array_values(array_filter($lines, static fn (DiffLine $line): bool => $line->kind->isInOld()));
        $new = array_values(array_filter($lines, static fn (DiffLine $line): bool => $line->kind->isInNew()));

        if ([] === $new) {
            return $this->wrapBlock('del', $old, $nonce);
        }

        if ([] === $old) {
            return $this->wrapBlock('ins', $new, $nonce);
        }

        // Inside either kind of code block a mark would render as its own literal
        // text, so a changed one is shown as a whole-block removal and addition.
        if ($block['fenced'] || array_all($lines, fn (DiffLine $line): bool => 1 === preg_match(self::INDENTED_CODE_PATTERN, $this->text($line)))) {
            return [...$this->wrapBlock('del', $old, $nonce), ...$this->wrapBlock('ins', $new, $nonce)];
        }

        return $this->inlineLines($lines, $nonce);
    }

    /**
     * Wraps a whole block in an HTML block, which CommonMark only parses the
     * Markdown inside of when the tags stand alone with blank lines between.
     *
     * @param list<DiffLine> $lines
     *
     * @return list<string>
     */
    private function wrapBlock(string $tag, array $lines, string $nonce): array
    {
        return [
            '',
            $this->openTag($tag, $nonce),
            '',
            ...array_map($this->text(...), $lines),
            '',
            sprintf('</%s>', $tag),
            '',
        ];
    }

    /**
     * @param list<DiffLine> $lines
     *
     * @return list<string>
     */
    private function inlineLines(array $lines, string $nonce): array
    {
        $out = [];
        $count = \count($lines);

        for ($index = 0; $index < $count; ++$index) {
            $line = $lines[$index];

            if (DiffKind::Unchanged === $line->kind) {
                $out[] = $this->text($line);

                continue;
            }

            // A marked definition stops being one, and every reference naming it
            // then renders as the literal text of its own brackets.
            if (1 === preg_match(self::REFERENCE_DEFINITION_PATTERN, $this->text($line))) {
                if ($line->kind->isInNew()) {
                    $out[] = $this->text($line);
                }

                continue;
            }

            $next = $lines[$index + 1] ?? null;
            $merged = DiffKind::Deleted === $line->kind && null !== $next && DiffKind::Inserted === $next->kind
                ? $this->mergedLine($line, $next, $nonce)
                : null;

            if (null !== $merged) {
                $out[] = $merged;
                ++$index;

                continue;
            }

            $out[] = $this->markedLine($line, $nonce);
        }

        return $out;
    }

    /**
     * Interleaves a word-marked delete/insert pair into the single line both
     * were built from, or null when they cannot be interleaved.
     *
     * The pair is only merged when both sides carry the same unchanged runs in
     * the same order, which is what makes the shared text safe to emit once —
     * and when the leading run covers whatever opens the block on either side,
     * since a mark over a `#` or a `-` turns its block into a paragraph.
     */
    private function mergedLine(DiffLine $deleted, DiffLine $inserted, string $nonce): ?string
    {
        $shared = $this->unchangedTexts($deleted);
        if ([] === $shared || $shared !== $this->unchangedTexts($inserted)) {
            return null;
        }

        $old = $deleted->segments;
        $new = $inserted->segments;
        $merged = '';
        $plain = '';
        $leading = '';
        $marks = [];

        for ($i = 0, $j = 0; $i < \count($old) || $j < \count($new);) {
            if ($i < \count($old) && DiffKind::Deleted === $old[$i]->kind) {
                [$merged, $plain, $marks] = $this->appendMark($old[$i]->text, 'del', $nonce, $merged, $plain, $marks);
                ++$i;
            } elseif ($j < \count($new) && DiffKind::Inserted === $new[$j]->kind) {
                [$merged, $plain, $marks] = $this->appendMark($new[$j]->text, 'ins', $nonce, $merged, $plain, $marks);
                ++$j;
            } else {
                $merged .= $old[$i]->text;
                $plain .= $old[$i]->text;
                if ([] === $marks) {
                    $leading .= $old[$i]->text;
                }
                ++$i;
                ++$j;
            }
        }

        $marker = max($this->markerLength($this->text($deleted)), $this->markerLength($this->text($inserted)));
        if (\strlen($leading) < $marker || $this->marksCutIntoSyntax($plain, $marks)) {
            return null;
        }

        return $merged;
    }

    /**
     * Appends one marked run, recording where it lands in the line the parser
     * will read — which is the merged text, not either version's own.
     *
     * @param list<array{int, int}> $marks
     *
     * @return array{string, string, list<array{int, int}>}
     */
    private function appendMark(string $text, string $tag, string $nonce, string $merged, string $plain, array $marks): array
    {
        $marks[] = [\strlen($plain), \strlen($plain) + \strlen($text)];

        return [$merged.$this->wrapInline($text, $tag, $nonce), $plain.$text, $marks];
    }

    /**
     * Whether any mark overlaps Markdown syntax without covering it whole.
     *
     * A pattern that cannot be scanned counts as a cut, so malformed input falls
     * back to marking the line rather than being emitted unchecked.
     *
     * @param list<array{int, int}> $marks
     */
    private function marksCutIntoSyntax(string $line, array $marks): bool
    {
        $found = preg_match_all(self::SYNTAX_PATTERN, $line, $matches, \PREG_OFFSET_CAPTURE);
        if (false === $found) {
            return true;
        }

        /** @var list<array{string, int}> $spans */
        $spans = $matches[0];

        return array_any($spans, static fn (array $span): bool => array_any(
            $marks,
            static fn (array $mark): bool => $mark[0] < $span[1] + \strlen($span[0]) && $span[1] < $mark[1],
        ));
    }

    /** Marks a whole line, leaving whatever opens its block outside the mark. */
    private function markedLine(DiffLine $line, string $nonce): string
    {
        $text = $this->text($line);
        $marker = $this->markerLength($text);
        $content = substr($text, $marker);

        if ('' === trim($content)) {
            return $text;
        }

        return substr($text, 0, $marker).$this->wrapInline($content, DiffKind::Deleted === $line->kind ? 'del' : 'ins', $nonce);
    }

    /**
     * Wraps marked text, split at every unescaped `|` so that no mark spans a
     * table cell boundary — one that did would open in one cell and close in
     * another. An escaped `\|` is cell content, and splitting there would cut
     * the escape in half.
     */
    private function wrapInline(string $text, string $tag, string $nonce): string
    {
        $open = $this->openTag($tag, $nonce);
        $close = sprintf('</%s>', $tag);
        $parts = preg_split('~(?<!\\\\)\|~', $text);

        return implode('|', array_map(
            static fn (string $part): string => '' === trim($part) ? $part : $open.$part.$close,
            false === $parts ? [$text] : $parts,
        ));
    }

    private function openTag(string $tag, string $nonce): string
    {
        return sprintf('<%s datetime="%s">', $tag, $nonce);
    }

    private function markerLength(string $text): int
    {
        return 1 === preg_match(self::LEADING_MARKER_PATTERN, $text, $matches) ? \strlen($matches[0]) : 0;
    }

    /** @return list<string> */
    private function unchangedTexts(DiffLine $line): array
    {
        return array_values(array_map(
            static fn (DiffSegment $segment): string => $segment->text,
            array_filter($line->segments, static fn (DiffSegment $segment): bool => DiffKind::Unchanged === $segment->kind),
        ));
    }

    private function text(DiffLine $line): string
    {
        return implode('', array_map(static fn (DiffSegment $segment): string => $segment->text, $line->segments));
    }
}
