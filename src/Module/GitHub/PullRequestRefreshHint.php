<?php

declare(strict_types=1);

namespace App\Module\GitHub;

/** One delivery fact that can change the state of tracked pull requests. Exactly one field is set. */
final readonly class PullRequestRefreshHint
{
    private function __construct(
        public ?int $number = null,
        public ?string $headSha = null,
        public ?string $baseBranch = null,
    ) {
    }

    public static function number(int $number): self
    {
        return new self(number: $number);
    }

    public static function head(string $sha): self
    {
        return new self(headSha: $sha);
    }

    public static function base(string $branch): self
    {
        return new self(baseBranch: $branch);
    }

    public function key(): string
    {
        return match (true) {
            null !== $this->number => 'number:'.$this->number,
            null !== $this->headSha => 'head:'.$this->headSha,
            default => 'base:'.$this->baseBranch,
        };
    }
}
