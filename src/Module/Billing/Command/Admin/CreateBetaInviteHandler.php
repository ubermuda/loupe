<?php

declare(strict_types=1);

namespace App\Module\Billing\Command\Admin;

use App\Module\Billing\Entity\BetaInvite;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class CreateBetaInviteHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(CreateBetaInviteCommand $command): CreateBetaInviteView
    {
        [$invite, $token] = BetaInvite::issue($command->createdBy, $command->note);
        $this->em->persist($invite);
        $this->em->flush();

        $id = (string) $invite->id;
        $this->auditor->record(
            'billing.beta_invite_created',
            AuditOutcome::Success,
            ['betaInviteId' => $id],
            new AuditSubject('beta_invite', $id),
        );

        return new CreateBetaInviteView($invite, $token);
    }
}
