<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Command\ShowAccountInboxCommand;
use App\Module\Inbox\Command\ShowAccountInboxHandler;
use App\Module\Inbox\Service\InboxAvailability;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/account/inbox',
    name: 'app_account_inbox',
    methods: ['GET'],
)]
final class ShowAccountInboxController extends AppController
{
    public function __construct(
        private readonly ShowAccountInboxHandler $showAccountInbox,
        private readonly InboxAvailability $inbox,
    ) {
    }

    public function __invoke(): Response
    {
        $this->inbox->requireEnabled();

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        return $this->render('@Inbox/show_account_inbox.html.twig', [
            'inbox' => ($this->showAccountInbox)(new ShowAccountInboxCommand($user)),
        ]);
    }
}
