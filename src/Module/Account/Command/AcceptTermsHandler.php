<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class AcceptTermsHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,

        #[Autowire(param: 'app.terms.version')]
        private string $termsVersion,
    ) {
    }

    public function __invoke(AcceptTermsCommand $command): void
    {
        $command->user->termsAcceptedAt = new \DateTimeImmutable();
        $command->user->termsVersion = $this->termsVersion;

        $this->em->flush();

        $this->auditor->record(
            'account.terms_accepted',
            AuditOutcome::Success,
            [
                'userId' => (string) $command->user->id,
                'version' => $this->termsVersion,
            ],
            new AuditSubject('user', (string) $command->user->id),
        );
    }
}
