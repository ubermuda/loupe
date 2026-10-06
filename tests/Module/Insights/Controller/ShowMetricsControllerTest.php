<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\AgentCredential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class ShowMetricsControllerTest extends WebTestCase
{
    use BridgeScenario;

    public function test_a_project_with_no_rows_shows_the_form_at_its_defaults_and_the_empty_state(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-empty@example.com');
        $projectId = (string) $this->project($em, $owner, 'Empty metrics')->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics');

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[data-metrics-form]');
        self::assertCount(1, $form);
        self::assertSame('/projects/'.$projectId.'/analytics/metrics', $form->attr('action'));
        self::assertSame('get', $form->attr('method'));
        self::assertSame(
            ['metric' => 'cost', 'unit' => 'card', 'statistic' => 'median', 'group' => 'none', 'range' => 'ninety-days', 'bucket' => 'week'],
            self::selected($form),
        );
        self::assertSame(['run', 'card'], self::options($form, 'unit'));
        self::assertSame('Cost', trim($form->filter('select[name="metric"] option[value="cost"]')->text()));
        self::assertCount(11, $form->filter('select[name="metric"] option'));
        self::assertCount(1, $form->filter('noscript'));

        $empty = $crawler->filter('[data-metrics-empty]');
        self::assertCount(1, $empty);
        self::assertSame('No runs are recorded yet', trim($empty->filter('h2')->text()));
        self::assertCount(0, $crawler->filter('[data-metrics-chart]'));
        self::assertCount(0, $crawler->filter('[data-metrics-summary]'));
        self::assertCount(0, $crawler->filter('[data-metrics-table]'));
    }

    public function test_the_form_offers_only_what_the_metric_allows_and_corrects_a_refused_value(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-allowed@example.com');
        $projectId = (string) $this->project($em, $owner, 'Allowed metrics')->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?metric=merge-rate&unit=run&statistic=p90&group=model');

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[data-metrics-form]');
        self::assertSame(['card'], self::options($form, 'unit'));
        self::assertSame(['mean', 'count'], self::options($form, 'statistic'));
        self::assertSame(['variant', 'card-type', 'none'], self::options($form, 'group'));
        self::assertSame(['metric' => 'merge-rate', 'unit' => 'card', 'statistic' => 'mean', 'group' => 'none'], array_slice(self::selected($form), 0, 4));
    }

    public function test_run_rows_fill_the_summary_the_chart_and_the_table(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-runs@example.com');
        $project = $this->project($em, $owner, 'Run metrics');
        $first = $this->seedRun($em, $project, cardNumber: 4, endedAt: new \DateTimeImmutable('2026-09-01 10:05:00'));
        $this->seedUsage($em, $first, costUsd: '1.00');
        $second = $this->seedRun($em, $project, cardNumber: 5, endedAt: new \DateTimeImmutable('2026-09-02 10:05:00'));
        $this->seedUsage($em, $second, costUsd: '3.00');
        $unpriced = $this->seedRun($em, $project, cardNumber: 6, endedAt: new \DateTimeImmutable('2026-09-03 10:05:00'));
        $projectId = (string) $project->id;
        $secondId = (string) $second->id;
        $unpricedId = (string) $unpriced->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?unit=run&statistic=sum&range=all&bucket=day');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-metrics-empty]'));

        $summary = $crawler->filter('[data-metrics-summary] [data-metrics-series="none"]');
        self::assertCount(1, $summary);
        self::assertSame('Cost', trim($summary->filter('dt')->text()));
        self::assertSame(['$4.00', '3 runs'], $summary->filter('dd')->each(static fn (Crawler $value): string => trim($value->text())));

        $chart = $crawler->filter('[data-metrics-chart]');
        self::assertCount(1, $chart);
        self::assertCount(0, $chart->filter('[data-metrics-legend]'));
        $bars = $chart->filter('[data-metrics-bar]');
        self::assertCount(2, $bars);
        self::assertSame('Sep 1: Cost, $1.00, 1 run', $bars->first()->attr('aria-label'));
        self::assertSame('Sep 1: Cost, $1.00, 1 run', trim($bars->first()->filter('title')->text()));

        $table = $crawler->filter('[data-metrics-table] [data-metrics-series="none"]');
        $rows = $table->filter('tbody tr');
        self::assertCount(3, $rows);
        self::assertSame('unknown', trim($rows->eq(0)->filter('td')->eq(1)->text()));
        self::assertSame('/projects/'.$projectId.'/worker-runs?search='.$unpricedId, $rows->eq(0)->filter('a')->attr('href'));
        self::assertSame('#6', trim($rows->eq(0)->filter('a')->text()));
        self::assertSame('$3.00', trim($rows->eq(1)->filter('td')->eq(1)->text()));
        self::assertSame('/projects/'.$projectId.'/worker-runs?search='.$secondId, $rows->eq(1)->filter('a')->attr('href'));
        self::assertCount(0, $table->filter('[data-metrics-hidden-rows]'));
    }

    public function test_a_grouped_query_draws_one_series_per_group_with_a_legend(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-groups@example.com');
        $project = $this->project($em, $owner, 'Grouped metrics');
        $this->seedRun($em, $project, workKind: 'plan');
        $this->seedRun($em, $project, workKind: 'review');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?unit=run&metric=duration&group=stage&range=all');

        self::assertResponseIsSuccessful();
        self::assertSame(['plan', 'review'], $crawler->filter('[data-metrics-summary] [data-metrics-series]')->each(static fn (Crawler $series): string => (string) $series->attr('data-metrics-series')));
        self::assertSame(['plan', 'review'], $crawler->filter('[data-metrics-legend] li')->each(static fn (Crawler $item): string => trim($item->text())));
        self::assertSame(['5 min 0 s', '5 min 0 s'], $crawler->filter('[data-metrics-summary] dd:first-of-type')->each(static fn (Crawler $value): string => trim($value->text())));
        self::assertCount(2, $crawler->filter('[data-metrics-chart] [data-metrics-bar]'));
    }

    public function test_a_long_series_shows_its_newest_hundred_rows_and_says_how_many_it_hides(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-long@example.com');
        $project = $this->project($em, $owner, 'Long metrics');
        $this->manyRuns($em, $project, 103);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?unit=run&metric=duration&range=all');

        self::assertResponseIsSuccessful();
        $table = $crawler->filter('[data-metrics-table] [data-metrics-series="none"]');
        self::assertCount(100, $table->filter('tbody tr'));
        self::assertSame('3 more runs are not shown.', trim($table->filter('[data-metrics-hidden-rows]')->text()));
    }

    public function test_a_card_row_links_to_the_card_when_the_board_is_on(): void
    {
        $crawler = $this->finishedCardPage(true);

        $row = $crawler->filter('[data-metrics-table] tbody tr');
        self::assertCount(1, $row);
        self::assertSame('$2.50', trim($row->filter('td')->eq(1)->text()));
        self::assertSame('#9', trim($row->filter('a')->text()));
    }

    public function test_a_card_row_names_the_card_with_no_link_when_the_board_is_off(): void
    {
        $crawler = $this->finishedCardPage(false);

        $row = $crawler->filter('[data-metrics-table] tbody tr');
        self::assertCount(1, $row);
        self::assertCount(0, $row->filter('a'));
        self::assertSame('#9', trim($row->filter('td')->first()->text()));
    }

    public function test_another_users_project_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-theirs@example.com');
        $stranger = $this->user($em, 'metrics-stranger@example.com');
        $projectId = (string) $this->project($em, $owner, 'Their metrics')->id;
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics');

        self::assertResponseStatusCodeSame(403);
    }

    private function finishedCardPage(bool $boardEnabled): Crawler
    {
        $client = static::createClient();
        $em = $this->em();
        static::getContainer()->get(FeatureFlagRepository::class)->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = $boardEnabled;
        $owner = $this->user($em, 'metrics-cards-'.($boardEnabled ? 'on' : 'off').'@example.com');
        $project = $this->project($em, $owner, 'Card metrics');
        $done = new BoardColumn($project, 'Done', 'done', 0, terminal: true);
        $card = new Card($project, $done, 'Ship it', '', 9);
        $card->completedAt = new \DateTimeImmutable('2026-09-10 12:00:00');
        $em->persist($done);
        $em->persist($card);
        $em->flush();
        $run = $this->seedRun($em, $project, cardNumber: 9, cardId: $card->id, endedAt: new \DateTimeImmutable('2026-09-10 11:00:00'));
        $this->seedUsage($em, $run, costUsd: '2.50');
        $projectId = (string) $project->id;
        $cardId = (string) $card->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?range=all');

        self::assertResponseIsSuccessful();
        if ($boardEnabled) {
            self::assertSame('/projects/'.$projectId.'/board/cards/'.$cardId, $crawler->filter('[data-metrics-table] tbody tr a')->attr('href'));
        }

        return $crawler;
    }

    /** One flush for all the runs, so the fact listener writes them together. */
    private function manyRuns(EntityManagerInterface $em, Project $project, int $count): void
    {
        $managed = AgentCredential::managed($em, $project, $project->id);
        $start = new \DateTimeImmutable('2026-09-01 10:00:00');
        for ($index = 0; $index < $count; ++$index) {
            $em->persist(new WorkerRun(
                project: $managed,
                bridgeId: Uuid::v7(),
                cardId: Uuid::v7(),
                cardNumber: $index + 1,
                workKind: 'plan',
                state: WorkerRunState::Succeeded,
                runKey: null,
                sessionId: Uuid::v4(),
                startedAt: $start,
                endedAt: $start->modify(\sprintf('+%d minutes', $index + 1)),
                exitCode: 0,
                hasResult: null,
                failureReason: null,
                output: '',
                receivedAt: $start,
            ));
        }
        $em->flush();
    }

    /** @return array<string, string> select name => selected value */
    private static function selected(Crawler $form): array
    {
        $selected = [];
        $form->filter('select')->each(static function (Crawler $select) use (&$selected): void {
            $selected[(string) $select->attr('name')] = (string) $select->filter('option[selected]')->attr('value');
        });

        return $selected;
    }

    /** @return list<string> */
    private static function options(Crawler $form, string $name): array
    {
        return $form->filter('select[name="'.$name.'"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value'));
    }
}
