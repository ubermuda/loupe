<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Review\Service\DiffMarkdownComposer;
use App\Module\Review\Service\MarkdownDiffer;
use App\Module\Review\Service\MarkdownRenderer;
use App\Module\Review\ValueObject\DocumentDiff;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Assertions are on the rendered HTML rather than the merged Markdown, since
 * what the merge is for is what CommonMark makes of it.
 */
final class DiffMarkdownComposerTest extends TestCase
{
    private MarkdownDiffer $differ;
    private MarkdownRenderer $renderer;
    private DiffMarkdownComposer $composer;

    protected function setUp(): void
    {
        $this->differ = new MarkdownDiffer();
        $this->renderer = new MarkdownRenderer(new NullLogger());
        $this->composer = new DiffMarkdownComposer();
    }

    public function test_a_reworded_paragraph_is_marked_inline(): void
    {
        $html = $this->renderDiff("Intro.\n\nThe old word here.\n", "Intro.\n\nThe new word here.\n");

        self::assertStringContainsString(
            '<p>The '.$this->del('old').$this->ins('new').' word here.</p>',
            $html,
        );
        self::assertStringContainsString('<p>Intro.</p>', $html);
    }

    public function test_a_reworded_heading_stays_a_heading(): void
    {
        $html = $this->renderDiff("## Old heading\n\nBody.\n", "## New heading\n\nBody.\n");

        self::assertStringContainsString(
            '<h2 id="heading-oldnew-heading">'.$this->del('Old').$this->ins('New').' heading</h2>',
            $html,
        );
    }

    public function test_a_mark_never_covers_the_marker_that_opens_a_block(): void
    {
        $html = $this->renderDiff("## Title\n\nBody.\n", "### Title\n\nBody.\n");

        self::assertStringContainsString('<h2 id="heading-title">'.$this->del('Title').'</h2>', $html);
        self::assertStringContainsString('<h3 id="heading-title-2">'.$this->ins('Title').'</h3>', $html);
    }

    public function test_a_removed_paragraph_becomes_a_marked_block(): void
    {
        $html = $this->renderDiff("One.\n\nTwo.\n\nThree.\n", "One.\n\nThree.\n");

        self::assertStringContainsString(
            '<del class="lp-diff__mark lp-diff__mark--deleted">'."\n".'<p>Two.</p>'."\n".'</del>',
            $html,
        );
        self::assertStringContainsString('<p>One.</p>', $html);
        self::assertStringContainsString('<p>Three.</p>', $html);
    }

    public function test_an_added_paragraph_becomes_a_marked_block(): void
    {
        $html = $this->renderDiff("One.\n\nThree.\n", "One.\n\nTwo.\n\nThree.\n");

        self::assertStringContainsString(
            '<ins class="lp-diff__mark lp-diff__mark--inserted">'."\n".'<p>Two.</p>'."\n".'</ins>',
            $html,
        );
    }

    public function test_a_changed_list_item_is_marked_inside_its_own_bullet(): void
    {
        $html = $this->renderDiff("- alpha\n- beta\n- gamma\n", "- alpha\n- delta\n- gamma\n");

        self::assertStringContainsString('<li>alpha</li>', $html);
        self::assertStringContainsString('<li>'.$this->del('beta').$this->ins('delta').'</li>', $html);
        self::assertStringContainsString('<li>gamma</li>', $html);
    }

    public function test_a_removed_list_item_keeps_the_list_intact(): void
    {
        $html = $this->renderDiff("- alpha\n- beta\n- gamma\n", "- alpha\n- gamma\n");

        self::assertStringContainsString('<li>'.$this->del('beta').'</li>', $html);
        self::assertSame(3, substr_count($html, '<li>'));
    }

    public function test_a_changed_table_cell_is_marked_inside_the_cell(): void
    {
        $html = $this->renderDiff(
            "| a | b |\n|---|---|\n| 1 | 2 |\n",
            "| a | b |\n|---|---|\n| 1 | 3 |\n",
        );

        self::assertStringContainsString('<td>'.$this->del('2').$this->ins('3').'</td>', $html);
    }

