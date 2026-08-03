<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\ValueObject;

use App\Module\Review\ValueObject\Decision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecisionTest extends TestCase
{
    /**
     * The whole table, because the two inputs each fix a case the other breaks
     * and it is the interaction that has to hold: label alone collapses two
     * options that read the same, index alone survives a reorder that moved
     * them.
     *
     * @param list<string> $options
     */
    #[DataProvider('resolutions')]
    public function test_a_recorded_answer_resolves_to_the_option_the_reviewer_chose(
        array $options,
        string $recordedLabel,
        int $recordedIndex,
        ?int $expected,
    ): void {
        self::assertSame($expected, new Decision('d', $options)->resolveIndex($recordedLabel, $recordedIndex));
    }

    /**
     * @return iterable<string, array{list<string>, string, int, int|null}>
     */
    public static function resolutions(): iterable
    {
        yield 'unchanged block' => [['A', 'B'], 'B', 1, 1];
        // The recorded index now names a different option, so the label leads.
        yield 'reordered block' => [['B', 'A'], 'B', 1, 0];
        yield 'option inserted above' => [['X', 'A', 'B'], 'B', 1, 2];
        // The recorded index still names a matching label, so it breaks the tie
        // rather than collapsing onto the first match.
        yield 'duplicate labels, second chosen' => [['A', 'A'], 'A', 1, 1];
        yield 'duplicate labels, first chosen' => [['A', 'A'], 'A', 0, 0];
        yield 'duplicate labels and a reorder' => [['B', 'A', 'A'], 'A', 1, 1];
        yield 'chosen option removed' => [['X', 'Y'], 'B', 1, null];
        yield 'recorded index now out of range' => [['A'], 'A', 3, 0];
        yield 'no options at all' => [[], 'A', 0, null];
    }
}
