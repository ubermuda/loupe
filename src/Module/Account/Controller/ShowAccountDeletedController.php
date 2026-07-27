<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Routing\PaywallExempt;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[PaywallExempt]
#[Route(
    '/goodbye',
    name: 'app_account_deleted',
    methods: ['GET'],
)]
class ShowAccountDeletedController extends AppController
{
    public function __invoke(): Response
    {
        return $this->render('@Account/account_deletion/show_account_deleted.html.twig');
    }
}
