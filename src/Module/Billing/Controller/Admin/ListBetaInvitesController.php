<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller\Admin;

use App\Controller\AppController;
use App\Module\Billing\Command\Admin\ListBetaInvitesCommand;
use App\Module\Billing\Command\Admin\ListBetaInvitesHandler;
use App\Module\Billing\Form\CreateBetaInviteFormType;
use App\Module\Billing\Form\CreateBetaInviteRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/beta-invites',
    name: 'app_admin_beta_invites_list',
    methods: ['GET'],
)]
final class ListBetaInvitesController extends AppController
{
    /** Holds the link the create action just issued, until this page shows it once. */
    public const string ISSUED_LINK = 'billing.beta_invite.issued_link';

    public const string CREATE_FORM = 'createForm';

    public function __construct(
        private readonly ListBetaInvitesHandler $listBetaInvites,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $view = ($this->listBetaInvites)(new ListBetaInvitesCommand());
        $issuedLink = $request->getSession()->remove(self::ISSUED_LINK);

        return $this->render('@Billing/admin/list_beta_invites.html.twig', [
            'invites' => $view->invites,
            'issuedLink' => \is_string($issuedLink) ? $issuedLink : null,
            'form' => $this->getInjectedFormView($request, self::CREATE_FORM)
                ?? $this->createForm(CreateBetaInviteFormType::class, new CreateBetaInviteRequest())->createView(),
        ]);
    }
}
