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
     * either version, so a comment left while reading a diff would have a
     * well-defined text basis to anchor against.
     */
    #[DataProvider('sourcePairs')]
    public function test_both_sources_are_recoverable_from_the_diff(string $old, string $new): void
    {
        $diff = $this->differ->diff($old, $new);

        self::assertSame($old, $diff->oldSource());
        self::assertSame($new, $diff->newSource());
    }

    public function test_identical_versions_report_no_changes_but_still_carry_the_document(): void
    {
        $source = "# Title\n\nOne paragraph.\n";
        $diff = $this->differ->diff($source, $source);

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

        self::assertSame([$old], self::textsOfKind($diff, DiffKind::Deleted));
        self::assertSame([$new], self::textsOfKind($diff, DiffKind::Inserted));
        self::assertSame([], self::textsOfKind($diff, DiffKind::Unchanged));
    }

    public function test_an_unchanged_region_is_emitted_once_not_once_per_side(): void
    {
        $diff = $this->differ->diff("alpha\nbeta\n", "alpha\ngamma\n");

        $unchanged = array_filter($diff->lines, static fn (DiffLine $l): bool => DiffKind::Unchanged === $l->kind);
        self::assertSame(['alpha', ''], array_values(array_map(
            static fn (DiffLine $l): string => $l->segments[0]->text ?? '',
            $unchanged,
        )));
    }

    public function test_diff_markup_in_the_source_is_not_mistaken_for_a_diff_mark(): void
    {
        $diff = $this->differ->diff('A <del>trap</del> here.', 'A <del>trap</del> there.');

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
