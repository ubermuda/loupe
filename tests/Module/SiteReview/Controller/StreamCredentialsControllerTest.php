<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Outbox\AgentPush;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class StreamCredentialsControllerTest extends WebTestCase
{
    /**
     * Written through the connection rather than the entity: the flag row comes
     * from a migration, so it already exists and this only has to flip it.
     */
    private function disablePush(EntityManagerInterface $em): void
    {
        $em->getConnection()->executeStatement(
            "UPDATE feature_flag SET value = 'false' WHERE name = ?",
            [AgentPush::FLAG],
        );
    }

    public function test_returns_per_site_topic_and_scoped_jwt(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw, , $project] = $this->issue($em, ApiTokenScope::Agent, 'stream@example.com');

        $client->request(Request::METHOD_GET, $this->streamPath($project->name),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('https://mercure.loupe.dev.localhost/.well-known/mercure', $data['hubUrl']);

        // The topic namespace is the app's own public URL (DEFAULT_URI), which
        // is the same value the router pins its default context to.
        $defaultUri = static::getContainer()->getParameter('router.request_context.base_url');
        self::assertIsString($defaultUri);
        $expectedTopic = rtrim($defaultUri, '/').'/projects/'.$project->id.'/events';
        self::assertSame($expectedTopic, $data['topic']);
        self::assertSame((string) $project->id, $data['site']['id']);
        self::assertSame($project->name, $data['site']['name']);

        // The JWT must be a subscriber token scoped to exactly this site's topic.
        $claims = $this->decodeJwtClaims((string) $data['jwt']);
        self::assertSame([$expectedTopic], $claims['mercure']['subscribe'] ?? null);
    }

    public function test_push_disabled_hides_the_endpoint(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        // The flag ships on (a migration seeds it), so this case has to turn it
        // off: a valid credential for a real project, refused only because the
        // instance does not do push.
        $this->disablePush($em);
        [$raw, , $project] = $this->issue($em, ApiTokenScope::Agent, 'stream-off@example.com');

        $client->request(Request::METHOD_GET, $this->streamPath($project->name),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        // 404 rather than 403: with push off there is no hub to subscribe to, so
        // there is nothing here to be authorized for. A 403 would tell a caller
        // the endpoint exists and its credential was rejected, which is a
        // different and wrong story.
        self::assertResponseStatusCodeSame(404);
    }

    public function test_site_resolves_by_id_too(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw, , $project] = $this->issue($em, ApiTokenScope::Agent, 'stream-by-id@example.com');

        $client->request(Request::METHOD_GET, $this->streamPath((string) $project->id),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame((string) $project->id, $data['site']['id']);
    }

    public function test_site_resolves_by_slug_too(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$raw, $user] = $this->issue($em, ApiTokenScope::Agent, 'stream-by-slug@example.com');
        $project = new Project($user, 'Stream Site');
        $em->persist($project);
        $em->flush();

        foreach (['stream-site', 'Stream Site'] as $handle) {
            $client->request(Request::METHOD_GET, $this->streamPath($handle),
                server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

            self::assertResponseIsSuccessful();
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertIsArray($data);
            self::assertSame((string) $project->id, $data['site']['id']);
        }
    }

    /**
     * The CLI escapes the slash as %2F, and both the router and the firewall
     * match the decoded path, so the name reaches them with a real slash.
     */
    public function test_a_name_holding_a_slash_resolves(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$raw, $user] = $this->issue($em, ApiTokenScope::Agent, 'stream-slash@example.com');
        $project = new Project($user, 'client/site');
        $em->persist($project);
        $em->flush();

        foreach ([$this->streamPath('client/site'), '/api/projects/client/site/stream'] as $path) {
            $client->request(Request::METHOD_GET, $path,
                server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

            self::assertResponseIsSuccessful();
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertIsArray($data);
            self::assertSame((string) $project->id, $data['site']['id']);
        }
    }

    public function test_a_site_that_is_one_slug_and_another_name_is_409(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$raw, $user] = $this->issue($em, ApiTokenScope::Agent, 'stream-ambiguous@example.com');
        $older = new Project($user, 'My App');
        $newer = new Project($user, 'my-app');
        new \ReflectionProperty(Project::class, 'slug')->setRawValue($newer, 'my-app-2');
        $em->persist($older);
        $em->persist($newer);
        $em->flush();

        $client->request(Request::METHOD_GET, $this->streamPath('my-app'),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(409);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('ambiguous_site', $data['error']);
        self::assertIsString($data['message']);
        self::assertStringContainsString((string) $older->id, $data['message']);
        self::assertStringContainsString((string) $newer->id, $data['message']);

        // The bridge reconnects with the resolved id, which an ambiguous pair never affects.
        foreach ([$older, $newer] as $project) {
            $client->request(Request::METHOD_GET, $this->streamPath((string) $project->id),
                server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

            self::assertResponseIsSuccessful();
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            self::assertIsArray($data);
            self::assertSame((string) $project->id, $data['site']['id']);
        }
    }

    public function test_blank_handle_is_404(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw] = $this->issue($em, ApiTokenScope::Agent, 'stream-no-site@example.com');

        $client->request(Request::METHOD_GET, $this->streamPath(' '),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(404);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('site_not_found', $data['error']);
    }

    public function test_the_old_agent_path_is_gone(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw, , $project] = $this->issue($em, ApiTokenScope::Agent, 'stream-old-path@example.com');

        $client->request(Request::METHOD_GET, '/api/agent/stream',
            ['site' => $project->name],
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_unknown_site_is_404(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw] = $this->issue($em, ApiTokenScope::Agent, 'stream-unknown@example.com');

        $client->request(Request::METHOD_GET, $this->streamPath('no-such-site'),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(404);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('site_not_found', $data['error']);
    }

    public function test_other_owners_site_is_404(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$raw] = $this->issue($em, ApiTokenScope::Agent, 'stream-owner1@example.com');
        [, , $otherSite] = $this->issue($em, ApiTokenScope::Agent, 'stream-owner2@example.com');

        // Request the other owner's site by ID — robust against name overlap.
        $client->request(Request::METHOD_GET, $this->streamPath((string) $otherSite->id),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_mcp_token_is_forbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw] = $this->issue($em, ApiTokenScope::Mcp, 'mcp-stream@example.com');

        $client->request(Request::METHOD_GET, $this->streamPath('x'),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_no_token_is_unauthorized(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, $this->streamPath('x'));
        self::assertResponseStatusCodeSame(401);
    }

    public function test_site_bound_widget_token_is_forbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // A widget token: SiteReview-scoped and BOUND to a site. It is embedded
        // in public page HTML, so it must never mint subscriber JWTs, not even
        // for its own site.
        $email = 'stream-widget@example.com';
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $raw] = ApiToken::issue($user, 'widget-tok', ApiTokenScope::SiteReview);
        $em->persist($token);
        $project = new Project($user, 'stream-widget-site');
        $project->widgetToken = $token;
        $em->persist($project);
        $em->flush();

        $client->request(Request::METHOD_GET, $this->streamPath($project->name),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        // The firewall refuses it on scope, so the answer comes from
        // ApiAccessDeniedHandler rather than from the controller.
        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('insufficient_scope', $data['error'] ?? null);
    }

    /**
     * An unbound site-review token is refused too. Scope alone decides here, so
     * the binding is not what keeps a widget token out.
     */
    public function test_unbound_site_review_token_is_forbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw, , $project] = $this->issue($em, ApiTokenScope::SiteReview, 'stream-unbound@example.com');

        $client->request(Request::METHOD_GET, $this->streamPath($project->name),
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('insufficient_scope', $data['error'] ?? null);
    }

    private function streamPath(string $handle): string
    {
        return '/api/projects/'.rawurlencode($handle).'/stream';
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

    /**
     * @return array<string, mixed>
     */
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
