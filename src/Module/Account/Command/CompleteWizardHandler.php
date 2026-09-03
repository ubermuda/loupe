<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class CompleteWizardHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(CompleteWizardCommand $command): void
    {
        if (null !== $command->user->wizardCompletedAt) {
            return;
        }

        $command->user->wizardCompletedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->auditor->record(
            'account.wizard_completed',
            AuditOutcome::Success,
            ['userId' => (string) $command->user->id],
            new AuditSubject('user', (string) $command->user->id),
        );
    }
}
