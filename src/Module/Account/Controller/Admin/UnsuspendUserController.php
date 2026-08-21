<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Admin;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Command\Admin\UnsuspendUserCommand;
use App\Module\Account\Command\Admin\UnsuspendUserHandler;
use App\Module\Account\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\AdminBundle\Listing\AdminReturnTo;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('admin-user-unsuspend')]
#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/users/{id:target}/unsuspend',
    name: 'app_admin_users_unsuspend',
    requirements: ['id' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
    methods: ['POST'],
)]
final class UnsuspendUserController extends AppController
{
    public function __construct(
        private readonly UnsuspendUserHandler $unsuspendUser,
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

        try {
            ($this->unsuspendUser)(new UnsuspendUserCommand($target, $actor));

            $this->addFlash('success', $this->translator->trans('account.admin.users.flash.unsuspended'));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }
        }

        return $this->redirect(
            $this->returnTo->validate('user', $request->request->get('returnTo'))
                ?? $this->generateUrl('app_admin_users_detail', ['id' => (string) $target->id]),
        );
    }
}
