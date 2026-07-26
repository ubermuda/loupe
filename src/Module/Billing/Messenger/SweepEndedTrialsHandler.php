<?php

declare(strict_types=1);

namespace App\Module\Billing\Messenger;

use App\Module\Billing\Service\TrialEndSweeper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SweepEndedTrialsHandler
{
    public function __construct(
        private TrialEndSweeper $sweeper,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SweepEndedTrialsMessage $message): void
    {
        $result = $this->sweeper->sweep();

        // One line per tick makes scheduler liveness greppable in the worker logs.
        $this->logger->info('billing.trial_sweep.completed', [
            'disabled' => $result->disabled,
            'churnedSurveys' => $result->churnedSurveys,
            'subscriberSurveys' => $result->subscriberSurveys,
            'cancelSurveys' => $result->cancelSurveys,
            'failed' => $result->failed,
        ]);
    }
}