    public function test_a_removed_table_row_marks_each_cell_separately(): void
    {
        $html = $this->renderDiff(
            "| a | b |\n|---|---|\n| 1 | 2 |\n| 3 | 4 |\n",
            "| a | b |\n|---|---|\n| 1 | 2 |\n",
        );

        // A single mark across the row would open in one cell and close in another.
        self::assertStringContainsString('<td>'.$this->del('3 ').'</td>', $html);
        self::assertStringContainsString('<td>'.$this->del(' 4 ').'</td>', $html);
    }

    public function test_a_changed_fenced_block_is_shown_whole_rather_than_marked_inside(): void
    {
        $html = $this->renderDiff(
            "```php\n\$a = 1;\n\n\$b = 1;\n```\n",
            "```php\n\$a = 2;\n\n\$b = 1;\n```\n",
        );

        self::assertStringNotContainsString('&lt;del', $html);
        self::assertStringNotContainsString('&lt;ins', $html);
        self::assertStringContainsString(
            '<del class="lp-diff__mark lp-diff__mark--deleted">'."\n".'<pre><code class="language-php">$a &#61; 1;',
            $html,
        );
        self::assertStringContainsString(
            '<ins class="lp-diff__mark lp-diff__mark--inserted">'."\n".'<pre><code class="language-php">$a &#61; 2;',
            $html,
        );
    }

    public function test_a_blank_line_inside_a_fence_does_not_split_the_block(): void
    {
        $merged = $this->compose(
            "```\none\n\ntwo\n```\n",
            "```\none\n\nthree\n```\n",
        );

        self::assertSame(1, substr_count($merged, '<del datetime='));
        self::assertSame(1, substr_count($merged, '<ins datetime='));
    }

    public function test_a_changed_indented_code_block_is_shown_whole(): void
    {
        $html = $this->renderDiff(
            "Text.\n\n    \$a = 1;\n    \$b = 1;\n",
            "Text.\n\n    \$a = 2;\n    \$b = 1;\n",
        );

        self::assertStringNotContainsString('&lt;del', $html);
        self::assertStringContainsString(
            '<del class="lp-diff__mark lp-diff__mark--deleted">'."\n".'<pre><code>$a &#61; 1;',
            $html,
        );
    }

    public function test_changed_front_matter_shows_the_new_values_unmarked(): void
    {
        $html = $this->renderDiff("---\ntitle: A\n---\n\nBody.\n", "---\ntitle: B\n---\n\nBody.\n");

        self::assertStringContainsString('<td>B</td>', $html);
        self::assertStringNotContainsString('<td>A</td>', $html);
        self::assertStringNotContainsString('lp-diff__mark', $html);
    }

    public function test_content_deleted_alongside_added_front_matter_is_still_shown(): void
    {
        $html = $this->renderDiff("Intro.\n\nBody.\n", "---\ntitle: B\n---\n\nBody.\n");

        self::assertStringContainsString('<td>B</td>', $html);
        self::assertStringContainsString(
            '<del class="lp-diff__mark lp-diff__mark--deleted">'."\n".'<p>Intro.</p>'."\n".'</del>',
            $html,
        );
    }

    public function test_a_side_with_more_lines_than_the_other_marks_every_line(): void
    {
        $html = $this->renderDiff("A one.\nB two.\nC three.\n\nEnd.\n", "Z nine.\n\nEnd.\n");

        self::assertSame(3, substr_count($html, 'lp-diff__mark--deleted'));
        self::assertSame(1, substr_count($html, 'lp-diff__mark--inserted'));
        self::assertStringContainsString($this->del('A one.'), $html);
        self::assertStringContainsString($this->ins('Z nine.'), $html);
        self::assertStringContainsString('<p>End.</p>', $html);
    }

    public function test_a_documents_own_ins_and_del_carry_no_diff_class(): void
    {
        $html = $this->renderDiff(
            "A <del>struck</del> word and <ins>added</ins>.\n",
            "A <del>struck</del> phrase and <ins>added</ins>.\n",
        );

        self::assertStringContainsString('<del>struck</del>', $html);
        self::assertStringContainsString('<ins>added</ins>', $html);
        self::assertSame(2, substr_count($html, 'lp-diff__mark '));
    }

