<?php

declare(strict_types=1);

namespace App\Module\DesignSystem\Token;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads the token stylesheet. A `@group` comment starts a group. A comment
 * above a run of declarations is the use of each token in that run, up to the
 * next blank line. The colour group lists the raw values only, because the
 * `--color-*` entries are Tailwind names for those same values.
 */
final readonly class TokenReader
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/assets/styles/tokens.css')]
        private string $path,
    ) {
    }

    /** @return list<TokenGroup> */
    public function groups(): array
    {
        $source = file_get_contents($this->path);
        if (false === $source) {
            throw new \RuntimeException(sprintf('Cannot read the token stylesheet %s.', $this->path));
        }

        /** @var array<string, list<Token>> $groups */
        $groups = [];
        $group = null;
        $use = '';
        $comment = null;
        $declaration = null;

        foreach (explode("\n", $source) as $line) {
            if (null !== $declaration) {
                $declaration .= ' '.trim($line);
                if (str_contains($line, ';')) {
                    $this->add($groups, $group, $declaration, $use);
                    $declaration = null;
                }

                continue;
            }

            if (null !== $comment) {
                $comment .= ' '.trim($line);
                if (str_contains($line, '*/')) {
                    $use = $this->useOf($comment);
                    $comment = null;
                }

                continue;
            }

            $trimmed = trim($line);
            if (1 === preg_match('~^/\*\s*@group\s+(\w+)\s*\*/$~', $trimmed, $match)) {
                $group = $match[1];
                $groups[$group] ??= [];
                $use = '';
            } elseif (str_starts_with($trimmed, '/*')) {
                if (str_contains($trimmed, '*/')) {
                    $use = $this->useOf($trimmed);
                } else {
                    $comment = $trimmed;
                }
            } elseif (str_starts_with($trimmed, '--')) {
                if (str_contains($trimmed, ';')) {
                    $this->add($groups, $group, $trimmed, $use);
                } else {
                    $declaration = $trimmed;
                }
            } else {
                $use = '';
            }
        }

        $result = [];
        foreach ($groups as $name => $tokens) {
            if ([] !== $tokens) {
                $result[] = new TokenGroup($name, $tokens);
            }
        }

        return $result;
    }

    /** @param array<string, list<Token>> $groups */
    private function add(array &$groups, ?string $group, string $declaration, string $use): void
    {
        if (null === $group || 1 !== preg_match('/^(--[\w-]+)\s*:\s*(.+?);/', $declaration, $match)) {
            return;
        }

        if ('colour' === $group && str_starts_with($match[1], '--color-')) {
            return;
        }

        $groups[$group][] = new Token($match[1], $match[2], $use);
    }

    private function useOf(string $comment): string
    {
        $text = trim(preg_replace('~^/\*|\*/$~', '', trim($comment)) ?? '');
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        $end = strpos($text, '. ');

        return false === $end ? $text : substr($text, 0, $end + 1);
    }
}
