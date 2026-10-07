<?php

declare(strict_types=1);

namespace App\Module\Readiness\EventListener;

use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Readiness\Command\FailDiscoveryRunCommand;
use App\Module\Readiness\Command\FailDiscoveryRunHandler;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The discovery request expired with no taker, or the engine cancelled it because the card left the Backlog. */
#[AsEventListener]
final readonly class FailDiscoveryOnRequestWithdrawn
{
    public const string NO_TAKER = 'no-taker';
    public const string CARD_MOVED = 'card-moved';

    public function __construct(
        private WorkRequestRepository $workRequests,
        private FailDiscoveryRunHandler $failDiscoveryRun,
    ) {
    }

    public function __invoke(WorkRequestChanged $event): void
    {
        $cardId = $event->cardId();
        $reason = match ($event->state) {
            WorkRequestState::Expired => self::NO_TAKER,
            WorkRequestState::Cancelled => self::CARD_MOVED,
            default => null,
        };
        if (null === $reason || null === $cardId) {
            return;
        }
        if (FailDiscoveryRunHandler::RULE_ID !== $this->workRequests->find($event->workRequestId)?->ruleId) {
            return;
        }

        // The handler cancels the request after it fails the run, so that cancel finds no requested run.
        ($this->failDiscoveryRun)(new FailDiscoveryRunCommand($cardId, $reason));
    }
}
