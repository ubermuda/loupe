<?php

declare(strict_types=1);

namespace App\Module\Billing\Messenger;

use App\Module\Billing\Service\TrialEndSweeper;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SweepEndedTrialsHandler
{
    public function __construct(
        private TrialEndSweeper $sweeper,
    ) {
    }

    public function __invoke(SweepEndedTrialsMessage $message): void
    {
        $this->sweeper->sweep();
    }
}
