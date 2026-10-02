<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\ValueObject\CliInstallMethod;
use App\Module\Bridge\ValueObject\CliUpdateState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\MercureCookies;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

final class ListAgentsControllerTest extends WebTestCase
{
    use BridgeScenario;
    use MercureCookies;

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

    #[TestWith([false, true, true, 'stale', 'Stale'])]
    #[TestWith([true, true, true, 'paused', 'Paused'])]
    #[TestWith([true, true, false, 'pausing', 'Pausing'])]
    #[TestWith([true, true, null, 'pausing', 'Pausing'])]
    #[TestWith([true, false, true, 'unpausing', 'Resuming new work'])]
    #[TestWith([true, false, false, 'healthy', 'Healthy'])]
    public function test_the_health_chip_shows_the_pause_as_the_bridge_reported_it(bool $recent, bool $pauseRequested, ?bool $pausedReported, string $state, string $label): void
    {
        $client = static::createClient();
        [$owner, $project, $bridge] = $this->pauseScenario('chip', lastSeenAt: new \DateTimeImmutable($recent ? 'now' : '-1 day'));
        $bridge->pauseRequested = $pauseRequested;
        $bridge->pausedReported = $pausedReported;
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        $chip = $crawler->filter('[data-agent-connection-id="'.$bridge->id.'"] [data-agent-health]');
        self::assertSame($state, $chip->attr('data-agent-health-state'));
        self::assertSame($label, trim($chip->text()));
    }

    public function test_a_paused_bridge_shows_when_and_by_whom(): void
    {
        $client = static::createClient();
        [$owner, $project, $bridge] = $this->pauseScenario('paused-block');
        $bridge->pauseRequested = true;
        $bridge->pauseRequestedAt = new \DateTimeImmutable('2026-09-14 16:05:00');
        $bridge->pauseRequestedBy = $owner;
        $bridge->pausedReported = true;
        $other = $this->seedBridge($this->em(), $owner, projects: [(string) $project->id]);
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        $block = $crawler->filter('[data-agent-connection-id="'.$bridge->id.'"] [data-agent-paused]');
        self::assertSame('Paused Sep 14, 16:05 by Riley Chen', preg_replace('/\s+/', ' ', trim($block->text())));
        // The guard: the other card renders, so the absent block is not an absent card.
        self::assertCount(1, $crawler->filter('[data-agent-connection-id="'.$other->id.'"] [data-agent-health]'));
        self::assertCount(0, $crawler->filter('[data-agent-connection-id="'.$other->id.'"] [data-agent-paused]'));
    }

    public function test_a_named_bridge_shows_its_name_and_an_unnamed_one_the_tail_of_its_id(): void
    {
        $client = static::createClient();
        [$owner, $project, $named] = $this->pauseScenario('named');
        $named->name = 'laptop';
        $unnamed = $this->seedBridge($this->em(), $owner, projects: [(string) $project->id]);
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        $namedLabel = $crawler->filter('[data-agent-connection-id="'.$named->id.'"] [data-bridge-label]');
        self::assertSame('laptop', $namedLabel->text());
        self::assertSame((string) $named->id, $namedLabel->attr('title'));
        $unnamedLabel = $crawler->filter('[data-agent-connection-id="'.$unnamed->id.'"] [data-bridge-label]');
        self::assertSame(substr((string) $unnamed->id, -12), $unnamedLabel->text());
        self::assertSame((string) $unnamed->id, $unnamedLabel->attr('title'));
        self::assertCount(0, $crawler->filter('[data-agent-name-clash]'));
    }

