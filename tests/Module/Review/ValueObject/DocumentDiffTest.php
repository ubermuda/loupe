<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\ValueObject;

use App\Module\Review\ValueObject\DiffKind;
use App\Module\Review\ValueObject\DiffLine;
use App\Module\Review\ValueObject\DiffSegment;
use App\Module\Review\ValueObject\DocumentDiff;
use PHPUnit\Framework\TestCase;

final class DocumentDiffTest extends TestCase
{
    public function test_a_diff_of_unchanged_lines_holds_no_change(): void
    {
        $diff = new DocumentDiff([DiffLine::unchanged('alpha'), DiffLine::unchanged('beta')]);

        self::assertFalse($diff->hasChanges());
    }

    public function test_an_empty_diff_holds_no_change(): void
    {
        self::assertFalse(new DocumentDiff([])->hasChanges());
    }

    public function test_one_changed_line_makes_the_diff_a_change(): void
    {
        $diff = new DocumentDiff([DiffLine::unchanged('alpha'), DiffLine::deleted('beta')]);

        self::assertTrue($diff->hasChanges());
    }

    /**
     * A rewritten line appears once per side, so reading one side back means
     * keeping the lines of that side and, within them, the segments of that
     * side. Taking whole lines or whole segments alone gives neither version.
     */
    public function test_each_side_reads_back_as_its_own_source(): void
    {
        $diff = new DocumentDiff([
            DiffLine::unchanged('# Plan'),
            DiffLine::unchanged(''),
            new DiffLine(DiffKind::Deleted, [
                new DiffSegment(DiffKind::Unchanged, 'We ship in '),
                new DiffSegment(DiffKind::Deleted, 'one step'),
                new DiffSegment(DiffKind::Unchanged, '.'),
            ]),
            new DiffLine(DiffKind::Inserted, [
                new DiffSegment(DiffKind::Unchanged, 'We ship in '),
                new DiffSegment(DiffKind::Inserted, 'three steps'),
                new DiffSegment(DiffKind::Unchanged, '.'),
            ]),
        ]);

        self::assertSame("# Plan\n\nWe ship in one step.", $diff->oldSource());
        self::assertSame("# Plan\n\nWe ship in three steps.", $diff->newSource());
    }
}
