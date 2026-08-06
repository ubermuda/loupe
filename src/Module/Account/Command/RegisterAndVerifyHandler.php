<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RegisterAndVerifyHandler
{
    public function __construct(
        private RegisterUserHandler $registerUser,
        private UserRepository $users,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(RegisterAndVerifyCommand $command): RegisterAndVerifyView
    {
        try {
            $user = ($this->registerUser)(new RegisterUserCommand(
                email: $command->email,
                fullName: $command->fullName,
                plainPassword: $command->plainPassword,
            ));
        } catch (DomainErrors $e) {
            // Already registered — reuse the existing account so a repeated seed
            // call is idempotent rather than an error.
            $user = $this->users->findOneByEmail($command->email);
            if (null === $user) {
                return new RegisterAndVerifyView(null, $e);
            }
        }

        if (!$user->isVerified()) {
            $user->emailVerifiedAt = new \DateTimeImmutable();
            $this->em->flush();
        }

        return new RegisterAndVerifyView($user, null);
    }
}
