<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Tests\Module\Bridge\BridgeScenario;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ListAgentsControllerTest extends WebTestCase
{
    use BridgeScenario;

    /** @param list<string> $expected */
    #[TestWith([1, 0, ['Healthy']])]
    #[TestWith([0, 1, ['Stale']])]
    #[TestWith([1, 1, ['Healthy', 'Stale']])]
    public function test_each_connection_status_uses_heartbeat_health(int $healthy, int $stale, array $expected): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'agents-health@example.com');
        $project = $this->project($em, $owner, 'Health');
        for ($index = 0; $index < $healthy + $stale; ++$index) {
            $this->seedBridge($em, $owner, projects: [(string) $project->id], lastSeenAt: new \DateTimeImmutable($index < $healthy ? 'now' : '-1 day'));
        }
        $em->clear();
        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        $statuses = $crawler->filter('[data-agent-connection-id] .lp-status-chip')->each(static fn ($chip): string => trim($chip->text()));
        sort($statuses);
        self::assertSame($expected, $statuses);
    }

    public function test_it_lists_only_connections_that_follow_the_project(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'agents-owner@example.com');
        $project = $this->project($em, $owner, 'Crew');
        $matching = $this->seedBridge($em, $owner, projects: [(string) $project->id], cliVersion: 'matching-version');
        $this->seedBridge($em, $owner, projects: [], cliVersion: 'other-version');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-agent-connection-id="'.$matching->id.'"]'));
        self::assertStringContainsString('matching-version', $crawler->text());
        self::assertStringNotContainsString('other-version', $crawler->text());
    }

    public function test_a_stranger_cannot_read_connections(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'agents-victim@example.com');
        $project = $this->project($em, $owner, 'Private crew');
        $stranger = $this->user($em, 'agents-stranger@example.com');
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseStatusCodeSame(403);
    }
}
