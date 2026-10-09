<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\AgentCredential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

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
        self::assertSame(['0', '0'], $bars->each(static fn (Crawler $bar): string => (string) $bar->attr('tabindex')));
        self::assertCount(2, $bars->filter('rect.lp-metric-chart__hit'));

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

    public function test_a_count_reads_as_a_number_on_a_ratio_metric(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-count@example.com');
        $project = $this->project($em, $owner, 'Count metrics');
        $this->seedRun($em, $project, exitCode: 0);
        $this->seedRun($em, $project, exitCode: 1);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?metric=stop-rate&statistic=count&range=all');

        self::assertResponseIsSuccessful();
        self::assertSame('2', trim($crawler->filter('[data-metrics-summary] .lp-metric-summary__value')->text()));
        $ticks = $crawler->filter('[data-metrics-chart] .lp-metric-chart__tick[text-anchor="end"]')->each(static fn (Crawler $tick): string => trim($tick->text()));
        self::assertSame(['0', '1', '2', '3', '4'], $ticks);
        self::assertStringEndsWith(', 2, 2 runs', (string) $crawler->filter('[data-metrics-bar]')->attr('aria-label'));
        // A row keeps the value of its own metric, a stop as 100% and a success as 0%.
        $values = $crawler->filter('[data-metrics-table] tbody tr td.lp-metric-table__number')->each(static fn (Crawler $cell): string => trim($cell->text()));
        sort($values);
        self::assertSame(['0.0%', '100.0%'], $values);
    }

    public function test_a_run_that_the_retention_sweep_deleted_shows_as_text(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-purged@example.com');
        $project = $this->project($em, $owner, 'Purged metrics');
        $kept = $this->seedRun($em, $project, cardNumber: 7, endedAt: new \DateTimeImmutable('2026-09-02 10:05:00'));
        $purged = $this->seedRun($em, $project, cardNumber: 8, endedAt: new \DateTimeImmutable('2026-09-01 10:05:00'));
        $projectId = (string) $project->id;
        $keptId = (string) $kept->id;
        $em->getConnection()->executeStatement('DELETE FROM bridge_worker_runs WHERE id = :id', ['id' => (string) $purged->id]);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?unit=run&metric=duration&range=all');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-metrics-table] tbody tr');
        self::assertCount(2, $rows);
        self::assertSame('/projects/'.$projectId.'/worker-runs?search='.$keptId, $rows->eq(0)->filter('a')->attr('href'));
        self::assertCount(0, $rows->eq(1)->filter('a'));
        self::assertSame('#8', trim($rows->eq(1)->filter('[data-metrics-purged-run]')->text()));
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

    public function test_a_bridge_series_shows_the_label_of_its_bridge(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-bridges@example.com');
        $project = $this->project($em, $owner, 'Bridge metrics');
        $named = $this->seedBridge($em, $owner, Uuid::fromString('0199a000-0000-7000-8000-000000000001'));
        $named->name = 'studio-mac';
        $em->flush();
        $unknown = Uuid::fromString('0199a000-0000-7000-8000-0000000000ff');
        $this->seedRun($em, $project, bridgeId: $named->id);
        $this->seedRun($em, $project, bridgeId: $unknown);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?unit=run&metric=duration&group=bridge&range=all');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['0199a000-0000-7000-8000-000000000001', '0199a000-0000-7000-8000-0000000000ff'],
            $crawler->filter('[data-metrics-summary] [data-metrics-series]')->each(static fn (Crawler $series): string => (string) $series->attr('data-metrics-series')),
        );
        self::assertSame(['studio-mac', '0000000000ff'], $crawler->filter('[data-metrics-summary] dt')->each(static fn (Crawler $label): string => trim($label->text())));
        self::assertSame(['studio-mac', '0000000000ff'], $crawler->filter('[data-metrics-legend] li')->each(static fn (Crawler $item): string => trim($item->text())));
        self::assertSame(['studio-mac', '0000000000ff'], $crawler->filter('[data-metrics-table] h3')->each(static fn (Crawler $title): string => trim($title->text())));
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

    public function test_a_card_row_links_to_the_card(): void
    {
        $crawler = $this->finishedCardPage();

        $row = $crawler->filter('[data-metrics-table] tbody tr');
        self::assertCount(1, $row);
        self::assertSame('$2.50', trim($row->filter('td')->eq(1)->text()));
        self::assertSame('#9', trim($row->filter('a')->text()));
    }

    public function test_the_picker_lists_one_option_for_each_bucket_with_time(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-bucket-picker@example.com');
        $project = $this->project($em, $owner, 'Bucket picker');
        $this->seedBucketTimes($em, $this->seedRun($em, $project), ['tests' => 10, 'git' => 5]);
        $this->seedBucketTimes($em, $this->seedRun($em, $project), ['tests' => 20]);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics');

        self::assertResponseIsSuccessful();
        $select = $crawler->filter('form[data-metrics-form] select[name="metric"]');
        self::assertCount(11, $select->children('option'));
        $group = $select->filter('optgroup');
        self::assertCount(1, $group);
        self::assertSame('Time buckets', $group->attr('label'));
        self::assertSame(['bucket-time:git', 'bucket-time:tests'], $group->filter('option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame(['Time in git', 'Time in tests'], $group->filter('option')->each(static fn (Crawler $option): string => trim($option->text())));
        self::assertCount(0, $group->filter('option[selected]'));
        self::assertSame('cost', $select->filter('option[selected]')->attr('value'));
    }

    public function test_a_bucket_metric_shows_the_time_of_its_bucket_and_leaves_a_run_with_no_data_out(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-bucket-rows@example.com');
        $project = $this->project($em, $owner, 'Bucket rows');
        $this->seedBucketTimes($em, $this->seedRun($em, $project, cardNumber: 4, endedAt: new \DateTimeImmutable('2026-09-03 10:05:00')), ['tests' => 4000, 'git' => 1000]);
        $this->seedBucketTimes($em, $this->seedRun($em, $project, cardNumber: 5, endedAt: new \DateTimeImmutable('2026-09-02 10:05:00')), ['git' => 700]);
        $this->seedRun($em, $project, cardNumber: 6, endedAt: new \DateTimeImmutable('2026-09-01 10:05:00'));
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?metric=bucket-time:tests&unit=run&statistic=sum&range=all&bucket=day');

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[data-metrics-form]');
        self::assertSame(['bucket-time:tests'], $form->filter('select[name="metric"] option[selected]')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame(['run', 'card'], self::options($form, 'unit'));

        $summary = $crawler->filter('[data-metrics-summary] [data-metrics-series="none"]');
        self::assertSame('Time in tests', trim($summary->filter('dt')->text()));
        self::assertSame('4 s', trim($summary->filter('.lp-metric-summary__value')->text()));
        self::assertStringContainsString('Time in tests', (string) $crawler->filter('[data-metrics-chart] svg')->attr('aria-label'));

        $table = $crawler->filter('[data-metrics-table] [data-metrics-series="none"]');
        self::assertSame('Time in tests', trim($table->filter('thead th')->eq(1)->text()));
        self::assertSame(['4 s', '0 ms', 'unknown'], $table->filter('tbody td.lp-metric-table__number')->each(static fn (Crawler $cell): string => trim($cell->text())));
    }

    public function test_a_numeric_bucket_name_selects_only_its_own_option(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-bucket-numeric@example.com');
        $project = $this->project($em, $owner, 'Bucket numeric');
        $this->seedBucketTimes($em, $this->seedRun($em, $project), ['1' => 10, '01' => 5]);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?metric=bucket-time:1&unit=run&range=all');

        self::assertResponseIsSuccessful();
        self::assertSame(['bucket-time:1'], $crawler->filter('form[data-metrics-form] select[name="metric"] option[selected]')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
    }

    public function test_a_valid_bucket_with_no_time_shows_zero_on_the_runs_with_data(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-bucket-empty@example.com');
        $project = $this->project($em, $owner, 'Bucket empty');
        $this->seedBucketTimes($em, $this->seedRun($em, $project, cardNumber: 4, endedAt: new \DateTimeImmutable('2026-09-02 10:05:00')), ['git' => 700]);
        $this->seedRun($em, $project, cardNumber: 5, endedAt: new \DateTimeImmutable('2026-09-01 10:05:00'));
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/metrics?metric=bucket-time:deploy&unit=run&statistic=count&range=all');

        self::assertResponseIsSuccessful();
        $group = $crawler->filter('form[data-metrics-form] select[name="metric"] optgroup');
        self::assertSame(['bucket-time:deploy', 'bucket-time:git'], $group->filter('option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame('bucket-time:deploy', $group->filter('option[selected]')->attr('value'));

        $summary = $crawler->filter('[data-metrics-summary] [data-metrics-series="none"]');
        self::assertSame('Time in deploy', trim($summary->filter('dt')->text()));
        self::assertSame('1', trim($summary->filter('.lp-metric-summary__value')->text()));
        $table = $crawler->filter('[data-metrics-table] [data-metrics-series="none"]');
        self::assertSame(['0 ms', 'unknown'], $table->filter('tbody td.lp-metric-table__number')->each(static fn (Crawler $cell): string => trim($cell->text())));
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

    private function finishedCardPage(): Crawler
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'metrics-cards@example.com');
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
        self::assertSame('/projects/'.$projectId.'/board/cards/'.$cardId, $crawler->filter('[data-metrics-table] tbody tr a')->attr('href'));

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
                subjectType: WorkSubject::CARD,
                subjectId: Uuid::v7(),
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
