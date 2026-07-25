<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Admin;

use App\Controller\AppController;
use App\Module\Account\Command\Admin\InviteOldestWaitlistCommand;
use App\Module\Account\Command\Admin\InviteOldestWaitlistHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('admin-waitlist-invite')]
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/waitlist/invite-oldest', name: 'app_admin_waitlist_invite_oldest', methods: ['POST'])]
final class InviteOldestWaitlistController extends AppController
{
    public function __construct(
        private readonly InviteOldestWaitlistHandler $inviteOldest,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $result = ($this->inviteOldest)(new InviteOldestWaitlistCommand($request->request->getInt('count')));

        $this->addFlash('success', $this->translator->trans(
            'account.admin.waitlist.invited_flash',
            ['%invited%' => $result->invited, '%skipped%' => $result->skipped],
        ));

        return $this->redirectToRoute('app_admin_waitlist_list');
    }
}
