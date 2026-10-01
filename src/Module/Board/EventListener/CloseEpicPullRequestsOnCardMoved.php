<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Event\CardMoved;
use App\Module\Board\Messenger\CloseEpicPullRequests;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Service\BoardAvailability;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Queues the close of an epic's pull requests when the epic moves to the
 * Backlog. The app moves a card there only once its pull requests are closed.
 */
#[AsEventListener]
final readonly class CloseEpicPullRequestsOnCardMoved
{
    public function __construct(
        private BoardAvailability $board,
        private CardPullRequestRepository $cardPullRequests,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(CardMoved $event): void
    {
        $card = $event->card;
        if ($event->move->fromColumn === $card->column
            || !$card->column->backlog
            || CardType::Epic !== $card->type
            || CardReporter::System === $event->actor
            || !$this->board->isEnabled()
            || [] === $this->cardPullRequests->findCurrentKeys($card)) {
            return;
        }

        // A named transport keeps PlaywrightSyncMiddleware from handling it inline, inside the move's transaction.
        $this->bus->dispatch(new CloseEpicPullRequests($card->id ?? throw new \LogicException('A stored card has an id.')), [new TransportNamesStamp(['async'])]);
    }
}
