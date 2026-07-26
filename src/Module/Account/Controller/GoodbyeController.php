<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/goodbye',
    name: 'app_account_goodbye',
    methods: ['GET'],
)]
class GoodbyeController extends AppController
{
    public function __invoke(): Response
    {
        return $this->render('@Account/account_deletion/goodbye.html.twig');
    }
}
