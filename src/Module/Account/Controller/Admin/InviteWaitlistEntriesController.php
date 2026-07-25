<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Admin;

use App\Controller\AppController;
use App\Module\Account\Command\Admin\InviteWaitlistEntriesCommand;
use App\Module\Account\Command\Admin\InviteWaitlistEntriesHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('admin-waitlist-invite')]
#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/waitlist/invite',
    name: 'app_admin_waitlist_invite',
    methods: ['POST'],
)]
final class InviteWaitlistEntriesController extends AppController
{
    public function __construct(
        private readonly InviteWaitlistEntriesHandler $inviteEntries,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        /** @var list<string> $ids */
        $ids = array_values(array_filter((array) $request->request->all('ids'), is_string(...)));
        $result = ($this->inviteEntries)(new InviteWaitlistEntriesCommand($ids));

        $this->addFlash('success', $this->translator->trans(
            'account.admin.waitlist.invited_flash',
            ['%invited%' => $result->invited, '%skipped%' => $result->skipped],
        ));

        return $this->redirectToRoute('app_admin_waitlist_list');
    }
}
