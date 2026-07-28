<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ShowHealthController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowHealthControllerTest extends WebTestCase
{
    public function test_anonymous_probe_gets_200_and_an_ok_body(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/healthz');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function test_unreachable_database_gets_503_and_leaks_nothing_about_the_failure(): void
    {
        /** @var Connection&Stub $connection */
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException(
            new \RuntimeException('SQLSTATE[08006] could not connect to server: host=db.internal user=app password=hunter2'),
        );

        $response = (new ShowHealthController($connection, new NullLogger()))();

        self::assertSame(503, $response->getStatusCode());
        $body = (string) $response->getContent();
        self::assertJsonStringEqualsJsonString('{"status":"error"}', $body);
        // The exception message carries the database host, user and password;
        // an unauthenticated probe must never see any of it.
        self::assertStringNotContainsString('db.internal', $body);
        self::assertStringNotContainsString('hunter2', $body);
        self::assertStringNotContainsString('SQLSTATE', $body);
    }
}
