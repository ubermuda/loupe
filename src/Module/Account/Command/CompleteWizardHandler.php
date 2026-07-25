<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class CompleteWizardHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CompleteWizardCommand $command): void
    {
        if (null !== $command->user->wizardCompletedAt) {
            return;
        }

        $command->user->wizardCompletedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->logger->info('account.wizard.completed', [
            'userId' => (string) $command->user->id,
        ]);
    }
}
