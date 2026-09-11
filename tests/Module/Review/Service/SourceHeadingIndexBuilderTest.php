<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Review\Service\MarkdownDiffer;
use App\Module\Review\Service\SourceHeadingIndexBuilder;
use App\Module\Review\ValueObject\DocumentDiff;
use App\Module\Review\ValueObject\DocumentHeading;
use PHPUnit\Framework\TestCase;

/**
 * The Markdown view's contents, read off the diff rather than off markup.
 *
 * A kernel-free test, because the cases that matter here are shapes of Markdown
 * rather than shapes of a page. The controller test covers the rendered result.
 */
final class SourceHeadingIndexBuilderTest extends TestCase
{
    /** @return list<string> each heading as `level:text` */
    private function index(string $old, string $new): array
    {
        $diff = new MarkdownDiffer()->diff($old, $new);
        self::assertInstanceOf(DocumentDiff::class, $diff);

        $built = new SourceHeadingIndexBuilder()->build($diff);

        return array_map(
            static fn (DocumentHeading $heading): string => $heading->level.':'.$heading->text,
            $built->headings,
        );
    }

    public function test_it_lists_atx_headings_of_both_versions(): void
    {
        self::assertSame(
            ['2:Kept', '2:Gone', '3:Arrived'],
            $this->index(
                "## Kept\n\nBody.\n\n## Gone\n\nDropped.\n",
                "## Kept\n\nBody.\n\n### Arrived\n\nAdded.\n",
            ),
        );
    }

    public function test_a_hash_inside_a_fence_is_not_a_heading(): void
    {
        self::assertSame(
            ['2:Real'],
            $this->index(
                "## Real\n\n```sh\n# not a heading\n```\n\nTail.\n",
                "## Real\n\n```sh\n# not a heading\n```\n\nTail changed.\n",
            ),
        );
    }

    public function test_a_tilde_fence_closes_only_on_its_own_marker(): void
    {
        self::assertSame(
            ['2:Real'],
            $this->index(
                "## Real\n\n~~~\n# inside\n```\n# still inside\n~~~\n\nTail.\n",
                "## Real\n\n~~~\n# inside\n```\n# still inside\n~~~\n\nTail moved.\n",
            ),
        );
    }

    /**
     * A revision that fences a block leaves the lines inside it unchanged. Such
     * a line is code in the newer version and a heading in the older one, and
     * the page shows it, so it earns a row. Treating either side's fence as
     * enough dropped it.
     */
    public function test_a_line_fenced_on_one_side_only_is_still_listed(): void
    {
        self::assertSame(
            ['2:Kept', '1:Sometimes code'],
            $this->index(
                "## Kept\n\n# Sometimes code\n\nTail.\n",
                "## Kept\n\n```\n# Sometimes code\n```\n\nTail.\n",
            ),
        );
    }

    public function test_closing_hashes_are_left_out_of_the_label(): void
    {
        self::assertSame(
            ['2:Closed'],
            $this->index("## Closed ##\n\nBody.\n", "## Closed ##\n\nBody changed.\n"),
        );
    }
}
