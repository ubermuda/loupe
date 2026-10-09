<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Event\CardChanged;
use App\Module\Workflow\Contract\WithdrawKind;
use App\Module\Workflow\Contract\WorkLedger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A work request keeps the card id without a foreign key, so it outlives its card until this cancels it. */
#[AsEventListener]
final readonly class WithdrawWorkRequestsOnCardDeleted
{
    public function __construct(
        private WorkLedger $ledger,
    ) {
    }

    public function __invoke(CardChanged $event): void
    {
        if (CardChanged::DELETED !== $event->change) {
            return;
        }

        foreach ($this->ledger->live($event->cardId) as $request) {
            $this->ledger->withdraw($request->id, WithdrawKind::Cancelled);
        }
    }
}
