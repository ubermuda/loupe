<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Command\RegisterUserCommand;
use App\Module\Account\Command\RegisterUserHandler;
use App\Module\Account\Form\RegistrationFormType;
use App\Module\Account\Form\RegistrationRequest;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\RegistrationGate;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
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
        private readonly WaitlistEntryRepository $waitlistEntries,

        #[Autowire(service: 'limiter.registration')]
        private readonly RateLimiterFactory $registrationLimiter,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        // The invite token arrives once, in the GET URL from the email link.
        // Stash it in the session and redirect to a clean URL — the token must
        // not linger in the address bar, browser history, or the form action.
        $queryToken = $request->query->get('invite');
        if (null !== $queryToken) {
            if (is_string($queryToken) && null !== $this->waitlistEntries->findOneByValidInviteToken($queryToken)) {
                $request->getSession()->set(self::INVITE_SESSION_KEY, $queryToken);
            }

            return $this->redirectToRoute('app_register');
        }

        $sessionToken = $request->getSession()->get(self::INVITE_SESSION_KEY);
        $inviteToken = is_string($sessionToken) ? $sessionToken : null;
        $hasValidInvite = null !== $inviteToken
            && null !== $this->waitlistEntries->findOneByValidInviteToken($inviteToken);

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

            try {
                $user = ($this->registerUser)(new RegisterUserCommand(
                    username: (string) $data->username,
                    fullName: (string) $data->fullName,
                    email: $data->email ?: throw new \LogicException('Email is required after form validation.'),
                    plainPassword: (string) $data->plainPassword,
                    inviteToken: $inviteToken,
                ));
            } catch (DomainErrors $e) {
                foreach ($e->errors as $field => $messageKey) {
                    $form->get($field)->addError(new FormError($this->translator->trans($messageKey)));
                }

                return $this->renderFormResponse('@Account/registration/register.html.twig', $form);
            }

            $request->getSession()->remove(self::INVITE_SESSION_KEY);
            $request->getSession()->set('registration_email', $user->email);

            return $this->redirectToRoute('app_register_check_email');
        }

        return $this->renderFormResponse('@Account/registration/register.html.twig', $form);
    }
}
