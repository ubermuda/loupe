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
        $project = new Project(new User('alice', 'Alice A', 'alice@example.com', 'x'), 'My project');

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
}
