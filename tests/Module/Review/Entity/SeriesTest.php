<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Entity;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Series;
use PHPUnit\Framework\TestCase;

final class SeriesTest extends TestCase
{
    public function test_the_name_is_lowercased_and_trimmed_on_construction(): void
    {
        $project = new Project(new User('Alice A', 'alice@example.com', 'x'), 'My project');

        self::assertSame('rust atomics', new Series($project, '  Rust Atomics  ')->name);
        self::assertSame('rust atomics', new Series($project, "Rust\tAtomics")->name);
    }

    public function test_normalize_name_is_what_the_constructor_applies(): void
    {
        self::assertSame('rust atomics', Series::normalizeName(' Rust  Atomics '));
        self::assertSame('', Series::normalizeName('   '));
        self::assertSame('écriture', Series::normalizeName('Écriture'));
    }

    public function test_a_complete_placement_normalises_to_a_name_and_its_ordinal(): void
    {
        self::assertSame(['blog series', 5], Series::normalizePlacement('  Blog Series ', 5));
    }

    public function test_no_series_and_no_ordinal_is_the_absence_of_a_placement(): void
    {
        self::assertSame([null, null], Series::normalizePlacement(null, null));
        // A blank name is how a caller takes a document out of its series.
        self::assertSame([null, null], Series::normalizePlacement('   ', null));
    }

    public function test_an_ordinal_without_a_name_is_rejected(): void
    {
        $this->expectExceptionObject(new DomainErrors(['series' => 'review.series.error.name_required']));

        Series::normalizePlacement(null, 3);
    }

    public function test_a_name_without_an_ordinal_is_rejected(): void
    {
        $this->expectExceptionObject(new DomainErrors(['seriesOrdinal' => 'review.series.error.ordinal_required']));

        Series::normalizePlacement('blog series', null);
    }

    public function test_an_ordinal_below_one_is_rejected(): void
    {
        $this->expectExceptionObject(new DomainErrors(['seriesOrdinal' => 'review.series.error.ordinal_not_positive']));

        Series::normalizePlacement('blog series', 0);
    }

    public function test_an_over_long_name_is_rejected_before_postgres_sees_it(): void
    {
        $this->expectExceptionObject(new DomainErrors(['series' => 'review.series.error.too_long']));

        Series::normalizePlacement(str_repeat('a', Series::MAX_NAME_LENGTH + 1), 1);
    }
}
