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

    #[TestWith([0, 0, 'No connections'])]
    #[TestWith([1, 0, 'Healthy'])]
    #[TestWith([0, 1, 'Stale'])]
    #[TestWith([1, 1, 'Some connections are stale'])]
    public function test_the_connection_summary_uses_heartbeat_health(int $healthy, int $stale, string $expected): void
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
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.lp-agent-connection-strip .lp-status-chip', $expected);
        self::assertSelectorCount($healthy + $stale, '[data-agent-connection-id]');
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
