<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** One variant of an experiment, over the cards the report keeps. */
final readonly class ExperimentVariant
{
    public function __construct(
        public string $name,
        /** The model the latest run of the variant asked for. */
        public ?string $model,
        /** Null when the bridge reported no weight for this variant. */
        public ?int $weight,
        public int $cards,
        /** Merged, or in a terminal column. */
        public int $finishedCards,
        public int $runs,
        /** Millionths of a dollar. */
        public int $costMicros,
    ) {
    }
}
