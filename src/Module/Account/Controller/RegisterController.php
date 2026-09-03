<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Command\CheckInviteTokenCommand;
use App\Module\Account\Command\CheckInviteTokenHandler;
use App\Module\Account\Command\RegisterUserCommand;
use App\Module\Account\Command\RegisterUserHandler;
use App\Module\Account\Form\RegistrationFormType;
use App\Module\Account\Form\RegistrationRequest;
use App\Module\Account\Service\EmailRateLimitKey;
use App\Module\Account\Service\RegistrationGate;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/register', name: 'app_register')]
class RegisterController extends AppController
{
    private const string INVITE_SESSION_KEY = 'waitlist_invite_token';

    public function __construct(
        private readonly RegisterUserHandler $registerUser,
        private readonly TranslatorInterface $translator,
        private readonly RegistrationGate $registrationGate,
        private readonly CheckInviteTokenHandler $checkInviteToken,
        private readonly Auditor $auditor,

        #[Autowire(service: 'limiter.registration')]
        private readonly RateLimiterFactoryInterface $registrationLimiter,

        #[Autowire(service: 'limiter.registration_address')]
        private readonly RateLimiterFactoryInterface $registrationAddressLimiter,
        private readonly EmailRateLimitKey $addressKey,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        // Above the invite-token stash below on purpose: a closed instance must
        // not write an invite token into a visitor's session either. 404 rather
        // than a message, matching how the install wizard and every other
        // feature-flagged route disappear when switched off.
        if (!$this->registrationGate->allowsNewAccounts()) {
            // A submission only: a GET is a page view, and a crawler would
            // write a row per request. The gate closes above the form, so this
            // says an attempt reached a closed door, not that a filled-in form
            // was rejected. No context, because the path is all this branch
            // knows and it is caller-controlled.
            if ($request->isMethod('POST')) {
                $this->auditor->record('account.registration_denied', AuditOutcome::Refused);
            }

            throw $this->createNotFoundException();
        }

        // The invite token arrives once, in the GET URL from the email link.
        // Stash it in the session and redirect to a clean URL — the token must
        // not linger in the address bar, browser history, or the form action.
        $queryToken = $request->query->get('invite');
        if (null !== $queryToken) {
            if (is_string($queryToken) && ($this->checkInviteToken)(new CheckInviteTokenCommand($queryToken))->valid) {
                $request->getSession()->set(self::INVITE_SESSION_KEY, $queryToken);
            }

            return $this->redirectToRoute('app_register');
        }

        $sessionToken = $request->getSession()->get(self::INVITE_SESSION_KEY);
        $inviteToken = is_string($sessionToken) ? $sessionToken : null;
        $hasValidInvite = null !== $inviteToken
            && ($this->checkInviteToken)(new CheckInviteTokenCommand($inviteToken))->valid;

        if (!$this->registrationGate->isOpen() && !$hasValidInvite) {
            return $this->redirectToRoute('app_waitlist_join');
        }

        $form = $this->createForm(RegistrationFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limiter = $this->registrationLimiter->create($request->getClientIp() ?? 'unknown');
            if (!$limiter->consume(1)->isAccepted()) {
                $form->get('email')->addError(new FormError($this->translator->trans('account.registration.error.throttled')));

                return $this->renderFormResponse('@Account/registration/register.html.twig', $form);
            }

            $data = $form->getData();
            assert($data instanceof RegistrationRequest);

            // Not the `?:` idiom used for email: "0" is a legitimate display
            // name that NotBlank accepts and a truthiness check would reject.
            $fullName = $data->fullName;
            if (null === $fullName || '' === $fullName) {
                throw new \LogicException('Display name is required after form validation.');
            }

            $email = $data->email ?: throw new \LogicException('Email is required after form validation.');

            // Reported, not silenced. Faking the check-email redirect here would
            // stash an unowned address in `registration_email`, which the resend
            // flow trusts — turning the throttle into a way to mail a stranger.
            // Nothing is disclosed by reporting it: this form already tells the
            // caller on the first attempt whether the address is taken.
            $addressLimiter = $this->registrationAddressLimiter->create(($this->addressKey)($email));
            if (!$addressLimiter->consume(1)->isAccepted()) {
                $form->get('email')->addError(new FormError($this->translator->trans('account.registration.error.throttled')));

                return $this->renderFormResponse('@Account/registration/register.html.twig', $form);
            }

            try {
                $user = ($this->registerUser)(new RegisterUserCommand(
                    email: $email,
                    fullName: $fullName,
                    plainPassword: (string) $data->plainPassword,
                    inviteToken: $inviteToken,
                ));
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);

                return $this->renderFormResponse('@Account/registration/register.html.twig', $form);
            }

            $request->getSession()->remove(self::INVITE_SESSION_KEY);
            $request->getSession()->set('registration_email', $user->email);

            return $this->redirectToRoute('app_register_check_email');
        }

        return $this->renderFormResponse('@Account/registration/register.html.twig', $form);
    }
}
