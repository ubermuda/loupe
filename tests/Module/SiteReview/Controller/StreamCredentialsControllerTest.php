<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class StreamCredentialsControllerTest extends WebTestCase
{
    public function test_returns_hub_url_topic_and_subscriber_jwt(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw, $user] = $this->issue($em, ApiTokenScope::SiteReview, 'stream@example.com');

        $client->request(Request::METHOD_GET, '/api/site-review/stream',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame('https://mercure.betterplans.dev.localhost/.well-known/mercure', $data['hubUrl']);

        $expectedTopic = 'https://betterplans.dev.localhost/users/'.$user->id.'/site-reviews';
        self::assertSame($expectedTopic, $data['topic']);

        // The JWT must be a subscriber token scoped to exactly this user's topic —
        // this, plus the publisher's private updates, is what isolates users.
        $claims = $this->decodeJwtClaims((string) $data['jwt']);
        self::assertSame([$expectedTopic], $claims['mercure']['subscribe'] ?? null);
    }

    public function test_mcp_token_is_forbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$raw] = $this->issue($em, ApiTokenScope::Mcp, 'mcp-stream@example.com');

        $client->request(Request::METHOD_GET, '/api/site-review/stream',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_no_token_is_unauthorized(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/api/site-review/stream');
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{0: string, 1: User}
     */
    private function issue(EntityManagerInterface $em, ApiTokenScope $scope, string $email): array
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $raw] = ApiToken::issue($user, 'tok', $scope);
        $em->persist($token);
        $em->flush();

        return [$raw, $user];
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
