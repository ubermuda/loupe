<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use App\Tests\Support\BillingScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AcceptTermsControllerTest extends WebTestCase
{
    private const string ACCEPTANCE_PATH = '/account/accept-terms';

    public function test_user_who_has_not_accepted_is_redirected_to_the_acceptance_page(): void
    {
        $client = static::createClient();
        $this->login($client, 'gated@example.com', termsVersion: null);

        $client->request(Request::METHOD_GET, '/projects');

        self::assertResponseRedirects(self::ACCEPTANCE_PATH);
    }

    public function test_user_on_an_old_terms_version_is_reprompted(): void
    {
        $client = static::createClient();
        $this->login($client, 'stale@example.com', termsVersion: '2019-01-01');

        $client->request(Request::METHOD_GET, '/projects');

        self::assertResponseRedirects(self::ACCEPTANCE_PATH);
    }

    public function test_acceptance_page_itself_returns_200_while_gated(): void
    {
        $client = static::createClient();
        $this->login($client, 'interstitial@example.com', termsVersion: null);

        $client->request(Request::METHOD_GET, self::ACCEPTANCE_PATH);

        // The loop guard: the gate must not divert its own page.
        self::assertResponseIsSuccessful();
    }

    public function test_accepting_records_the_version_and_returns_to_the_intended_page(): void
    {
        $client = static::createClient();
        $user = $this->login($client, 'accepting@example.com', termsVersion: null);

        $client->request(Request::METHOD_GET, '/projects');
        self::assertResponseRedirects(self::ACCEPTANCE_PATH);

        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $client->submitForm('I accept');
        self::assertResponseRedirects('/projects');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->find(User::class, $user->id);

        self::assertInstanceOf(User::class, $reloaded);
        self::assertNotNull($reloaded->termsAcceptedAt);
        self::assertSame(
            static::getContainer()->getParameter('app.terms.version'),
            $reloaded->termsVersion,
        );
    }

    public function test_accepting_without_an_intended_page_lands_on_home(): void
    {
        $client = static::createClient();
        $this->login($client, 'direct@example.com', termsVersion: null);

        $client->request(Request::METHOD_GET, self::ACCEPTANCE_PATH);
        $client->submitForm('I accept');

        self::assertResponseRedirects('/');
    }

    public function test_a_paywalled_user_can_still_reach_the_acceptance_page(): void
    {
        $client = static::createClient();

        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        $user = $scenario->verifiedUser('paywalledterms');
        $scenario->profile($user, new \DateTimeImmutable('-1 day'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user->termsAcceptedAt = null;
        $user->termsVersion = null;
        $em->flush();
        $em->clear();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, self::ACCEPTANCE_PATH);

        // Guards #[PaywallExempt] on the acceptance controllers. Without it the
        // paywall (priority 4) sends this to /billing/subscribe, which the terms
        // gate (priority 3) then sends straight back here — forever.
        self::assertResponseIsSuccessful();
    }

    public function test_legal_pages_are_reachable_while_gated(): void
    {
        $client = static::createClient();
        $this->login($client, 'reader@example.com', termsVersion: null);

        foreach (['/terms', '/privacy', '/ai-policy'] as $path) {
            $client->request(Request::METHOD_GET, $path);
            self::assertResponseIsSuccessful("{$path} must stay readable to be acceptable");
        }
    }

    public function test_logout_is_reachable_while_gated(): void
    {
        $client = static::createClient();
        $this->login($client, 'leaver@example.com', termsVersion: null);

        $client->request(Request::METHOD_GET, '/logout');

        self::assertNotSame(self::ACCEPTANCE_PATH, $client->getResponse()->headers->get('Location'));
    }

    public function test_machine_endpoints_are_not_diverted(): void
    {
        $client = static::createClient();
        $this->login($client, 'agent@example.com', termsVersion: null);

        foreach (['/mcp', '/api/site-review/sites'] as $path) {
            $client->request(Request::METHOD_GET, $path);
            self::assertNotSame(
                self::ACCEPTANCE_PATH,
                $client->getResponse()->headers->get('Location'),
                "{$path} carries a bearer token and cannot accept anything",
            );
        }
    }

    /** @param non-empty-string $email */
    private function login(KernelBrowser $client, string $email, ?string $termsVersion): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User(fullName: 'Terms User', email: $email, password: 'hashed-placeholder');
        // Verified, or RedirectUnverifiedUserListener diverts first: it runs at
        // priority 5, above this gate's 3.
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $user->termsVersion = $termsVersion;
        $user->termsAcceptedAt = null === $termsVersion ? null : new \DateTimeImmutable();

        $em->persist($user);
        $em->flush();
        $em->clear();

        $client->loginUser($user);

        return $user;
    }
}
