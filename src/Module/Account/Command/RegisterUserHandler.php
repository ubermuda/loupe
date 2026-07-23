<?php

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\VerificationEmailSender;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class RegisterUserHandler
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private VerificationEmailSender $verificationEmailSender,
    ) {
    }

    /** @throws DomainErrors */
    public function __invoke(RegisterUserCommand $command): User
    {
        $errors = [];

        if ($this->users->findOneByEmail($command->email)) {
            $errors['email'] = 'account.registration.error.email_duplicate';
        }

        if ($this->users->findOneByUsername($command->username)) {
            $errors['username'] = 'account.registration.error.username_taken';
        }

        if ([] !== $errors) {
            throw new DomainErrors($errors);
        }

        $user = new User(
            username: $command->username,
            fullName: $command->fullName,
            email: $command->email,
        );
        $user->password = $this->passwordHasher->hashPassword($user, $command->plainPassword);

        $this->em->persist($user);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // A concurrent registration won the race between the pre-checks
            // above and this flush; surface the same field error instead of
            // letting the request 500. The EM is closed at this point, so the
            // colliding field cannot be re-queried — email is the likely one.
            throw new DomainErrors(['email' => 'account.registration.error.email_duplicate']);
        }

        try {
            $this->verificationEmailSender->send($user);
        } catch (\Throwable) {
            // Email sending failed; account is created — user can resend from check-email page.
        }

        return $user;
    }
}
