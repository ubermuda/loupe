<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/account/accept-terms',
    name: 'app_account_accept_terms',
    methods: ['GET'],
)]
class ShowAcceptTermsController extends AppController
{
    public function __invoke(): Response
    {
        return $this->render('@Account/show_accept_terms.html.twig');
    }
}
