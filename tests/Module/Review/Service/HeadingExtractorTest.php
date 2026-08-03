<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Review\Service\HeadingExtractor;
use App\Module\Review\Service\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class HeadingExtractorTest extends TestCase
{
    public function test_lists_every_heading_in_document_order(): void
    {
        $html = new MarkdownRenderer()->render("# Title\n\nIntro.\n\n## First\n\n### Deeper\n\n## Second\n");

        $headings = new HeadingExtractor()->extract($html);

        self::assertSame(
            [[1, 'heading-title', 'Title'], [2, 'heading-first', 'First'], [3, 'heading-deeper', 'Deeper'], [2, 'heading-second', 'Second']],
            array_map(static fn ($heading): array => [$heading->level, $heading->id, $heading->text], $headings),
        );
    }

    public function test_reads_the_text_through_inline_markup(): void
    {
        $html = new MarkdownRenderer()->render("## Use `render()` *now*\n");

        $headings = new HeadingExtractor()->extract($html);

        self::assertCount(1, $headings);
        self::assertSame('Use render() now', $headings[0]->text);
    }

    public function test_ignores_headings_stored_before_ids_were_emitted(): void
    {
        // Versions rendered by an older MarkdownRenderer have no ids and nothing
        // can link to them; `app:review:rerender-versions` is the way forward.
        $headings = new HeadingExtractor()->extract('<h2>Legacy</h2><p>Body.</p>');

        self::assertSame([], $headings);
    }
}