    public function test_a_documents_own_datetime_cannot_pass_for_a_diff_mark(): void
    {
        $html = $this->renderDiff(
            "A <del datetime=\"2020-01-01\">x</del> word.\n",
            "A <del datetime=\"2020-01-01\">x</del> phrase.\n",
        );

        self::assertStringContainsString('<del datetime="2020-01-01">x</del>', $html);
        self::assertSame(1, substr_count($html, 'lp-diff__mark--deleted'));
    }

    public function test_the_diff_nonce_never_reaches_the_output(): void
    {
        $html = $this->renderDiff("# Title\n\nOld body.\n", "# Title\n\nNew body.\n");

        self::assertStringNotContainsString('loupe-diff-', $html);
        self::assertStringNotContainsString('datetime=', $html);
    }

    public function test_a_heading_kept_across_versions_does_not_collide_with_its_removed_twin(): void
    {
        $html = $this->renderDiff(
            "# Notes\n\n## Same\n\nA.\n\n## Same\n\nB.\n",
            "# Notes\n\n## Same\n\nB.\n",
        );

        self::assertStringContainsString('<h2 id="heading-same">Same</h2>', $html);
        self::assertStringContainsString('<h2 id="heading-same-2">Same</h2>', $html);
    }

    public function test_an_ordinary_render_is_untouched_by_the_diff_path(): void
    {
        $html = $this->renderer->render("# Title\n\nA <del>struck</del> word.\n");

        self::assertStringNotContainsString('lp-diff', $html);
        self::assertStringContainsString('<del>struck</del>', $html);
    }

    public function test_an_unchanged_document_renders_with_no_marks_at_all(): void
    {
        $source = "# Title\n\nBody.\n\n- one\n- two\n";

        self::assertSame(
            $this->renderer->render($source),
            $this->renderDiff($source, $source),
        );
    }

    public function test_a_changed_link_destination_stays_a_link(): void
    {
        $html = $this->renderDiff(
            "See [site](https://old.example) now.\n",
            "See [site](https://new.example) now.\n",
        );

        self::assertStringContainsString('<a href="https://old.example">site</a>', $html);
        self::assertStringContainsString('<a href="https://new.example">site</a>', $html);
        self::assertStringNotContainsString('[site]', $html);
    }

    public function test_a_changed_link_label_is_still_marked_inside_the_link(): void
    {
        $html = $this->renderDiff(
            "See [old site](https://a.example) now.\n",
            "See [new site](https://a.example) now.\n",
        );

        self::assertStringContainsString(
            '<a href="https://a.example">'.$this->del('old').$this->ins('new').' site</a>',
            $html,
        );
    }

    public function test_a_changed_link_title_stays_a_link(): void
    {
        $html = $this->renderDiff(
            "See [s](https://a.example \"Old\") now.\n",
            "See [s](https://a.example \"New\") now.\n",
        );

        self::assertStringContainsString('<a href="https://a.example" title="Old">s</a>', $html);
        self::assertStringContainsString('<a href="https://a.example" title="New">s</a>', $html);
    }

    public function test_a_changed_code_span_stays_a_code_span(): void
    {
        $html = $this->renderDiff("Call `oldName()` first.\n", "Call `newName()` first.\n");

        self::assertStringContainsString('<code>oldName()</code>', $html);
        self::assertStringContainsString('<code>newName()</code>', $html);
    }

    public function test_a_change_beside_a_code_span_is_still_marked_inline(): void
    {
        $html = $this->renderDiff("Call `same()` first here.\n", "Call `same()` second here.\n");

        self::assertStringContainsString(
            '<code>same()</code> '.$this->del('first').$this->ins('second').' here.',
            $html,
        );
    }

    public function test_a_changed_image_stays_an_image(): void
    {
        $html = $this->renderDiff("![old alt](same.png)\n", "![new alt](same.png)\n");

        // An image's label renders into `alt`, where a mark would be escaped.
        self::assertStringContainsString('alt="old alt"', $html);
        self::assertStringContainsString('alt="new alt"', $html);
        self::assertStringNotContainsString('loupe-diff-', $html);
    }

    public function test_a_changed_reference_label_is_still_marked_inside_the_link(): void
    {
        $html = $this->renderDiff(
            "Read [the old spec][spec].\n\n[spec]: https://a.example\n",
            "Read [the new spec][spec].\n\n[spec]: https://a.example\n",
        );

        self::assertStringContainsString(
            '<a href="https://a.example">the '.$this->del('old').$this->ins('new').' spec</a>',
            $html,
        );
    }

