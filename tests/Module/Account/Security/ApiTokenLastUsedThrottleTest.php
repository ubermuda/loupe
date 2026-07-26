<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Security;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class ApiTokenLastUsedThrottleTest extends WebTestCase
{
    private const string INIT = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}';

    public function test_second_request_within_stale_window_does_not_update_last_used_at(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$id, $raw] = $this->issueToken($em);

        $this->requestMcp($client, $raw);
        $firstValue = $this->fetchLastUsedAt($em, $id);
        self::assertNotNull($firstValue);

        $this->requestMcp($client, $raw);
        $secondValue = $this->fetchLastUsedAt($em, $id);
        self::assertSame($firstValue, $secondValue);
    }

    public function test_request_after_stale_window_updates_last_used_at(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$id, $raw] = $this->issueToken($em);

        $staleValue = new \DateTimeImmutable('-1 hour')->format('Y-m-d H:i:s');
        $em->getConnection()->executeStatement(
            'UPDATE api_tokens SET last_used_at = :ts WHERE id = :id',
            ['ts' => $staleValue, 'id' => (string) $id],
        );

        $this->requestMcp($client, $raw);
        $updatedValue = $this->fetchLastUsedAt($em, $id);

        self::assertNotNull($updatedValue);
        self::assertGreaterThan($staleValue, $updatedValue);
    }

    /** @return array{0: Uuid, 1: non-empty-string} */
    private function issueToken(EntityManagerInterface $em): array
    {
        $user = new User(username: 'throttle-user', fullName: 'Throttle', email: 'throttle@example.com', password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $raw] = ApiToken::issue($user, 'throttle token', ApiTokenScope::Mcp);
        $em->persist($token);
        $em->flush();
        $em->clear();

        return [$token->id ?? throw new \LogicException('a flushed token always has an id'), $raw];
    }

    private function requestMcp(KernelBrowser $client, string $raw): void
    {
        $client->request(Request::METHOD_POST, '/mcp', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
            'CONTENT_TYPE' => 'application/json',
        ], content: self::INIT);
    }

    private function fetchLastUsedAt(EntityManagerInterface $em, Uuid $id): ?string
    {
        $value = $em->getConnection()->fetchOne('SELECT last_used_at FROM api_tokens WHERE id = :id', ['id' => (string) $id]);

        return false === $value ? null : (string) $value;
    }
}
