<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights;

use App\Tests\Module\Bridge\BridgeScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AnalyticsPageShellTest extends WebTestCase
{
    use BridgeScenario;

    /** @return iterable<string, array{string, string}> */
    public static function tabs(): iterable
    {
        yield 'metrics' => ['/analytics/metrics', 'Metrics'];
        yield 'experiments' => ['/analytics/experiments', 'Experiments'];
        yield 'reports' => ['/analytics/reports', 'Reports'];
    }

    #[DataProvider('tabs')]
    public function test_each_tab_shows_the_analytics_header_tabs_crumb_and_sidebar(string $path, string $activeTab): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'analytics-shell-'.strtolower($activeTab).'@example.com');
        $project = $this->project($em, $owner, 'Shell project');
        $base = '/projects/'.$project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $base.$path);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('Analytics', trim($crawler->filter('title')->text()));
        self::assertSame('Analytics', trim($crawler->filter('h1.lp-workspace-title')->text()));

        $tabs = $crawler->filter('nav.lp-analytics-tabs .lp-tabs__tab');
        self::assertSame(['Metrics', 'Experiments', 'Reports'], $tabs->each(static fn ($tab): string => trim($tab->text())));
        self::assertSame(
            [$base.'/analytics/metrics', $base.'/analytics/experiments', $base.'/analytics/reports'],
            $tabs->each(static fn ($tab): ?string => $tab->attr('href')),
        );
        self::assertSame([$activeTab], $crawler->filter('nav.lp-analytics-tabs [aria-current="page"]')->each(static fn ($tab): string => trim($tab->text())));

        self::assertSame($base.'/documents', $crawler->filter('.lp-topbar__crumb')->attr('href'));
        self::assertSame('Analytics', trim($crawler->filter('.lp-topbar__here')->text()));

        $active = $crawler->filter('a.lp-sidebar__link--active[aria-current="page"]');
        self::assertCount(1, $active);
        self::assertSame($base.'/analytics/metrics', $active->attr('href'));
        self::assertSame('Analytics', trim($active->text()));
    }

    #[DataProvider('tabs')]
    public function test_another_users_project_is_refused(string $path, string $activeTab): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'analytics-shell-theirs-'.strtolower($activeTab).'@example.com');
        $stranger = $this->user($em, 'analytics-shell-stranger-'.strtolower($activeTab).'@example.com');
        $base = '/projects/'.$this->project($em, $owner, 'Their project')->id;
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, $base.$path);

        self::assertResponseStatusCodeSame(403);
    }
}
