<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Command\RegisterAndVerifyCommand;
use App\Module\Account\Command\RegisterAndVerifyHandler;
use App\Module\Account\Service\DisplayNameDeriver;
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
        private readonly RegisterAndVerifyHandler $registerAndVerify,
        private readonly DisplayNameDeriver $displayNameDeriver,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $email = $request->request->getString('email');
        $password = $request->request->getString('password');

        if ('' === $email || '' === $password) {
            return $this->json(['error' => 'email and password are required'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The browser fills this field on the real form; a caller that skips it
        // gets the same value the registration page would have suggested. The
        // truncation matches users.full_name, which no form guards here.
        $fullName = mb_substr($request->request->getString('fullName'), 0, 150);
        if ('' === $fullName) {
            $fullName = $this->displayNameDeriver->derive($email);
        }

        $view = ($this->registerAndVerify)(new RegisterAndVerifyCommand(
            email: $email,
            fullName: $fullName,
            plainPassword: $password,
        ));

        if (null === $view->user) {
            return $this->json(
                ['error' => 'Registration failed', 'errors' => $view->errors->errors ?? []],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->json(['email' => $view->user->email], JsonResponse::HTTP_OK);
    }
}
