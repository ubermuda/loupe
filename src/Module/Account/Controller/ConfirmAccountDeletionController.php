<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/account/delete/confirm', name: 'app_account_delete_confirm', methods: ['GET'])]
class ConfirmAccountDeletionController extends AppController
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $token = $request->query->get('token');
        $user = is_string($token) ? $this->users->findByAccountDeletionToken($token) : null;

        if (null === $user || null === $token || !$user->isAccountDeletionTokenValid($token)) {
            return $this->render('@Account/account_deletion/invalid.html.twig');
        }

        return $this->render('@Account/account_deletion/confirm.html.twig', [
            'account' => $user,
            'token' => $token,
        ]);
    }
}
