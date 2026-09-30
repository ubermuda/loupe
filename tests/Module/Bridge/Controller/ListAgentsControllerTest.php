<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\ValueObject\CliInstallMethod;
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
    #[TestWith(['1.2.0', 'off', '1.3.0', 'Update available: 1.3.0'])]
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

    #[TestWith([null, 'curl -fsSL http://localhost/install.sh | sh'])]
    #[TestWith([CliInstallMethod::Homebrew, 'brew upgrade loupe'])]
    public function test_an_available_update_shows_the_command_that_installs_it(?CliInstallMethod $method, string $expected): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'agents-available@example.com');
        $project = $this->project($em, $owner, 'Available');
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id], cliVersion: '1.2.0');
        $bridge->updateState = CliUpdateState::Off;
        $bridge->updateVersion = '1.3.0';
        $bridge->installMethod = $method;
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        $commands = $crawler->filter('[data-agent-connection-id="'.$bridge->id.'"] [data-agent-update-command]')->each(static fn ($node): string => trim($node->text()));
        self::assertSame([$expected], $commands);
    }

    public function test_the_update_command_starts_collapsed_behind_the_chip(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'agents-collapsed@example.com');
        $project = $this->project($em, $owner, 'Collapsed');
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id], cliVersion: '1.2.0');
        $bridge->updateState = CliUpdateState::Off;
        $bridge->updateVersion = '1.3.0';
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-agent-connection-id="'.$bridge->id.'"]');
        $toggle = $card->filter('button[data-agent-update][data-action="disclosure#toggle"]');
        self::assertCount(1, $toggle);
        $panel = $card->filter('[id="'.$toggle->attr('aria-controls').'"]');
        self::assertCount(1, $panel->filter('[data-agent-update-command]'));
        self::assertStringNotContainsString(' open', ' '.$panel->attr('class'));
        self::assertSame('content', $panel->attr('data-disclosure-target'));
    }

    public function test_no_command_shows_without_an_available_update(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'agents-no-command@example.com');
        $project = $this->project($em, $owner, 'No command');
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id], cliVersion: '1.2.0');
        $bridge->updateState = CliUpdateState::Current;
        $bridge->installMethod = CliInstallMethod::Homebrew;
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-agent-connection-id="'.$bridge->id.'"]');
        self::assertCount(1, $card->filter('[data-agent-update]'));
        self::assertCount(0, $card->filter('[data-agent-update-command]'));
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

    public function test_a_connection_shows_the_use_of_each_worker_pool(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'agents-pools@example.com');
        $project = $this->project($em, $owner, 'Pools');
        // A later heartbeat carried no pools, so the counts date from the report, not from the last heartbeat.
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id], lastSeenAt: new \DateTimeImmutable('2026-09-14 16:09:00'), workerPools: [
            ['name' => 'default', 'size' => 3, 'inUse' => 2, 'queued' => 0],
            ['name' => 'quick', 'size' => 1, 'inUse' => 1, 'queued' => 4],
        ], workerPoolsReportedAt: new \DateTimeImmutable('2026-09-14 16:05:00'));
        $older = $this->seedBridge($em, $owner, projects: [(string) $project->id]);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        $pools = $crawler->filter('[data-agent-connection-id="'.$bridge->id.'"] [data-agent-worker-pools]');
        self::assertStringContainsString('Worker pools', $pools->text());
        self::assertStringContainsString('As of Sep 14, 16:05', $pools->text());
        self::assertStringNotContainsString('16:09', $pools->text());
        self::assertSame(
            ['default 2 in use of 3 · 0 queued', 'quick 1 in use of 1 · 4 queued'],
            $pools->filter('[data-agent-worker-pool]')->each(static fn ($row): string => preg_replace('/\s+/', ' ', trim($row->text())) ?? ''),
        );
        // The guard: the older bridge's card renders, so the absent block is not an absent card.
        self::assertCount(1, $crawler->filter('[data-agent-connection-id="'.$older->id.'"] [data-agent-health]'));
        self::assertCount(0, $crawler->filter('[data-agent-connection-id="'.$older->id.'"] [data-agent-worker-pools]'));
    }

    public function test_an_empty_pool_report_shows_no_block(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'agents-pools-empty@example.com');
        $project = $this->project($em, $owner, 'No pools');
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id], workerPools: []);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-agent-connection-id="'.$bridge->id.'"] [data-agent-health]'));
        self::assertCount(0, $crawler->filter('[data-agent-connection-id="'.$bridge->id.'"] [data-agent-worker-pools]'));
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
