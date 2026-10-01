<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Controller;

use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Entity\User;
use App\Module\Account\Registration\RegistrationPasses;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\RegistrationGate;
use App\Module\Billing\Command\Admin\RevokeCompCommand;
use App\Module\Billing\Command\Admin\RevokeCompHandler;
use App\Module\Billing\Entity\BetaInvite;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Entity\Subscription;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Module\Billing\Repository\BetaInviteRepository;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\BillingGrants;
use App\Tests\Support\BillingScenario;
use App\Tests\Support\InstalledInstance;
use App\Tests\Support\RecordingAuditor;
use App\Tests\Support\SocialLoginScenario;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\OAuth2ClientInterface;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

/**
 * The OAuth case uses Google, as SocialLoginFlowTest does: its arm of
 * SocialProfileFactory needs no HTTP call, and the pass logic is the same for
 * every provider.
 */
final class RedeemBetaInviteControllerTest extends WebTestCase
{
    use SocialLoginScenario;

    private const string GOOGLE_CALLBACK = '/oauth/google/check?code=stub-code&state=stub-state';

    public function test_a_signed_out_visitor_is_sent_to_sign_up_with_the_token_in_the_session(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->closeRegistration($client);
        [, $token] = $this->seedInvite($client);

        $client->request(Request::METHOD_GET, '/beta/'.$token);

        self::assertResponseRedirects('/register');
        self::assertSame($token, $client->getRequest()->getSession()->get(RegistrationPasses::SESSION_KEY));
    }

    public function test_at_cap_a_form_sign_up_with_a_beta_link_creates_the_account_and_a_comp(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->closeRegistration($client);
        $admin = $this->persistUser($client, 'beta-admin@example.com');
        [$invite, $token] = $this->seedInvite($client, $admin);

        $client->request(Request::METHOD_GET, '/beta/'.$token);
        $client->followRedirect();
        $client->submitForm('Create account', [
            'registration_form[email]' => 'beta-form@example.com',
            'registration_form[fullName]' => 'Beta Form',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ]);

        self::assertResponseRedirects('/register/check-email');
        $user = $this->userByEmail($client, 'beta-form@example.com');
        self::assertSame($user->id?->toRfc4122(), $this->reload($client, $invite)->redeemedBy?->id?->toRfc4122());
        $comp = $this->currentComp($client, $user);
        self::assertNotNull($comp);
        self::assertSame($admin->id?->toRfc4122(), $comp->grantedBy?->id?->toRfc4122());
    }

