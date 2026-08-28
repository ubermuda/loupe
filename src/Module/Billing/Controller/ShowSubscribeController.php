<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\ShowSubscribeCommand;
use App\Module\Billing\Command\ShowSubscribeHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// No #[IsGranted]: there is no resource subject here, and the `^/` access_control
// catch-all already requires ROLE_USER — same posture as the project list.
#[Route(
    '/billing/subscribe',
    name: 'app_billing_subscribe',
    methods: ['GET'],
)]
final class ShowSubscribeController extends AppController
{
    public function __construct(
        private readonly ShowSubscribeHandler $showSubscribe,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        return $this->render('@Billing/show_subscribe.html.twig', [
            'view' => ($this->showSubscribe)(new ShowSubscribeCommand(
                $user,
                inviteToken: $request->query->getString('invite') ?: null,
            )),
        ]);
    }
}
