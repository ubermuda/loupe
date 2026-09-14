<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Mercure\UserTopicBuilder;
use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Outbox\AgentPush;
use App\Outbox\Command\DrainOutboxCommand;
use App\Outbox\Command\DrainOutboxHandler;
use App\Outbox\OutboxWriter;
use App\Outbox\Repository\OutboxEventRepository;
use App\Tests\Support\FeatureFlags;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

final class ShowEventsControllerTest extends WebTestCase
{
    public function test_returns_the_callers_own_topic_its_projects_and_a_jwt_for_that_topic_alone(): void
    {
        $client = static::createClient();
        $em = $this->em();
        [$raw, $user, $first] = $this->issue($em, ApiTokenScope::Agent, 'events@example.com');
        $second = new Project($user, 'Second Events Site');
        $em->persist($second);
        [$foreignRaw, $foreignUser, $foreign] = $this->issue($em, ApiTokenScope::Agent, 'events-other@example.com');
        $em->flush();

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
        $em = $this->em();
        [$raw, $user, $first] = $this->issue($em, ApiTokenScope::Agent, 'events-drain@example.com');
        $second = new Project($user, 'Second Drained Site');
        $em->persist($second);
        [, , $foreign] = $this->issue($em, ApiTokenScope::Agent, 'events-drain-other@example.com');
        $topic = $this->events($client, $raw)['topic'];

        $writer = static::getContainer()->get(OutboxWriter::class);
        self::assertInstanceOf(OutboxWriter::class, $writer);
        foreach ([$first, $second, $foreign] as $project) {
            $writer->write($project, 'test.event', ['projectId' => (string) $project->id]);
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
        $em = $this->em();
        $user = new User(fullName: 'U', email: 'events-empty@example.com', password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $raw] = ApiToken::issue($user, 'tok', ApiTokenScope::Agent);
        $em->persist($token);
        $em->flush();

        $data = $this->events($client, $raw);

        self::assertSame([], $data['projects']);
        self::assertSame([$data['topic']], $this->decodeJwtClaims((string) $data['jwt'])['mercure']['subscribe'] ?? null);
    }

    public function test_push_disabled_hides_the_endpoint(): void
    {
        $client = static::createClient();
        $em = $this->em();
        // The flag ships on through a migration, so this case turns it off.
        $em->getConnection()->executeStatement(
            "UPDATE feature_flag SET value = 'false' WHERE name = ?",
            [AgentPush::FLAG],
        );
        [$raw] = $this->issue($em, ApiTokenScope::Agent, 'events-off@example.com');

        $client->request(Request::METHOD_GET, '/api/events', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        // 404 rather than 403: with push off there is no hub to subscribe to.
        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_per_project_stream_route_is_gone(): void
    {
        $client = static::createClient();
        [$raw, , $project] = $this->issue($this->em(), ApiTokenScope::Agent, 'events-old-path@example.com');

        foreach (['/api/projects/'.$project->id.'/stream', '/api/projects/'.$project->slug.'/stream', '/api/agent/stream'] as $path) {
            $client->request(Request::METHOD_GET, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function test_mcp_token_is_forbidden(): void
    {
        $client = static::createClient();
        [$raw] = $this->issue($this->em(), ApiTokenScope::Mcp, 'mcp-events@example.com');

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
        $em = $this->em();

        // A widget token is embedded in public page HTML, so it must never mint
        // subscriber JWTs, not even for its own project.
        $user = new User(fullName: 'U', email: 'events-widget@example.com', password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $raw] = ApiToken::issue($user, 'widget-tok', ApiTokenScope::SiteReview);
        $em->persist($token);
        $project = new Project($user, 'events-widget-site');
        $project->widgetToken = $token;
        $em->persist($project);
        $em->flush();

        $client->request(Request::METHOD_GET, '/api/events', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(403);
        self::assertJsonStringEqualsJsonString('{"error":"insufficient_scope"}', (string) $client->getResponse()->getContent());
    }

    /** Scope alone decides, so the project binding is not what keeps a widget token out. */
    public function test_unbound_site_review_token_is_forbidden(): void
    {
        $client = static::createClient();
        [$raw] = $this->issue($this->em(), ApiTokenScope::SiteReview, 'events-unbound@example.com');

        $client->request(Request::METHOD_GET, '/api/events', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(403);
        self::assertJsonStringEqualsJsonString('{"error":"insufficient_scope"}', (string) $client->getResponse()->getContent());
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

    /**
     * @param non-empty-string $email
     *
     * @return array{0: string, 1: User, 2: Project}
     */
    private function issue(EntityManagerInterface $em, ApiTokenScope $scope, string $email): array
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $raw] = ApiToken::issue($user, 'tok', $scope);
        $em->persist($token);
        $project = new Project($user, 'site-'.substr(md5($email), 0, 8));
        $em->persist($project);
        $em->flush();

        return [$raw, $user, $project];
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
