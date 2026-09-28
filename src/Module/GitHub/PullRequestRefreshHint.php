<?php

declare(strict_types=1);

namespace App\Module\GitHub;

use App\Module\Forge\Entity\PullRequestReview;

/**
 * One delivery fact that can change the state of tracked pull requests. Exactly
 * one of number, head and base is set. A verdict marks a submitted review, and
 * the review id names it.
 */
final readonly class PullRequestRefreshHint
{
    private function __construct(
        public ?int $number = null,
        public ?string $headSha = null,
        public ?string $baseBranch = null,
        public ?PullRequestReview $verdict = null,
        public ?string $reviewId = null,
    ) {
    }

    public static function number(int $number, ?PullRequestReview $verdict = null, ?string $reviewId = null): self
    {
        return new self(number: $number, verdict: $verdict, reviewId: null === $verdict ? null : $reviewId);
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
     * One hint per key, in first-seen order. A hint with a verdict replaces a
     * plain one for the same number, so the verdict is never dropped.
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
            if (!isset($unique[$key]) || (null !== $hint->verdict && null === $unique[$key]->verdict)) {
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
