<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\EpicPullRequestWrites;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestStateWriter;

/**
 * Closes the pull requests of an epic that a person or an agent moved to the
 * Backlog. An epic that left the Backlog before this runs keeps them open.
 */
final readonly class CloseBackloggedEpicPullRequestsHandler
{
    public function __construct(
        private CardRepository $cards,
        private BoardAutomation $boardAutomation,
        private EpicPullRequestWrites $writes,
    ) {
    }

    public function __invoke(CloseBackloggedEpicPullRequestsCommand $command): void
    {
        $card = $this->cards->find($command->cardId);
        if (null === $card) {
            return;
        }

        $this->cards->refreshTypeAndParent($card);
        $this->cards->refreshColumn($card);
        if (!$card->column->backlog || CardType::Epic !== $card->type || !$this->boardAutomation->settingsOf($card->project)->enabled) {
            return;
        }

        $this->writes->apply(
            $card,
            'close',
            static fn (PullRequestStateWriter $writer, ForgePullRequest $pullRequest) => $writer->close($pullRequest),
        );
    }
}
