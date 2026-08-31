<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Controller\LinkSocialAccountController;
use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ConnectedAccountRepository;
use App\Module\Account\Repository\UserRepository;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use App\Tests\Support\SocialLoginScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class LinkSocialAccountControllerTest extends WebTestCase
{
    use SocialLoginScenario;

    private const string PASSWORD = 'LinkPass123!';
    private const string PROVIDER_USER_ID = 'google-uid-123';
    private const string EMAIL = 'pending@example.com';

    public function test_get_without_a_pending_identity_redirects_to_login(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/oauth/link');

        self::assertResponseRedirects('/login');
    }

    public function test_get_with_a_pending_identity_renders_the_password_form(): void
    {
        $client = static::createClient();
        $user = $this->seedUser($client);
        $this->setProviderFlag($client, SocialProvider::Google, true);
        $this->primePending($client, $user);

        $client->request(Request::METHOD_GET, '/oauth/link');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[type="password"]');
        self::assertStringContainsString(self::EMAIL, (string) $client->getResponse()->getContent());
    }

    public function test_wrong_password_returns_422_links_nothing_and_keeps_the_pending_identity(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $user = $this->seedUser($client);
        $this->setProviderFlag($client, SocialProvider::Google, true);
        $this->primePending($client, $user);

        $client->request(Request::METHOD_GET, '/oauth/link');
        $client->submitForm('Link account and sign in', $this->passwordField($client, 'wrong-password'));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull($this->connectedAccounts($client)->findOneByProviderAndProviderUserId(SocialProvider::Google, self::PROVIDER_USER_ID));

        // The pending identity survives, so a second, correct attempt works.
        $client->submitForm('Link account and sign in', $this->passwordField($client, self::PASSWORD));
        self::assertResponseRedirects();
        self::assertNotNull($this->connectedAccounts($client)->findOneByProviderAndProviderUserId(SocialProvider::Google, self::PROVIDER_USER_ID));
    }

    public function test_correct_password_links_the_identity_retro_verifies_and_signs_in(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $user = $this->seedUser($client, verified: false);
        $this->setProviderFlag($client, SocialProvider::Google, true);
        $this->primePending($client, $user);

        $client->request(Request::METHOD_GET, '/oauth/link');
        $client->submitForm('Link account and sign in', $this->passwordField($client, self::PASSWORD));

        self::assertResponseRedirects();

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $client->getContainer()->get(UserRepository::class)->find($user->id);
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->emailVerifiedAt);

        $linked = $this->connectedAccounts($client)->findOneByProviderAndProviderUserId(SocialProvider::Google, self::PROVIDER_USER_ID);
        self::assertNotNull($linked);
        self::assertSame((string) $user->id, (string) $linked->user->id);

        // The remember-me cookie proves the social sign-in badge was enabled.
        self::assertNotNull($client->getCookieJar()->get('REMEMBERME'));

        // The pending identity is consumed: replaying the POST falls back to login.
        $client->request(Request::METHOD_POST, '/oauth/link');
        self::assertResponseRedirects('/login');
    }

    public function test_a_completed_link_is_recorded_against_the_account(): void
    {
        $client = static::createClient();
        $audit = RecordingAuditor::installedIn($client->getContainer());
        $client->disableReboot();
        $user = $this->seedUser($client, verified: false);
        $this->setProviderFlag($client, SocialProvider::Google, true);
        $this->primePending($client, $user);
        $audit->forget();

        $client->request(Request::METHOD_GET, '/oauth/link');
        $client->submitForm('Link account and sign in', $this->passwordField($client, self::PASSWORD));

        self::assertResponseRedirects();

        $record = $audit->record('account.social.linked');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame(
            ['provider' => 'google', 'userId' => (string) $user->id],
            $record->context,
        );
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame((string) $user->id, $record->subject->id);

        self::assertContains('account.social.linked', $audit->domainLogLines());
        self::assertSame([], $audit->securityLogLines());
    }

    public function test_a_throttled_link_attempt_is_recorded_as_a_refusal(): void
    {
        $client = static::createClient();
        $audit = RecordingAuditor::installedIn($client->getContainer());
        $client->disableReboot();
        $user = $this->seedUser($client);
        $this->setProviderFlag($client, SocialProvider::Google, true);
        $this->primePending($client, $user);

        $client->getContainer()->set('limiter.oauth_link', new RateLimiterFactory(
            ['id' => 'oauth_link', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));

        $client->request(Request::METHOD_GET, '/oauth/link');
        $client->submitForm('Link account and sign in', $this->passwordField($client, 'wrong-password'));
        $audit->forget();
        $client->submitForm('Link account and sign in', $this->passwordField($client, self::PASSWORD));

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        $record = $audit->record('account.social.link_throttled');
        self::assertSame(AuditOutcome::Refused, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame(
            ['provider' => 'google', 'userId' => (string) $user->id],
            $record->context,
        );
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame((string) $user->id, $record->subject->id);

        self::assertContains('account.social.link_throttled', $audit->domainLogLines());
    }

    public function test_the_controller_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(LinkSocialAccountController::class);
    }

    public function test_the_account_email_changing_after_the_callback_rejects_the_link(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $user = $this->seedUser($client);
        $this->setProviderFlag($client, SocialProvider::Google, true);
        $this->primePending($client, $user);

        $client->request(Request::METHOD_GET, '/oauth/link');

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $user->email = 'moved@example.com';
        $em->flush();

        $client->submitForm('Link account and sign in', $this->passwordField($client, self::PASSWORD));

        self::assertResponseRedirects('/login?social_error=1');
        self::assertNull($this->connectedAccounts($client)->findOneByProviderAndProviderUserId(SocialProvider::Google, self::PROVIDER_USER_ID));
    }

    public function test_a_disabled_provider_flag_404s_mid_flow(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $user = $this->seedUser($client);
        $this->setProviderFlag($client, SocialProvider::Google, false);
        $this->primePending($client, $user);

        $client->request(Request::METHOD_GET, '/oauth/link');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request(Request::METHOD_POST, '/oauth/link');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function test_repeated_attempts_are_throttled(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $user = $this->seedUser($client);
        $this->setProviderFlag($client, SocialProvider::Google, true);
        $this->primePending($client, $user);

        $client->getContainer()->set('limiter.oauth_link', new RateLimiterFactory(
            ['id' => 'oauth_link', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));

        $client->request(Request::METHOD_GET, '/oauth/link');
        $client->submitForm('Link account and sign in', $this->passwordField($client, 'wrong-password'));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $client->submitForm('Link account and sign in', $this->passwordField($client, 'wrong-password'));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $client->submitForm('Link account and sign in', $this->passwordField($client, self::PASSWORD));
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        // Throttling must not consume the pending identity or link anything.
        self::assertNull($this->connectedAccounts($client)->findOneByProviderAndProviderUserId(SocialProvider::Google, self::PROVIDER_USER_ID));
    }

    /** @return array<string, string> */
    private function passwordField(KernelBrowser $client, string $password): array
    {
        $name = $client->getCrawler()->filter('input[type="password"]')->attr('name');

        return [(string) $name => $password];
    }

    private function connectedAccounts(KernelBrowser $client): ConnectedAccountRepository
    {
        return $client->getContainer()->get(ConnectedAccountRepository::class);
    }

    private function seedUser(KernelBrowser $client, bool $verified = true): User
    {
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User(fullName: 'Link User', email: self::EMAIL);
        AcceptedTerms::stamp($user, $container);
        $user->password = $hasher->hashPassword($user, self::PASSWORD);
        if ($verified) {
            $user->emailVerifiedAt = new \DateTimeImmutable();
        }
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function primePending(KernelBrowser $client, User $user): void
    {
        $this->primePendingSocialLink(
            $client,
            SocialProvider::Google,
            self::PROVIDER_USER_ID,
            self::EMAIL,
            (string) $user->id,
        );
    }
}
