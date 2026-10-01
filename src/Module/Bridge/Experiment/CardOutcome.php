<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** What happened to one card on its way to a merge, as its history and its pull requests show it. */
final readonly class CardOutcome
{
    /**
     * @param array<string, int> $fixRounds reason => fix requests with that reason
     */
    public function __construct(
        public array $fixRounds = [],
        public bool $stopped = false,
        /** True only when an app rule moved the card because its pull request merged. */
        public bool $merged = false,
        /** The earliest opening of the card's pull requests. */
        public ?\DateTimeImmutable $openedAt = null,
        /** The latest merge of the card's pull requests. */
        public ?\DateTimeImmutable $mergedAt = null,
    ) {
    }

    public function totalFixRounds(): int
    {
        return array_sum($this->fixRounds);
    }

    public function hoursToMerge(): ?float
    {
        if (null === $this->openedAt || null === $this->mergedAt) {
            return null;
        }

        return ($this->mergedAt->getTimestamp() - $this->openedAt->getTimestamp()) / 3600;
    }
}
