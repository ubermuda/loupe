<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class WorkshopCrewTest extends WebTestCase
{
    use BridgeScenario;

    public function test_workshop_lists_project_connections_with_matching_health_and_links(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'workshop-crew@example.com');
        $project = $this->project($em, $owner, 'Workshop crew');
        $otherProject = $this->project($em, $owner, 'Other project');
        $healthy = $this->seedBridge($em, $owner, projects: [(string) $project->id], cliVersion: 'healthy-version');
        $stale = $this->seedBridge($em, $owner, projects: [(string) $project->id], cliVersion: 'stale-version', lastSeenAt: new \DateTimeImmutable('-1 day'));
        $this->seedBridge($em, $owner, projects: [(string) $otherProject->id], cliVersion: 'other-project-version');
        $stranger = $this->user($em, 'workshop-crew-stranger@example.com');
        $this->seedBridge($em, $stranger, projects: [(string) $project->id], cliVersion: 'foreign-version');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id);

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '[data-workshop-connection]');
        self::assertSelectorTextContains('[data-workshop-connection="'.$healthy->id.'"]', 'healthy-version');
        self::assertSelectorTextSame('[data-workshop-connection="'.$healthy->id.'"] .lp-workshop-crew__status', 'Healthy');
        self::assertSelectorTextSame('[data-workshop-connection="'.$stale->id.'"] .lp-workshop-crew__status', 'Stale');
        self::assertStringNotContainsString('other-project-version', $crawler->text());
        self::assertStringNotContainsString('foreign-version', $crawler->text());
        self::assertSelectorNotExists('[data-workshop-crew-empty]');

        $link = $crawler->filter('[data-workshop-connection="'.$stale->id.'"]')->link();
        self::assertStringEndsWith('/agents#agent-connection-'.$stale->id, $link->getUri());
        $client->click($link);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#agent-connection-'.$stale->id, 'stale-version');
        self::assertSelectorTextSame('#agent-connection-'.$stale->id.' .lp-status-chip', 'Stale');
    }

    public function test_a_project_without_connections_shows_connection_guidance(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'workshop-empty-crew@example.com');
        $project = $this->project($em, $owner, 'Empty crew');
        $this->seedBridge($em, $owner, projects: [], cliVersion: 'unrelated-version');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id);

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(0, '[data-workshop-connection]');
        self::assertSelectorCount(1, '[data-workshop-crew-empty]');
        self::assertSame('/projects/'.$project->id.'/connect', $crawler->filter('[data-workshop-crew-empty]')->attr('href'));
    }
}
