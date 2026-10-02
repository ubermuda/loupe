<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\Service\CardHolds;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\AgentCredential;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

final class ListCardHoldsControllerTest extends WebTestCase
{
    use BridgeScenario;

    public function test_lists_the_holds_of_every_project_the_user_owns(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $clock = new MockClock('2026-10-01 12:00:00');
        static::getContainer()->set('clock', $clock);
        $em = $this->em();
        $owner = $this->user($em, 'holds-list@example.com');
        $first = $this->project($em, $owner, 'Holds first');
        $second = $this->project($em, $owner, 'Holds second');
        $stranger = $this->user($em, 'holds-list-other@example.com');
        $foreign = $this->project($em, $stranger, 'Holds foreign');
        $one = Uuid::v7();
        $two = Uuid::v7();
        $this->cardHolds()->hold($second, $two, $owner);
        $clock->modify('-1 minute');
        $this->cardHolds()->hold($first, $one, $owner);
        $this->cardHolds()->hold($foreign, Uuid::v7(), $stranger);

        $data = $this->list($client, $this->agentToken($client, $owner));

        self::assertSame(['holds' => [
            ['projectId' => $first->id?->toRfc4122(), 'cardId' => $one->toRfc4122()],
            ['projectId' => $second->id?->toRfc4122(), 'cardId' => $two->toRfc4122()],
        ]], $data);
    }

    public function test_no_hold_is_an_empty_list(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $owner = $this->user($em, 'holds-list-empty@example.com');
        $this->project($em, $owner, 'Holds empty');

        $client->request(Request::METHOD_GET, '/api/card-holds', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->agentToken($client, $owner)]);

        self::assertResponseIsSuccessful();
        self::assertSame('{"holds":[]}', $client->getResponse()->getContent());
    }

    public function test_a_token_without_the_agent_scope_is_forbidden(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $owner = $this->user($em, 'holds-list-widget@example.com');
        $project = $this->project($em, $owner, 'Holds widget');
        $this->cardHolds()->hold($project, Uuid::v7(), $owner);
        $raw = AgentCredential::tokenFor(static::getContainer(), $owner, 'site-review', $project);

        $client->request(Request::METHOD_GET, '/api/card-holds', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(403);
        self::assertJsonStringEqualsJsonString('{"error":"insufficient_scope"}', (string) $client->getResponse()->getContent());
    }

    public function test_no_token_is_unauthorized(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/api/card-holds');

        self::assertResponseStatusCodeSame(401);
    }

    public function test_the_limit_counts_per_token(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.agent_card_holds', new RateLimiterFactory(
            ['id' => 'agent_card_holds', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $em = $this->em();
        $first = $this->agentToken($client, $this->user($em, 'holds-limit@example.com'));
        $second = $this->agentToken($client, $this->user($em, 'holds-limit-other@example.com'));

        $this->list($client, $first);

        $client->request(Request::METHOD_GET, '/api/card-holds', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$first]);
        self::assertResponseStatusCodeSame(429);

        $this->list($client, $second);
    }

    /** @return array<mixed> */
    private function list(KernelBrowser $client, string $raw): array
    {
        $client->request(Request::METHOD_GET, '/api/card-holds', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    private function cardHolds(): CardHolds
    {
        $cardHolds = static::getContainer()->get(CardHolds::class);
        self::assertInstanceOf(CardHolds::class, $cardHolds);

        return $cardHolds;
    }
}
