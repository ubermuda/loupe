<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use Doctrine\ORM\EntityManagerInterface;

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
