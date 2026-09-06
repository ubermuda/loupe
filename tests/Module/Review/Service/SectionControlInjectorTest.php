<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Service\HeadingExtractor;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\Service\SectionControlInjector;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The injected control must add no text to the pane.
 *
 * Every comment anchor is an offset into DocumentVersion::plainText(), and every
 * section digest is a slice of the same string. One character of label inside
 * the pane moves every anchor below the first heading and invalidates the very
 * approvals the control records.
 */
final class SectionControlInjectorTest extends KernelTestCase
{
    private const string MARKDOWN = <<<'MD'
        Preamble before any heading.

        ## Alpha

        Alpha body with a `code span` and an *emphasis*.

        ### Nested

        Nested body.

        ## Beta

        | Column | Other |
        | --- | --- |
        | one | two |
        MD;

    /** Shaped like the real control: a form, hidden inputs, an icon-only button. */
    private const string CONTROL = '<form class="lp-section-approve" method="POST" action="/x">'
        .'<input type="hidden" name="_csrf_token" value="abc">'
        .'<button type="submit" aria-label="Approve the section Alpha" aria-pressed="false">'
        .'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="none" d="M20 6L9 17l-5-5"/></svg>'
        .'</button></form>';

    public function test_the_plain_text_is_byte_identical_with_and_without_the_controls(): void
    {
        $html = $this->render(self::MARKDOWN);
        $headings = new HeadingExtractor()->extract($html);
        self::assertCount(3, $headings, 'the fixture must carry several headings for this to mean anything');

        $controls = [];
        foreach ($headings as $heading) {
            $controls[$heading->id] = self::CONTROL;
        }

        $injected = new SectionControlInjector()->inject($html, $controls);

        self::assertNotSame($html, $injected, 'the controls must actually have been injected');
        self::assertSame(
            DocumentVersion::plainTextOf($html),
            DocumentVersion::plainTextOf($injected),
            'the control added text to the pane, which moves every anchor below the first heading',
        );
    }

    /**
     * The guard above passes for the wrong reason if the injector silently does
     * nothing, so this proves the assertion can fail: a control carrying one
     * word of label breaks byte identity.
     */
    public function test_a_control_carrying_a_text_label_breaks_the_basis(): void
    {
        $html = $this->render(self::MARKDOWN);
        $labelled = str_replace('</button></form>', 'Approve</button></form>', self::CONTROL);

        $injected = new SectionControlInjector()->inject($html, ['heading-alpha' => $labelled]);

        self::assertNotSame(DocumentVersion::plainTextOf($html), DocumentVersion::plainTextOf($injected));
    }

    public function test_a_heading_with_no_control_is_left_exactly_as_it_was(): void
    {
        $html = $this->render(self::MARKDOWN);

        $injected = new SectionControlInjector()->inject($html, ['heading-alpha' => self::CONTROL]);

        self::assertStringContainsString('<div class="lp-section-head"><h2 id="heading-alpha">', $injected);
        self::assertStringContainsString('<h2 id="heading-beta">Beta</h2>', $injected);
        self::assertStringNotContainsString('<div class="lp-section-head"><h2 id="heading-beta">', $injected);
    }

    public function test_an_empty_control_set_returns_the_html_unchanged(): void
    {
        $html = $this->render(self::MARKDOWN);

        self::assertSame($html, new SectionControlInjector()->inject($html, []));
    }

    private function render(string $markdown): string
    {
        $renderer = self::getContainer()->get(MarkdownRenderer::class);
        self::assertInstanceOf(MarkdownRenderer::class, $renderer);

        return $renderer->render($markdown);
    }
}
