<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class AcceptTermsHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,

        #[Autowire(param: 'app.terms.version')]
        private string $termsVersion,
    ) {
    }

    public function __invoke(AcceptTermsCommand $command): void
    {
        $command->user->termsAcceptedAt = new \DateTimeImmutable();
        $command->user->termsVersion = $this->termsVersion;

        $this->em->flush();

        $this->logger->info('account.terms.accepted', [
            'userId' => (string) $command->user->id,
            'version' => $this->termsVersion,
        ]);
    }
}