    public function test_a_changed_reference_definition_keeps_the_reference_resolving(): void
    {
        $html = $this->renderDiff(
            "Read [it][spec].\n\n[spec]: https://old.example\n",
            "Read [it][spec].\n\n[spec]: https://new.example\n",
        );

        // A marked definition stops being one, and the reference naming it would
        // then render as its own brackets.
        self::assertStringContainsString('<a href="https://new.example">it</a>', $html);
        self::assertStringNotContainsString('[spec]', $html);
    }

    public function test_a_changed_autolink_stays_a_link(): void
    {
        $html = $this->renderDiff("Try <https://old.example> now.\n", "Try <https://new.example> now.\n");

        self::assertStringContainsString('<a href="https://old.example">https://old.example</a>', $html);
        self::assertStringContainsString('<a href="https://new.example">https://new.example</a>', $html);
    }

    public function test_changed_emphasis_delimiters_still_emphasise(): void
    {
        $html = $this->renderDiff("This *word* here.\n", "This **word** here.\n");

        self::assertStringContainsString('<em>word</em>', $html);
        self::assertStringContainsString('<strong>word</strong>', $html);
    }

    public function test_a_change_inside_emphasis_is_still_marked_inline(): void
    {
        $html = $this->renderDiff("This **bold** here.\n", "This **bald** here.\n");

        self::assertStringContainsString(
            '<strong>'.$this->del('bold').$this->ins('bald').'</strong>',
            $html,
        );
    }

    public function test_a_changed_raw_html_tag_is_not_cut_in_half(): void
    {
        $html = $this->renderDiff(
            "A <span title=\"old\">x</span> here.\n",
            "A <span title=\"new\">x</span> here.\n",
        );

        self::assertSame(2, substr_count($html, '<span>x</span>'));
        self::assertStringNotContainsString('&lt;span', $html);
    }

    public function test_changed_backslash_escapes_stay_escapes(): void
    {
        $html = $this->renderDiff("Literal \\*x\\* here.\n", "Literal \\_x\\_ here.\n");

        self::assertStringContainsString($this->del('Literal *x* here.'), $html);
        self::assertStringContainsString($this->ins('Literal _x_ here.'), $html);
        self::assertStringNotContainsString('<em>', $html);
    }

    public function test_a_changed_link_inside_a_table_cell_stays_a_link(): void
    {
        $html = $this->renderDiff(
            "| a | b |\n|---|---|\n| 1 | [s](https://old.example) |\n",
            "| a | b |\n|---|---|\n| 1 | [s](https://new.example) |\n",
        );

        self::assertStringContainsString('<a href="https://old.example">s</a>', $html);
        self::assertStringContainsString('<a href="https://new.example">s</a>', $html);
    }

    public function test_an_escaped_pipe_survives_a_marked_row(): void
    {
        $html = $this->renderDiff(
            "| a | b |\n|---|---|\n| 1 | 2 |\n| x \\| y | z |\n",
            "| a | b |\n|---|---|\n| 1 | 2 |\n",
        );

        // Splitting the mark at the escaped pipe would cut the escape in half.
        self::assertStringContainsString('<td>'.$this->del('x | y ').'</td>', $html);
        self::assertStringContainsString('<td>'.$this->del(' z ').'</td>', $html);
    }

    private function renderDiff(string $old, string $new): string
    {
        return $this->renderer->renderDiff($this->diff($old, $new));
    }

    private function compose(string $old, string $new): string
    {
        return $this->composer->compose($this->diff($old, $new), 'nonce');
    }

    private function diff(string $old, string $new): DocumentDiff
    {
        $diff = $this->differ->diff($old, $new);
        self::assertInstanceOf(DocumentDiff::class, $diff);

        return $diff;
    }

    private function del(string $text): string
    {
        return '<del class="lp-diff__mark lp-diff__mark--deleted">'.$text.'</del>';
    }

    private function ins(string $text): string
    {
        return '<ins class="lp-diff__mark lp-diff__mark--inserted">'.$text.'</ins>';
    }
}
