<?php

declare(strict_types=1);

namespace App\Module\Workflow\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Bridge\Command\WithdrawWorkRequestCommand;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Workflow\Engine\EngineSwitch;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A work request keeps the card id without a foreign key, so it outlives its card until this cancels it. */
#[AsEventListener]
final readonly class WithdrawWorkRequestsOnCardDeleted
{
    public function __construct(
        private EngineSwitch $engine,
        private WorkRequestRepository $workRequests,
        private WithdrawWorkRequestHandler $withdrawWorkRequest,
    ) {
    }

    public function __invoke(CardChanged $event): void
    {
        if (CardChanged::DELETED !== $event->change || !$this->engine->isOn()) {
            return;
        }

        foreach ($this->workRequests->findLiveForCard($event->cardId) as $request) {
            ($this->withdrawWorkRequest)(new WithdrawWorkRequestCommand(
                $request->id ?? throw new \LogicException('A persisted work request has an id.'),
                WorkRequestState::Cancelled,
            ));
        }
    }
}
