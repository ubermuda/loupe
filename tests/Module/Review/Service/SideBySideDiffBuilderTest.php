<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Review\Service\SideBySideDiffBuilder;
use App\Module\Review\ValueObject\SideBySideRow;
use PHPUnit\Framework\TestCase;

/**
 * The fixtures are the shape RenderedDiffBuilder leaves behind: a block wrapped
 * in a mark for a whole block added or removed, and a mark inside a block for a
 * reworded phrase.
 */
final class SideBySideDiffBuilderTest extends TestCase
{
    private const string DELETED = 'lp-diff__mark lp-diff__mark--deleted';
    private const string INSERTED = 'lp-diff__mark lp-diff__mark--inserted';

    private SideBySideDiffBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new SideBySideDiffBuilder();
    }

    public function test_an_unchanged_block_fills_both_cells(): void
    {
        $rows = $this->pair('<p>The rollout takes one step.</p>');

        self::assertCount(1, $rows);
        self::assertSame($rows[0]->oldHtml, $rows[0]->newHtml);
        self::assertStringContainsString('The rollout takes one step.', (string) $rows[0]->oldHtml);
    }

    public function test_a_removed_block_leaves_the_new_side_empty(): void
    {
        $rows = $this->pair(\sprintf('<del class="%s"><p>The risk section.</p></del>', self::DELETED));

        self::assertCount(1, $rows);
        self::assertNull($rows[0]->newHtml);
        self::assertStringContainsString('The risk section.', (string) $rows[0]->oldHtml);
    }

    public function test_an_added_block_leaves_the_old_side_empty(): void
    {
        $rows = $this->pair(\sprintf('<ins class="%s"><p>A new caveat.</p></ins>', self::INSERTED));

        self::assertCount(1, $rows);
        self::assertNull($rows[0]->oldHtml);
        self::assertStringContainsString('A new caveat.', (string) $rows[0]->newHtml);
    }

    public function test_inline_marks_split_one_block_across_both_cells(): void
    {
        $rows = $this->pair(\sprintf(
            '<p>The rollout takes <del class="%s">one step</del><ins class="%s">three steps</ins>.</p>',
            self::DELETED,
            self::INSERTED,
        ));

        self::assertCount(1, $rows);
        self::assertSame('The rollout takes one step.', $this->text((string) $rows[0]->oldHtml));
        self::assertSame('The rollout takes three steps.', $this->text((string) $rows[0]->newHtml));
    }

    public function test_a_table_stays_one_row_of_the_pairing(): void
    {
        $rows = $this->pair(\sprintf(
            '<table><thead><tr><th>Environment</th></tr></thead>'
            .'<tbody><tr><td><del class="%s">staging</del><ins class="%s">production</ins></td></tr></tbody></table>',
            self::DELETED,
            self::INSERTED,
        ));

        self::assertCount(1, $rows);
        self::assertStringContainsString('staging', $this->text((string) $rows[0]->oldHtml));
        self::assertStringNotContainsString('production', $this->text((string) $rows[0]->oldHtml));
        self::assertStringContainsString('production', $this->text((string) $rows[0]->newHtml));
        self::assertStringNotContainsString('staging', $this->text((string) $rows[0]->newHtml));
    }

    /** A rule and an image carry no text, and the reader still sees them. */
    public function test_a_block_that_draws_itself_is_not_read_as_empty(): void
    {
        $rows = $this->pair('<hr>');

        self::assertCount(1, $rows);
        self::assertNotNull($rows[0]->oldHtml);
        self::assertNotNull($rows[0]->newHtml);
    }

    public function test_the_rows_keep_document_order(): void
    {
        $rows = $this->pair(
            '<p>Intro.</p>'
            .\sprintf('<del class="%s"><p>Cut section.</p></del>', self::DELETED)
            .\sprintf('<ins class="%s"><p>New section.</p></ins>', self::INSERTED)
            .'<p>Outro.</p>',
        );

        self::assertCount(4, $rows);
        self::assertSame('Intro.', $this->text((string) $rows[0]->newHtml));
        self::assertNull($rows[1]->newHtml);
        self::assertNull($rows[2]->oldHtml);
        self::assertSame('Outro.', $this->text((string) $rows[3]->oldHtml));
    }

    /**
     * Each mark belongs to one side, so the jump targets the rendered pane
     * numbered stay unique once the pane is split into two columns.
     */
    public function test_no_hunk_id_reaches_both_cells(): void
    {
        $rows = $this->pair(
            \sprintf(
                '<p>Takes <del class="%s" id="diff-hunk-1" data-diff-navigation-target="hunk">one</del><ins class="%s">three</ins> steps.</p>',
                self::DELETED,
                self::INSERTED,
            )
            .\sprintf('<del class="%s" id="diff-hunk-2" data-diff-navigation-target="hunk"><p>Cut.</p></del>', self::DELETED),
        );

        self::assertSame(['diff-hunk-1', 'diff-hunk-2'], $this->ids($rows));
    }

    /**
     * An unchanged heading reaches both cells, and its id may only be in the
     * page once, or a fragment lands in whichever column comes first.
     */
    public function test_an_unchanged_block_keeps_its_id_on_the_newer_side_alone(): void
    {
        $rows = $this->pair('<h2 id="heading-risk">Risk</h2><p>Unchanged <span id="inner">run</span>.</p>');

        self::assertSame(
            ['diff-old-heading-risk', 'heading-risk', 'diff-old-inner', 'inner'],
            $this->ids($rows),
        );
    }

    /**
     * A decision block renders inside a diff, and its labels name their controls
     * by id. Renaming one without the other would send a label to the control in
     * the other column, or to none at all.
     */
    public function test_a_reference_to_a_renamed_id_follows_it(): void
    {
        $rows = $this->pair(
            '<fieldset class="lp-decision" aria-describedby="hint">'
            .'<label for="option-a">Ship it</label>'
            .'<input type="radio" id="option-a" disabled>'
            .'<p id="hint">Pick one.</p></fieldset>',
        );

        self::assertStringContainsString('for="diff-old-option-a"', (string) $rows[0]->oldHtml);
        self::assertStringContainsString('id="diff-old-option-a"', (string) $rows[0]->oldHtml);
        self::assertStringContainsString('aria-describedby="diff-old-hint"', (string) $rows[0]->oldHtml);
        self::assertStringContainsString('for="option-a"', (string) $rows[0]->newHtml);
        self::assertStringContainsString('aria-describedby="hint"', (string) $rows[0]->newHtml);
    }

    /**
     * The renderer mints heading ids and keeps a relative href, so a document's
     * own jump link is live. It has to reach its own column, and the heading it
     * names is a block of its own.
     */
    public function test_a_fragment_link_reaches_the_heading_in_its_own_column(): void
    {
        $rows = $this->pair(
            '<p><a href="#heading-risk">Jump</a></p><h2 id="heading-risk">Risk</h2>',
        );

        self::assertStringContainsString('href="#diff-old-heading-risk"', (string) $rows[0]->oldHtml);
        self::assertStringContainsString('href="#heading-risk"', (string) $rows[0]->newHtml);
        self::assertStringContainsString('id="diff-old-heading-risk"', (string) $rows[1]->oldHtml);
    }

    /**
     * @param list<SideBySideRow> $rows
     *
     * @return list<string>
     */
    private function ids(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            preg_match_all('/id="([^"]+)"/', ($row->oldHtml ?? '').($row->newHtml ?? ''), $matches);
            $ids = [...$ids, ...$matches[1]];
        }

        return $ids;
    }

    /** @return list<SideBySideRow> */
    private function pair(string $html): array
    {
        return $this->builder->build($html)->rows;
    }

    private function text(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    }
}
