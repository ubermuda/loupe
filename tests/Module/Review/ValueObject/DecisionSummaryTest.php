<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\ValueObject;

use App\Module\Review\ValueObject\Decision;
use App\Module\Review\ValueObject\DecisionSummary;
use PHPUnit\Framework\TestCase;

final class DecisionSummaryTest extends TestCase
{
    public function test_a_note_with_no_option_counts_as_an_answer(): void
    {
        $summary = new DecisionSummary(
            [new Decision('chosen', ['A', 'B']), new Decision('noted', ['A', 'B']), new Decision('open', ['A', 'B'])],
            ['chosen' => [1]],
            ['noted' => 'Neither, see below.'],
            [],
        );

        self::assertSame(2, $summary->answeredCount());
        self::assertSame(
            [['chosen', true, ['B']], ['noted', true, []], ['open', false, []]],
            array_map(
                static fn (array $row): array => [$row['label'], $row['answered'], $row['selected']],
                $summary->rows(),
            ),
        );
    }

    public function test_a_note_on_a_block_the_version_does_not_hold_counts_nothing(): void
    {
        $summary = new DecisionSummary([new Decision('open', ['A'])], [], ['gone' => 'Old note.'], []);

        self::assertSame(0, $summary->answeredCount());
        self::assertFalse($summary->rows()[0]['answered']);
    }

    public function test_a_row_takes_its_tag_from_a_d_heading_and_falls_back_to_its_position(): void
    {
        $summary = new DecisionSummary(
            [new Decision('first', ['A']), new Decision('second', ['A']), new Decision('third', ['A']), new Decision('fourth', ['A'])],
            [],
            [],
            ['second' => 'D4: How do we ship?', 'third' => 'Decisions', 'fourth' => 'D12 Rollout'],
        );

        self::assertSame(['D1', 'D4', 'D3', 'D12'], array_column($summary->rows(), 'tag'));
    }

    public function test_a_row_takes_its_title_from_a_d_heading_and_falls_back_to_its_label(): void
    {
        $summary = new DecisionSummary(
            [new Decision('first', ['A'], 'Which store?'), new Decision('second', ['A']), new Decision('third', ['A'], 'Who owns it?')],
            [],
            [],
            ['first' => 'D1: Where the choice is stored', 'second' => 'D2 Rollout', 'third' => 'Decisions'],
        );

        self::assertSame(['Where the choice is stored', 'Rollout', 'Who owns it?'], array_column($summary->rows(), 'title'));
    }

    public function test_a_row_carries_its_note_beside_its_pick(): void
    {
        $summary = new DecisionSummary(
            [new Decision('picked', ['A', 'B']), new Decision('noted', ['A']), new Decision('open', ['A'])],
            ['picked' => [0]],
            ['picked' => 'Only for now.', 'noted' => 'Neither.'],
            [],
        );

        self::assertSame(
            [['A'], 'Only for now.', [], 'Neither.', [], null],
            [
                $summary->rows()[0]['selected'], $summary->rows()[0]['note'],
                $summary->rows()[1]['selected'], $summary->rows()[1]['note'],
                $summary->rows()[2]['selected'], $summary->rows()[2]['note'],
            ],
        );
    }
}
