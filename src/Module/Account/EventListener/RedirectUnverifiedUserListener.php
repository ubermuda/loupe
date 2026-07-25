<?php

namespace App\Module\Account\EventListener;

use App\Module\Account\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

// Priority 5: run after the security firewall (priority 8) so the token is already populated.
#[AsEventListener(priority: 5)]
final readonly class RedirectUnverifiedUserListener
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
        'app_oauth_start_google',
        'app_oauth_start_github',
        'app_oauth_check_google',
        'app_oauth_check_github',
        'app_oauth_link',
        'app_oauth_link_submit',
    ];

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
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
