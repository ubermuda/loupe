<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Service\CliCompatibility;
use App\Module\Project\Entity\Project;
use App\Outbox\AgentPush;
use App\Outbox\Command\DrainOutboxCommand;
use App\Outbox\Command\DrainOutboxHandler;
use App\Outbox\OutboxWriter;
use App\Outbox\Repository\OutboxEventRepository;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\FeatureFlags;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

final class ShowEventsControllerTest extends WebTestCase
{
    private const string INBOX_FLAG = 'inbox.enabled';

    private const string HEARTBEAT_FLAG = 'bridge.heartbeat_interval_seconds';

    public function test_returns_the_callers_own_topic_its_projects_and_a_jwt_for_that_topic_alone(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        [$raw, $user, $first] = $this->issue($client, 'events@example.com');
        $second = new Project($user, 'Second Events Site');
        $em->persist($second);
        $em->flush();
        [$foreignRaw, $foreignUser, $foreign] = $this->issue($client, 'events-other@example.com');

        $data = $this->events($client, $raw);

        self::assertSame('https://mercure.loupe.dev.localhost/.well-known/mercure', $data['hubUrl']);
        $defaultUri = static::getContainer()->getParameter('router.request_context.base_url');
        self::assertIsString($defaultUri);
        $topicOf = static fn (User $owner): string => rtrim($defaultUri, '/').'/users/'.$owner->id.'/events';
        self::assertSame($topicOf($user), $data['topic']);

        $expected = [
            (string) $first->id => ['id' => (string) $first->id, 'slug' => $first->slug, 'name' => $first->name],
            (string) $second->id => ['id' => (string) $second->id, 'slug' => 'second-events-site', 'name' => 'Second Events Site'],
        ];
        self::assertIsArray($data['projects']);
        self::assertEqualsCanonicalizing($expected, array_column($data['projects'], null, 'id'));
        self::assertNotContains((string) $foreign->id, array_column($data['projects'], 'id'));

        self::assertSame([$topicOf($user)], $this->decodeJwtClaims((string) $data['jwt'])['mercure']['subscribe'] ?? null);
        self::assertSame(CliCompatibility::RANGE, $data['cliRange']);

        // The other user's own call names their topic only, never the first user's.
        $foreignData = $this->events($client, $foreignRaw);
        self::assertSame([$topicOf($foreignUser)], $this->decodeJwtClaims((string) $foreignData['jwt'])['mercure']['subscribe'] ?? null);
    }

    /**
     * The drain publishes on the owner's topic, and /api/events hands out that
     * same topic, so every owned project reaches one subscriber.
     */
    public function test_an_event_of_any_owned_project_is_published_on_the_topic_the_endpoint_returns(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        [$raw, $user, $first] = $this->issue($client, 'events-drain@example.com');
        $second = new Project($user, 'Second Drained Site');
        $em->persist($second);
        $em->flush();
        [, , $foreign] = $this->issue($client, 'events-drain-other@example.com');
        $topic = $this->events($client, $raw)['topic'];

        $writer = static::getContainer()->get(OutboxWriter::class);
        self::assertInstanceOf(OutboxWriter::class, $writer);
        foreach ([$first, $second, $foreign] as $project) {
            $writer->write(AgentCredential::managed($em, $project, $project->id), 'test.event', ['projectId' => (string) $project->id]);
        }
        $em->flush();

        $hub = new class implements HubInterface {
            /** @var list<Update> */
            public array $updates = [];

            public function getPublicUrl(): string
            {
                return 'https://hub.test';
            }

            public function getFactory(): ?TokenFactoryInterface
            {
                return null;
            }

            public function publish(Update $update): string
            {
                $this->updates[] = $update;

                return 'id';
            }
        };
        $userTopics = static::getContainer()->get(UserTopicBuilder::class);
        self::assertInstanceOf(UserTopicBuilder::class, $userTopics);
        $outboxEvents = static::getContainer()->get(OutboxEventRepository::class);
        self::assertInstanceOf(OutboxEventRepository::class, $outboxEvents);
        (new DrainOutboxHandler($outboxEvents, $em, $hub, new NullLogger(), FeatureFlags::service([AgentPush::FLAG => true]), $userTopics))(new DrainOutboxCommand());

        $reached = [];
        foreach ($hub->updates as $update) {
            if (\in_array($topic, $update->getTopics(), true)) {
                $reached[] = json_decode($update->getData(), true)['projectId'] ?? null;
            }
        }
        self::assertCount(3, $hub->updates);
        self::assertEqualsCanonicalizing([(string) $first->id, (string) $second->id], $reached);
    }

