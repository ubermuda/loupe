<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

/**
 * One freehand stroke a new comment carries. The Api request DTO maps onto
 * this, so the handler never depends on the shape of the HTTP payload.
 */
final readonly class NewStroke
{
    /**
     * @param list<array{float, float}> $points
     */
    public function __construct(
        public string $space,
        public array $points,
    ) {
    }
}
