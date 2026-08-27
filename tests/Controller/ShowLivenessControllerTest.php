<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;

final class ShowLivenessControllerTest extends WebTestCase
{
    public function test_anonymous_probe_gets_200_and_is_not_cached(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/livez');

        self::assertResponseIsSuccessful();
        self::assertSame('live', $client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function test_it_issues_no_database_query(): void
    {
        // The whole reason the route exists: a first deploy has no reachable
        // database, so a single query here deadlocks the health check.
        $client = static::createClient();
        $client->enableProfiler();
        $client->request(Request::METHOD_GET, '/livez');
        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $executed = [];
        foreach ($collector->getQueries() as $queries) {
            foreach ($queries as $query) {
                $executed[] = (string) $query['sql'];
            }
        }

        self::assertSame([], $executed);
    }
}
