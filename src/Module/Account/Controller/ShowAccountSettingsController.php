<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Command\ShowAccountSettingsCommand;
use App\Module\Account\Command\ShowAccountSettingsHandler;
use App\Module\Account\Entity\User;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/account', name: 'app_account_settings', methods: ['GET'])]
class ShowAccountSettingsController extends AppController
{
    public function __construct(private readonly ShowAccountSettingsHandler $handler)
    {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        $view = ($this->handler)(new ShowAccountSettingsCommand($user));

        return $this->render('@Account/show_account_settings.html.twig', ['view' => $view]);
    }
}