    public function test_a_caller_with_no_project_gets_an_empty_list_and_still_its_own_topic(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $user = $this->user($em, 'events-empty@example.com');
        $em->flush();
        $raw = AgentCredential::agentToken(static::getContainer(), $user);

        $data = $this->events($client, $raw);

        self::assertSame([], $data['projects']);
        self::assertSame([$data['topic']], $this->decodeJwtClaims((string) $data['jwt'])['mercure']['subscribe'] ?? null);
    }

    /** An instance that never seeded the inbox flag holds no row for it, and the bridge must read that as off. */
    public function test_the_inbox_flag_reads_as_off_when_it_has_no_row(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $em->getConnection()->executeStatement('DELETE FROM feature_flag WHERE name = ?', [self::INBOX_FLAG]);
        [$raw] = $this->issue($client, 'events-flags-none@example.com');

        self::assertSame(false, $this->flags($client, $raw)[self::INBOX_FLAG]);
    }

    public function test_the_flags_map_carries_the_inbox_flag_when_it_is_on(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $this->storeInboxFlag($em, 'true');
        [$raw] = $this->issue($client, 'events-flags-on@example.com');

        self::assertSame(true, $this->flags($client, $raw)[self::INBOX_FLAG]);
    }

    /** The endpoint answers only while push is on, so that flag is stored and on, and still stays out of the map. */
    public function test_the_flags_map_names_no_flag_outside_the_allowlist(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $this->storeInboxFlag($em, 'true');
        [$raw] = $this->issue($client, 'events-flags-allowlist@example.com');

        $flags = $this->flags($client, $raw);

        self::assertSame([self::INBOX_FLAG, self::HEARTBEAT_FLAG], array_keys($flags));
        self::assertArrayNotHasKey(AgentPush::FLAG, $flags);
    }

    /** An instance upgraded past the seed migration still has no row until it runs, so the bridge reads the coded default. */
    public function test_the_heartbeat_interval_reads_as_sixty_seconds_when_it_has_no_row(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $em->getConnection()->executeStatement('DELETE FROM feature_flag WHERE name = ?', [self::HEARTBEAT_FLAG]);
        [$raw] = $this->issue($client, 'events-heartbeat-none@example.com');

        self::assertSame(60, $this->flags($client, $raw)[self::HEARTBEAT_FLAG]);
    }

    public function test_the_heartbeat_interval_carries_the_stored_value_as_an_integer(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $this->storeHeartbeatFlag($em, '90');
        [$raw] = $this->issue($client, 'events-heartbeat-stored@example.com');

        self::assertSame(90, $this->flags($client, $raw)[self::HEARTBEAT_FLAG]);
    }

    /** @return iterable<string, array{string, int}> */
    public static function heartbeatFloor(): iterable
    {
        yield 'zero' => ['0', 60];
        yield 'one below the floor' => ['9', 60];
        yield 'the floor' => ['10', 10];
    }

    /** The flag is operator-typed, and a few seconds would let one bridge spend its token's rate limit. */
    #[DataProvider('heartbeatFloor')]
    public function test_a_heartbeat_interval_below_ten_seconds_reads_as_the_default(string $stored, int $shared): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $this->storeHeartbeatFlag($em, $stored);
        [$raw] = $this->issue($client, 'events-heartbeat-floor-'.$stored.'@example.com');

