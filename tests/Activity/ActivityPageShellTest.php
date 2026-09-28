<?php

declare(strict_types=1);

namespace App\Tests\Activity;

use App\Tests\Module\Bridge\BridgeScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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
        self::assertSame(['Runs', 'Events', 'Cost'], $tabs->each(static fn ($tab): string => trim($tab->text())));
        self::assertSame(
            [$base.'/worker-runs', $base.'/activity', $base.'/worker-runs/cost'],
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

    public function test_the_events_header_keeps_the_feed_controls_inside_the_filter_controller(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-shell-controls@example.com');
        $project = $this->project($em, $owner, 'Controls project');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/activity');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller="activity-filter"] header [data-activity-filter-target="status"]'));
        self::assertCount(1, $crawler->filter('[data-controller="activity-filter"] header [data-activity-filter-target="toggle"]'));
    }
}
