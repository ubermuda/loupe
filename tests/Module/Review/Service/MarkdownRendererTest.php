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
}
