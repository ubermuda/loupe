<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller\Admin;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Billing\Command\Admin\RevokeBetaInviteCommand;
use App\Module\Billing\Command\Admin\RevokeBetaInviteHandler;
use App\Module\Billing\Entity\BetaInvite;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('billing-beta-invite-revoke')]
#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/beta-invites/{id:invite}/revoke',
    name: 'app_admin_beta_invites_revoke',
    requirements: ['id' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
    methods: ['POST'],
)]
final class RevokeBetaInviteController extends AppController
{
    public function __construct(
        private readonly RevokeBetaInviteHandler $revokeBetaInvite,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(BetaInvite $invite): Response
    {
        try {
            ($this->revokeBetaInvite)(new RevokeBetaInviteCommand($invite));

            $this->addFlash('success', $this->translator->trans('billing.admin.beta_invite.flash.revoked'));
        } catch (DomainErrors $e) {
            // A fieldless form, so a domain failure has no field and becomes a flash.
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }
        }

        return $this->redirectToRoute('app_admin_beta_invites_list');
    }
}
