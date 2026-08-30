<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Admin;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Command\Admin\ShowUserCommand;
use App\Module\Account\Command\Admin\ShowUserHandler;
use App\Module\Account\Command\Admin\UpdateUserCommand;
use App\Module\Account\Command\Admin\UpdateUserHandler;
use App\Module\Account\Entity\User;
use App\Module\Account\Form\Admin\AdminUserFormType;
use App\Module\Account\Form\Admin\AdminUserRequest;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\AdminBundle\Listing\AdminReturnTo;

#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/users/{id:target}',
    name: 'app_admin_users_detail',
    requirements: ['id' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
    methods: ['GET', 'POST'],
)]
final class ShowUserController extends AppController
{
    public function __construct(
        private readonly ShowUserHandler $showUser,
        private readonly UpdateUserHandler $updateUser,
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

        $form = $this->createForm(AdminUserFormType::class, AdminUserRequest::fromUser($target));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            assert($data instanceof AdminUserRequest);

            try {
                ($this->updateUser)(new UpdateUserCommand(
                    target: $target,
                    actor: $actor,
                    fullName: $data->fullName ?? throw new \LogicException('Full name is required after form validation.'),
                    email: $data->email ?? throw new \LogicException('Email is required after form validation.'),
                    isAdmin: $data->isAdmin,
                    isVerified: $data->isVerified,
                ));

                $this->addFlash('success', $this->translator->trans('account.admin.users.flash.updated'));

                $redirectParams = ['id' => (string) $target->id];
                $validatedReturnTo = $this->returnTo->validate('user', $request->query->get('returnTo'));
                if (null !== $validatedReturnTo) {
                    $redirectParams['returnTo'] = $validatedReturnTo;
                }

                return $this->redirectToRoute('app_admin_users_detail', $redirectParams);
            } catch (DomainErrors $e) {
                $this->reportDomainErrors($form, $e);
            }
        }

        $view = ($this->showUser)(new ShowUserCommand($target));

        return $this->renderFormResponse('@Account/admin/users/show_user.html.twig', $form, [
            'user' => $view->user,
            'connectedAccounts' => $view->connectedAccounts,
            'apiTokenCount' => $view->apiTokenCount,
            'dataExports' => $view->dataExports,
            'panels' => $view->panels,
        ]);
    }

    /**
     * AdminUserGuard keys its failures 'user' and 'roles', neither of which is a
     * field on this form — handing those to a field lookup throws. Anything the
     * form does not own becomes a flash instead.
     *
     * @param FormInterface<mixed> $form
     */
    private function reportDomainErrors(FormInterface $form, DomainErrors $e): void
    {
        foreach ($e->errors as $field => $translationKey) {
            $message = $this->translator->trans($translationKey);

            if ($form->has($field)) {
                $form->get($field)->addError(new FormError($message));

                continue;
            }

            $this->addFlash('error', $message);
        }
    }
}
