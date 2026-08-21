<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Admin;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Admin\AdminUserGuard;
use App\Module\Account\Command\ResendVerificationEmailCommand;
use App\Module\Account\Command\ResendVerificationEmailHandler;
use App\Module\Account\Entity\User;
use App\Module\Account\Service\EmailRateLimitKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\AdminBundle\Listing\AdminReturnTo;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('admin-user-resend-verification')]
#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/users/{id:target}/resend-verification',
    name: 'app_admin_users_resend_verification',
    requirements: ['id' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
    methods: ['POST'],
)]
final class ResendVerificationController extends AppController
{
    public function __construct(
        private readonly AdminUserGuard $guard,
        private readonly ResendVerificationEmailHandler $resendVerificationEmail,

        #[Autowire(service: 'limiter.resend_verification_email_address')]
        private readonly RateLimiterFactoryInterface $addressLimiter,
        private readonly EmailRateLimitKey $addressKey,
        private readonly AdminReturnTo $returnTo,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(User $target, Request $request): Response
    {
        try {
            // The handler is shared with the public resend flow, which must stay
            // free of admin-only preconditions, so the guard is called here. The
            // agent account is unverified forever and must never be mailed.
            $this->guard->assertMutable($target);

            // Only the per-recipient bucket: the per-IP one exists to slow anonymous
            // resends down to one a minute, which an admin working a list is not.
            if ($this->addressLimiter->create(($this->addressKey)($target->email))->consume(1)->isAccepted()) {
                ($this->resendVerificationEmail)(new ResendVerificationEmailCommand($target->email));

                $this->addFlash('success', $this->translator->trans('account.admin.users.flash.verification_resent'));
            } else {
                $this->addFlash('error', $this->translator->trans('account.admin.users.flash.resend_throttled'));
            }
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
