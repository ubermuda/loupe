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
        $summary = new DecisionSummary([new Decision('open', ['A'])], [], ['gone' => 'Old note.']);

        self::assertSame(0, $summary->answeredCount());
        self::assertFalse($summary->rows()[0]['answered']);
    }
}
