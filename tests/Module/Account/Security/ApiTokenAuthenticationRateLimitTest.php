<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Security;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Account\EventListener\RateLimitApiAuthentication;
use App\Module\Review\EventListener\RateLimitMcpRequests;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Http\Firewall;

/**
 * The record the limiter exists to bound: every rejected API token writes one
 * audit row, and the row count is what shows whether the throttle ran before
 * the firewall or after it.
 */
final class ApiTokenAuthenticationRateLimitTest extends WebTestCase
{
    private const string OPERATION = 'account.api_token.authentication_failed';

    private const string INITIALIZE = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}';

    /**
     * The site-review widget token authenticates on every page view of a
     * customer's site, and those visitors share one bucket behind a NAT. An
     * allowance of one proves the point: ten accepted calls spend nothing, and
     * the first rejected token still gets the whole allowance.
     */
    public function test_an_accepted_bearer_request_spends_no_allowance(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->useBucketOf(1);
        $raw = $this->issueMcpToken();

        $before = $this->failureRecords();

        for ($i = 0; $i < 10; ++$i) {
            $this->callMcp($client, $raw);
            self::assertResponseIsSuccessful();
        }

        $this->callWithBadToken($client);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame($before + 1, $this->failureRecords());

        $this->callWithBadToken($client);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function test_repeated_failures_exhaust_the_allowance(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->useBucketOf(3);

        $before = $this->failureRecords();

        for ($i = 0; $i < 3; ++$i) {
            $this->callWithBadToken($client);
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        }

        self::assertSame($before + 3, $this->failureRecords());

        $this->callWithBadToken($client);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame($before + 3, $this->failureRecords());
    }

    public function test_a_throttled_request_writes_no_authentication_failure_record(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->useBucketOf(1);

        $before = $this->failureRecords();

        $this->callWithBadToken($client);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame($before + 1, $this->failureRecords());

        $this->callWithBadToken($client);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        // The authenticator never ran on the second call, so the trail did not grow.
        self::assertSame($before + 1, $this->failureRecords());
    }

    /**
     * The defect this listener fixes, asserted as the ordering it depends on: a
     * limiter below the firewall never sees a request the firewall answers.
     */
    public function test_the_limiter_runs_before_the_firewall_and_the_older_ones_after_it(): void
    {
        static::createClient();
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $firewall = $this->priorityOf($dispatcher, Firewall::class);

        self::assertGreaterThan($firewall, $this->priorityOf($dispatcher, RateLimitApiAuthentication::class));
        self::assertLessThan($firewall, $this->priorityOf($dispatcher, RateLimitMcpRequests::class));
    }

    private function priorityOf(EventDispatcherInterface $dispatcher, string $class): int
    {
        foreach ($dispatcher->getListeners(KernelEvents::REQUEST) as $listener) {
            $target = is_array($listener) ? $listener[0] : $listener;
            if (!$target instanceof $class) {
                continue;
            }

            $priority = $dispatcher->getListenerPriority(KernelEvents::REQUEST, $listener);
            if (null !== $priority) {
                return $priority;
            }
        }

        self::fail(sprintf('%s does not listen to kernel.request.', $class));
    }

    private function useBucketOf(int $limit): void
    {
        static::getContainer()->set('limiter.api_authentication', new RateLimiterFactory(
            ['id' => 'api_authentication', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        ));
    }

    /** @return non-empty-string */
    private function issueMcpToken(): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $user = new User(fullName: 'Widget Owner', email: 'widget-owner@example.com', password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $raw] = ApiToken::issue($user, 'rate limit token', ApiTokenScope::Mcp);
        $em->persist($token);
        $em->flush();
        $em->clear();

        return $raw;
    }

    private function callMcp(KernelBrowser $client, string $raw): void
    {
        $client->request(
            Request::METHOD_POST,
            '/mcp',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'CONTENT_TYPE' => 'application/json'],
            content: self::INITIALIZE,
        );
    }

    private function callWithBadToken(KernelBrowser $client): void
    {
        $client->request(
            Request::METHOD_POST,
            '/mcp',
            server: ['HTTP_AUTHORIZATION' => 'Bearer not-a-real-token', 'CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","id":1,"method":"tools/list"}',
        );
    }

    private function failureRecords(): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $connection = $em->getConnection();
        self::assertInstanceOf(Connection::class, $connection);

        return (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE operation = :operation',
            ['operation' => self::OPERATION],
        );
    }
}
