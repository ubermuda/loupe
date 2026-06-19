<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Repository\SiteReviewBatchRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class SubmitBatchControllerTest extends WebTestCase
{
    private const string PAYLOAD = '{"comments":[{"body":"too big","selector":".card","text":"Save","url":"https://app.localhost/x"}]}';

    public function test_valid_token_creates_batch(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $raw = $this->issue($em, ApiTokenScope::SiteReview, 'a@example.com');

        $client->request(Request::METHOD_POST, '/api/site-review/batches',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://app.localhost'],
            content: self::PAYLOAD);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('https://app.localhost', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('batchId', $data);

        $repo = static::getContainer()->get(SiteReviewBatchRepository::class);
        self::assertCount(1, $repo->findAll());
    }

    public function test_mcp_token_is_forbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $raw = $this->issue($em, ApiTokenScope::Mcp, 'b@example.com');

        $client->request(Request::METHOD_POST, '/api/site-review/batches',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'CONTENT_TYPE' => 'application/json'],
            content: self::PAYLOAD);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_no_token_is_unauthorized(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_POST, '/api/site-review/batches', server: ['CONTENT_TYPE' => 'application/json'], content: self::PAYLOAD);
        self::assertResponseStatusCodeSame(401);
    }

    public function test_empty_comments_is_422(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $raw = $this->issue($em, ApiTokenScope::SiteReview, 'c@example.com');

        $client->request(Request::METHOD_POST, '/api/site-review/batches',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'CONTENT_TYPE' => 'application/json'],
            content: '{"comments":[]}');

        self::assertResponseStatusCodeSame(422);
    }

    public function test_comment_missing_required_field_is_422(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $raw = $this->issue($em, ApiTokenScope::SiteReview, 'd@example.com');

        $client->request(Request::METHOD_POST, '/api/site-review/batches',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw, 'CONTENT_TYPE' => 'application/json'],
            content: '{"comments":[{"body":"hi","selector":".x"}]}');

        self::assertResponseStatusCodeSame(422);
    }

    public function test_options_preflight_returns_204_with_cors(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_OPTIONS, '/api/site-review/batches', server: ['HTTP_ORIGIN' => 'https://app.localhost']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('https://app.localhost', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    /** @param non-empty-string $email */
    private function issue(EntityManagerInterface $em, ApiTokenScope $scope, string $email): string
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $raw] = ApiToken::issue($user, 'tok', $scope);
        $em->persist($token);
        $em->flush();

        return $raw;
    }
}
