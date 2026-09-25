<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class SetCardLaneRequest
{
    public const string RETURN_TO_CARD = 'card';
    public const string RETURN_TO_BOARD = 'board';

    public function __construct(
        /** The state the lane takes, not a flip, so a second submit of a stale page changes nothing. */
        #[Assert\Choice(choices: ['0', '1'])]
        #[Assert\NotNull]
        public ?string $laneEnabled = null,

        /** A named page rather than a URL, so the redirect can never leave the app. */
        #[Assert\Choice(choices: [self::RETURN_TO_CARD, self::RETURN_TO_BOARD])]
        #[Assert\NotNull]
        public ?string $returnTo = self::RETURN_TO_CARD,
    ) {
    }
}
