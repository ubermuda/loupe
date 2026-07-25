<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Review\Service\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class MarkdownRendererTest extends TestCase
{
    public function test_renders_markdown_and_strips_dangerous_html(): void
    {
        $html = new MarkdownRenderer()->render("# Title\n\nHello <script>alert(1)</script> world\n\n- a\n- b");

        self::assertStringContainsString('<h1>Title</h1>', $html);
        self::assertStringContainsString('<li>a</li>', $html);
        self::assertStringContainsString('<p>', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('alert(1)', $html);
    }

    public function test_strips_onclick_attributes(): void
    {
        $html = new MarkdownRenderer()->render('<p onclick="alert(2)">hi</p>');

        self::assertStringContainsString('hi', $html);
        self::assertStringNotContainsString('onclick', $html);
    }

    public function test_renders_documents_larger_than_the_sanitizer_default_limit(): void
    {
        // The sanitizer's default 20 000-byte input cap silently truncated long
        // documents; a >20KB document must keep its final section.
        $section = "## Section\n\nSome paragraph text that pads the document out.\n\n";
        $markdown = str_repeat($section, 500)."## The Very Last Heading\n\nfinal-marker-text\n";
        self::assertGreaterThan(20_000, strlen($markdown));

        $html = new MarkdownRenderer()->render($markdown);

        self::assertStringContainsString('The Very Last Heading', $html);
        self::assertStringContainsString('final-marker-text', $html);
    }

    public function test_strips_javascript_links(): void
    {
        $html = new MarkdownRenderer()->render('[click me](javascript:alert(1))');

        self::assertStringContainsString('click me', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }
}
