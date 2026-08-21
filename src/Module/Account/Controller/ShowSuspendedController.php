<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Routing\PaywallExempt;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[PaywallExempt]
#[Route(
    '/account/suspended',
    name: 'app_account_suspended',
    methods: ['GET'],
)]
class ShowSuspendedController extends AppController
{
    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        return $this->render('@Account/show_suspended.html.twig', [
            'reason' => $user->suspendedReason,
        ]);
    }
}
