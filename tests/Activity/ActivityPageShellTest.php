<?php

declare(strict_types=1);

namespace App\Tests\Activity;

use App\Mercure\ProjectTopicBuilder;
use App\Outbox\Entity\OutboxEvent;
use App\Tests\Module\Bridge\BridgeScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class ActivityPageShellTest extends WebTestCase
{
    use BridgeScenario;

    /** @return iterable<string, array{string, string}> */
    public static function tabs(): iterable
    {
        yield 'runs' => ['/worker-runs', 'Runs'];
        yield 'events' => ['/activity', 'Events'];
        yield 'cost' => ['/worker-runs/cost', 'Cost'];
        yield 'experiments' => ['/worker-runs/experiments', 'Experiments'];
    }

    #[DataProvider('tabs')]
    public function test_each_tab_shows_the_activity_header_tabs_crumb_and_sidebar(string $path, string $activeTab): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-shell-'.strtolower($activeTab).'@example.com');
        $project = $this->project($em, $owner, 'Shell project');
        $base = '/projects/'.$project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $base.$path);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('Activity', trim($crawler->filter('title')->text()));
        self::assertSame('Activity', trim($crawler->filter('h1.lp-workspace-title')->text()));
        self::assertSame(
            'Every worker run and project event, with the context to understand it.',
            trim($crawler->filter('.lp-workspace-desc')->first()->text()),
        );

        $tabs = $crawler->filter('nav.lp-activity-tabs .lp-tabs__tab');
        self::assertSame(['Runs', 'Events', 'Cost', 'Experiments'], $tabs->each(static fn ($tab): string => trim($tab->text())));
        self::assertSame(
            [$base.'/worker-runs', $base.'/activity', $base.'/worker-runs/cost', $base.'/worker-runs/experiments'],
            $tabs->each(static fn ($tab): ?string => $tab->attr('href')),
        );
        self::assertSame([$activeTab], $crawler->filter('nav.lp-activity-tabs [aria-current="page"]')->each(static fn ($tab): string => trim($tab->text())));

        self::assertSame(['Shell project'], $crawler->filter('.lp-topbar__crumb')->each(static fn ($crumb): string => trim($crumb->text())));
        self::assertSame($base.'/documents', $crawler->filter('.lp-topbar__crumb')->attr('href'));
        self::assertSame('Activity', trim($crawler->filter('.lp-topbar__here')->text()));

        self::assertStringNotContainsString('Run history', $crawler->filter('.lp-sidebar')->text());
        $active = $crawler->filter('a.lp-sidebar__link--active[aria-current="page"]');
        self::assertCount(1, $active);
        self::assertSame($base.'/worker-runs', $active->attr('href'));
        self::assertSame('Activity', trim($active->text()));
    }

    public function test_the_events_page_subscribes_to_the_activity_topic_and_wraps_the_frame_in_the_refresh_controller(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-shell-live@example.com');
        $project = $this->project($em, $owner, 'Live project');
        $em->persist(new OutboxEvent($project, 'board.card_moved', 'topic', '{}'));
        $em->flush();
        $projectId = (string) $project->id;
        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        $topic = $topics->forActivity($project->id ?? throw new \LogicException('The project has no id.'));
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/activity');

        self::assertResponseIsSuccessful();
        self::assertContains($topic, $crawler->filter('form#mercure-subscriptions input[data-mercure-topic]')->each(static fn (Crawler $input): ?string => $input->attr('value')));
        $refresh = $crawler->filter('[data-controller="worker-run-refresh"]');
        self::assertCount(1, $refresh);
        self::assertSame(['activity.changed'], json_decode((string) $refresh->attr('data-worker-run-refresh-events-value'), true));
        self::assertSame(['activity-count'], json_decode((string) $refresh->attr('data-worker-run-refresh-frames-value'), true));
        self::assertCount(1, $refresh->filter('turbo-frame#activity-frame[target="_top"][data-worker-run-refresh-target="frame"] [data-activity-event-id]'));
        self::assertStringNotContainsString('Pause feed', $crawler->text());
    }
}
