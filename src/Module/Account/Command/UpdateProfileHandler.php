<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class UpdateProfileHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(UpdateProfileCommand $command): User
    {
        $user = $command->user;
        $user->fullName = trim($command->fullName);
        $this->em->flush();

        $this->logger->info('account.profile.updated', ['userId' => (string) $user->id]);

        return $user;
    }
}
