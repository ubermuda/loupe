<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardLink;
use App\Module\Board\Repository\CardLinkRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;

final readonly class ShowCardHandler
{
    public function __construct(
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private CardLinkRepository $cardLinks,
    ) {
    }

    public function __invoke(ShowCardCommand $command): CardView
    {
        return new CardView(
            $command->card,
            $this->cardSiteReviewComments->findForCard($command->card),
            array_map(
                static fn (CardLink $link): RelatedCard => new RelatedCard($link->otherThan($command->card), $link->kindFor($command->card)),
                $this->cardLinks->findForCard($command->card),
            ),
        );
    }
}
