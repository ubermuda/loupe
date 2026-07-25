<?php

declare(strict_types=1);

namespace App\Module\Account\Security;

use App\Module\Account\Command\ResolveSocialLoginCommand;
use App\Module\Account\Command\ResolveSocialLoginHandler;
use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Service\PendingSocialLink;
use App\Module\Account\Service\SocialLoginRace;
use App\Module\Account\Service\SocialProfileFactory;
use App\Module\Account\Service\UnverifiedProviderEmail;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

final class SocialAuthenticator extends OAuth2Authenticator
{
    use TargetPathTrait;

    /** Callback route name to provider. Each provider has its own route so the
     * route itself can carry a static #[RequireFeatureFlag]. */
    private const array CHECK_ROUTES = [
        'app_oauth_check_google' => SocialProvider::Google,
        'app_oauth_check_github' => SocialProvider::Github,
    ];

    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly RouterInterface $router,
        private readonly SocialProfileFactory $profileFactory,
        private readonly ResolveSocialLoginHandler $resolveSocialLogin,
        private readonly PendingSocialLink $pendingSocialLink,
        private readonly FeatureFlagService $featureFlags,
    ) {
    }

    public function supports(Request $request): bool
    {
        $provider = $this->providerFor($request);

        // When the provider's flag is off the firewall skips this authenticator,
        // so the request falls through to the callback controller, whose
        // #[RequireFeatureFlag] turns it into a 404.
        return null !== $provider && $this->featureFlags->isEnabled($provider->flagName());
    }

    public function authenticate(Request $request): Passport
    {
        $provider = $this->providerFor($request) ?? throw new AuthenticationException('Unknown social provider.');
        $client = $this->clientRegistry->getClient($provider->value.'_main');
        $accessToken = $this->fetchAccessToken($client);

        $profile = $this->profileFactory->fromResourceOwner(
            $provider,
            $client->fetchUserFromToken($accessToken),
            $accessToken->getToken(),
        );

        try {
            $outcome = ($this->resolveSocialLogin)(new ResolveSocialLoginCommand($profile));
        } catch (SocialLoginRace $e) {
            // A concurrent callback won a uniqueness race and closed the
            // EntityManager, so nothing can be salvaged in this request. Fail
            // with the generic banner; the next attempt resolves cleanly.
            throw new AuthenticationException('A concurrent social login won a uniqueness race.', previous: $e);
        }

        if ($outcome->requiresPasswordLink) {
            $this->pendingSocialLink->store($profile, $outcome->user);

            throw new RequiresPasswordLinkException();
        }

        $user = $outcome->user;

        // The callback URL carries no _remember_me parameter and the firewall
        // does not set always_remember_me, so the badge has to be enabled here or
        // no cookie is ever written. Social sign-in is treated as a "keep me
        // signed in" gesture: the provider already holds a long-lived session and
        // re-authenticating costs a full round-trip through it.
        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), static fn (): object => $user),
            [new RememberMeBadge()->enable()],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $target = $this->getTargetPath($request->getSession(), $firewallName);

        return new RedirectResponse($target ?? $this->router->generate('app_home'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof RequiresPasswordLinkException) {
            return new RedirectResponse($this->router->generate('app_oauth_link'));
        }

        $reason = $exception instanceof UnverifiedProviderEmail ? 'unverified' : '1';

        return new RedirectResponse($this->router->generate('app_login', ['social_error' => $reason]));
    }

    private function providerFor(Request $request): ?SocialProvider
    {
        return self::CHECK_ROUTES[(string) $request->attributes->get('_route')] ?? null;
    }
}
