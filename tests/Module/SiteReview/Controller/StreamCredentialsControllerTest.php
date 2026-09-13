<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Outbox\AgentPush;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class StreamCredentialsControllerTest extends WebTestCase
{
    public function test_returns_a_topic_for_every_project_of_the_caller_and_one_jwt_for_them(): void
    {
        $client = static::createClient();
        $em = $this->em();
        [$raw, $user, $first] = $this->issue($em, ApiTokenScope::Agent, 'events@example.com');
        $second = new Project($user, 'Second Events Site');
        $em->persist($second);
        [, , $foreign] = $this->issue($em, ApiTokenScope::Agent, 'events-other@example.com');
        $em->flush();

        $data = $this->events($client, $raw);

        self::assertSame('https://mercure.loupe.dev.localhost/.well-known/mercure', $data['hubUrl']);
        $defaultUri = static::getContainer()->getParameter('router.request_context.base_url');
        self::assertIsString($defaultUri);
        $topicOf = static fn (Project $project): string => rtrim($defaultUri, '/').'/projects/'.$project->id.'/events';

        $expected = [
            (string) $first->id => ['id' => (string) $first->id, 'slug' => $first->slug, 'name' => $first->name, 'topic' => $topicOf($first)],
            (string) $second->id => ['id' => (string) $second->id, 'slug' => 'second-events-site', 'name' => 'Second Events Site', 'topic' => $topicOf($second)],
        ];
        self::assertIsArray($data['projects']);
        self::assertEqualsCanonicalizing($expected, array_column($data['projects'], null, 'id'));
        self::assertNotContains((string) $foreign->id, array_column($data['projects'], 'id'));

        $claims = $this->decodeJwtClaims((string) $data['jwt']);
        $subscribe = $claims['mercure']['subscribe'] ?? null;
        self::assertIsArray($subscribe);
        self::assertEqualsCanonicalizing([$topicOf($first), $topicOf($second)], $subscribe);
        self::assertNotContains($topicOf($foreign), $subscribe);
    }

    public function test_a_caller_with_no_project_gets_an_empty_list(): void
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
        self::assertSame([], $this->decodeJwtClaims((string) $data['jwt'])['mercure']['subscribe'] ?? null);
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
