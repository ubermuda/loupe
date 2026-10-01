<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller\Admin;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\Admin\CreateBetaInviteCommand;
use App\Module\Billing\Command\Admin\CreateBetaInviteHandler;
use App\Module\Billing\Form\CreateBetaInviteFormType;
use App\Module\Billing\Form\CreateBetaInviteRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/beta-invites',
    name: 'app_admin_beta_invites_create',
    methods: ['POST'],
)]
final class CreateBetaInviteController extends AppController
{
    public function __construct(
        private readonly CreateBetaInviteHandler $createBetaInvite,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $data = new CreateBetaInviteRequest();
        $form = $this->createForm(CreateBetaInviteFormType::class, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->forward(ListBetaInvitesController::class, [ListBetaInvitesController::CREATE_FORM => $form->createView()])
                ->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s).', self::class, get_debug_type($actor)));
        }

        $view = ($this->createBetaInvite)(new CreateBetaInviteCommand($actor, $data->note));

        $request->getSession()->set(
            ListBetaInvitesController::ISSUED_LINK,
            $this->generateUrl('app_billing_beta_invite', ['token' => $view->token], UrlGeneratorInterface::ABSOLUTE_URL),
        );

        return $this->redirectToRoute('app_admin_beta_invites_list');
    }
}
