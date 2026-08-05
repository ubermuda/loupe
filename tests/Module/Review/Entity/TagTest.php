<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Entity;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Tag;
use PHPUnit\Framework\TestCase;

final class TagTest extends TestCase
{
    public function test_the_name_is_lowercased_and_trimmed_on_construction(): void
    {
        $project = new Project(new User('Alice A', 'alice@example.com', 'x'), 'My project');

        self::assertSame('design', new Tag($project, '  Design  ')->name);
        self::assertSame('design spec', new Tag($project, 'DESIGN SPEC')->name);
    }

    public function test_normalize_name_is_what_the_constructor_applies(): void
    {
        self::assertSame('design', Tag::normalizeName(' DeSiGn '));
        self::assertSame('', Tag::normalizeName('   '));
        // Non-ASCII must fold too, or "Écriture" and "écriture" become two rows.
        self::assertSame('écriture', Tag::normalizeName('Écriture'));
    }

    public function test_interior_whitespace_collapses_so_a_typo_is_not_a_second_tag(): void
    {
        self::assertSame('design spec', Tag::normalizeName('design  spec'));
        self::assertSame('design spec', Tag::normalizeName("design\tspec"));
        self::assertSame('design spec', Tag::normalizeName("design \n spec"));
    }

    public function test_unicode_whitespace_normalises_away_rather_than_becoming_a_tag(): void
    {
        $nonBreakingSpace = "\u{00a0}";

        self::assertSame('', Tag::normalizeName($nonBreakingSpace));
        self::assertSame('design spec', Tag::normalizeName('design'.$nonBreakingSpace.'spec'));
    }

    public function test_a_punctuation_only_name_is_allowed(): void
    {
        // "c++" and "v2" are names somebody means; only whitespace is noise.
        self::assertSame('c++', Tag::normalizeName('C++'));
    }
}
