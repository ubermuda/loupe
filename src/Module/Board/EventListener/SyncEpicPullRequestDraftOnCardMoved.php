<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Entity\CardType;
use App\Module\Board\Event\CardMoved;
use App\Module\Board\Messenger\SyncEpicPullRequestDraft;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Board\Service\EpicPullRequestWrites;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/** Queues the draft state of an epic's pull request when the epic enters review or implementation. */
#[AsEventListener]
final readonly class SyncEpicPullRequestDraftOnCardMoved
{
    public function __construct(
        private BoardAvailability $board,
        private EpicPullRequestWrites $writes,
        private CardPullRequestRepository $cardPullRequests,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(CardMoved $event): void
    {
        $card = $event->card;
        if ($event->move->fromColumn === $card->column
            || CardType::Epic !== $card->type
            || null === $this->writes->draftFor($card->column)
            || !$this->board->isEnabled()
            || [] === $this->cardPullRequests->findCurrentKeys($card)) {
            return;
        }

        // A named transport keeps PlaywrightSyncMiddleware from handling it inline, inside the move's transaction.
        $this->bus->dispatch(new SyncEpicPullRequestDraft($card->id ?? throw new \LogicException('A stored card has an id.')), [new TransportNamesStamp(['async'])]);
    }
}
