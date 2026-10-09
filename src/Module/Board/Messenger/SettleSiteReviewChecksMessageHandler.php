<?php

declare(strict_types=1);

namespace App\Module\Board\Messenger;

use App\Module\Board\Command\SettleSiteReviewChecksCommand;
use App\Module\Board\Command\SettleSiteReviewChecksHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SettleSiteReviewChecksMessageHandler
{
    public function __construct(
        private SettleSiteReviewChecksHandler $settleSiteReviewChecks,
    ) {
    }

    public function __invoke(SettleSiteReviewChecks $message): void
    {
        ($this->settleSiteReviewChecks)(new SettleSiteReviewChecksCommand($message->projectId));
    }
}
