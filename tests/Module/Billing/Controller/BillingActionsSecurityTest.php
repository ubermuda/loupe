<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Controller;

use App\Module\Account\Entity\User;
use App\Module\Billing\Service\StripeGatewayInterface;
use App\Tests\Support\BillingScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two endpoints that spend money guard themselves the same way: POST only,
 * authenticated, CSRF-checked, and inert while the billing flag is off.
 */
final class BillingActionsSecurityTest extends WebTestCase
{
    private const string VALID_CSRF_TOKEN = 'csrf-token';

    /** @return iterable<string, array{string}> */
    public static function mutationEndpoints(): iterable
    {
        yield 'checkout' => ['/billing/checkout'];
        yield 'portal' => ['/billing/portal'];
    }

    /** @return StripeGatewayInterface&MockObject */
    private function stubGateway(KernelBrowser $client): StripeGatewayInterface
    {
        $stripe = $this->createMock(StripeGatewayInterface::class);
        $client->getContainer()->set(StripeGatewayInterface::class, $stripe);

        return $stripe;
    }

    /**
     * Logs the user in and makes one GET first: the stateless CSRF sentinel is
     * only accepted once BrowserKit has an origin cookie and history.
     */
    private function authenticate(KernelBrowser $client, User $user): void
    {
        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/projects');
    }

    #[DataProvider('mutationEndpoints')]
    public function test_get_is_not_allowed(string $url): void
    {
        $client = static::createClient();
        $client->loginUser(new BillingScenario(static::getContainer())->verifiedUser('getter'.substr(md5($url), 0, 8)));

        $client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[DataProvider('mutationEndpoints')]
    public function test_anonymous_posts_are_sent_to_the_login_page(string $url): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_POST, $url, ['_csrf_token' => self::VALID_CSRF_TOKEN]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[DataProvider('mutationEndpoints')]
    public function test_an_invalid_csrf_token_is_rejected(string $url): void
    {
        $client = static::createClient();
        $client->loginUser(new BillingScenario(static::getContainer())->verifiedUser('csrf'.substr(md5($url), 0, 8)));

        $client->request(Request::METHOD_POST, $url, ['_csrf_token' => 'not-a-valid-token']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[DataProvider('mutationEndpoints')]
    public function test_while_billing_is_disabled_nothing_reaches_stripe(string $url): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $stripe = $this->stubGateway($client);
        $stripe->expects($this->never())->method('createCustomer');
        $stripe->expects($this->never())->method('createCheckoutSession');
        $stripe->expects($this->never())->method('createPortalSession');

        $scenario = new BillingScenario(static::getContainer());
        $this->authenticate($client, $scenario->verifiedUser('dark'.substr(md5($url), 0, 8)));

        $client->request(Request::METHOD_POST, $url, ['_csrf_token' => self::VALID_CSRF_TOKEN]);

        self::assertResponseRedirects('/billing/subscribe');
    }

    public function test_checkout_redirects_to_the_stripe_hosted_page(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $stripe = $this->stubGateway($client);
        $stripe->method('createCustomer')->willReturn('cus_functional');
        $stripe->expects($this->once())
            ->method('createCheckoutSession')
            ->willReturn('https://checkout.stripe.test/session');

        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        $scenario->priceFlag('price_functional_checkout');
        $user = $scenario->verifiedUser('checkoutok');

        $this->authenticate($client, $user);
        $client->request(Request::METHOD_POST, '/billing/checkout', ['_csrf_token' => self::VALID_CSRF_TOKEN]);

        self::assertResponseStatusCodeSame(Response::HTTP_SEE_OTHER);
        self::assertResponseRedirects('https://checkout.stripe.test/session');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $stored = $em->getConnection()->fetchOne(
            'SELECT stripe_customer_id FROM billing_profiles WHERE user_id = ?',
            [(string) $user->id],
        );
        self::assertSame('cus_functional', $stored);
    }

    public function test_checkout_without_a_price_flag_flashes_an_error(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $stripe = $this->stubGateway($client);
        $stripe->expects($this->never())->method('createCheckoutSession');

        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();

        $this->authenticate($client, $scenario->verifiedUser('noprice'));
        $client->request(Request::METHOD_POST, '/billing/checkout', ['_csrf_token' => self::VALID_CSRF_TOKEN]);

        self::assertResponseRedirects('/billing/subscribe');
        $crawler = $client->followRedirect();
        self::assertCount(1, $crawler->filter('.lp-flash--error'));
    }

    public function test_portal_redirects_a_stripe_customer_to_the_portal(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $stripe = $this->stubGateway($client);
        $stripe->expects($this->once())
            ->method('createPortalSession')
            ->willReturn('https://portal.stripe.test/session');

        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();
        $user = $scenario->verifiedUser('portalok');
        $profile = $scenario->profile($user, new \DateTimeImmutable('+5 days'));
        $profile->stripeCustomerId = 'cus_portal';
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->authenticate($client, $user);
        $client->request(Request::METHOD_POST, '/billing/portal', ['_csrf_token' => self::VALID_CSRF_TOKEN]);

        self::assertResponseStatusCodeSame(Response::HTTP_SEE_OTHER);
        self::assertResponseRedirects('https://portal.stripe.test/session');
    }

    public function test_portal_without_a_stripe_customer_flashes_an_error(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $stripe = $this->stubGateway($client);
        $stripe->expects($this->never())->method('createPortalSession');

        $scenario = new BillingScenario(static::getContainer());
        $scenario->enableBilling();

        $this->authenticate($client, $scenario->verifiedUser('noportal'));
        $client->request(Request::METHOD_POST, '/billing/portal', ['_csrf_token' => self::VALID_CSRF_TOKEN]);

        self::assertResponseRedirects('/billing/subscribe');
        $crawler = $client->followRedirect();
        self::assertCount(1, $crawler->filter('.lp-flash--error'));
    }
}
