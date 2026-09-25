<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\ValueObject\CliUpdateState;
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
        $statuses = $crawler->filter('[data-agent-connection-id] [data-agent-health]')->each(static fn ($chip): string => trim($chip->text()));
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

    public function test_a_commit_sha_version_shows_its_short_form(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'agents-sha@example.com');
        $project = $this->project($em, $owner, 'Sha');
        $sha = '03cc8ba4f1e2d3c4b5a6978877665544332211aa';
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id], cliVersion: $sha);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-agent-connection-id="'.$bridge->id.'"]');
        self::assertStringContainsString('03cc8ba4', $card->text());
        self::assertStringNotContainsString($sha, $card->text());
    }

    #[TestWith(['1.2.0', 'current', null, 'Up to date'])]
    #[TestWith(['1.2.0', 'updating', '1.3.0', 'Updating'])]
    #[TestWith(['1.2.0', 'rolled-back', '1.3.0', 'Rolled back from 1.3.0'])]
    #[TestWith(['1.2.0', 'blocked', '1.3.0', 'Update blocked'])]
    #[TestWith(['1.2.0', 'off', null, null])]
    #[TestWith(['1.2.0', 'dev', null, null])]
    #[TestWith(['1.2.0', null, null, null])]
    #[TestWith(['2.0.0', 'current', null, 'Needs ^1.0'])]
    #[TestWith(['03cc8ba4f1e2d3c4b5a6978877665544332211aa', 'dev', null, 'Needs ^1.0'])]
    public function test_the_update_chip_follows_the_version_and_the_update_state(string $cliVersion, ?string $state, ?string $version, ?string $expected): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'agents-update@example.com');
        $project = $this->project($em, $owner, 'Update');
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id], cliVersion: $cliVersion);
        $bridge->updateState = null === $state ? null : CliUpdateState::from($state);
        $bridge->updateVersion = $version;
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        $chips = $crawler->filter('[data-agent-connection-id="'.$bridge->id.'"] [data-agent-update]')->each(static fn ($chip): string => trim($chip->text()));
        self::assertSame(null === $expected ? [] : [$expected], $chips);
    }

    public function test_a_semver_version_is_shown_whole(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'agents-semver@example.com');
        $project = $this->project($em, $owner, 'Semver');
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id], cliVersion: '1.10.3');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1.10.3', $crawler->filter('[data-agent-connection-id="'.$bridge->id.'"]')->text());
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
