<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Command\ConfirmAccountDeletionCommand;
use App\Module\Account\Command\ConfirmAccountDeletionHandler;
use App\Routing\PaywallExempt;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[PaywallExempt]
#[Route(
    '/account/delete/confirm',
    name: 'app_account_delete_confirm',
    methods: ['GET'],
)]
class ConfirmAccountDeletionController extends AppController
{
    public function __construct(
        private readonly ConfirmAccountDeletionHandler $confirmAccountDeletion,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $token = $request->query->get('token');
        $view = ($this->confirmAccountDeletion)(new ConfirmAccountDeletionCommand(
            is_string($token) ? $token : null,
        ));

        if (null === $view->account) {
            return $this->render('@Account/account_deletion/invalid.html.twig');
        }

        return $this->render('@Account/account_deletion/confirm.html.twig', [
            'account' => $view->account,
            'token' => $token,
        ]);
    }
}
