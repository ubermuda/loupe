<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** The tokens one model spent in one worker process. */
final class WorkerRunModelUsageInput
{
    /** The largest value numeric(12,6) holds. */
    public const float MAX_COST_USD = 999999.999999;

    public function __construct(
        #[Assert\NotNull]
        #[Assert\PositiveOrZero]
        public ?int $inputTokens = null,

        #[Assert\NotNull]
        #[Assert\PositiveOrZero]
        public ?int $outputTokens = null,

        #[Assert\NotNull]
        #[Assert\PositiveOrZero]
        public ?int $cacheReadTokens = null,

        #[Assert\NotNull]
        #[Assert\PositiveOrZero]
        public ?int $cacheWriteTokens = null,

        /** Null when the bridge estimated a model it knows no price for. */
        #[Assert\LessThanOrEqual(self::MAX_COST_USD)]
        #[Assert\PositiveOrZero]
        public ?float $costUsd = null,
    ) {
    }

    /** Six places, the scale of the column. */
    public function costUsd(): ?string
    {
        return null === $this->costUsd ? null : \sprintf('%.6F', $this->costUsd);
    }
}
