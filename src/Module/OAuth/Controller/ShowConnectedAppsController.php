<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\ListConnectedAppsCommand;
use App\Module\OAuth\Command\ListConnectedAppsHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/account/connected-apps',
    name: 'app_account_connected_apps',
    methods: ['GET'],
)]
final class ShowConnectedAppsController extends AppController
{
    public function __construct(
        private readonly ListConnectedAppsHandler $listConnectedApps,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        return $this->render('@OAuth/show_connected_apps.html.twig', [
            'view' => ($this->listConnectedApps)(new ListConnectedAppsCommand($user)),
        ]);
    }
}
