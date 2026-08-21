<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Routing\PaywallExempt;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// PaywallExempt is load-bearing, not decoration: the paywall runs above the
// terms gate, so without it a user with both a lapsed subscription and stale
// terms bounces between the subscribe page and this one forever.
#[PaywallExempt]
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
