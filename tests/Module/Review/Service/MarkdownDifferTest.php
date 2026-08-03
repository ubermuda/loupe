<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Review\Service\MarkdownDiffer;
use App\Module\Review\ValueObject\DiffKind;
use App\Module\Review\ValueObject\DiffLine;
use App\Module\Review\ValueObject\DocumentDiff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownDifferTest extends TestCase
{
    private MarkdownDiffer $differ;

    protected function setUp(): void
    {
        $this->differ = new MarkdownDiffer();
    }

    /** @return iterable<string, array{string, string}> */
    public static function sourcePairs(): iterable
    {
        yield 'identical' => ["# Title\n\nOne paragraph.", "# Title\n\nOne paragraph."];

        yield 'one word swapped' => [
            "The quick brown fox jumps over the lazy dog.\n",
            "The quick brown fox leaps over the lazy dog.\n",
        ];

        yield 'paragraph rewritten from scratch' => [
            "# Title\n\nA paragraph that will be entirely rewritten from scratch.\n\n- one\n- two",
            "# Title\n\nCompletely different prose, sharing nothing with what it replaces.\n\n- one\n- two",
        ];

        yield 'lines added and removed' => [
            "alpha\nbeta\ngamma\n",
            "alpha\ninserted one\ninserted two\ngamma\ndelta\n",
        ];

        yield 'no trailing newline' => ["alpha\nbeta", "alpha\ngamma"];

        yield 'markup-only edits that render the same' => [
            "See [the spec][spec] for details.\n\n- bullet\n\n[spec]: https://example.test/spec\n",
            "See [the spec](https://example.test/spec) for details.\n\n* bullet\n",
        ];

        yield 'html-escaping round trip' => [
            "Use <div> & \"quotes\" and 'apostrophes' here.\n",
            "Use <span> & \"quotes\" and 'apostrophes' there.\n",
        ];

        yield 'tabs and trailing whitespace' => ["\tindented old  \n\nlast", "\tindented new  \n\nlast"];

        yield 'empty old document' => ['', "# New\n\nBody."];

        yield 'everything removed' => ["# Gone\n\nBody.", ''];

        yield 'unicode and emoji' => ["Héllo wörld — naïve café 🎉\n", "Héllo wörld — naïve tea 🎉\n"];

        yield 'a long paragraph reflowed' => [
            "One sentence. Another sentence. A third sentence that is fairly long.\n",
            "One sentence.\nAnother sentence.\nA third sentence that is fairly long.\n",
        ];
    }

    /**
     * The property the whole design turns on: the diff carries enough to rebuild
     * either version. The rendered page is pinned separately — see the round-trip
     * over the diff pane in ShowDocumentControllerTest.
     */
    #[DataProvider('sourcePairs')]
    public function test_both_sources_are_recoverable_from_the_diff(string $old, string $new): void
    {
        $diff = $this->differ->diff($old, $new);

        self::assertNotNull($diff);
        self::assertSame($old, $diff->oldSource());
        self::assertSame($new, $diff->newSource());
    }

    public function test_identical_versions_report_no_changes_but_still_carry_the_document(): void
    {
        $source = "# Title\n\nOne paragraph.\n";
        $diff = $this->differ->diff($source, $source);

        self::assertNotNull($diff);
        self::assertFalse($diff->hasChanges());
        // Without this the previous assertion would also hold for an empty diff,
        // which cannot reconstruct anything.
        self::assertSame($source, $diff->newSource());
    }

    public function test_a_reworded_sentence_is_marked_word_by_word(): void
    {
        $diff = $this->differ->diff(
            'The quick brown fox jumps over the lazy dog.',
            'The quick brown fox leaps over the lazy dog.',
        );

        self::assertNotNull($diff);
        self::assertTrue($diff->hasChanges());
        self::assertSame(['jumps'], self::textsOfKind($diff, DiffKind::Deleted));
        self::assertSame(['leaps'], self::textsOfKind($diff, DiffKind::Inserted));
    }

    /**
     * The threshold that stops a word diff from interleaving two unrelated
     * paragraphs into an unreadable mangle.
     */
    public function test_a_wholly_rewritten_block_becomes_a_clean_delete_and_insert(): void
    {
        $old = 'A paragraph that will be entirely rewritten from scratch.';
        $new = 'Completely different prose, sharing nothing with what it replaces.';

        $diff = $this->differ->diff($old, $new);

        self::assertNotNull($diff);
        self::assertSame([$old], self::textsOfKind($diff, DiffKind::Deleted));
        self::assertSame([$new], self::textsOfKind($diff, DiffKind::Inserted));
        self::assertSame([], self::textsOfKind($diff, DiffKind::Unchanged));
    }

    /**
     * The two tests above bracket the threshold at 0.11 and 0.97, which any
     * value in between would satisfy. These two sit either side of it — a
     * half-rewritten sentence at ~0.55 and a rewritten heading at ~0.81 — so the
     * constant cannot drift far without one of them failing.
     */
    public function test_the_threshold_splits_a_heading_rewrite_but_not_a_half_rewritten_sentence(): void
    {
        $halfRewritten = $this->differ->diff(
            'Diffing the rendered HTML loses source changes that render identically.',
            'Diffing the rendered HTML drops edits a reader would want to see anyway.',
        );
        self::assertNotNull($halfRewritten);
        self::assertNotSame([], self::textsOfKind($halfRewritten, DiffKind::Unchanged), 'below the threshold: word marks kept');

        $headingRewritten = $this->differ->diff('## Requirements and constraints', '## Scope');
        self::assertNotNull($headingRewritten);
        self::assertSame([], self::textsOfKind($headingRewritten, DiffKind::Unchanged), 'above the threshold: clean delete and insert');
    }

    public function test_a_document_too_large_to_compare_is_refused_rather_than_attempted(): void
    {
        $line = str_repeat('a stable line of prose here. ', 3);

        $small = implode("\n", array_fill(0, 100, $line));
        self::assertNotNull($this->differ->diff($small, $small.'\nextra'));

        // Past the line bound: ~10 000 short lines is a changelog well inside the
        // 1 MB a version may hold, and exhausts a worker outright.
        $manyLines = implode("\n", array_fill(0, 10_000, $line));
        self::assertNull($this->differ->diff($manyLines, $manyLines.'\nextra'));

        // Past the work bound with only a handful of lines: the word pass is
        // quadratic in a line's length, so few long lines cost far more than many
        // short ones at the same size.
        $fewLongLines = implode("\n", array_fill(0, 100, str_repeat('word ', 1_000)));
        self::assertNull($this->differ->diff($fewLongLines, $fewLongLines.'\nextra'));
    }

    /**
     * The library reserves private-use characters as its own markers and consumes
     * rather than escapes them, so a document containing one would be described
     * by a diff of something else.
     */
    public function test_the_library_s_own_markers_cannot_survive_into_a_diff(): void
    {
        $old = "A \u{fcffc}\u{ff2fb}trap\u{fff41}\u{fcffc} here\u{ff2fa}\u{fcffc}\u{fff42}and here";
        $new = "A trap there\u{ff2fa}\u{fcffc}\u{fff42}and here";

        $diff = $this->differ->diff($old, $new);

        self::assertNotNull($diff);
        self::assertSame('A trap hereand here', $diff->oldSource());
        self::assertSame('A trap thereand here', $diff->newSource());
    }

    public function test_an_unchanged_region_is_emitted_once_not_once_per_side(): void
    {
        $diff = $this->differ->diff("alpha\nbeta\n", "alpha\ngamma\n");

        self::assertNotNull($diff);
        $unchanged = array_filter($diff->lines, static fn (DiffLine $l): bool => DiffKind::Unchanged === $l->kind);
        self::assertSame(['alpha', ''], array_values(array_map(
            static fn (DiffLine $l): string => $l->segments[0]->text ?? '',
            $unchanged,
        )));
    }

    public function test_diff_markup_in_the_source_is_not_mistaken_for_a_diff_mark(): void
    {
        $diff = $this->differ->diff('A <del>trap</del> here.', 'A <del>trap</del> there.');

        self::assertNotNull($diff);
        self::assertSame(['here'], self::textsOfKind($diff, DiffKind::Deleted));
        self::assertSame(['there'], self::textsOfKind($diff, DiffKind::Inserted));
    }

    /** @return list<string> */
    private static function textsOfKind(DocumentDiff $diff, DiffKind $kind): array
    {
        $texts = [];
        foreach ($diff->lines as $line) {
            foreach ($line->segments as $segment) {
                if ($kind === $segment->kind && '' !== $segment->text) {
                    $texts[] = $segment->text;
                }
            }
        }

        return $texts;
    }
}
