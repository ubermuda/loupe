<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Service\BucketRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BucketRuleTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function globs(): iterable
    {
        yield 'literal' => ['Read', 'Read', true];
        yield 'literal is whole' => ['Read', 'Read(a)', false];
        yield 'star ends' => ['Bash(git *)', 'Bash(git status)', true];
        yield 'star spans slashes' => ['Read(src/*)', 'Read(src/a/b.php)', true];
        yield 'star spans nothing' => ['Bash(git*)', 'Bash(git)', true];
        yield 'question is one' => ['Bash(g?t)', 'Bash(git)', true];
        yield 'question is not none' => ['Bash(g?t)', 'Bash(gt)', false];
        yield 'brackets are literal' => ['a[b]', 'ab', false];
        yield 'brackets match themselves' => ['a[b]', 'a[b]', true];
        yield 'dot is literal' => ['a.c', 'abc', false];
        yield 'case matters' => ['read', 'Read', false];
        yield 'star spans a newline' => ['Bash(*)', "Bash(a\nb)", true];
        yield 'regex characters are literal' => ['Bash(a|b)+', 'Bash(a)+', false];
    }

    #[DataProvider('globs')]
    public function test_a_pattern_is_a_glob_of_star_and_question(string $pattern, string $signature, bool $expected): void
    {
        self::assertSame($expected, new BucketRule($pattern, 'tests')->matches($signature));
    }

    #[DataProvider('names')]
    public function test_a_bucket_name_is_1_to_64_characters_of_a_to_z_digits_underscore_and_hyphen(string $name, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(\InvalidArgumentException::class);
        }

        $rule = new BucketRule('*', $name);

        self::assertSame($name, $rule->bucket);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function names(): iterable
    {
        yield 'plain' => ['tests', true];
        yield 'all characters' => ['a_b-9', true];
        yield 'longest' => [str_repeat('a', 64), true];
        yield 'too long' => [str_repeat('a', 65), false];
        yield 'empty' => ['', false];
        yield 'upper case' => ['Tests', false];
        yield 'space' => ['a b', false];
        yield 'colon' => ['a:b', false];
        yield 'trailing newline' => ["tests\n", false];
    }
}
