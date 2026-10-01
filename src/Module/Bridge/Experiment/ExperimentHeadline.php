<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** The answer at the top of the report, from the first two variants by name. */
final readonly class ExperimentHeadline
{
    public function __construct(
        /** The variant with the lower cost per merged card. Null when two variants do not both have one. */
        public ?string $cheaperVariant,
        /** The share of the dearer variant's cost per merged card that the cheaper one saves. */
        public ?float $saving,
        public bool $costClear,
        /** The merge rate and the fix rounds both give a clear answer. */
        public bool $qualitySettled,
    ) {
    }
}
