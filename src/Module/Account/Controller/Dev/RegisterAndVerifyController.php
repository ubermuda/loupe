<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Dev;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Command\RegisterUserCommand;
use App\Module\Account\Command\RegisterUserHandler;
use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only endpoint that registers a user and immediately marks their email as verified.
 * Used exclusively by Playwright e2e tests — not available in production (When('dev')).
 */
#[Route(
    '/dev/register-and-verify',
    name: 'app_dev_register_and_verify',
    methods: ['POST'],
)]
#[When('dev')]
final class RegisterAndVerifyController extends AppController
{
    public function __construct(
        private readonly RegisterUserHandler $registerUser,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $email = $request->request->getString('email');
        $password = $request->request->getString('password');

        if ('' === $email || '' === $password) {
            return $this->json(['error' => 'email and password are required'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $user = ($this->registerUser)(new RegisterUserCommand(
                email: $email,
                plainPassword: $password,
            ));
        } catch (DomainErrors $e) {
            // User already exists — look them up so we can return their info.
            $existing = $this->users->findOneByEmail($email);
            if (null === $existing) {
                return $this->json(['error' => 'Registration failed', 'errors' => $e->errors], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
            $user = $existing;
        }

        // Mark the email as verified without sending any email.
        if (!$user->isVerified()) {
            $user->emailVerifiedAt = new \DateTimeImmutable();
            $this->em->flush();
        }

        return $this->json(['email' => $user->email], JsonResponse::HTTP_OK);
    }
}
