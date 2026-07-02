<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\Site;
use App\Module\SiteReview\Entity\SiteReviewStatus;
use App\Module\SiteReview\Repository\SiteReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class SiteReviewApiTest extends WebTestCase
{
    /**
     * @param non-empty-string $email
     *
     * @return array{0: string, 1: Site} raw token + its site
     */
    private function siteWithToken(EntityManagerInterface $em, string $email, string $name = 'api-site'): array
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $raw] = ApiToken::issue($user, 'widget', ApiTokenScope::SiteReview);
        $em->persist($token);
        $site = new Site($user, $name);
        $site->token = $token;
        $em->persist($site);
        $em->flush();

        return [$raw, $site];
    }

    /** @param array<string, mixed>|null $json */
    private function api(mixed $client, string $method, string $path, string $raw, ?array $json = null): void
    {
        $client->request($method, $path,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://app.localhost'],
            content: null === $json ? null : json_encode($json, \JSON_THROW_ON_ERROR));
    }

    public function test_add_comment_creates_a_draft_review(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw, $site] = $this->siteWithToken($em, 'api-a@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw,
            ['body' => 'too big', 'selector' => '.card', 'text' => 'Save', 'url' => 'https://app/x']);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('commentId', $data);

        $review = static::getContainer()->get(SiteReviewRepository::class)->findOneInProgress($site);
        self::assertNotNull($review);
        self::assertCount(1, $review->comments);
    }

    public function test_unbound_site_review_token_is_forbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User(username: 'api-b@example.com', fullName: 'U', email: 'api-b@example.com', password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $raw] = ApiToken::issue($user, 'unbound', ApiTokenScope::SiteReview);
        $em->persist($token);
        $em->flush();

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw,
            ['body' => 'x', 'url' => 'https://app/x']);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_current_review_round_trip_and_submit(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw, $site] = $this->siteWithToken($em, 'api-c@example.com');

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        self::assertSame(['review' => null], json_decode((string) $client->getResponse()->getContent(), true));

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, ['body' => 'one', 'url' => 'https://app/x']);
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, ['body' => 'two', 'url' => 'https://app/y']);

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data['review'] ?? null);
        self::assertCount(2, $data['review']['comments']);
        self::assertSame('one', $data['review']['comments'][0]['body']);
        self::assertNotEmpty($data['review']['comments'][0]['id']);

        $this->api($client, Request::METHOD_POST, '/api/site-review/review/submit', $raw);
        self::assertResponseIsSuccessful();
        $em->clear();
        $submitted = static::getContainer()->get(SiteReviewRepository::class)->findForSite($site);
        self::assertSame(SiteReviewStatus::Submitted, $submitted[0]->status);

        // No draft anymore: a second submit is a 422, and GET review is null again.
        $this->api($client, Request::METHOD_POST, '/api/site-review/review/submit', $raw);
        self::assertResponseStatusCodeSame(422);
    }

    public function test_edit_and_delete_draft_comment(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw] = $this->siteWithToken($em, 'api-d@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, ['body' => 'orig', 'url' => 'https://app/x']);
        $id = json_decode((string) $client->getResponse()->getContent(), true)['commentId'];

        $this->api($client, Request::METHOD_PATCH, '/api/site-review/comments/'.$id, $raw, ['body' => 'edited']);
        self::assertResponseIsSuccessful();

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        self::assertSame('edited', json_decode((string) $client->getResponse()->getContent(), true)['review']['comments'][0]['body']);

        $this->api($client, Request::METHOD_DELETE, '/api/site-review/comments/'.$id, $raw);
        self::assertResponseStatusCodeSame(204);

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true)['review']['comments']);
    }

    public function test_cross_site_comment_is_not_reachable(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$rawA] = $this->siteWithToken($em, 'api-e@example.com', 'site-a');
        [$rawB] = $this->siteWithToken($em, 'api-f@example.com', 'site-b');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $rawA, ['body' => 'mine', 'url' => 'https://app/x']);
        $id = json_decode((string) $client->getResponse()->getContent(), true)['commentId'];

        $this->api($client, Request::METHOD_PATCH, '/api/site-review/comments/'.$id, $rawB, ['body' => 'hijack']);
        self::assertResponseStatusCodeSame(404);

        $this->api($client, Request::METHOD_DELETE, '/api/site-review/comments/'.$id, $rawB);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_auth_matrix_and_validation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // No token → 401.
        $client->request(Request::METHOD_POST, '/api/site-review/comments', server: ['CONTENT_TYPE' => 'application/json'], content: '{"body":"x","url":"u"}');
        self::assertResponseStatusCodeSame(401);

        // MCP-scoped token → 403 (firewall access_control, before the controller).
        $user = new User(username: 'api-g@example.com', fullName: 'U', email: 'api-g@example.com', password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $mcpRaw] = ApiToken::issue($user, 'mcp', ApiTokenScope::Mcp);
        $em->persist($token);
        $em->flush();
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $mcpRaw, ['body' => 'x', 'url' => 'u']);
        self::assertResponseStatusCodeSame(403);

        // Blank body → 422; malformed comment id → 404.
        [$raw] = $this->siteWithToken($em, 'api-h@example.com');
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, ['body' => '  ', 'url' => 'https://app/x']);
        self::assertResponseStatusCodeSame(422);
        $this->api($client, Request::METHOD_PATCH, '/api/site-review/comments/not-a-uuid', $raw, ['body' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_preflight_carries_new_methods(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_OPTIONS, '/api/site-review/comments', server: ['HTTP_ORIGIN' => 'https://app.localhost']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('GET, POST, PATCH, DELETE, OPTIONS', $client->getResponse()->headers->get('Access-Control-Allow-Methods'));
        self::assertSame('https://app.localhost', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }
}
