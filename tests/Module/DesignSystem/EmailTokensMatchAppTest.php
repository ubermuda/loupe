<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Token\TokenReader;
use PHPUnit\Framework\TestCase;

final class EmailTokensMatchAppTest extends TestCase
{
    private const array TOKENS = ['--ink' => '#20241f', '--radius-lg' => '0.5rem'];

    public function test_the_email_stylesheet_matches_the_tokens(): void
    {
        $root = dirname(__DIR__, 3);
        $tokens = [];
        foreach (new TokenReader($root.'/assets/styles/tokens.css')->groups() as $group) {
            foreach ($group->tokens as $token) {
                $tokens[$token->name] = $token->value;
            }
        }

        $css = file_get_contents($root.'/assets/styles/email.css');
        self::assertIsString($css);
        self::assertStringContainsString('/* --accent */', $css);
        self::assertSame([], self::driftIn($css, $tokens));
    }

    public function test_matching_values_pass(): void
    {
        $css = <<<'CSS'
            h1 {
                color: #20241F; /* --ink */
                border: 1px solid #20241f; /* --ink */
                border-radius: 8px; /* --radius-lg */
                padding: 40px;
            }
            CSS;

        self::assertSame([], self::driftIn($css, self::TOKENS));
    }

    public function test_a_drifted_colour_fails(): void
    {
        self::assertSame(
            ['line 1: #0f0f0d is not --ink (#20241f)'],
            self::driftIn('color: #0f0f0d; /* --ink */', self::TOKENS),
        );
    }

    public function test_a_drifted_length_fails(): void
    {
        self::assertSame(
            ['line 1: 7px is not --radius-lg (0.5rem)'],
            self::driftIn('border-radius: 7px; /* --radius-lg */', self::TOKENS),
        );
    }

    public function test_an_unannotated_hex_fails(): void
    {
        self::assertSame(
            ['line 2: a hex colour names no token'],
            self::driftIn("h1 {\n    color: #20241f;\n}", self::TOKENS),
        );
    }

    public function test_an_unknown_token_fails(): void
    {
        self::assertSame(
            ['line 1: --nope is not a token'],
            self::driftIn('color: #20241f; /* --nope */', self::TOKENS),
        );
    }

    /**
     * @param array<string, string> $tokens
     *
     * @return list<string>
     */
    private static function driftIn(string $css, array $tokens): array
    {
        $errors = [];
        foreach (explode("\n", $css) as $index => $line) {
            $number = $index + 1;
            $annotated = 1 === preg_match('~^\s*[\w-]+\s*:\s*(.+?)\s*;\s*/\*\s*(--[\w-]+)\s*\*/\s*$~', $line, $match);
            if (!$annotated) {
                if (1 === preg_match('/#[0-9a-f]{3,8}\b/i', $line)) {
                    $errors[] = sprintf('line %d: a hex colour names no token', $number);
                }

                continue;
            }

            [, $value, $name] = $match;
            if (!isset($tokens[$name])) {
                $errors[] = sprintf('line %d: %s is not a token', $number, $name);

                continue;
            }

            $expected = $tokens[$name];
            $actual = 1 === preg_match('/#[0-9a-f]{3,8}\b/i', $value, $hex) ? $hex[0] : $value;
            if (!self::same($actual, $expected)) {
                $errors[] = sprintf('line %d: %s is not %s (%s)', $number, $actual, $name, $expected);
            }
        }

        return $errors;
    }

    private static function same(string $actual, string $expected): bool
    {
        if (1 === preg_match('/^([\d.]+)rem$/', $expected, $rem) && 1 === preg_match('/^([\d.]+)px$/', $actual, $px)) {
            return (float) $rem[1] * 16 === (float) $px[1];
        }

        return strtolower($actual) === strtolower($expected);
    }
}
