<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

use Symfony\Component\Uid\Uuid;

/** One card of an experiment, as the Cards tab lists it. */
final readonly class ExperimentCard
{
    /**
     * @param list<LeftOutReason> $leftOut in display order, empty when the report keeps the card
     */
    public function __construct(
        public Uuid $cardId,
        /** Null when the card has a pin and no run. */
        public ?int $number,
        public ?string $title,
        public ?string $variant,
        /** Null when the card is gone or the board is off. */
        public ?CardColumn $column,
        public int $runs,
        public int $fixRounds,
        /** Millionths of a dollar. */
        public int $costMicros,
        public array $leftOut,
    ) {
    }

    public function included(): bool
    {
        return [] === $this->leftOut;
    }

    public function firstLeftOut(): ?LeftOutReason
    {
        return $this->leftOut[0] ?? null;
    }
}
