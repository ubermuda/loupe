<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\SiteReviewPush;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final class StreamCredentialsControllerTest extends WebTestCase
{
    /**
     * The endpoint is gated by site_review.push.enabled, so every case here has
     * to switch it on — including the ones asserting a failure, which must fail
     * for their own reason rather than because the feature is off.
     */
    private function enablePush(EntityManagerInterface $em): void
    {
        $em->persist(new FeatureFlag(name: SiteReviewPush::FLAG, type: FeatureFlagType::Bool, value: true));
        $em->flush();
    }

    public function test_returns_per_site_topic_and_scoped_jwt(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enablePush($em);
        [$raw, , $project] = $this->issue($em, ApiTokenScope::SiteReview, 'stream@example.com');

        $client->request(Request::METHOD_GET, '/api/site-review/stream',
            ['site' => $project->name],
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('https://mercure.loupe.dev.localhost/.well-known/mercure', $data['hubUrl']);

        // The topic namespace is the app's own public URL (DEFAULT_URI), which
        // is the same value the router pins its default context to.
        $defaultUri = static::getContainer()->getParameter('router.request_context.base_url');
        self::assertIsString($defaultUri);
        $expectedTopic = rtrim($defaultUri, '/').'/projects/'.$project->id.'/site-reviews';
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
        // Deliberately no enablePush(): a valid credential for a real project,
        // refused only because the instance does not do push.
        [$raw, , $project] = $this->issue($em, ApiTokenScope::SiteReview, 'stream-off@example.com');

        $client->request(Request::METHOD_GET, '/api/site-review/stream',
            ['site' => $project->name],
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
        $this->enablePush($em);
        [$raw, , $project] = $this->issue($em, ApiTokenScope::SiteReview, 'stream-by-id@example.com');

        $client->request(Request::METHOD_GET, '/api/site-review/stream',
            ['site' => (string) $project->id],
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame((string) $project->id, $data['site']['id']);
    }

    public function test_missing_site_is_400(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enablePush($em);
        [$raw] = $this->issue($em, ApiTokenScope::SiteReview, 'stream-no-site@example.com');

        // Missing site parameter → 400.
        $client->request(Request::METHOD_GET, '/api/site-review/stream',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('missing_site_parameter', $data['error']);

        // Blank-after-trim site parameter (a space) is also a 400.
        $client->request(Request::METHOD_GET, '/api/site-review/stream',
            ['site' => ' '],
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('missing_site_parameter', $data['error']);
    }

    public function test_unknown_site_is_404(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enablePush($em);
        [$raw] = $this->issue($em, ApiTokenScope::SiteReview, 'stream-unknown@example.com');

        $client->request(Request::METHOD_GET, '/api/site-review/stream',
            ['site' => 'no-such-site'],
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
        $this->enablePush($em);

        [$raw] = $this->issue($em, ApiTokenScope::SiteReview, 'stream-owner1@example.com');
        [, , $otherSite] = $this->issue($em, ApiTokenScope::SiteReview, 'stream-owner2@example.com');

        // Request the other owner's site by ID — robust against name overlap.
        $client->request(Request::METHOD_GET, '/api/site-review/stream',
            ['site' => (string) $otherSite->id],
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_mcp_token_is_forbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enablePush($em);
        [$raw] = $this->issue($em, ApiTokenScope::Mcp, 'mcp-stream@example.com');

        $client->request(Request::METHOD_GET, '/api/site-review/stream',
            ['site' => 'x'],
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_no_token_is_unauthorized(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/api/site-review/stream', ['site' => 'x']);
        self::assertResponseStatusCodeSame(401);
    }

    public function test_site_bound_widget_token_is_forbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enablePush($em);

        // A widget token: SiteReview-scoped but BOUND to a site. It is embedded
        // in public page HTML, so it must never mint subscriber JWTs — not even
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

        $client->request(Request::METHOD_GET, '/api/site-review/stream',
            ['site' => $project->name],
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('site_bound_token_not_allowed', $data['error'] ?? null);
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
