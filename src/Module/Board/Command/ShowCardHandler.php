<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Repository\CardLinkRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\CardPullRequestStates;
use App\Module\Workflow\Contract\CardTypeCatalog;

final readonly class ShowCardHandler
{
    public function __construct(
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private CardLinkRepository $cardLinks,
        private CardRepository $cards,
        private CardPullRequestStates $pullRequestStates,
        private ShowCardHistoryHandler $history,
        private CardTypeCatalog $catalog,
    ) {
    }

    public function __invoke(ShowCardCommand $command): CardView
    {
        $children = [];
        $progress = null;
        if ($this->catalog->forProject($command->card->project->requireId())->get($command->card->type)->children) {
            $children = $this->cards->findChildren($command->card);
            $progress = new CardProgress(
                \count(array_filter($children, static fn (Card $child): bool => $child->column->terminal)),
                \count($children),
            );
        }

        return new CardView(
            $command->card,
            $this->cardSiteReviewComments->findForCard($command->card),
            array_map(
                static fn (CardLink $link): RelatedCard => new RelatedCard($link->otherThan($command->card), $link->kindFor($command->card)),
                $this->cardLinks->findForCard($command->card),
            ),
            $this->pullRequestStates->forCards([$command->card]),
            ($this->history)(new ShowCardHistoryCommand($command->card)),
            $children,
            $progress,
        );
    }
}
