<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class StreamCredentialsControllerTest extends WebTestCase
{
    public function test_returns_per_site_topic_and_scoped_jwt(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw, , $site] = $this->issue($em, ApiTokenScope::SiteReview, 'stream@example.com');

        $client->request(Request::METHOD_GET, '/api/site-review/stream',
            ['site' => $site->name],
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('https://mercure.betterplans.dev.localhost/.well-known/mercure', $data['hubUrl']);

        $expectedTopic = 'https://betterplans.dev.localhost/sites/'.$site->id.'/site-reviews';
        self::assertSame($expectedTopic, $data['topic']);
        self::assertSame((string) $site->id, $data['site']['id']);
        self::assertSame($site->name, $data['site']['name']);

        // The JWT must be a subscriber token scoped to exactly this site's topic.
        $claims = $this->decodeJwtClaims((string) $data['jwt']);
        self::assertSame([$expectedTopic], $claims['mercure']['subscribe'] ?? null);
    }

    public function test_site_resolves_by_id_too(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw, , $site] = $this->issue($em, ApiTokenScope::SiteReview, 'stream-by-id@example.com');

        $client->request(Request::METHOD_GET, '/api/site-review/stream',
            ['site' => (string) $site->id],
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame((string) $site->id, $data['site']['id']);
    }

    public function test_missing_site_is_400(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
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

        // A widget token: SiteReview-scoped but BOUND to a site. It is embedded
        // in public page HTML, so it must never mint subscriber JWTs — not even
        // for its own site.
        $email = 'stream-widget@example.com';
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $raw] = ApiToken::issue($user, 'widget-tok', ApiTokenScope::SiteReview);
        $em->persist($token);
        $site = new Site($user, 'stream-widget-site');
        $site->token = $token;
        $em->persist($site);
        $em->flush();

        $client->request(Request::METHOD_GET, '/api/site-review/stream',
            ['site' => $site->name],
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('site_bound_token_not_allowed', $data['error'] ?? null);
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{0: string, 1: User, 2: Site}
     */
    private function issue(EntityManagerInterface $em, ApiTokenScope $scope, string $email): array
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $raw] = ApiToken::issue($user, 'tok', $scope);
        $em->persist($token);
        $site = new Site($user, 'site-'.substr(md5($email), 0, 8));
        $em->persist($site);
        $em->flush();

        return [$raw, $user, $site];
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
