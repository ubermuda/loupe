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
 * Marks an epic's pull requests ready in the review column, and converts them
 * to draft in implementation. It reads the column when it runs, so a late or
 * retried message writes the state of the column the epic holds now.
 */
final readonly class MatchEpicPullRequestDraftHandler
{
    public function __construct(
        private CardRepository $cards,
        private BoardAutomation $boardAutomation,
        private EpicPullRequestWrites $writes,
    ) {
    }

    public function __invoke(MatchEpicPullRequestDraftCommand $command): void
    {
        $card = $this->cards->find($command->cardId);
        if (null === $card) {
            return;
        }

        $this->cards->refreshTypeAndParent($card);
        $this->cards->refreshColumn($card);
        $draft = $this->writes->draftFor($card->column);
        if (null === $draft || CardType::Epic !== $card->type || !$this->boardAutomation->settingsOf($card->project)->enabled) {
            return;
        }

        $this->writes->apply(
            $card,
            $draft ? 'draft' : 'ready',
            static fn (PullRequestStateWriter $writer, ForgePullRequest $pullRequest) => $writer->setDraft($pullRequest, $draft),
        );
    }
}
