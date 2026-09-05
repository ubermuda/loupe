<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One freehand stroke of an incoming comment. Points are fractions, not pixels:
 * `anchor` measures each one against the box of anchor 0, and `page` divides
 * both axes by the document width. A point may fall outside the box it is
 * measured against, so the range is generous rather than 0 to 1.
 */
final class SiteReviewStrokeInput
{
    public const float COORDINATE_LIMIT = 100.0;

    /**
     * @param list<mixed> $points
     */
    public function __construct(
        #[Assert\Choice(choices: ['anchor', 'page'])]
        public string $space = 'page',

        #[Assert\Count(min: 2, max: 500)]
        public array $points = [],
    ) {
    }

    #[Assert\Callback]
    public function validatePoints(ExecutionContextInterface $context): void
    {
        foreach ($this->points as $point) {
            if (!\is_array($point) || 2 !== \count($point) || !isset($point[0], $point[1])) {
                $context->buildViolation('Each point must be a pair of numbers.')->atPath('points')->addViolation();

                return;
            }

            foreach ([$point[0], $point[1]] as $coordinate) {
                if (!\is_int($coordinate) && !\is_float($coordinate)) {
                    $context->buildViolation('Each point must be a pair of numbers.')->atPath('points')->addViolation();

                    return;
                }

                if (abs((float) $coordinate) > self::COORDINATE_LIMIT) {
                    $context->buildViolation('A point falls too far outside the page.')->atPath('points')->addViolation();

                    return;
                }
            }
        }
    }
}
