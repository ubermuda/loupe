<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Twig;

use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class RulesPageHooksTest extends WebTestCase
{
    use BridgeScenario;

    public function test_the_rules_page_lists_the_hooks_of_each_bridge_that_follows_the_project(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'rules-hooks-rows@example.com');
        $project = $this->project($em, $owner, 'Hooked');
        $other = $this->project($em, $owner, 'Elsewhere');
        $bridge = $this->seedBridge($em, $owner, Uuid::fromString('0199a0c4-5d3e-7b1a-8f00-00000000b41d'), [(string) $project->id]);
        $bridge->hooks = [
            ['package' => 'github:acme/loupe-hooks', 'ref' => 'v1.2.0', 'event' => 'start', 'lastRunAt' => '2026-09-14T16:00:00+00:00', 'outcome' => 'ok', 'error' => null],
            ['package' => 'github:acme/notify', 'ref' => 'main', 'event' => 'stop', 'lastRunAt' => '2026-09-14T16:05:00+00:00', 'outcome' => 'failed', 'error' => 'exit status 2'],
            ['package' => 'github:acme/notify', 'ref' => 'main', 'event' => 'idle', 'lastRunAt' => null, 'outcome' => 'never', 'error' => null],
        ];
        $elsewhere = $this->seedBridge($em, $owner, projects: [(string) $other->id]);
        $elsewhere->hooks = [['package' => 'github:acme/unrelated', 'ref' => 'main', 'event' => 'busy', 'lastRunAt' => null, 'outcome' => 'never', 'error' => null]];
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/rules');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-bridge-hooks]'));
        self::assertSelectorTextContains('[data-bridge-hooks="0199a0c4-5d3e-7b1a-8f00-00000000b41d"]', '00000000b41d');
        self::assertCount(3, $crawler->filter('[data-hook-row]'));
        $failed = $crawler->filter('[data-hook-row][data-hook-event="stop"]');
        self::assertStringContainsString('github:acme/notify', $failed->text());
        self::assertStringContainsString('main', $failed->text());
        self::assertStringContainsString('exit status 2', $failed->text());
        self::assertCount(1, $failed->filter('.lp-status-chip--failed'));
        self::assertCount(1, $crawler->filter('[data-hook-event="start"] .lp-status-chip--ok'));
        self::assertCount(1, $crawler->filter('[data-hook-event="idle"] .lp-status-chip--neutral'));
        self::assertStringNotContainsString('github:acme/unrelated', $crawler->text());
        self::assertSelectorNotExists('[data-hooks-none]');
    }

    public function test_a_bridge_that_reports_no_hooks_says_so(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'rules-hooks-none@example.com');
        $project = $this->project($em, $owner, 'Unhooked');
        $this->seedBridge($em, $owner, projects: [(string) $project->id]);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/rules');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-bridge-hooks]'));
        self::assertCount(0, $crawler->filter('[data-hook-row]'));
        self::assertSelectorTextContains('[data-bridge-hooks] [data-hooks-none]', 'No hook is installed');
    }

    public function test_a_project_that_no_bridge_follows_shows_no_bridge_hooks(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'rules-hooks-nobridge@example.com');
        $project = $this->project($em, $owner, 'Unfollowed');
        $this->seedBridge($em, $owner, projects: []);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/rules');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-bridge-hooks]'));
        self::assertSelectorTextContains('[data-hooks-section] [data-hooks-none]', 'No hook is installed');
    }
}
