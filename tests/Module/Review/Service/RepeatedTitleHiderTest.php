<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Review\Service\HeadingExtractor;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\Service\RepeatedTitleHider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\IdentityTranslator;

final class RepeatedTitleHiderTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function repeatedTitles(): iterable
    {
        yield 'the same words' => ['Preview: three answered decisions'];
        yield 'another case' => ['PREVIEW: Three Answered Decisions'];
        yield 'extra whitespace' => ["  Preview:  three answered\tdecisions "];
    }

    #[DataProvider('repeatedTitles')]
    public function test_hides_a_leading_h1_that_repeats_the_title(string $title): void
    {
        $html = $this->render("# Preview: three answered decisions\n\nIntro.\n\n## Next\n");

        [$hidden, $headings] = new RepeatedTitleHider()->hide($html, new HeadingExtractor()->extract($html), $title);

        self::assertStringStartsWith('<h1 id="heading-preview-three-answered-decisions" data-repeated-title>', $hidden);
        self::assertSame(['heading-next'], array_map(static fn ($heading): string => $heading->id, $headings));
    }

    public function test_adds_no_text(): void
    {
        $html = $this->render("# Plan\n\nIntro.\n");

        [$hidden] = new RepeatedTitleHider()->hide($html, new HeadingExtractor()->extract($html), 'Plan');

        self::assertNotSame($html, $hidden);
        self::assertSame(strip_tags($html), strip_tags($hidden));
    }

    public function test_keeps_a_leading_h1_with_other_words(): void
    {
        $html = $this->render("# Rollout\n\nIntro.\n");
        $headings = new HeadingExtractor()->extract($html);

        self::assertSame([$html, $headings], new RepeatedTitleHider()->hide($html, $headings, 'Plan'));
    }

    public function test_keeps_an_h1_that_does_not_lead(): void
    {
        $html = $this->render("Intro.\n\n# Plan\n");
        $headings = new HeadingExtractor()->extract($html);

        self::assertSame([$html, $headings], new RepeatedTitleHider()->hide($html, $headings, 'Plan'));
    }

    public function test_keeps_a_leading_h2(): void
    {
        $html = $this->render("## Plan\n\nIntro.\n");
        $headings = new HeadingExtractor()->extract($html);

        self::assertSame([$html, $headings], new RepeatedTitleHider()->hide($html, $headings, 'Plan'));
    }

    private function render(string $markdown): string
    {
        return new MarkdownRenderer(new NullLogger(), new IdentityTranslator())->render($markdown);
    }
}
