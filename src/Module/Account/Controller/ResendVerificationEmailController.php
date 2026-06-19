<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\VerificationEmailSender;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('resend-verification')]
#[Route(
    '/register/resend',
    name: 'app_register_resend',
    methods: ['POST'],
)]
class ResendVerificationEmailController extends AppController
{
    public function __construct(
        #[Autowire(service: 'limiter.resend_verification_email')]
        private readonly RateLimiterFactory $resendVerificationEmailLimiter,
        private readonly UserRepository $users,
        private readonly TranslatorInterface $translator,
        private readonly VerificationEmailSender $verificationEmailSender,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        $email = $request->getSession()->get('registration_email')
            ?? ($user instanceof User ? $user->email : null);

        if (null === $email) {
            return $this->redirectToRoute('app_register');
        }

        $limiter = $this->resendVerificationEmailLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$limiter->consume(1)->isAccepted()) {
            $this->addFlash('error', $this->translator->trans('account.registration.flash.resend_throttled'));

            return $this->redirectToRoute('app_register_check_email');
        }

        $existingUser = $this->users->findOneByEmail((string) $email);
        if ($existingUser instanceof User && !$existingUser->isVerified()) {
            try {
                $this->verificationEmailSender->send($existingUser);
            } catch (\Throwable) {
                // Email sending failed; redirect anyway so the page doesn't 500.
            }
        }

        $this->addFlash('success', $this->translator->trans('account.registration.flash.resent'));

        return $this->redirectToRoute('app_register_check_email');
    }
}