    public function test_a_bridge_whose_name_another_bridge_holds_shows_the_clash(): void
    {
        $client = static::createClient();
        [$owner, $project, $holder] = $this->pauseScenario('clash');
        $holder->name = 'laptop';
        $holder->requestedName = 'laptop';
        $claimer = $this->seedBridge($this->em(), $owner, projects: [(string) $project->id]);
        $claimer->requestedName = 'laptop';
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-agent-connection-id="'.$claimer->id.'"]');
        self::assertSame(substr((string) $claimer->id, -12), $card->filter('[data-bridge-label]')->text());
        $warning = preg_replace('/\s+/', ' ', trim($card->filter('[data-agent-name-clash]')->text()));
        self::assertSame('Name already in use laptop Another bridge already holds this name. This bridge shows its id until the name is free, or until you change it in its rules.yaml.', $warning);
        self::assertCount(0, $crawler->filter('[data-agent-connection-id="'.$holder->id.'"] [data-agent-name-clash]'));
    }

    public function test_a_pause_the_bridge_has_not_confirmed_reads_as_requested(): void
    {
        $client = static::createClient();
        [$owner, $project] = $this->pauseScenario('paused-nobody', pauseRequested: true);
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        self::assertSame('Pause requested Sep 14, 16:05', preg_replace('/\s+/', ' ', trim($crawler->filter('[data-agent-paused]')->text())));
    }

    #[TestWith([false, 'pause', 'Pause new work'])]
    #[TestWith([true, 'unpause', 'Resume new work'])]
    public function test_the_owner_gets_the_pause_control_in_the_card_menu(bool $pauseRequested, string $action, string $label): void
    {
        $client = static::createClient();
        [$owner, $project, $bridge] = $this->pauseScenario('menu-'.$action, pauseRequested: $pauseRequested);
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        $menu = $crawler->filter('[data-agent-connection-id="'.$bridge->id.'"] .lp-agent-card__heading [data-agent-menu][data-controller="popover"]');
        self::assertSame('Bridge actions', $menu->filter('button[data-popover-target="trigger"]')->attr('aria-label'));
        $form = $menu->filter('form[data-agent-pause-control="'.$action.'"]');
        self::assertSame('/projects/'.$project->id.'/agents/'.$bridge->id.'/'.$action, $form->attr('action'));
        self::assertSame('post', $form->attr('method'));
        self::assertSame('_top', $form->attr('data-turbo-frame'));
        self::assertCount(1, $form->filter('input[name="_csrf_token"]'));
        $button = $form->filter('button[type="submit"]');
        self::assertSame($label, trim($button->text()));
        self::assertNull($button->attr('disabled'));
    }

    public function test_a_bridge_that_takes_no_commands_shows_why_it_cannot_pause(): void
    {
        $client = static::createClient();
        [$owner, $project, $bridge] = $this->pauseScenario('outdated', capabilities: null);
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        $menu = $crawler->filter('[data-agent-connection-id="'.$bridge->id.'"] [data-agent-menu]');
        self::assertCount(0, $menu->filter('form'));
        $item = $menu->filter('button[data-agent-pause-control="pause"]');
        self::assertNotNull($item->attr('disabled'));
        self::assertSame('Update the bridge to 1.5.0 or later to pause it.', $item->attr('title'));
        self::assertSame('Update the bridge to 1.5.0 or later to pause it.', $item->filter('.sr-only')->text());
    }

    public function test_an_outdated_bridge_can_still_resume_new_work(): void
    {
        $client = static::createClient();
        [$owner, $project, $bridge] = $this->pauseScenario('outdated-unpause', capabilities: null, pauseRequested: true);
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('[data-agent-connection-id="'.$bridge->id.'"] form[data-agent-pause-control="unpause"]');
        self::assertCount(1, $form);
        self::assertNull($form->filter('button[type="submit"]')->attr('disabled'));
    }

    public function test_the_cards_sit_in_a_frame_that_live_updates_reload(): void
    {
        $client = static::createClient();
        [$owner, $project, $bridge] = $this->pauseScenario('live');
        $topic = $this->topics()->forWorkerRuns($project->id ?? throw new \LogicException('The project has no id.'));
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller="worker-run-refresh"] turbo-frame#agents-frame[target="_top"][data-worker-run-refresh-target="frame"] [data-agent-connection-id="'.$bridge->id.'"]'));
        self::assertContains($topic, self::subscribedTopics($client->getResponse()) ?? []);
        self::assertContains($topic, $crawler->filter('form#mercure-subscriptions input[data-mercure-topic]')->each(static fn (Crawler $input): ?string => $input->attr('value')));
    }

    public function test_the_empty_state_sits_in_the_live_frame(): void
    {
        $client = static::createClient();
        $owner = $this->user($this->em(), 'agents-live-empty@example.com');
        $project = $this->project($this->em(), $owner, 'Live empty');
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/agents');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#agents-frame [data-agent-empty]'));
    }

    public function test_a_refused_pause_shows_its_reason_on_the_page(): void
    {
        $client = static::createClient();
        [$owner, $project] = $this->pauseScenario('flash');
        $this->em()->clear();

        $client->loginUser($owner);
        $url = '/projects/'.$project->id.'/agents';
        $client->request(Request::METHOD_GET, $url);
        $this->addPauseFlash($client, 'This bridge is not connected to your account.');
        $crawler = $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('This bridge is not connected to your account.', $crawler->filter('[data-bridge-pause-flash]')->text());
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

    /**
     * @param list<string>|null $capabilities
     *
     * @return array{0: User, 1: Project, 2: Bridge}
     */
    private function pauseScenario(
        string $name,
        \DateTimeImmutable $lastSeenAt = new \DateTimeImmutable(),
        ?array $capabilities = [Bridge::CAPABILITY_COMMANDS],
        bool $pauseRequested = false,
    ): array {
        $em = $this->em();
        $owner = $this->user($em, 'agents-pause-'.$name.'@example.com');
        $project = $this->project($em, $owner, 'Agents pause '.$name);
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id], cliVersion: '1.5.0', lastSeenAt: $lastSeenAt);
        $bridge->capabilities = $capabilities;
        $bridge->pauseRequested = $pauseRequested;
        $bridge->pauseRequestedAt = $pauseRequested ? new \DateTimeImmutable('2026-09-14 16:05:00') : null;
        $em->flush();

        return [$owner, $project, $bridge];
    }

    /** The pause routes refuse only a bridge that goes away after the lookup, so the test writes the flash itself. */
    private function addPauseFlash(KernelBrowser $client, string $message): void
    {
        $session = $client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        $session->getFlashBag()->add('bridge-pause', $message);
        $session->save();
    }

    private function topics(): ProjectTopicBuilder
    {
        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);

        return $topics;
    }
}
