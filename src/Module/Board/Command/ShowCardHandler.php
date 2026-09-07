<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Repository\CardSiteReviewCommentRepository;

final readonly class ShowCardHandler
{
    public function __construct(
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
    ) {
    }

    public function __invoke(ShowCardCommand $command): CardView
    {
        return new CardView(
            $command->card,
            $this->cardSiteReviewComments->findForCard($command->card),
        );
    }
}
