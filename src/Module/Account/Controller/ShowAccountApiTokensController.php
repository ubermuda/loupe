<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Command\ShowAccountSettingsCommand;
use App\Module\Account\Command\ShowAccountSettingsHandler;
use App\Module\Account\Entity\User;
use App\Module\Account\Form\MintApiTokenFormType;
use App\Module\Account\Form\MintApiTokenRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/account/api-tokens',
    name: 'app_account_api_tokens',
    methods: ['GET'],
)]
class ShowAccountApiTokensController extends AppController
{
    public function __construct(
        private readonly ShowAccountSettingsHandler $handler,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        return $this->render('@Account/show_account_api_tokens.html.twig', [
            'view' => ($this->handler)(new ShowAccountSettingsCommand($user)),
            'mintForm' => $this->getInjectedFormView($request, 'mintForm')
                ?? $this->createForm(MintApiTokenFormType::class, new MintApiTokenRequest())->createView(),
        ]);
    }
}
