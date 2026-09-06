<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Review\Service\HeadingExtractor;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\Service\SectionHasher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SectionHasherTest extends KernelTestCase
{
    public function test_a_section_runs_to_the_next_heading_whatever_its_level(): void
    {
        $html = $this->render("## Alpha\n\nAlpha body.\n\n### Nested\n\nNested body.\n\n## Beta\n\nBeta body.\n");

        $hashes = $this->hashes($html);

        self::assertSame(['heading-alpha', 'heading-nested', 'heading-beta'], array_keys($hashes));

        // A deeper heading closes the section above it, so editing the nested
        // body must not disturb the section that precedes it.
        $edited = $this->render("## Alpha\n\nAlpha body.\n\n### Nested\n\nNested body changed.\n\n## Beta\n\nBeta body.\n");
        $editedHashes = $this->hashes($edited);

        self::assertSame($hashes['heading-alpha'], $editedHashes['heading-alpha']);
        self::assertSame($hashes['heading-beta'], $editedHashes['heading-beta']);
        self::assertNotSame($hashes['heading-nested'], $editedHashes['heading-nested']);
    }

    public function test_the_last_section_runs_to_the_end_of_the_text(): void
    {
        $before = $this->hashes($this->render("## Alpha\n\nAlpha body.\n\n## Omega\n\nFirst line.\n"));
        $after = $this->hashes($this->render("## Alpha\n\nAlpha body.\n\n## Omega\n\nFirst line.\n\nSecond line.\n"));

        self::assertSame($before['heading-alpha'], $after['heading-alpha']);
        self::assertNotSame($before['heading-omega'], $after['heading-omega']);
    }

    public function test_text_before_the_first_heading_belongs_to_no_section(): void
    {
        $hashes = $this->hashes($this->render("Preamble.\n\n## Alpha\n\nAlpha body.\n"));

        self::assertSame(['heading-alpha'], array_keys($hashes));

        // A preamble edit changes the plain text but no section, so nothing moves.
        $edited = $this->hashes($this->render("Preamble rewritten.\n\n## Alpha\n\nAlpha body.\n"));
        self::assertSame($hashes['heading-alpha'], $edited['heading-alpha']);
    }

    /**
     * The digest covers the plain-text slice at the heading's offset, never
     * DocumentHeading::$text. Punctuation drops out of the slug, so these two
     * headings share an id and must still hash differently.
     */
    public function test_an_edit_inside_the_heading_changes_its_digest(): void
    {
        $before = $this->hashes($this->render("## Alpha!\n\nBody.\n"));
        $after = $this->hashes($this->render("## Alpha?\n\nBody.\n"));

        self::assertSame(['heading-alpha'], array_keys($before));
        self::assertSame(['heading-alpha'], array_keys($after));
        self::assertNotSame($before['heading-alpha'], $after['heading-alpha']);
    }

    private function render(string $markdown): string
    {
        $renderer = self::getContainer()->get(MarkdownRenderer::class);
        self::assertInstanceOf(MarkdownRenderer::class, $renderer);

        return $renderer->render($markdown);
    }

    /** @return array<string, string> */
    private function hashes(string $html): array
    {
        $headings = new HeadingExtractor();

        return new SectionHasher()->hashes($html, $headings->extract($html));
    }
}
