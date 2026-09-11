<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\ValueObject\DiffLine;
use App\Module\Review\ValueObject\DiffSegment;
use App\Module\Review\ValueObject\DocumentDiff;
use App\Module\Review\ValueObject\DocumentHeading;
use App\Module\Review\ValueObject\SourceHeadingIndex;

/**
 * Lists the headings of a Markdown-source diff, in the order the page holds
 * them, and mints an anchor for each.
 *
 * The source view renders lines rather than headings, so there is no id to read
 * back out of markup the way {@see HeadingExtractor} does. The ids are minted
 * here instead, and the walk is over {@see DocumentDiff::groups()}, which is the
 * same list the template renders.
 *
 * A removed heading is listed too. It is on the page the reader has, and the
 * rendered views list their removed headings for the same reason.
 */
final readonly class SourceHeadingIndexBuilder
{
    /** An ATX heading, with the optional closing hashes left out of the label. */
    private const string HEADING_PATTERN = '~^ {0,3}(\#{1,6})[ \t]+(.*?)(?:[ \t]+\#+)?[ \t]*$~';

    /** Namespaced, so a minted id can never read as a document heading or a hunk. */
    private const string ID_PREFIX = 'diff-source-heading-';

    public function build(DocumentDiff $diff): SourceHeadingIndex
    {
        $headings = [];
        $ids = [];
        $oldFence = null;
        $newFence = null;

        foreach ($diff->groups() as $groupIndex => $group) {
            foreach ($group->lines as $lineIndex => $line) {
                $text = $this->text($line);
                $inOld = $line->kind->isInOld();
                $inNew = $line->kind->isInNew();

                $inFence = ($inOld && null !== $oldFence) || ($inNew && null !== $newFence);
                $isFenceLine = $inOld && $this->toggleFence($oldFence, $text);
                $isFenceLine = ($inNew && $this->toggleFence($newFence, $text)) || $isFenceLine;

                if ($inFence || $isFenceLine || 1 !== preg_match(self::HEADING_PATTERN, $text, $matches)) {
                    continue;
                }

                $id = self::ID_PREFIX.(\count($headings) + 1);
                $ids[$groupIndex][$lineIndex] = $id;
                // The offset is 0: a source line is not a slice of the version's
                // plain text, and no reader of these headings measures one.
                $headings[] = new DocumentHeading(\strlen($matches[1]), $id, trim($matches[2]), 0);
            }
        }

        return new SourceHeadingIndex($headings, $ids);
    }

    /**
     * Opens or closes one side's fence, reporting whether the line was a fence
     * marker. Fence state is per side, because a fence whose opening line
     * changed is open on one side and not the other.
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

        if (1 !== preg_match(\sprintf('#^ {0,3}%s{%d,}[ \t]*$#', preg_quote($open['char'], '#'), $open['length']), $text)) {
            return false;
        }
        $open = null;

        return true;
    }

    private function text(DiffLine $line): string
    {
        return implode('', array_map(static fn (DiffSegment $segment): string => $segment->text, $line->segments));
    }
}
