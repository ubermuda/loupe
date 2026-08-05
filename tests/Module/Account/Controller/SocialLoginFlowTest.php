<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\ConnectedAccount;
use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ConnectedAccountRepository;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\RegistrationGate;
use App\Tests\Support\InstalledInstance;
use App\Tests\Support\SocialLoginScenario;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\OAuth2ClientInterface;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

/**
 * Drives the whole callback flow with the knpu client registry stubbed out, so
 * the real authenticator, resolver and firewall run without a provider
 * round-trip. Google is used throughout: its arm of SocialProfileFactory needs
 * no HTTP call (the GitHub arm is unit-covered in SocialProfileFactoryTest).
 */
final class SocialLoginFlowTest extends WebTestCase
{
    use SocialLoginScenario;

    private const string CALLBACK = '/oauth/google/check?code=stub-code&state=stub-state';

    public function test_an_unknown_verified_identity_creates_a_verified_account_and_signs_in(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setProviderFlag($client, SocialProvider::Google, true);
        InstalledInstance::ensure($client->getContainer()->get(EntityManagerInterface::class));
        $this->stubProvider($client, 'google-sub-1', ['email' => 'newbie@example.com', 'email_verified' => true, 'name' => 'New Bie']);

        $client->request(Request::METHOD_GET, self::CALLBACK);

        self::assertResponseRedirects('/');

        $created = $client->getContainer()->get(UserRepository::class)->findOneByEmail('newbie@example.com');
        self::assertNotNull($created);
        self::assertNotNull($created->emailVerifiedAt);
        self::assertFalse($created->hasUsablePassword());
        self::assertNotNull(
            $client->getContainer()->get(ConnectedAccountRepository::class)
                ->findOneByProviderAndProviderUserId(SocialProvider::Google, 'google-sub-1'),
        );

        // Social sign-in enables the remember-me badge explicitly.
        self::assertNotNull($client->getCookieJar()->get('REMEMBERME'));

        // The session really is authenticated.
        $client->request(Request::METHOD_GET, '/');
        self::assertResponseRedirects();
        self::assertStringNotContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function test_a_known_identity_signs_into_the_same_account(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setProviderFlag($client, SocialProvider::Google, true);

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $user = new User(fullName: 'Returning User', email: 'returning@example.com');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->persist(new ConnectedAccount($user, SocialProvider::Google, 'google-sub-2', 'returning@example.com'));
        $em->flush();

        // Even a changed provider email must not move the identity: the account
        // is keyed on the provider's immutable subject id.
        $this->stubProvider($client, 'google-sub-2', ['email' => 'changed@example.com', 'email_verified' => true]);

        $client->request(Request::METHOD_GET, self::CALLBACK);

        self::assertResponseRedirects('/');
        self::assertNull($client->getContainer()->get(UserRepository::class)->findOneByEmail('changed@example.com'));
    }

    public function test_a_collision_with_a_password_account_asks_for_the_password(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setProviderFlag($client, SocialProvider::Google, true);

        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $user = new User(fullName: 'Collider', email: 'collider@example.com');
        $user->password = $container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'SecurePassword1!');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        $this->stubProvider($client, 'google-sub-3', ['email' => 'collider@example.com', 'email_verified' => true]);

        $client->request(Request::METHOD_GET, self::CALLBACK);

        self::assertResponseRedirects('/oauth/link');
        self::assertNull(
            $container->get(ConnectedAccountRepository::class)
                ->findOneByProviderAndProviderUserId(SocialProvider::Google, 'google-sub-3'),
        );

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[type="password"]');
    }

    public function test_an_unverified_provider_email_is_rejected_and_creates_nothing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setProviderFlag($client, SocialProvider::Google, true);
        $this->stubProvider($client, 'google-sub-4', ['email' => 'unverified@example.com', 'email_verified' => false]);

        $client->request(Request::METHOD_GET, self::CALLBACK);

        self::assertResponseRedirects('/login?social_error=unverified');
        self::assertNull($client->getContainer()->get(UserRepository::class)->findOneByEmail('unverified@example.com'));
        self::assertNull(
            $client->getContainer()->get(ConnectedAccountRepository::class)
                ->findOneByProviderAndProviderUserId(SocialProvider::Google, 'google-sub-4'),
        );

