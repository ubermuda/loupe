<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Command\RequestPasswordResetCommand;
use App\Module\Account\Command\RequestPasswordResetHandler;
use App\Module\Account\Form\ResetPasswordRequestFormType;
use App\Module\Account\Service\EmailRateLimitKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/forgot-password', name: 'app_forgot_password_request')]
class RequestPasswordResetController extends AppController
{
    public function __construct(
        private readonly RequestPasswordResetHandler $requestPasswordReset,
        private readonly TranslatorInterface $translator,

        #[Autowire(service: 'limiter.password_reset_request')]
        private readonly RateLimiterFactoryInterface $passwordResetRequestLimiter,

        #[Autowire(service: 'limiter.password_reset_request_address')]
        private readonly RateLimiterFactoryInterface $passwordResetRequestAddressLimiter,
        private readonly EmailRateLimitKey $addressKey,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limiter = $this->passwordResetRequestLimiter->create($request->getClientIp() ?? 'unknown');
            if (!$limiter->consume(1)->isAccepted()) {
                $form->get('email')->addError(new FormError($this->translator->trans('account.reset_password.error.throttled')));

                return $this->renderFormResponse('@Account/reset_password/request_password_reset.html.twig', $form);
            }

            $submitted = $form->get('email')->getData();
            $email = is_string($submitted) ? $submitted : '';

            // Consumed for every address, not only ones with an account, and a
            // rejection still returns the success redirect below — anything
            // else would answer the question RequestPasswordResetHandler's
            // anti-enumeration policy refuses to answer.
            $addressLimiter = $this->passwordResetRequestAddressLimiter->create(($this->addressKey)($email));
            if ($addressLimiter->consume(1)->isAccepted()) {
                ($this->requestPasswordReset)(new RequestPasswordResetCommand($email));
            }

            return $this->redirectToRoute('app_forgot_password_check_email');
        }

        return $this->renderFormResponse('@Account/reset_password/request_password_reset.html.twig', $form);
    }
}
