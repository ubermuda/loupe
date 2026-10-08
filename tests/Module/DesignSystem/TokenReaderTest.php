<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Module\DesignSystem\Token\TokenReader;
use PHPUnit\Framework\TestCase;

final class TokenReaderTest extends TestCase
{
    private string $path;

    #[\Override]
    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'tokens');
    }

    #[\Override]
    protected function tearDown(): void
    {
        unlink($this->path);
    }

    public function test_it_reads_groups_values_and_uses(): void
    {
        file_put_contents($this->path, <<<'CSS'
            /* @group radius */
            @theme static {
                /* Buttons and inputs. */
                --radius-lg: 0.5rem;
                /* Cards. Also panels. */
                --radius-xl: 0.75rem;
            }

            /* @group type */
            @theme inline {
                --font-sans:
                    'DM Sans', sans-serif;
            }
            CSS);

        $groups = new TokenReader($this->path)->groups();

        self::assertSame(['radius', 'type'], array_map(static fn ($group): string => $group->name, $groups));
        self::assertSame('--radius-lg', $groups[0]->tokens[0]->name);
        self::assertSame('0.5rem', $groups[0]->tokens[0]->value);
        self::assertSame('Buttons and inputs.', $groups[0]->tokens[0]->use);
        self::assertSame('Cards.', $groups[0]->tokens[1]->use);
        self::assertSame("'DM Sans', sans-serif", $groups[1]->tokens[0]->value);
        self::assertSame('', $groups[1]->tokens[0]->use);
    }

    public function test_a_comment_applies_to_its_run_until_a_blank_line(): void
    {
        file_put_contents($this->path, <<<'CSS'
            /* @group colour */
            :root {
                /* Text ladder */
                --ink: #000;
                --text: #111;

                --bg: #fff;
            }
            CSS);

        $tokens = new TokenReader($this->path)->groups()[0]->tokens;

        self::assertSame('Text ladder', $tokens[0]->use);
        self::assertSame('Text ladder', $tokens[1]->use);
        self::assertSame('', $tokens[2]->use);
    }

    public function test_the_colour_group_leaves_out_the_tailwind_aliases(): void
    {
        file_put_contents($this->path, <<<'CSS'
            /* @group colour */
            :root {
                --ink: #000;
            }
            @theme inline {
                --color-ink: var(--ink);
            }
            CSS);

        $tokens = new TokenReader($this->path)->groups()[0]->tokens;

        self::assertSame(['--ink'], array_map(static fn ($token): string => $token->name, $tokens));
    }

    public function test_the_real_stylesheet_has_every_group_with_a_use_on_the_scale_tokens(): void
    {
        $groups = new TokenReader(dirname(__DIR__, 3).'/assets/styles/tokens.css')->groups();
        $byName = [];
        foreach ($groups as $group) {
            $byName[$group->name] = $group;
        }

        self::assertSame(['colour', 'type', 'spacing', 'radius', 'shadow', 'motion'], array_keys($byName));
        foreach (['spacing', 'radius', 'motion'] as $name) {
            foreach ($byName[$name]->tokens as $token) {
                self::assertNotSame('', $token->use, $token->name.' has no use');
            }
        }
    }
}