        $client->followRedirect();
        self::assertSelectorTextContains('.auth-error', 'no verified email address');
    }

    public function test_a_disabled_provider_404s_both_its_start_and_callback_routes(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setProviderFlag($client, SocialProvider::Google, false);
        $this->stubProvider($client, 'google-sub-5', ['email' => 'nope@example.com', 'email_verified' => true]);

        $client->request(Request::METHOD_GET, '/oauth/google');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request(Request::METHOD_GET, self::CALLBACK);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertNull($client->getContainer()->get(UserRepository::class)->findOneByEmail('nope@example.com'));
    }

    public function test_buttons_render_only_for_enabled_providers(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setProviderFlag($client, SocialProvider::Google, true);
        $this->setProviderFlag($client, SocialProvider::Github, false);
        InstalledInstance::ensure($client->getContainer()->get(EntityManagerInterface::class));

        foreach (['/login', '/register'] as $path) {
            $client->request(Request::METHOD_GET, $path);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/oauth/google"]');
            self::assertSelectorNotExists('a[href="/oauth/github"]');
            // Turbo Drive cannot follow the cross-origin redirect the start
            // route answers with, so the link must opt out of it.
            self::assertSelectorExists('a[href="/oauth/google"][data-turbo="false"]');
            // The brand mark resolves through UX Icons rather than inline SVG.
            self::assertSelectorExists('a[href="/oauth/google"] svg');
        }
    }

    public function test_buttons_are_hidden_when_no_provider_is_enabled(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/oauth/google"]');
        self::assertSelectorNotExists('a[href="/oauth/github"]');
    }

    public function test_at_cap_a_new_identity_is_diverted_to_the_waitlist(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setProviderFlag($client, SocialProvider::Google, true);
        $this->closeRegistration($client);
        $this->stubProvider($client, 'google-sub-waitlisted', ['email' => 'oauth-waitlisted@example.com', 'email_verified' => true, 'name' => 'Waitlisted OAuth']);

        $client->request(Request::METHOD_GET, self::CALLBACK);

        self::assertResponseRedirects('/waitlist?joined=1');
        self::assertNull($client->getContainer()->get(UserRepository::class)->findOneByEmail('oauth-waitlisted@example.com'));
        self::assertNotNull(
            $client->getContainer()->get(WaitlistEntryRepository::class)->findOneByEmail('oauth-waitlisted@example.com'),
        );

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', "You're on the list");
        self::assertSelectorNotExists('.auth-error');
    }

    public function test_a_new_identity_is_refused_when_registration_is_switched_off(): void
    {
        // Distinct from the at-cap case above: a full instance still takes
        // names, a switched-off one has nothing to queue the visitor for — so
        // the login fails outright and no waitlist row appears either.
        $client = static::createClient();
        $client->disableReboot();
        $this->setProviderFlag($client, SocialProvider::Google, true);
        InstalledInstance::ensure($client->getContainer()->get(EntityManagerInterface::class));
        $this->disableRegistration($client);
        $this->stubProvider($client, 'google-sub-closed', ['email' => 'oauth-closed@example.com', 'email_verified' => true, 'name' => 'Closed OAuth']);

        $client->request(Request::METHOD_GET, self::CALLBACK);

        self::assertResponseRedirects('/login?social_error=closed');
        self::assertNull($client->getContainer()->get(UserRepository::class)->findOneByEmail('oauth-closed@example.com'));
        self::assertNull(
            $client->getContainer()->get(WaitlistEntryRepository::class)->findOneByEmail('oauth-closed@example.com'),
        );

        $client->followRedirect();
        self::assertSelectorTextContains('.auth-error', 'not accepting new accounts');
    }

    private function disableRegistration(KernelBrowser $client): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist(new FeatureFlag(name: RegistrationGate::ENABLED_FLAG, type: FeatureFlagType::Bool, value: false));
        $em->flush();
    }

    private function closeRegistration(KernelBrowser $client): void
    {
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $users = $container->get(UserRepository::class);

        $em->persist(new User(fullName: 'Gate Filler', email: 'gate-filler@example.com', password: 'x'));
        $em->flush();

        $em->persist(new FeatureFlag(name: RegistrationGate::CAP_FLAG, type: FeatureFlagType::Int, value: $users->countActive()));
        $em->flush();
    }

    public function test_a_password_less_account_cannot_sign_in_through_the_password_form(): void
    {
        $client = static::createClient();

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $user = new User(fullName: 'Social Only', email: 'socialonly@example.com');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        $client->request(Request::METHOD_GET, '/login');
        $client->submitForm('Sign in', ['email' => 'socialonly@example.com', 'password' => '']);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorExists('.auth-error');
    }

    /**
     * Replaces the knpu client registry with a stub that hands back a fixed
     * access token and resource owner, so the callback route exercises the real
     * authenticator without talking to a provider.
     *
     * @param array<string, mixed> $data
     */
    private function stubProvider(KernelBrowser $client, string $providerUserId, array $data): void
    {
        $owner = new readonly class($providerUserId, $data) implements ResourceOwnerInterface {
            /** @param array<string, mixed> $data */
            public function __construct(
                private string $id,
                private array $data,
            ) {
            }

            public function getId(): string
            {
                return $this->id;
            }

            /** @return array<string, mixed> */
            public function toArray(): array
            {
                return $this->data;
            }
        };

        $oauthClient = $this->createStub(OAuth2ClientInterface::class);
        $oauthClient->method('getAccessToken')->willReturn(new AccessToken(['access_token' => 'stub-token']));
        $oauthClient->method('fetchUserFromToken')->willReturn($owner);

        $registry = $this->createStub(ClientRegistry::class);
        $registry->method('getClient')->willReturn($oauthClient);

        $client->getContainer()->set('knpu.oauth2.registry', $registry);
    }
}
