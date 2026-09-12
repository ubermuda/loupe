<?php

declare(strict_types=1);

namespace App\Tests\Utils;

use App\Utils\Slug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function names(): iterable
    {
        yield 'spaces and capitals' => ['In progress', 'in-progress'];
        yield 'accent' => ['Café', 'cafe'];
        yield 'han characters' => ['完了', 'wan-le'];
        yield 'punctuation runs' => ['  --My_App!! 2--  ', 'my-app-2'];
    }

    #[DataProvider('names')]
    public function test_a_name_gives_a_slug_that_matches_the_pattern(string $name, string $expected): void
    {
        $slug = Slug::fromName($name);

        self::assertSame($expected, $slug);
        self::assertMatchesRegularExpression('/'.Slug::PATTERN.'/', $slug);
    }

    public function test_for_name_keeps_a_current_slug_that_fits_the_name(): void
    {
        self::assertSame('my-app', Slug::forName('My App', 'my-app'));
        self::assertSame('my-app-2', Slug::forName('my-app', 'my-app-2'));
    }

    public function test_for_name_replaces_a_missing_or_stale_slug(): void
    {
        self::assertSame('my-app', Slug::forName('My App', null));
        self::assertSame('my-app', Slug::forName('My App', 'old-name'));
        self::assertSame('my-app', Slug::forName('My App', 'my-app-two'));
        self::assertSame('my-app-2', Slug::forName('My App 2', 'my-app'));
        self::assertSame('', Slug::forName('🚀', 'project'));
    }

    public function test_a_name_with_nothing_to_transliterate_gives_an_empty_slug(): void
    {
        self::assertSame('', Slug::fromName('🚀'));
    }
}
