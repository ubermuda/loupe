<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller\Admin;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\Admin\GrantCompCommand;
use App\Module\Billing\Command\Admin\GrantCompHandler;
use App\Module\Billing\Security\CompVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\AdminBundle\Listing\AdminReturnTo;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('billing-comp-grant')]
#[IsGranted(CompVoter::MANAGE, subject: 'target')]
#[Route(
    '/admin/users/{id:target}/comp',
    name: 'app_admin_users_comp_grant',
    requirements: ['id' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
    methods: ['POST'],
)]
final class GrantCompController extends AppController
{
    public function __construct(
        private readonly GrantCompHandler $grantComp,
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
            ($this->grantComp)(new GrantCompCommand($target, $actor));

            $this->addFlash('success', $this->translator->trans('billing.admin.comp.flash.granted'));
        } catch (DomainErrors $e) {
            // The panel is a fieldless form, so a domain failure has no field to
            // attach to and becomes a flash.
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
