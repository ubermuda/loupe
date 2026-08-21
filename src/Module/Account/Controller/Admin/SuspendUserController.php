<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Admin;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Command\Admin\SuspendUserCommand;
use App\Module\Account\Command\Admin\SuspendUserHandler;
use App\Module\Account\Entity\User;
use App\Module\Account\Form\Admin\SuspendUserFormType;
use App\Module\Account\Form\Admin\SuspendUserRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\AdminBundle\Listing\AdminReturnTo;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('admin-user-suspend')]
#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/users/{id:target}/suspend',
    name: 'app_admin_users_suspend',
    requirements: ['id' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
    methods: ['POST'],
)]
final class SuspendUserController extends AppController
{
    public function __construct(
        private readonly SuspendUserHandler $suspendUser,
        private readonly AdminReturnTo $returnTo,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(User $target, Request $request): Response
    {
        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s).', self::class, get_debug_type($actor)));
        }

        $data = new SuspendUserRequest();
        $form = $this->createForm(SuspendUserFormType::class, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', $this->translator->trans('account.admin.users.flash.reason_too_long'));
        } else {
            try {
                ($this->suspendUser)(new SuspendUserCommand($target, $actor, $data->reason));

                $this->addFlash('success', $this->translator->trans('account.admin.users.flash.suspended'));
            } catch (DomainErrors $e) {
                // The guard keys its failures 'user' and 'roles'; neither is a
                // field the caller can see, so they surface as flashes.
                foreach ($e->errors as $translationKey) {
                    $this->addFlash('error', $this->translator->trans($translationKey));
                }
            }
        }

        return $this->redirect(
            $this->returnTo->validate('user', $request->request->get('returnTo'))
                ?? $this->generateUrl('app_admin_users_detail', ['id' => (string) $target->id]),
        );
    }
}
