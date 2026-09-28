<?php

declare(strict_types=1);

namespace App\Module\GitHub;

/**
 * One delivery fact that can change the state of tracked pull requests. Exactly
 * one of number, head and base is set. A review marks a submitted review.
 */
final readonly class PullRequestRefreshHint
{
    private function __construct(
        public ?int $number = null,
        public ?string $headSha = null,
        public ?string $baseBranch = null,
        public bool $review = false,
    ) {
    }

    public static function number(int $number, bool $review = false): self
    {
        return new self(number: $number, review: $review);
    }

    public static function head(string $sha): self
    {
        return new self(headSha: $sha);
    }

    public static function base(string $branch): self
    {
        return new self(baseBranch: $branch);
    }

    /**
     * One hint per key, in first-seen order. A review hint replaces a plain
     * one for the same number, so the marker is never dropped.
     *
     * @param list<self> $hints
     *
     * @return list<self>
     */
    public static function unique(array $hints): array
    {
        $unique = [];
        foreach ($hints as $hint) {
            $key = $hint->key();
            if (!isset($unique[$key]) || ($hint->review && !$unique[$key]->review)) {
                $unique[$key] = $hint;
            }
        }

        return array_values($unique);
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