    public function test_at_cap_a_social_sign_up_with_a_beta_link_creates_the_account_and_a_comp(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setProviderFlag($client, SocialProvider::Google, true);
        $this->closeRegistration($client);
        [$invite, $token] = $this->seedInvite($client);
        $this->stubProvider($client, 'google-sub-beta', ['email' => 'beta-oauth@example.com', 'email_verified' => true, 'name' => 'Beta OAuth']);

        $client->request(Request::METHOD_GET, '/beta/'.$token);
        $client->request(Request::METHOD_GET, self::GOOGLE_CALLBACK);

        // Back to the link, which shows the success page to its redeemer once
        // the terms gate lets the new account through.
        self::assertResponseRedirects('http://localhost/beta/'.$token);
        $user = $this->userByEmail($client, 'beta-oauth@example.com');
        AcceptedTerms::stamp($user, $client->getContainer());
        $client->getContainer()->get(EntityManagerInterface::class)->flush();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-beta-claim-form]');
        self::assertSelectorNotExists('[data-beta-invite-invalid]');
        self::assertSame($user->id?->toRfc4122(), $this->reload($client, $invite)->redeemedBy?->id?->toRfc4122());
        $comp = $this->currentComp($client, $user);
        self::assertNotNull($comp);
        // The invite has no creator, so the comp has no granting admin.
        self::assertNull($comp->grantedBy);
    }

    public function test_at_cap_a_form_sign_up_is_refused_when_the_invite_was_revoked_after_the_link(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->closeRegistration($client);
        [$invite, $token] = $this->seedInvite($client);

        $client->request(Request::METHOD_GET, '/beta/'.$token);
        $client->followRedirect();
        // Re-read: each request resets the entity manager, which detaches $invite.
        $this->reload($client, $invite)->revoke();
        $client->getContainer()->get(EntityManagerInterface::class)->flush();

        $client->submitForm('Create account', [
            'registration_form[email]' => 'beta-late@example.com',
            'registration_form[fullName]' => 'Beta Late',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ]);

        self::assertResponseRedirects('/waitlist');
        self::assertNull($client->getContainer()->get(UserRepository::class)->findOneByEmail('beta-late@example.com'));
    }

    public function test_at_cap_a_social_sign_up_is_waitlisted_when_the_invite_was_used_after_the_link(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->setProviderFlag($client, SocialProvider::Google, true);
        $this->closeRegistration($client);
        [$invite, $token] = $this->seedInvite($client);
        $this->stubProvider($client, 'google-sub-late', ['email' => 'beta-late-oauth@example.com', 'email_verified' => true, 'name' => 'Late OAuth']);

        $client->request(Request::METHOD_GET, '/beta/'.$token);
        $first = $this->persistUser($client, 'first-tester@example.com');
        $this->reload($client, $invite)->redeem($client->getContainer()->get(UserRepository::class)->find($first->id) ?? throw new \LogicException('persisted above'));
        $client->getContainer()->get(EntityManagerInterface::class)->flush();
        $client->request(Request::METHOD_GET, self::GOOGLE_CALLBACK);

        self::assertResponseRedirects('/waitlist?joined=1');
        self::assertNull($client->getContainer()->get(UserRepository::class)->findOneByEmail('beta-late-oauth@example.com'));
        self::assertNotNull($client->getContainer()->get(WaitlistEntryRepository::class)->findOneByEmail('beta-late-oauth@example.com'));
        self::assertSame('first-tester@example.com', $this->reload($client, $invite)->redeemedBy?->email);
    }

    public function test_a_signed_out_visitor_is_returned_to_the_link_after_signing_in_to_an_existing_account(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->closeRegistration($client);
        $existing = new User(fullName: 'Existing', email: 'beta-existing@example.com');
        $container = $client->getContainer();
        $existing->password = $container->get(UserPasswordHasherInterface::class)->hashPassword($existing, 'SecurePassword1!');
        $existing->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($existing, $container);
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($existing);
        $em->flush();
        [$invite, $token] = $this->seedInvite($client);

        $client->request(Request::METHOD_GET, '/beta/'.$token);
        self::assertStringEndsWith('/beta/'.$token, (string) $client->getRequest()->getSession()->get('_security.main.target_path'));

        $client->request(Request::METHOD_GET, '/login');
        $client->submitForm('Sign in', ['email' => 'beta-existing@example.com', 'password' => 'SecurePassword1!']);

        self::assertResponseRedirects('http://localhost/beta/'.$token);
        $client->followRedirect();
        self::assertSelectorExists('[data-beta-claim-form]');
        self::assertTrue($this->reload($client, $invite)->isUsable());
    }

    public function test_a_signed_in_get_shows_the_claim_page_and_redeems_nothing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tester = new BillingScenario($client->getContainer())->verifiedUser('beta-prefetch');
        [$invite, $token] = $this->seedInvite($client);

        $client->loginUser($tester);
        $client->request(Request::METHOD_GET, '/beta/'.$token);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-beta-claim-form]');
        self::assertTrue($this->reload($client, $invite)->isUsable());
        self::assertSame([], $this->comps($client, $tester));
    }

    public function test_a_claim_with_a_bad_csrf_token_is_refused(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tester = new BillingScenario($client->getContainer())->verifiedUser('beta-csrf');
        [$invite, $token] = $this->seedInvite($client);

        $client->loginUser($tester);
        $this->claim($client, $token, 'not-a-valid-token');

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->reload($client, $invite)->isUsable());
    }

    public function test_a_signed_in_user_claims_the_link_and_gets_a_comp(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $scenario = new BillingScenario($client->getContainer());
        $tester = $scenario->verifiedUser('beta-signed-in');
        [$invite, $token] = $this->seedInvite($client);

        $client->loginUser($tester);
        $this->claim($client, $token);

        self::assertResponseRedirects('/beta/'.$token);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-beta-claim-form]');
        self::assertSelectorNotExists('[data-beta-stripe-hint]');
        self::assertSame($tester->id?->toRfc4122(), $this->reload($client, $invite)->redeemedBy?->id?->toRfc4122());
        self::assertNotNull($this->currentComp($client, $tester));
    }

    public function test_a_signed_in_user_whose_email_is_not_verified_can_claim_the_link(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tester = new User(fullName: 'Unverified', email: 'beta-unverified@example.com', password: 'x');
        AcceptedTerms::stamp($tester, $client->getContainer());
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($tester);
        $em->flush();
        [$invite, $token] = $this->seedInvite($client);

        $client->loginUser($tester);
        $client->request(Request::METHOD_GET, '/beta/'.$token);
        self::assertResponseIsSuccessful();
        $client->request(Request::METHOD_POST, '/beta/'.$token, ['_csrf_token' => 'csrf-token']);

        self::assertResponseRedirects('/beta/'.$token);
        self::assertSame($tester->id?->toRfc4122(), $this->reload($client, $invite)->redeemedBy?->id?->toRfc4122());
        self::assertNotNull($this->currentComp($client, $tester));
    }

    public function test_a_reload_by_the_user_who_redeemed_the_link_shows_the_success_page_and_grants_nothing_more(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $audit = RecordingAuditor::installedIn($client->getContainer());
        $tester = new BillingScenario($client->getContainer())->verifiedUser('beta-reload');
        [, $token] = $this->seedInvite($client);

        $client->loginUser($tester);
        $this->claim($client, $token);
        $client->request(Request::METHOD_POST, '/beta/'.$token, ['_csrf_token' => 'csrf-token']);
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-beta-invite-invalid]');
        self::assertSelectorNotExists('[data-beta-claim-form]');
        self::assertCount(1, $audit->records('billing.beta_invite_redeemed'));
        self::assertCount(1, $this->comps($client, $tester));
    }

    public function test_a_reload_after_an_admin_revoked_the_comp_does_not_promise_free_access(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $scenario = new BillingScenario($client->getContainer());
        $tester = $scenario->verifiedUser('beta-revoked-comp');
        $admin = $scenario->verifiedUser('beta-revoking-admin');
        [, $token] = $this->seedInvite($client);

        $client->loginUser($tester);
        $this->claim($client, $token);
        $client->getContainer()->get(RevokeCompHandler::class)(new RevokeCompCommand($tester, $admin));
        $client->request(Request::METHOD_GET, '/beta/'.$token);

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorExists('[data-beta-invite-invalid]');
    }

    public function test_a_link_another_user_redeemed_stays_invalid_for_a_signed_in_user(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $scenario = new BillingScenario($client->getContainer());
        $first = $scenario->verifiedUser('beta-first');
        $second = $scenario->verifiedUser('beta-second');
        [, $token] = $this->seedInvite($client);

        $client->loginUser($first);
        $this->claim($client, $token);
        $client->loginUser($second);
        $client->request(Request::METHOD_POST, '/beta/'.$token, ['_csrf_token' => 'csrf-token']);
        $client->followRedirect();

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorExists('[data-beta-invite-invalid]');
        self::assertSame([], $this->comps($client, $second));
    }

    public function test_a_form_sign_up_with_a_beta_link_while_the_cap_is_open_uses_the_invite_and_grants_a_comp(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        InstalledInstance::ensure($client->getContainer());
        [$invite, $token] = $this->seedInvite($client);

        $client->request(Request::METHOD_GET, '/beta/'.$token);
        $client->followRedirect();
        $client->submitForm('Create account', [
            'registration_form[email]' => 'beta-open@example.com',
            'registration_form[fullName]' => 'Beta Open',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ]);

        self::assertResponseRedirects('/register/check-email');
        $user = $this->userByEmail($client, 'beta-open@example.com');
        self::assertSame($user->id?->toRfc4122(), $this->reload($client, $invite)->redeemedBy?->id?->toRfc4122());
        self::assertNotNull($this->currentComp($client, $user));
    }

    public function test_at_cap_a_form_sign_up_with_a_duplicate_email_is_refused_and_the_invite_stays_usable(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->closeRegistration($client);
        [$invite, $token] = $this->seedInvite($client);

        $client->request(Request::METHOD_GET, '/beta/'.$token);
        $client->followRedirect();
        $client->submitForm('Create account', [
            'registration_form[email]' => 'gate-filler@example.com',
            'registration_form[fullName]' => 'Gate Filler Again',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ]);

        self::assertResponseStatusCodeSame(422);
        $reloaded = $this->reload($client, $invite);
        self::assertTrue($reloaded->isUsable());
        self::assertNull($reloaded->redeemedBy);
    }

    public function test_a_signed_in_user_who_is_already_comped_uses_the_invite_and_keeps_one_comp(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $scenario = new BillingScenario($client->getContainer());
        $tester = $scenario->verifiedUser('beta-comped');
        $profile = $scenario->profile($tester, new \DateTimeImmutable('+14 days'));
        $scenario->grant(BillingGrants::comp($profile));
        [$invite, $token] = $this->seedInvite($client);

        $client->loginUser($tester);
        $this->claim($client, $token);

        self::assertResponseRedirects('/beta/'.$token);
        self::assertTrue($this->reload($client, $invite)->isRedeemed());
        self::assertCount(1, $this->comps($client, $tester));
    }

    public function test_a_tester_who_pays_through_stripe_is_told_to_cancel(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $scenario = new BillingScenario($client->getContainer());
        $tester = $scenario->verifiedUser('beta-paying');
        $profile = $scenario->profile($tester, new \DateTimeImmutable('-1 day'));
        $scenario->grant(BillingGrants::stripe($profile, BillingStatus::Active, new \DateTimeImmutable('+30 days')));
        [, $token] = $this->seedInvite($client);

        $client->loginUser($tester);
        $this->claim($client, $token);
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-beta-stripe-hint]');
    }

    public function test_a_tester_whose_trial_ended_reaches_the_link_past_the_paywall(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $scenario = new BillingScenario($client->getContainer());
        $scenario->enableBilling();
        $tester = $scenario->verifiedUser('beta-lapsed');
        $scenario->profile($tester, new \DateTimeImmutable('-1 day'));
        [, $token] = $this->seedInvite($client);

        $client->loginUser($tester);
        $client->request(Request::METHOD_GET, '/beta/'.$token);
        self::assertResponseIsSuccessful();
        $client->request(Request::METHOD_POST, '/beta/'.$token, ['_csrf_token' => 'csrf-token']);

        self::assertResponseRedirects('/beta/'.$token);
        self::assertNotNull($this->currentComp($client, $tester));
    }

    public function test_an_unknown_token_shows_the_no_longer_valid_page(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/beta/'.str_repeat('0', 64));

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorExists('[data-beta-invite-invalid]');
        self::assertFalse($client->getRequest()->getSession()->has(RegistrationPasses::SESSION_KEY));
    }

    public function test_a_used_token_shows_the_no_longer_valid_page(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$invite, $token] = $this->seedInvite($client);
        $invite->redeem($this->persistUser($client, 'earlier-tester@example.com'));
        $client->getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request(Request::METHOD_GET, '/beta/'.$token);

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorExists('[data-beta-invite-invalid]');
    }

    public function test_a_revoked_token_shows_the_no_longer_valid_page_to_a_signed_in_user(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tester = new BillingScenario($client->getContainer())->verifiedUser('beta-revoked');
        [$invite, $token] = $this->seedInvite($client);
        $invite->revoke();
        $client->getContainer()->get(EntityManagerInterface::class)->flush();

        $client->loginUser($tester);
        $client->request(Request::METHOD_GET, '/beta/'.$token);

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorExists('[data-beta-invite-invalid]');
        self::assertSame([], $this->comps($client, $tester));
    }

    public function test_a_valid_link_404s_when_sign_up_is_switched_off(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist(new FeatureFlag(name: RegistrationGate::ENABLED_FLAG, type: FeatureFlagType::Bool, value: false));
        $em->flush();
        [, $token] = $this->seedInvite($client);

        $client->request(Request::METHOD_GET, '/beta/'.$token);

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorNotExists('[data-beta-invite-invalid]');
        self::assertFalse($client->getRequest()->getSession()->has(RegistrationPasses::SESSION_KEY));
    }

    public function test_a_signed_in_user_claims_the_link_when_sign_up_is_switched_off(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist(new FeatureFlag(name: RegistrationGate::ENABLED_FLAG, type: FeatureFlagType::Bool, value: false));
        $em->flush();
        $tester = new BillingScenario($client->getContainer())->verifiedUser('beta-switch-off');
        [, $token] = $this->seedInvite($client);

        $client->loginUser($tester);
        $this->claim($client, $token);

        self::assertNotNull($this->currentComp($client, $tester));
    }

    /**
     * The GET first, as a browser does: it also gives BrowserKit the history
     * that lets the stateless CSRF sentinel pass as same-origin.
     */
    private function claim(KernelBrowser $client, string $token, string $csrfToken = 'csrf-token'): void
    {
        $client->request(Request::METHOD_GET, '/beta/'.$token);
        $client->request(Request::METHOD_POST, '/beta/'.$token, ['_csrf_token' => $csrfToken]);
    }

    /** @return array{BetaInvite, string} */
    private function seedInvite(KernelBrowser $client, ?User $createdBy = null): array
    {
        [$invite, $token] = BetaInvite::issue($createdBy, 'test invite');
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($invite);
        $em->flush();

        return [$invite, $token];
    }

    /** @param non-empty-string $email */
    private function persistUser(KernelBrowser $client, string $email): User
    {
        $user = new User(fullName: 'Someone', email: $email, password: 'x');
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function closeRegistration(KernelBrowser $client): void
    {
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $em->persist(new User(fullName: 'Gate Filler', email: 'gate-filler@example.com', password: 'x'));
        $em->flush();

        $em->persist(new FeatureFlag(name: RegistrationGate::CAP_FLAG, type: FeatureFlagType::Int, value: $container->get(UserRepository::class)->countActive()));
        $em->flush();
    }

    private function reload(KernelBrowser $client, BetaInvite $invite): BetaInvite
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $client->getContainer()->get(BetaInviteRepository::class)->find($invite->id)
            ?? throw new \LogicException('the invite was persisted');
    }

    private function userByEmail(KernelBrowser $client, string $email): User
    {
        return $client->getContainer()->get(UserRepository::class)->findOneByEmail($email)
            ?? throw new \LogicException(sprintf('no account for %s', $email));
    }

    /** @return list<Subscription> */
    private function comps(KernelBrowser $client, User $user): array
    {
        $profile = $client->getContainer()->get(BillingProfileRepository::class)->findOneByUser($user);
        if (null === $profile) {
            return [];
        }

        return array_values(array_filter(
            $profile->subscriptions->toArray(),
            static fn (Subscription $subscription): bool => SubscriptionKind::Comp === $subscription->kind,
        ));
    }

    private function currentComp(KernelBrowser $client, User $user): ?Subscription
    {
        return $client->getContainer()->get(BillingProfileRepository::class)->findOneByUser($user)
            ?->currentSubscriptionOfKind(SubscriptionKind::Comp, new \DateTimeImmutable());
    }

    /**
     * Replaces the knpu client registry, so the real authenticator runs
     * without a provider round-trip.
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
