<?php

declare(strict_types=1);

namespace App\Module\Readiness\EventListener;

use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Readiness\Command\FailDiscoveryRunCommand;
use App\Module\Readiness\Command\FailDiscoveryRunHandler;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** No bridge claimed the discovery request before it expired. */
#[AsEventListener]
final readonly class FailDiscoveryOnRequestExpired
{
    public const string REASON = 'no-taker';

    public function __construct(
        private WorkRequestRepository $workRequests,
        private FailDiscoveryRunHandler $failDiscoveryRun,
    ) {
    }

    public function __invoke(WorkRequestChanged $event): void
    {
        $cardId = $event->cardId();
        if (WorkRequestState::Expired !== $event->state || null === $cardId) {
            return;
        }
        if (FailDiscoveryRunHandler::RULE_ID !== $this->workRequests->find($event->workRequestId)?->ruleId) {
            return;
        }

        ($this->failDiscoveryRun)(new FailDiscoveryRunCommand($cardId, self::REASON));
    }
}
