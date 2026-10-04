<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Module\Account\Entity\User;
use App\Module\Bridge\BridgeEventType;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Project\Entity\Project;
use App\Module\Project\ProjectEventType;
use App\Outbox\AgentPush;
use App\Outbox\Entity\OutboxEvent;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\EventBridge;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class ShowEventReplayControllerTest extends WebTestCase
{
    /** @var array<string, Bridge> */
    private array $bridges = [];

    public function test_returns_the_callers_events_after_the_cursor_in_sequence_order(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        [$raw, $user, $first] = $this->issue($client, 'replay-order@example.com');
        $second = new Project($user, 'Second Replay Site');
        $em->persist($second);
        $em->flush();
        [, , $foreign] = $this->issue($client, 'replay-order-other@example.com');

        $one = $this->row($first, '{"n":1,"text":"café \/ \"quoted\""}');
        $this->row($foreign, '{"foreign":true}');
        $two = $this->row($second, '{"n":2}');
        $two->markPublished();
        $em->flush();
        $three = $this->row($first, '{"n":3}');

        $data = $this->replay($client, $raw, 0);

        self::assertSame([
            'events' => [
                ['id' => $one->sequence, 'type' => BridgeEventType::COMMAND, 'data' => '{"n":1,"text":"café \/ \"quoted\""}'],
                ['id' => $two->sequence, 'type' => BridgeEventType::COMMAND, 'data' => '{"n":2}'],
                ['id' => $three->sequence, 'type' => BridgeEventType::COMMAND, 'data' => '{"n":3}'],
            ],
            'hasMore' => false,
        ], $data);
    }

    public function test_a_cursor_at_the_head_returns_nothing_new(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, , $project] = $this->issue($client, 'replay-head@example.com');
        $old = new \DateTimeImmutable('-1 hour');
        $this->row($project, '{}', $old);
        $last = $this->row($project, '{}', $old->modify('+10 minutes'));

        $data = $this->replay($client, $raw, (int) $last->sequence);

        self::assertSame(false, $data['hasMore']);
        self::assertSame([(string) $last->sequence], array_column($this->eventsOf($data), 'id'));
    }

    /** A row committed late can carry a lower sequence than one already seen, so rows just below the cursor repeat. */
    public function test_rows_at_or_below_the_cursor_created_within_two_minutes_of_the_anchor_repeat(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, , $project] = $this->issue($client, 'replay-overlap@example.com');
        $anchorAt = new \DateTimeImmutable('2026-09-01 12:00:00');

        $this->row($project, '{"age":"old"}', $anchorAt->modify('-3 minutes'));
        $recent = $this->row($project, '{"age":"recent"}', $anchorAt->modify('-1 minute'));
        $anchor = $this->row($project, '{"age":"anchor"}', $anchorAt);
        $next = $this->row($project, '{"age":"next"}', $anchorAt->modify('+1 minute'));

        $data = $this->replay($client, $raw, (int) $anchor->sequence);

        self::assertSame(
            [$recent->sequence, $anchor->sequence, $next->sequence],
            array_column($this->eventsOf($data), 'id'),
        );
        self::assertSame(false, $data['hasMore']);
    }

    /** The anchor is the row at the cursor whoever owns it; only its time is read, and a foreign row never shows. */
    public function test_the_anchor_may_be_a_foreign_row_and_stays_out_of_the_result(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, , $project] = $this->issue($client, 'replay-foreign-anchor@example.com');
        [, , $foreign] = $this->issue($client, 'replay-foreign-anchor-other@example.com');
        $base = new \DateTimeImmutable('2026-09-01 12:00:00');

        $this->row($project, '{"own":true}', $base);
        $foreignAnchor = $this->row($foreign, '{"foreign":true}', $base->modify('+10 minutes'));

        $data = $this->replay($client, $raw, (int) $foreignAnchor->sequence);

        self::assertSame([], $data['events']);
    }

    public function test_a_page_holds_two_hundred_new_rows_and_says_more_remain(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        [$raw, , $project] = $this->issue($client, 'replay-paging@example.com');
        $rows = [];
        for ($index = 0; $index < 202; ++$index) {
            $rows[] = $event = new OutboxEvent($project, BridgeEventType::COMMAND, 'topic', '{"i":'.$index.'}');
            $em->persist($event);
        }
        $em->flush();
        $sequences = array_map(static fn (OutboxEvent $event): ?string => $event->sequence, $rows);
        usort($sequences, static fn (?string $a, ?string $b): int => (int) $a <=> (int) $b);

        $first = $this->replay($client, $raw, 0);

        self::assertSame(true, $first['hasMore']);
        self::assertSame(\array_slice($sequences, 0, 200), array_column($this->eventsOf($first), 'id'));

        // Every row sits inside the overlap window, and the overlap keeps the 200 highest at or below the cursor.
        $second = $this->replay($client, $raw, (int) $sequences[200]);

        self::assertSame(false, $second['hasMore']);
        self::assertSame(\array_slice($sequences, 1, 201), array_column($this->eventsOf($second), 'id'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCursors(): iterable
    {
        yield 'missing' => [''];
        yield 'negative' => ['?after=-1'];
        yield 'not an integer' => ['?after=abc'];
        yield 'a fraction' => ['?after=1.5'];
    }

    #[DataProvider('invalidCursors')]
    public function test_an_invalid_cursor_is_a_bad_request(string $query): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw] = $this->issue($client, 'replay-invalid@example.com');

        $client->request(Request::METHOD_GET, '/api/events/replay'.$query, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(400);
    }

    public function test_only_the_types_a_bridge_runs_are_replayed(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, , $project] = $this->issue($client, 'replay-types@example.com');
        $old = new \DateTimeImmutable('-1 hour');
        $kept = [];
        foreach ([BridgeEventType::WORK_REQUEST, 'board.card_moved', BridgeEventType::COMMAND, ProjectEventType::RENAMED, 'pull_request.merged', BridgeEventType::CARD_HELD, BridgeEventType::CARD_RELEASED] as $type) {
            $event = $this->row($project, '{}', $old, $type);
            if (!\in_array($type, ['board.card_moved', 'pull_request.merged'], true)) {
                $kept[] = $event->sequence;
            }
        }
        $anchor = $this->row($project, '{}', $old->modify('+10 minutes'), 'board.card_moved');
        $this->row($project, '{}', $old->modify('+10 minutes'), 'board.card_moved');

        self::assertSame($kept, array_column($this->eventsOf($this->replay($client, $raw, 0)), 'id'));
        self::assertSame([], $this->eventsOf($this->replay($client, $raw, (int) $anchor->sequence)));
    }

    public function test_a_bridge_that_runs_no_work_requests_is_told_to_upgrade(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        [$raw, $user, $project] = $this->issue($client, 'replay-refused@example.com');
        $this->row($project, '{}');

        foreach ([
            ['HTTP_AUTHORIZATION' => 'Bearer '.$raw],
            EventBridge::server($raw, EventBridge::register($em, $user, [Bridge::CAPABILITY_COMMANDS])),
        ] as $server) {
            $client->request(Request::METHOD_GET, '/api/events/replay?after=0', server: $server);

            self::assertResponseStatusCodeSame(426);
            $body = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertIsArray($body);
            self::assertStringContainsString('loupe CLI', (string) ($body['error'] ?? ''));
            self::assertArrayNotHasKey('events', $body);
        }
    }

    public function test_push_disabled_hides_the_endpoint(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em()->getConnection()->executeStatement(
            "UPDATE feature_flag SET value = 'false' WHERE name = ?",
            [AgentPush::FLAG],
        );
        [$raw] = $this->issue($client, 'replay-off@example.com');

        $client->request(Request::METHOD_GET, '/api/events/replay?after=0', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_site_bound_widget_token_is_forbidden(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $user = $this->user($em, 'replay-widget@example.com');
        $project = new Project($user, 'replay-widget-site');
        $em->persist($project);
        $em->flush();
        $this->row($project, '{"secret":true}');
        $raw = AgentCredential::tokenFor(static::getContainer(), $user, 'site-review', $project);

        $client->request(Request::METHOD_GET, '/api/events/replay?after=0', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(403);
        self::assertJsonStringEqualsJsonString('{"error":"insufficient_scope"}', (string) $client->getResponse()->getContent());
    }

    public function test_no_token_is_unauthorized(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/api/events/replay?after=0');

        self::assertResponseStatusCodeSame(401);
    }

    public function test_the_limit_counts_per_token(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.agent_event_replay', new RateLimiterFactory(
            ['id' => 'agent_event_replay', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        [$first] = $this->issue($client, 'replay-limit@example.com');
        [$second] = $this->issue($client, 'replay-limit-other@example.com');

        $this->replay($client, $first, 0);

        $client->request(Request::METHOD_GET, '/api/events/replay?after=0', server: EventBridge::server($first, $this->bridges[$first]));
        self::assertResponseStatusCodeSame(429);

        $this->replay($client, $second, 0);
    }

    private function row(Project $project, string $payload, ?\DateTimeImmutable $createdAt = null, string $type = BridgeEventType::COMMAND): OutboxEvent
    {
        $em = $this->em();
        $event = new OutboxEvent($project, $type, 'topic', $payload, $createdAt ?? new \DateTimeImmutable());
        $em->persist($event);
        $em->flush();
        self::assertNotNull($event->sequence);

        return $event;
    }

    /** @return array<string, mixed> */
    private function replay(KernelBrowser $client, string $raw, int $after): array
    {
        $client->request(Request::METHOD_GET, '/api/events/replay?after='.$after, server: EventBridge::server($raw, $this->bridges[$raw]));

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function eventsOf(array $data): array
    {
        self::assertIsList($data['events']);

        return $data['events'];
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);

        return $user;
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{0: string, 1: User, 2: Project}
     */
    private function issue(KernelBrowser $client, string $email): array
    {
        $em = $this->em();
        $user = $this->user($em, $email);
        $project = new Project($user, 'site-'.substr(md5($email), 0, 8));
        $em->persist($project);
        $em->flush();

        $raw = AgentCredential::agentToken(static::getContainer(), $user);
        $this->bridges[$raw] = EventBridge::register($em, $user);

        return [
            $raw,
            AgentCredential::managed($em, $user, $user->id),
            AgentCredential::managed($em, $project, $project->id),
        ];
    }
}
