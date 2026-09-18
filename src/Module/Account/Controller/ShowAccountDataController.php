<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Command\ShowAccountSettingsCommand;
use App\Module\Account\Command\ShowAccountSettingsHandler;
use App\Module\Account\Entity\User;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/account/data',
    name: 'app_account_data',
    methods: ['GET'],
)]
class ShowAccountDataController extends AppController
{
    public function __construct(
        private readonly ShowAccountSettingsHandler $handler,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        return $this->render('@Account/show_account_data.html.twig', [
            'view' => ($this->handler)(new ShowAccountSettingsCommand($user)),
        ]);
    }
}
