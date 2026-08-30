<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller\Admin;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\Admin\RevokeCompCommand;
use App\Module\Billing\Command\Admin\RevokeCompHandler;
use App\Module\Billing\Security\CompVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\AdminBundle\Listing\AdminReturnTo;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('billing-comp-revoke')]
#[IsGranted(CompVoter::MANAGE, subject: 'target')]
#[Route(
    '/admin/users/{id:target}/comp/revoke',
    name: 'app_admin_users_comp_revoke',
    requirements: ['id' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
    methods: ['POST'],
)]
final class RevokeCompController extends AppController
{
    public function __construct(
        private readonly RevokeCompHandler $revokeComp,
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
            ($this->revokeComp)(new RevokeCompCommand($target, $actor));

            $this->addFlash('success', $this->translator->trans('billing.admin.comp.flash.revoked'));
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
