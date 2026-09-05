<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class SiteReviewApiTest extends WebTestCase
{
    /**
     * @param non-empty-string $email
     *
     * @return array{0: string, 1: Project} raw token + its project
     */
    private function projectWithToken(EntityManagerInterface $em, string $email, string $name = 'api-site'): array
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $raw] = ApiToken::issue($user, 'widget', ApiTokenScope::SiteReview);
        $em->persist($token);
        $project = new Project($user, $name);
        $project->widgetToken = $token;
        $em->persist($project);
        $em->flush();

        return [$raw, $project];
    }

    /** @param array<string, mixed>|null $json */
    private function api(KernelBrowser $client, string $method, string $path, string $raw, ?array $json = null): void
    {
        $client->request($method, $path,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://app.localhost'],
            content: null === $json ? null : json_encode($json, \JSON_THROW_ON_ERROR));
    }

    public function test_add_comment_creates_a_pending_comment(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw, $project] = $this->projectWithToken($em, 'api-a@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw,
            ['body' => 'too big', 'selector' => '.card', 'text' => 'Save', 'url' => 'https://app/x']);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('commentId', $data);

        $pending = static::getContainer()->get(SiteReviewCommentRepository::class)->findPendingForProject($project);
        self::assertCount(1, $pending);
    }

    public function test_a_comment_can_point_at_several_elements(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw, $project] = $this->projectWithToken($em, 'api-anchors@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, [
            'body' => 'These two belong side by side',
            'url' => 'https://app/x',
            'anchors' => [
                ['selector' => '.card', 'text' => 'Save'],
                ['selector' => '.panel', 'text' => 'Cancel'],
            ],
        ]);
        self::assertResponseStatusCodeSame(201);

        $pending = static::getContainer()->get(SiteReviewCommentRepository::class)->findPendingForProject($project);
        $anchors = array_values($pending[0]->anchors->toArray());
        self::assertCount(2, $anchors);
        self::assertSame(['.card', '.panel'], array_map(static fn ($a) => $a->selector, $anchors));
        self::assertSame([0, 1], array_map(static fn ($a) => $a->position, $anchors));
        self::assertNull($anchors[0]->quote);
    }

    /**
     * The widget script URL carries no version, so a browser can hold a
     * pre-anchors copy for a long time and still post the old body.
     */
    public function test_a_legacy_selector_body_becomes_one_anchor(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw, $project] = $this->projectWithToken($em, 'api-legacy@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw,
            ['body' => 'old widget', 'selector' => '.hero h1', 'text' => 'Hello', 'url' => 'https://app/x']);
        self::assertResponseStatusCodeSame(201);

        $pending = static::getContainer()->get(SiteReviewCommentRepository::class)->findPendingForProject($project);
        $anchors = array_values($pending[0]->anchors->toArray());
        self::assertCount(1, $anchors);
        self::assertSame('.hero h1', $anchors[0]->selector);
        self::assertSame('Hello', $anchors[0]->text);
    }

    public function test_a_legacy_body_with_an_empty_selector_gets_no_anchor(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw, $project] = $this->projectWithToken($em, 'api-legacy-note@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw,
            ['body' => 'a page note', 'selector' => '', 'text' => '', 'url' => 'https://app/x']);
        self::assertResponseStatusCodeSame(201);

        $pending = static::getContainer()->get(SiteReviewCommentRepository::class)->findPendingForProject($project);
        self::assertCount(0, $pending[0]->anchors);
    }

    public function test_anchors_win_over_a_legacy_selector_in_the_same_body(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw, $project] = $this->projectWithToken($em, 'api-both@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, [
            'body' => 'both shapes',
            'selector' => '.legacy',
            'text' => 'Legacy',
            'url' => 'https://app/x',
            'anchors' => [['selector' => '.modern', 'text' => 'Modern']],
        ]);
        self::assertResponseStatusCodeSame(201);

        $pending = static::getContainer()->get(SiteReviewCommentRepository::class)->findPendingForProject($project);
        $anchors = array_values($pending[0]->anchors->toArray());
        self::assertCount(1, $anchors);
        self::assertSame('.modern', $anchors[0]->selector);
    }

    /**
     * The widget repeats its first anchor in the scalar pair, so an instance
     * that predates anchors[] still records the element. The current API must
     * read anchors[] and drop the repeat, rather than store it twice.
     */
    public function test_the_widget_shape_does_not_double_the_first_anchor(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw, $project] = $this->projectWithToken($em, 'api-widget-shape@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, [
            'body' => 'These two belong side by side',
            'url' => 'https://app/x',
            'anchors' => [
                ['selector' => '.card', 'text' => 'Save'],
                ['selector' => '.panel', 'text' => 'Cancel'],
            ],
            'selector' => '.card',
            'text' => 'Save',
        ]);
        self::assertResponseStatusCodeSame(201);

        $pending = static::getContainer()->get(SiteReviewCommentRepository::class)->findPendingForProject($project);
        $anchors = array_values($pending[0]->anchors->toArray());
        self::assertCount(2, $anchors);
        self::assertSame(['.card', '.panel'], array_map(static fn ($a) => $a->selector, $anchors));
    }

    public function test_more_than_ten_anchors_is_rejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw] = $this->projectWithToken($em, 'api-cap@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, [
            'body' => 'too many',
            'url' => 'https://app/x',
            'anchors' => array_map(static fn (int $i) => ['selector' => '.e'.$i, 'text' => 'E'], range(1, 11)),
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function test_an_anchor_with_a_blank_selector_is_rejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw] = $this->projectWithToken($em, 'api-blank-anchor@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw,
            ['body' => 'blank', 'url' => 'https://app/x', 'anchors' => [['selector' => '', 'text' => 'E']]]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * A widget copy that predates anchors[] reads the scalar pair, so the
     * rehydrate response keeps repeating the first anchor there.
     */
    public function test_the_rehydrate_response_carries_anchors_and_the_legacy_scalars(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw] = $this->projectWithToken($em, 'api-rehydrate@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, [
            'body' => 'two elements',
            'url' => 'https://app/x',
            'anchors' => [['selector' => '.a', 'text' => 'A'], ['selector' => '.b', 'text' => 'B']],
        ]);
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw,
            ['body' => 'a page note', 'url' => 'https://app/y']);

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertCount(2, $data['comments'][0]['anchors']);
        self::assertSame('.a', $data['comments'][0]['anchors'][0]['selector']);
        self::assertSame('.a', $data['comments'][0]['selector']);
        self::assertSame('A', $data['comments'][0]['text']);

        self::assertSame([], $data['comments'][1]['anchors']);
        self::assertSame('', $data['comments'][1]['selector']);
        self::assertSame('', $data['comments'][1]['text']);
    }

    public function test_unbound_site_review_token_is_forbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User(fullName: 'U', email: 'api-b@example.com', password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $raw] = ApiToken::issue($user, 'unbound', ApiTokenScope::SiteReview);
        $em->persist($token);
        $em->flush();

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw,
            ['body' => 'x', 'url' => 'https://app/x']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('token_not_bound_to_site', json_decode((string) $client->getResponse()->getContent(), true)['error'] ?? null);
    }

    public function test_saved_comments_are_immediately_live(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw, $project] = $this->projectWithToken($em, 'api-c@example.com');

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        self::assertSame(['comments' => []], json_decode((string) $client->getResponse()->getContent(), true));

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, ['body' => 'one', 'url' => 'https://app/x']);
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, ['body' => 'two', 'url' => 'https://app/y']);

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertCount(2, $data['comments']);
        self::assertSame('one', $data['comments'][0]['body']);
        self::assertNotEmpty($data['comments'][0]['id']);

        // No send step: the POSTs alone put both comments on the agent's queue.
        $em->clear();
        $pending = static::getContainer()->get(SiteReviewCommentRepository::class)->findPendingForProject($project);
        self::assertCount(2, $pending);
    }

    public function test_the_submit_route_is_gone(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw] = $this->projectWithToken($em, 'api-e@example.com');

        // The GET on the same prefix must keep working — only the POST is gone.
        $this->api($client, Request::METHOD_POST, '/api/site-review/review/submit', $raw);
        self::assertResponseStatusCodeSame(404);
        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        self::assertResponseIsSuccessful();
    }

    public function test_edit_and_delete_pending_comment(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw] = $this->projectWithToken($em, 'api-d@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, ['body' => 'orig', 'url' => 'https://app/x']);
        $id = json_decode((string) $client->getResponse()->getContent(), true)['commentId'];

        $this->api($client, Request::METHOD_PATCH, '/api/site-review/comments/'.$id, $raw, ['body' => 'edited']);
        self::assertResponseIsSuccessful();

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        self::assertSame('edited', json_decode((string) $client->getResponse()->getContent(), true)['comments'][0]['body']);

        $this->api($client, Request::METHOD_DELETE, '/api/site-review/comments/'.$id, $raw);
        self::assertResponseStatusCodeSame(204);

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true)['comments']);
    }

    /**
     * NotBlank accepts "0" by design, so a comment body of exactly that reaches
     * the controller as a valid payload and must not be mistaken for empty.
     */
    public function test_a_comment_body_of_zero_is_saved_and_editable(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw] = $this->projectWithToken($em, 'api-zero@example.com');

        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, ['body' => '0', 'url' => 'https://app/x']);
        self::assertResponseStatusCodeSame(201);
        $id = json_decode((string) $client->getResponse()->getContent(), true)['commentId'];

        $this->api($client, Request::METHOD_PATCH, '/api/site-review/comments/'.$id, $raw, ['body' => '0']);
        self::assertResponseIsSuccessful();

        $this->api($client, Request::METHOD_GET, '/api/site-review/review', $raw);
        self::assertSame('0', json_decode((string) $client->getResponse()->getContent(), true)['comments'][0]['body']);
    }

    public function test_cross_site_comment_is_not_reachable(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$rawA] = $this->projectWithToken($em, 'api-e@example.com', 'site-a');
        [$rawB] = $this->projectWithToken($em, 'api-f@example.com', 'site-b');

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
        $user = new User(fullName: 'U', email: 'api-g@example.com', password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $mcpRaw] = ApiToken::issue($user, 'mcp', ApiTokenScope::Mcp);
        $em->persist($token);
        $em->flush();
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $mcpRaw, ['body' => 'x', 'url' => 'u']);
        self::assertResponseStatusCodeSame(403);
        // The wrong-scope 403 is JSON with a machine-readable code (not the framework's HTML
        // error page), so the widget can distinguish it from an unbound-token 403.
        self::assertSame('insufficient_scope', json_decode((string) $client->getResponse()->getContent(), true)['error'] ?? null);

        // Blank body → 422; malformed comment id → 404.
        [$raw] = $this->projectWithToken($em, 'api-h@example.com');
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, ['body' => '  ', 'url' => 'https://app/x']);
        self::assertResponseStatusCodeSame(422);

        // Non-http(s) URL (javascript: scheme) → 422 (stored-XSS guard).
        $this->api($client, Request::METHOD_POST, '/api/site-review/comments', $raw, ['body' => 'x', 'url' => 'javascript:alert(1)']);
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

    public function test_a_request_without_an_origin_is_granted_no_origin(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_OPTIONS, '/api/site-review/comments');

        // A wildcard fallback here would hand the API to every page on the web.
        self::assertResponseStatusCodeSame(204);
        self::assertFalse($client->getResponse()->headers->has('Access-Control-Allow-Origin'));
    }
}
