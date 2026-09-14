<?php

declare(strict_types=1);

namespace App\Tests\Mercure;

use App\Mercure\EventListener\SetMercureCookieOnResponse;
use App\Mercure\LiveUpdates;
use App\Mercure\MercureSubscriptions;
use App\Mercure\MercureTopicAuthorizerInterface;
use App\Outbox\AgentPush;
use App\Tests\Support\FeatureFlags;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\EventSubscriber\SetCookieSubscriber;
use Symfony\Component\Mercure\HubRegistry;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;

final class MercureSubscriptionsTest extends TestCase
{
    private const string HUB = 'https://hub.example.com/.well-known/mercure';
    private const string BOARD = 'https://loupe.example.com/projects/1/board';
    private const string REVIEW = 'https://loupe.example.com/documents/2/review';
    private const string REFUSED = 'https://loupe.example.com/documents/3/review';
    private const string UNCLAIMED = 'https://elsewhere.example.com/topic';

    public function test_two_modules_share_one_cookie_that_holds_only_their_allowed_topics(): void
    {
        $log = new TestHandler();
        [$subscriptions, $authorization, $request] = $this->subscriptions(self::HUB, $log);

        foreach ([self::BOARD, self::REVIEW, self::REFUSED, self::UNCLAIMED, self::BOARD] as $topic) {
            $subscriptions->request($topic);
        }

        self::assertSame([self::BOARD, self::REVIEW], $subscriptions->allowedTopics());

        $cookies = $this->respond($subscriptions, $authorization, $request);
        self::assertCount(1, $cookies);
        self::assertSame('mercureAuthorization', $cookies[0]->getName());
        self::assertSame([self::BOARD, self::REVIEW], $this->claims($cookies[0])['mercure']['subscribe'] ?? null);
        self::assertTrue($log->hasInfoThatContains('mercure.topic_refused'));
        self::assertTrue($log->hasInfoThatContains('mercure.topic_unclaimed'));
    }

    public function test_a_page_with_no_allowed_topic_gets_no_cookie(): void
    {
        [$subscriptions, $authorization, $request] = $this->subscriptions(self::HUB, new TestHandler());
        $subscriptions->request(self::REFUSED);

        self::assertSame([], $subscriptions->allowedTopics());
        self::assertSame([], $this->respond($subscriptions, $authorization, $request));
    }

    public function test_a_topic_requested_after_the_first_read_still_reaches_the_cookie(): void
    {
        [$subscriptions, $authorization, $request] = $this->subscriptions(self::HUB, new TestHandler());
        $subscriptions->request(self::BOARD);
        self::assertSame([self::BOARD], $subscriptions->allowedTopics());

        $subscriptions->request(self::REVIEW);

        $cookies = $this->respond($subscriptions, $authorization, $request);
        self::assertSame([self::BOARD, self::REVIEW], $this->claims($cookies[0])['mercure']['subscribe'] ?? null);
    }

    public function test_a_hub_on_another_site_allows_nothing(): void
    {
        $log = new TestHandler();
        [$subscriptions, $authorization, $request] = $this->subscriptions('https://hub.elsewhere.test/.well-known/mercure', $log);
        $subscriptions->request(self::BOARD);

        self::assertSame([], $subscriptions->allowedTopics());
        self::assertSame([], $this->respond($subscriptions, $authorization, $request));
        self::assertTrue($log->hasWarningThatContains('mercure.authorization_failed'));
    }

    public function test_a_blank_public_hub_url_allows_nothing(): void
    {
        [$subscriptions, $authorization, $request] = $this->subscriptions('', new TestHandler());
        $subscriptions->request(self::BOARD);

        self::assertSame([], $subscriptions->allowedTopics());
        self::assertSame([], $this->respond($subscriptions, $authorization, $request));
    }

    public function test_live_updates_off_allows_nothing_even_with_agent_push_on(): void
    {
        [$subscriptions] = $this->subscriptions(self::HUB, new TestHandler(), [LiveUpdates::FLAG => false, AgentPush::FLAG => true]);
        $subscriptions->request(self::BOARD);

        self::assertSame([], $subscriptions->allowedTopics());
    }

    public function test_live_updates_on_allows_topics_with_agent_push_off(): void
    {
        [$subscriptions] = $this->subscriptions(self::HUB, new TestHandler(), [LiveUpdates::FLAG => true, AgentPush::FLAG => false]);
        $subscriptions->request(self::BOARD);

        self::assertSame([self::BOARD], $subscriptions->allowedTopics());
    }

    public function test_reset_forgets_the_requested_topics(): void
    {
        [$subscriptions] = $this->subscriptions(self::HUB, new TestHandler());
        $subscriptions->request(self::BOARD);
        self::assertSame([self::BOARD], $subscriptions->allowedTopics());

        $subscriptions->reset();

        self::assertSame([], $subscriptions->allowedTopics());
    }

    /**
     * @param array<string, bool> $flags
     *
     * @return array{MercureSubscriptions, Authorization, Request}
     */
    private function subscriptions(string $hubUrl, TestHandler $log, array $flags = [LiveUpdates::FLAG => true]): array
    {
        $hub = new MockHub(
            'http://mercure/.well-known/mercure',
            new StaticTokenProvider('token'),
            static fn (): string => 'id',
            new LcobucciFactory(str_repeat('s', 32)),
            $hubUrl,
        );
        $authorization = new Authorization(new HubRegistry($hub));
        $request = Request::create('https://loupe.example.com/projects/1/board');
        $requests = new RequestStack();
        $requests->push($request);

        $subscriptions = new MercureSubscriptions(
            [
                $this->authorizer('https://loupe.example.com/projects/', [self::BOARD]),
                $this->authorizer('https://loupe.example.com/documents/', [self::REVIEW]),
            ],
            FeatureFlags::service($flags),
            $requests,
            new Logger('test', [$log]),
            static fn (): Authorization => $authorization,
            $hubUrl,
        );

        return [$subscriptions, $authorization, $request];
    }

    /** @param list<string> $allowed */
    private function authorizer(string $owns, array $allowed): MercureTopicAuthorizerInterface
    {
        return new readonly class($owns, $allowed) implements MercureTopicAuthorizerInterface {
            /** @param list<string> $allowed */
            public function __construct(
                private string $owns,
                private array $allowed,
            ) {
            }

            #[\Override]
            public function mayCurrentUserSubscribe(string $topic): ?bool
            {
                return str_starts_with($topic, $this->owns) ? \in_array($topic, $this->allowed, true) : null;
            }
        };
    }

    /** @return list<Cookie> */
    private function respond(MercureSubscriptions $subscriptions, Authorization $authorization, Request $request): array
    {
        $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, new Response());
        new SetMercureCookieOnResponse($subscriptions, static fn (): Authorization => $authorization)($event);
        new SetCookieSubscriber()->onKernelResponse($event);

        return array_values($event->getResponse()->headers->getCookies());
    }

    /** @return array<string, mixed> */
    private function claims(Cookie $cookie): array
    {
        $parts = explode('.', (string) $cookie->getValue());
        self::assertCount(3, $parts);
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        self::assertIsArray($claims);
        self::assertIsArray($claims['mercure'] ?? null);

        return $claims;
    }
}