        self::assertSame($shared, $this->flags($client, $raw)[self::HEARTBEAT_FLAG]);
    }

    public function test_push_disabled_hides_the_endpoint(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        // The flag ships on through a migration, so this case turns it off.
        $em->getConnection()->executeStatement(
            "UPDATE feature_flag SET value = 'false' WHERE name = ?",
            [AgentPush::FLAG],
        );
        [$raw] = $this->issue($client, 'events-off@example.com');

        $client->request(Request::METHOD_GET, '/api/events', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        // 404 rather than 403: with push off there is no hub to subscribe to.
        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_per_project_stream_route_is_gone(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$raw, , $project] = $this->issue($client, 'events-old-path@example.com');

        foreach (['/api/projects/'.$project->id.'/stream', '/api/projects/'.$project->slug.'/stream', '/api/agent/stream'] as $path) {
            $client->request(Request::METHOD_GET, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function test_mcp_token_is_forbidden(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();
        $user = $this->user($em, 'mcp-events@example.com');
        $project = new Project($user, 'mcp-events-site');
        $em->persist($project);
        $em->flush();
        $raw = AgentCredential::tokenFor(static::getContainer(), $user, 'mcp', $project);

        $client->request(Request::METHOD_GET, '/api/events', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('/users/', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('jwt', (string) $client->getResponse()->getContent());
    }

    public function test_no_token_is_unauthorized(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/api/events');
        self::assertResponseStatusCodeSame(401);
    }

    public function test_site_bound_widget_token_is_forbidden(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $this->em();

        // A widget credential holds one site and the site-review scope. It must
        // never mint subscriber JWTs, not even for its own project.
        $user = $this->user($em, 'events-widget@example.com');
        $project = new Project($user, 'events-widget-site');
        $em->persist($project);
        $em->flush();
        $raw = AgentCredential::tokenFor(static::getContainer(), $user, 'site-review', $project);

        $client->request(Request::METHOD_GET, '/api/events', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(403);
        self::assertJsonStringEqualsJsonString('{"error":"insufficient_scope"}', (string) $client->getResponse()->getContent());
    }

    private function storeInboxFlag(EntityManagerInterface $em, string $value): void
    {
        $em->getConnection()->executeStatement('DELETE FROM feature_flag WHERE name = ?', [self::INBOX_FLAG]);
        $em->getConnection()->executeStatement(
            "INSERT INTO feature_flag (name, type, value, tags, options) VALUES (?, 'bool', ?, '[]', NULL)",
            [self::INBOX_FLAG, $value],
        );
    }

    private function storeHeartbeatFlag(EntityManagerInterface $em, string $value): void
    {
        $em->getConnection()->executeStatement('DELETE FROM feature_flag WHERE name = ?', [self::HEARTBEAT_FLAG]);
        $em->getConnection()->executeStatement(
            "INSERT INTO feature_flag (name, type, value, tags, options) VALUES (?, 'int', ?, '[]', NULL)",
            [self::HEARTBEAT_FLAG, $value],
        );
    }

    /** @return array<string, mixed> */
    private function flags(KernelBrowser $client, string $raw): array
    {
        $flags = $this->events($client, $raw)['flags'];
        self::assertIsArray($flags);

        return $flags;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @return array<string, mixed> */
    private function events(KernelBrowser $client, string $raw): array
    {
        $client->request(Request::METHOD_GET, '/api/events', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        return $data;
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
     * An agent credential for a new owner who holds one project. The flow signs
     * the user in, so both entities come back managed.
     *
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

        return [
            $raw,
            AgentCredential::managed($em, $user, $user->id),
            AgentCredential::managed($em, $project, $project->id),
        ];
    }

    /** @return array<string, mixed> */
    private function decodeJwtClaims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);
        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        self::assertIsString($payload);
        $claims = json_decode($payload, true);
        self::assertIsArray($claims);

        return $claims;
    }
}
