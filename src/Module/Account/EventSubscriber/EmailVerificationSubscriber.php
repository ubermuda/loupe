<?php

namespace App\Module\Account\EventSubscriber;

use App\Module\Account\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class EmailVerificationSubscriber implements EventSubscriberInterface
{
    // Routes that unverified users may access freely
    private const array ALLOWED_ROUTES = [
        'app_login',
        'app_logout',
        'app_register',
        'app_register_check_email',
        'app_register_resend',
        'app_verify_email',
        'app_forgot_password_request',
        'app_forgot_password_check_email',
        'app_reset_password',
    ];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 5: run after the security firewall (priority 8) so the token is already populated.
        return [KernelEvents::REQUEST => ['onKernelRequest', 5]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (null === $token) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        if ($user->isVerified()) {
            return;
        }

        $currentRoute = $event->getRequest()->attributes->get('_route');
        if (in_array($currentRoute, self::ALLOWED_ROUTES, true)) {
            return;
        }

        $event->setResponse(
            new RedirectResponse($this->urlGenerator->generate('app_register_check_email'))
        );
    }
}
