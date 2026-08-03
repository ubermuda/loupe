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
            'levels, ids and text, in document order',
        );
    }

    public function test_reads_the_text_through_inline_markup(): void
    {
        $html = new MarkdownRenderer()->render("## Use `render()` *now*\n");

        $headings = new HeadingExtractor()->extract($html);

        self::assertCount(1, $headings);
        self::assertSame('Use render() now', $headings[0]->text);
    }

    public function test_reports_each_heading_offset_into_the_anchor_basis(): void
    {
        $markdown = "Intro paragraph.\n\n## First\n\nBody.\n\n## Second\n";
        $html = new MarkdownRenderer()->render($markdown);
        $plainText = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $headings = new HeadingExtractor()->extract($html);

        // The offset must address the heading in the same string comment anchors are
        // measured against, which is what a structural anchor would resolve through.
        foreach ($headings as $heading) {
            self::assertSame(
                $heading->text,
                mb_substr($plainText, $heading->offset, mb_strlen($heading->text)),
            );
        }
        // "Intro paragraph.\n" then "First\nBody.\n" before the second heading.
        self::assertSame([17, 29], array_map(static fn ($heading): int => $heading->offset, $headings));
    }

    public function test_reads_the_id_whatever_order_the_attributes_arrive_in(): void
    {
        // A renderer that later emits a second heading attribute must not make the
        // whole table of contents silently vanish.
        $headings = new HeadingExtractor()->extract('<h2 data-x="1" id="heading-later">Later</h2>');

        self::assertCount(1, $headings);
        self::assertSame('heading-later', $headings[0]->id);
    }

    public function test_ignores_headings_stored_before_ids_were_emitted(): void
    {
        // Versions rendered by an older MarkdownRenderer have no ids and nothing
        // can link to them; `app:review:rerender-versions` is the way forward.
        $headings = new HeadingExtractor()->extract('<h2>Legacy</h2><p>Body.</p>');

        self::assertSame([], $headings);
    }
}
