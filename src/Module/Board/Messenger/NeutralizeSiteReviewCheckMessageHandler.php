<?php

declare(strict_types=1);

namespace App\Module\Board\Messenger;

use App\Module\Board\Command\NeutralizeSiteReviewCheckCommand;
use App\Module\Board\Command\NeutralizeSiteReviewCheckHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class NeutralizeSiteReviewCheckMessageHandler
{
    public function __construct(
        private NeutralizeSiteReviewCheckHandler $neutralizeSiteReviewCheck,
    ) {
    }

    public function __invoke(NeutralizeSiteReviewCheck $message): void
    {
        ($this->neutralizeSiteReviewCheck)(new NeutralizeSiteReviewCheckCommand(
            $message->projectId,
            $message->forge,
            $message->repository,
            $message->number,
            $message->headSha,
            $message->runId,
        ));
    }
}
