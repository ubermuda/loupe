<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\BoardAutomationSettingsRepository;
use App\Module\Board\Repository\CardLinkRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\CardPullRequestStates;
use App\Module\Board\Service\StageHold;
use App\Module\Board\Service\SyncLine;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use Psr\Clock\ClockInterface;

final readonly class ShowCardHandler
{
    public function __construct(
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private CardLinkRepository $cardLinks,
        private CardRepository $cards,
        private CardPullRequestStates $pullRequestStates,
        private ShowCardHistoryHandler $history,
        private BoardAutomationSettingsRepository $boardAutomationSettings,
        private ForgePullRequestRepository $forgePullRequests,
        private ClockInterface $clock,
        private StageHold $hold,
    ) {
    }

    public function __invoke(ShowCardCommand $command): CardView
    {
        $children = [];
        $progress = null;
        if (CardType::Epic === $command->card->type) {
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
            $this->pullRequestStates->forCards([$command->card], $this->syncLine($command->card)),
            ($this->history)(new ShowCardHistoryCommand($command->card)),
            $children,
            $progress,
            $this->hold->heldBy($command->card),
        );
    }

    private function syncLine(Card $card): ?SyncLine
    {
        if ($card->pullRequests->isEmpty()) {
            return null;
        }
        $settings = $this->boardAutomationSettings->findOneByProject($card->project);
        if (null === $settings || !$settings->enabled || !$settings->syncBehind) {
            return null;
        }

        return new SyncLine(
            $this->forgePullRequests->findOpenForProject($card->project->id ?? throw new \LogicException('A card project is persisted.')),
            $this->clock->now(),
        );
    }
}
