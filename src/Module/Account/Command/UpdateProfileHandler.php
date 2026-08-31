<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\User;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UpdateProfileHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(UpdateProfileCommand $command): User
    {
        $user = $command->user;
        $fullName = trim($command->fullName);

        // A submit that changes nothing is accepted, not refused — and an audit
        // record would claim a transition the database never made.
        if ($fullName === $user->fullName) {
            return $user;
        }

        $user->fullName = $fullName;
        $this->em->flush();

        $this->auditor->record(
            'account.profile.updated',
            AuditOutcome::Success,
            ['userId' => (string) $user->id],
            new AuditSubject('user', (string) $user->id),
        );

        return $user;
    }
}
