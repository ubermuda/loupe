<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Command\ResolveSocialLoginCommand;
use App\Module\Account\Command\ResolveSocialLoginHandler;
use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Security\SocialAuthenticator;
use App\Module\Account\Service\PendingSocialLink;
use App\Module\Account\Service\SocialProfile;
use App\Module\Account\Service\UnverifiedProviderEmail;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

/**
 * Dev-only endpoint standing in for a successful OAuth provider callback, so the
 * social-login flow can be driven by Playwright without a real provider
 * round-trip (no authorization redirect, no token or userinfo call).
 *
 * It builds a SocialProfile straight from request parameters and runs it through
 * the real ResolveSocialLoginHandler, so the branch order, the password-link
 * hand-off and the programmatic login are all exercised; only the provider HTTP
 * boundary is faked.
 *
 * SECURITY: this route grants an authenticated session purely from request
 * parameters, so it must be impossible in production. Two independent guards
 * ensure that: the #[When('dev')] attribute means the controller service (and
 * therefore its route) does not exist outside the dev environment, and the
 * runtime environment check below refuses to run even if it somehow did.
 *
 * GET rather than POST is deliberate: the seam logs a user in via the session
 * cookie, so a spec drives it with page.goto() inside the browser context. A
 * request-fixture POST would deposit the session cookie in a separate jar and
 * leave the page unauthenticated.
 */
#[Route(
    '/dev/e2e/social-login',
    name: 'dev_e2e_social_login',
    methods: ['GET'],
)]
#[When('dev')]
final class E2eSocialLoginController extends AppController
{
    public function __construct(
        private readonly ResolveSocialLoginHandler $resolveSocialLogin,
        private readonly PendingSocialLink $pendingSocialLink,
        private readonly Security $security,

        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!in_array($this->environment, ['dev', 'test'], true)) {
            throw $this->createNotFoundException();
        }

        $provider = SocialProvider::tryFrom($request->query->getString('provider'));
        if (null === $provider) {
            throw $this->createNotFoundException();
        }

        $profile = new SocialProfile(
            provider: $provider,
            providerUserId: $request->query->getString('providerUserId'),
            email: $this->nullable($request->query->getString('email')),
            fullName: $this->nullable($request->query->getString('fullName')),
            emailVerified: $request->query->getBoolean('emailVerified', true),
        );

        try {
            $outcome = ($this->resolveSocialLogin)(new ResolveSocialLoginCommand($profile));
        } catch (UnverifiedProviderEmail) {
            return $this->redirectToRoute('app_login', ['social_error' => 'unverified']);
        }

        // The registration cap diverted this profile's email to the waitlist —
        // mirror SocialAuthenticator, no account was created and there is
        // nothing to log in.
        if ($outcome->waitlisted) {
            return $this->redirectToRoute('app_waitlist_join', ['joined' => 1]);
        }

        $user = $outcome->user ?? throw new \LogicException('A non-waitlisted outcome must carry a user.');

        // The email collides with a password-protected account: mirror
        // SocialAuthenticator by stashing the pending identity and sending the
        // user to the password confirmation page.
        if ($outcome->requiresPasswordLink) {
            $this->pendingSocialLink->store($profile, $user);

            return $this->redirectToRoute('app_oauth_link');
        }

        return $this->security->login(
            $user,
            SocialAuthenticator::class,
            badges: [new RememberMeBadge()->enable()],
        ) ?? $this->redirectToRoute('app_home');
    }

    private function nullable(string $value): ?string
    {
        return '' !== $value ? $value : null;
    }
}
