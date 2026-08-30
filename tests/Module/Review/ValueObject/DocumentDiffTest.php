<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\ValueObject;

use App\Module\Review\ValueObject\DiffGroup;
use App\Module\Review\ValueObject\DiffLine;
use App\Module\Review\ValueObject\DocumentDiff;
use PHPUnit\Framework\TestCase;

final class DocumentDiffTest extends TestCase
{
    public function test_a_diff_with_no_changes_is_one_unchanged_group(): void
    {
        $diff = new DocumentDiff([DiffLine::unchanged('alpha'), DiffLine::unchanged('beta')]);

        self::assertSame([[false, 2]], self::shape($diff->groups()));
        self::assertSame(0, $diff->changeCount());
    }

    public function test_an_empty_diff_has_no_groups(): void
    {
        $diff = new DocumentDiff([]);

        self::assertSame([], $diff->groups());
        self::assertSame(0, $diff->changeCount());
    }

    public function test_a_diff_opening_on_a_change_starts_with_a_changed_group(): void
    {
        $diff = new DocumentDiff([
            DiffLine::inserted('alpha'),
            DiffLine::unchanged('beta'),
            DiffLine::unchanged('gamma'),
        ]);

        self::assertSame([[true, 1], [false, 2]], self::shape($diff->groups()));
        self::assertSame(1, $diff->changeCount());
    }

    public function test_a_diff_closing_on_a_change_ends_with_a_changed_group(): void
    {
        $diff = new DocumentDiff([
            DiffLine::unchanged('alpha'),
            DiffLine::deleted('beta'),
        ]);

        self::assertSame([[false, 1], [true, 1]], self::shape($diff->groups()));
        self::assertSame(1, $diff->changeCount());
    }

    /**
     * A rewritten line is a deletion followed by an insertion, so a run that
     * mixes the two kinds is still one edit — counting it as two would send the
     * reviewer through the same change twice.
     */
    public function test_adjacent_changed_lines_collapse_into_one_group(): void
    {
        $diff = new DocumentDiff([
            DiffLine::unchanged('alpha'),
            DiffLine::deleted('beta'),
            DiffLine::inserted('gamma'),
            DiffLine::inserted('delta'),
            DiffLine::unchanged('epsilon'),
            DiffLine::deleted('zeta'),
        ]);

        self::assertSame([[false, 1], [true, 3], [false, 1], [true, 1]], self::shape($diff->groups()));
        self::assertSame(2, $diff->changeCount());
    }

    public function test_grouping_preserves_every_line_in_document_order(): void
    {
        $lines = [
            DiffLine::unchanged('alpha'),
            DiffLine::inserted('beta'),
            DiffLine::unchanged('gamma'),
        ];

        $regrouped = [];
        foreach (new DocumentDiff($lines)->groups() as $group) {
            $regrouped = [...$regrouped, ...$group->lines];
        }

        self::assertSame($lines, $regrouped);
    }

    /**
     * @param list<DiffGroup> $groups
     *
     * @return list<array{bool, int}>
     */
    private static function shape(array $groups): array
    {
        return array_map(
            static fn (DiffGroup $group): array => [$group->changed, count($group->lines)],
            $groups,
        );
    }
}
