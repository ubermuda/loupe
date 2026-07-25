<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Command\LinkSocialAccountCommand;
use App\Module\Account\Command\LinkSocialAccountHandler;
use App\Module\Account\Form\LinkSocialAccountFormType;
use App\Module\Account\Form\LinkSocialAccountRequest;
use App\Module\Account\Security\SocialAuthenticator;
use App\Module\Account\Service\PendingSocialLink;
use App\Module\Account\Service\SocialLoginRace;
use App\Module\Account\Service\StaleSocialLink;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/**
 * Confirms ownership of a password-protected account whose email collides with a
 * social identity. Proving control of the same email address is deliberately not
 * enough to take over such an account: the password is the second factor.
 */
#[Route(
    '/oauth/link',
    name: 'app_oauth_link',
    methods: ['GET', 'POST'],
)]
class LinkSocialAccountController extends AppController
{
    private const string TEMPLATE = '@Account/security/link_social_account.html.twig';

    public function __construct(
        private readonly PendingSocialLink $pendingSocialLink,
        private readonly LinkSocialAccountHandler $linkSocialAccount,
        private readonly FeatureFlagService $featureFlags,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,

        #[Autowire(service: 'limiter.oauth_link')]
        private readonly RateLimiterFactory $oauthLinkLimiter,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $pending = $this->pendingSocialLink->peek();
        if (null === $pending) {
            return $this->redirectToRoute('app_login');
        }

        // A provider switched off mid-flow must not be able to complete a link.
        if (!$this->featureFlags->isEnabled($pending->profile->provider->flagName())) {
            throw $this->createNotFoundException();
        }

        $viewData = [
            'email' => $pending->profile->email,
            'providerLabel' => $this->translator->trans($pending->profile->provider->label()),
            'error' => null,
        ];

        $form = $this->createForm(LinkSocialAccountFormType::class, new LinkSocialAccountRequest());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limiter = $this->oauthLinkLimiter->create($request->getClientIp() ?? 'unknown');
            if (!$limiter->consume(1)->isAccepted()) {
                $this->logger->info('account.social.link_throttled', ['provider' => $pending->profile->provider->value]);

                return $this->render(self::TEMPLATE, array_merge($viewData, [
                    'form' => $form,
                    'error' => 'account.social.link.error.rate_limited',
                ]))->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);
            }

            $data = $form->getData();

            try {
                $account = ($this->linkSocialAccount)(new LinkSocialAccountCommand(
                    userId: $pending->userId,
                    profile: $pending->profile,
                    plainPassword: $data->password ?? '',
                ));

                // Consume the pending link only once it has actually been used:
                // a wrong password must leave the user able to try again.
                $this->pendingSocialLink->pull();
                $this->logger->info('account.social.linked', [
                    'provider' => $pending->profile->provider->value,
                    'user' => (string) $account->user->id,
                ]);

                return $this->security->login(
                    $account->user,
                    SocialAuthenticator::class,
                    badges: [new RememberMeBadge()->enable()],
                ) ?? $this->redirectToRoute('app_home');
            } catch (DomainErrors $e) {
                foreach ($e->errors as $field => $translationKey) {
                    $form->get($field)->addError(new FormError($this->translator->trans($translationKey)));
                }
            } catch (StaleSocialLink|SocialLoginRace) {
                $this->pendingSocialLink->pull();

                return $this->redirectToRoute('app_login', ['social_error' => 1]);
            }
        }

        return $this->renderFormResponse(self::TEMPLATE, $form, $viewData);
    }
}
