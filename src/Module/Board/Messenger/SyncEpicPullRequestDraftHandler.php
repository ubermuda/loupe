<?php

declare(strict_types=1);

namespace App\Module\Board\Messenger;

use App\Module\Board\Command\MatchEpicPullRequestDraftCommand;
use App\Module\Board\Command\MatchEpicPullRequestDraftHandler;
use App\Module\Board\Service\BoardAvailability;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncEpicPullRequestDraftHandler
{
    public function __construct(
        private MatchEpicPullRequestDraftHandler $matchDraft,
        private BoardAvailability $board,
    ) {
    }

    public function __invoke(SyncEpicPullRequestDraft $message): void
    {
        if (!$this->board->isEnabled()) {
            return;
        }

        ($this->matchDraft)(new MatchEpicPullRequestDraftCommand($message->cardId));
    }
}
