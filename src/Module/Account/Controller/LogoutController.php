<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Routing\PaywallExempt;
use Symfony\Component\Routing\Attribute\Route;

#[PaywallExempt]
#[Route('/logout', name: 'app_logout')]
class LogoutController extends AppController
{
    public function __invoke(): never
    {
        throw new \LogicException('This method can be blank — it will be intercepted by the logout key on your firewall.');
    }
}
