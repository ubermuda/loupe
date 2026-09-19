<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\ShortShaExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShortShaExtensionTest extends TestCase
{
    public function test_a_full_git_sha_shortens_to_eight_characters(): void
    {
        self::assertSame('03cc8ba4', new ShortShaExtension()->shortSha('03cc8ba4f1e2d3c4b5a6978877665544332211aa'));
    }

    public function test_an_upper_case_sha_shortens_too(): void
    {
        self::assertSame('03CC8BA4', new ShortShaExtension()->shortSha('03CC8BA4F1E2D3C4B5A6978877665544332211AA'));
    }

    #[DataProvider('notASha')]
    public function test_anything_else_passes_through(string $value): void
    {
        self::assertSame($value, new ShortShaExtension()->shortSha($value));
    }

    /** @return iterable<string, array{string}> */
    public static function notASha(): iterable
    {
        yield 'a version number' => ['1.4.2'];
        yield 'an empty string' => [''];
        yield 'a short sha' => ['03cc8ba4'];
        yield 'forty characters that are not hex' => [str_repeat('z', 40)];
        yield 'forty-one hex characters' => [str_repeat('a', 41)];
    }
}
