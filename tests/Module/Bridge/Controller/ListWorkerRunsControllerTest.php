<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Bridge\Command\ListWorkerRunsHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunReason;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\MercureCookies;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class ListWorkerRunsControllerTest extends WebTestCase
{
    use BridgeScenario;
    use MercureCookies;

    public function test_the_page_lists_the_projects_runs_and_hides_another_projects(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'runs-owner@example.com');
        $project = $this->project($em, $owner, 'Mine');
        $other = $this->project($em, $owner, 'Theirs');
        $this->seedRun($em, $project, cardNumber: 42, workKind: 'plan');
        $this->seedRun($em, $other, cardNumber: 99, workKind: 'othersrule');

        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('#42', $body);
        self::assertStringContainsString('plan', $body);
        self::assertStringNotContainsString('othersrule', $body);
    }

    /**
     * The output is agent-written text nobody reviewed, and the page shows it to
     * everyone who can view the project.
     */
    public function test_the_output_is_rendered_as_text_and_never_as_markup(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'escape-owner@example.com');
        $project = $this->project($em, $owner, 'Escapes');
        $this->seedRun($em, $project, output: "line one\n<script>alert('pwned')</script>\n<img src=x onerror=alert(1)>");

        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        // The guard: without it the two negative assertions below also pass on a
        // page that rendered no output at all.
        self::assertStringContainsString('line one', $body);
        self::assertStringContainsString('&lt;script&gt;alert(&#039;pwned&#039;)&lt;/script&gt;', $body);
        self::assertStringNotContainsString("<script>alert('pwned')</script>", $body);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $body);
    }

    /** A queued run has no start, so the row shows when the server first heard of it. */
    public function test_a_run_that_has_not_started_shows_when_it_was_received(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'queued-owner@example.com');
        $project = $this->project($em, $owner, 'Queued');
        $em->persist(new WorkerRun(
            project: $project,
            bridgeId: Uuid::v7(),
            cardId: Uuid::v7(),
            cardNumber: 5,
            workKind: 'waiting rule',
            state: WorkerRunState::Queued,
            runKey: Uuid::v7(),
            receivedAt: new \DateTimeImmutable('2026-03-04 05:06:00'),
        ));
        $em->flush();

        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('waiting rule', (string) $client->getResponse()->getContent());
        $time = $crawler->filter('[data-worker-run-id] > .lp-data-table__cell > time[datetime="2026-03-04T05:06:00+00:00"]');
        self::assertCount(1, $time);
        self::assertSame('2026-03-04 05:06:00 UTC', $time->attr('title'));
        self::assertMatchesRegularExpression('/ ago$/', $time->text());
    }

    /** The output shows on every run, in its drawer, not on failures only. */
    public function test_a_successful_run_shows_its_output_in_the_drawer(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'collapsed-owner@example.com');
        $project = $this->project($em, $owner, 'Collapsed');
        $this->seedRun($em, $project, exitCode: 0, output: 'a successful worker still printed this');

        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-worker-run-id] details'));
        self::assertSame('a successful worker still printed this', $crawler->filter('[data-worker-run-id] dialog .lp-worker-run__output')->text());
    }

    public function test_the_drawer_shows_the_metrics_row_of_a_run(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'drawer-metrics-owner@example.com');
        $project = $this->project($em, $owner, 'Drawer metrics');
        $priced = $this->seedRun($em, $project, cardNumber: 1);
        $this->seedUsage($em, $priced, model: 'claude-opus-5-5', costUsd: '1.5', inputTokens: 12345);
        $unpriced = (string) $this->seedRun($em, $project, cardNumber: 2)->id;
        $noFact = (string) $this->seedRun($em, $project, cardNumber: 3)->id;
        $em->getConnection()->executeStatement('DELETE FROM bridge_worker_run_facts WHERE run_id = :id', ['id' => $noFact]);

        $projectId = (string) $project->id;
        $pricedId = (string) $priced->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        $metrics = $crawler->filter('[data-worker-run-id="'.$pricedId.'"] [data-worker-run-metrics]');
        self::assertCount(1, $metrics);
        self::assertSame(
            ['Cost' => '$1.50', 'Input tokens' => '12,345', 'Output tokens' => '20', 'Cache read tokens' => '300', 'Cache write tokens' => '40', 'Model' => 'claude-opus-5-5', 'Duration' => '5m 0s'],
            array_combine(
                $metrics->filter('dt')->each(static fn (Crawler $term): string => trim($term->text())),
                $metrics->filter('dd')->each(static fn (Crawler $value): string => trim($value->text())),
            ),
        );

        $unpricedMetrics = $crawler->filter('[data-worker-run-id="'.$unpriced.'"] [data-worker-run-metrics]');
        self::assertSame(['Cost', 'Duration'], $unpricedMetrics->filter('dt')->each(static fn (Crawler $term): string => trim($term->text())));
        self::assertSame('unknown', trim($unpricedMetrics->filter('dd')->first()->text()));

        $noFactRow = $crawler->filter('[data-worker-run-id="'.$noFact.'"]');
        // The guard: the drawer renders, so the absent metrics are not an absent drawer.
        self::assertCount(1, $noFactRow->filter('.lp-run-drawer__body'));
        self::assertCount(0, $noFactRow->filter('[data-worker-run-metrics]'));
    }

    public function test_a_run_shows_its_worker_pool_in_the_row_and_the_drawer(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'pool-owner@example.com');
        $project = $this->project($em, $owner, 'Pools');
        $pooled = (string) $this->seedRun($em, $project, cardNumber: 1, workKind: 'plan', workerPool: 'quick')->id;
        $plain = (string) $this->seedRun($em, $project, cardNumber: 2, workKind: 'review')->id;

        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        $pooledRow = $crawler->filter('[data-worker-run-id="'.$pooled.'"]');
        self::assertSame('quick pool', trim($pooledRow->filter('[data-worker-pool]')->text()));
        self::assertSame('Worker pool', $pooledRow->filter('[data-worker-run-pool] dt')->text());
        self::assertSame('quick', $pooledRow->filter('[data-worker-run-pool] dd')->text());

        $plainRow = $crawler->filter('[data-worker-run-id="'.$plain.'"]');
        // The guard: the row and its drawer render, so the absent pool is not an absent row.
        self::assertStringContainsString('review', $plainRow->text());
        self::assertCount(1, $plainRow->filter('.lp-run-drawer__body'));
        self::assertCount(0, $plainRow->filter('[data-worker-pool]'));
        self::assertCount(0, $plainRow->filter('[data-worker-run-pool]'));
    }

    public function test_the_drawer_shows_the_experiment_of_a_run(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'experiment-owner@example.com');
        $project = $this->project($em, $owner, 'Experiments');
        $pinned = $this->seedRun($em, $project, cardNumber: 1, workKind: 'implement');
        $pinned->experiment = 'impl-model';
        $pinned->variant = 'sonnet';
        $pinned->requestedModel = 'claude-sonnet-5-5';
        $switched = $this->seedRun($em, $project, cardNumber: 2, workKind: 'implement');
        $switched->experiment = 'impl-model';
        $switched->variant = 'sonnet';
        $switched->switchedFrom = '<b>opus</b>';
        $plain = (string) $this->seedRun($em, $project, cardNumber: 3, workKind: 'review')->id;
        $em->flush();
        $pinnedId = (string) $pinned->id;
        $switchedId = (string) $switched->id;

        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        $pinnedLine = $crawler->filter('[data-worker-run-id="'.$pinnedId.'"] [data-worker-run-experiment]');
        self::assertSame('Experiment', $pinnedLine->filter('dt')->text());
        self::assertSame('impl-model / sonnet (claude-sonnet-5-5)', $pinnedLine->filter('dd')->text());

        $switchedLine = $crawler->filter('[data-worker-run-id="'.$switchedId.'"] [data-worker-run-experiment]');
        self::assertSame('impl-model / sonnet, switched from <b>opus</b>', $switchedLine->filter('dd')->text());
        self::assertCount(0, $switchedLine->filter('b'));

        $plainRow = $crawler->filter('[data-worker-run-id="'.$plain.'"]');
        // The guard: the drawer renders, so the absent line is not an absent drawer.
        self::assertCount(1, $plainRow->filter('.lp-run-drawer__body'));
        self::assertCount(0, $plainRow->filter('[data-worker-run-experiment]'));
    }

    /** A list with no caveat reads as a complete history, and it is not one. */
    public function test_the_list_is_paged(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'paging-owner@example.com');
        $project = $this->project($em, $owner, 'Paged');
        for ($index = 0; $index < ListWorkerRunsHandler::PER_PAGE + 1; ++$index) {
            $this->seedRun($em, $project, cardNumber: $index + 1);
        }

        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $first = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');
        self::assertResponseIsSuccessful();
        self::assertCount(ListWorkerRunsHandler::PER_PAGE, $first->filter('[data-worker-run-id]'));

        $second = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs?page=2');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $second->filter('[data-worker-run-id]'));

        // The two pages must not overlap, which is what an unstable sort breaks.
        $firstIds = $first->filter('[data-worker-run-id]')->each(
            static fn (Crawler $node): string => (string) $node->attr('data-worker-run-id'),
        );
        $secondIds = $second->filter('[data-worker-run-id]')->each(
            static fn (Crawler $node): string => (string) $node->attr('data-worker-run-id'),
        );
        self::assertSame([], array_intersect($firstIds, $secondIds));
    }

    public function test_a_page_past_the_end_redirects_to_the_last_page(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'clamp-owner@example.com');
        $project = $this->project($em, $owner, 'Clamped');
        $this->seedRun($em, $project);

        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs?page=9');

        self::assertResponseRedirects('/projects/'.$projectId.'/worker-runs?page=1');
    }

    public function test_search_covers_the_card_number_the_work_kind_and_the_output(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'search-owner@example.com');
        $project = $this->project($em, $owner, 'Searched');
        $this->seedRun($em, $project, cardNumber: 512, output: 'nothing special', workKind: 'plan');
        $this->seedRun($em, $project, cardNumber: 7, output: 'segmentation fault', workKind: 'implement');

        $projectId = (string) $project->id;
        $em->clear();
        $client->loginUser($owner);

        foreach (['512' => '#512', 'implement' => '#7', 'segmentation' => '#7'] as $query => $expected) {
            $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs?search='.$query);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('[data-worker-run-id]'), 'search '.$query);
            self::assertStringContainsString($expected, (string) $client->getResponse()->getContent());
        }
    }

    public function test_run_id_search_preserves_project_and_filter_scope(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'run-id-search@example.com');
        $project = $this->project($em, $owner, 'Run IDs');
        $other = $this->project($em, $owner, 'Other run IDs');
        $wanted = $this->seedRun($em, $project, cardNumber: 42);
        $this->seedRun($em, $project, cardNumber: 43);
        $foreign = $this->seedRun($em, $other, cardNumber: 99);
        $runId = (string) $wanted->id;
        $bridgeId = (string) $wanted->bridgeId;
        $foreignId = (string) $foreign->id;
        $projectId = (string) $project->id;
        $em->clear();
        $client->loginUser($owner);

        foreach ([$runId, strtoupper($runId)] as $query) {
            $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs?'.http_build_query([
                'search' => $query,
                'outcome' => 'succeeded',
                'bridge' => $bridgeId,
            ]));
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('[data-worker-run-id]'));
            self::assertSame($runId, $crawler->filter('[data-worker-run-id]')->attr('data-worker-run-id'));
            // A card's run history links here by run id, so the run's drawer opens on arrival.
            self::assertSame('true', $crawler->filter('[data-worker-run-id]')->attr('data-modal-reopen-value'));
        }

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');
        self::assertCount(0, $crawler->filter('[data-worker-run-id][data-modal-reopen-value="true"]'));

        foreach ([
            ['search' => $foreignId],
            ['search' => $runId, 'outcome' => 'failed'],
            ['search' => $runId, 'bridge' => (string) Uuid::v7()],
            ['search' => (string) Uuid::v7()],
        ] as $query) {
            $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs?'.http_build_query($query));
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('[data-worker-run-id]'));
        }
    }

    public function test_the_outcome_filter_separates_the_four_endings(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'outcome-owner@example.com');
        $project = $this->project($em, $owner, 'Outcomes');
        $this->seedRun($em, $project, cardNumber: 1, exitCode: 0, hasResult: true);
        $this->seedRun($em, $project, cardNumber: 2, exitCode: 3, hasResult: false);
        $this->seedRun($em, $project, cardNumber: 3, exitCode: null, failureReason: 'binary missing');
        $this->seedRun($em, $project, cardNumber: 4, exitCode: 0, hasResult: false);
        $this->seedRun($em, $project, cardNumber: 5, exitCode: 0);

        $projectId = (string) $project->id;
        $em->clear();
        $client->loginUser($owner);

        foreach ([
            'succeeded' => ['#1', '#5'],
            'failed' => ['#2'],
            'not-started' => ['#3'],
            'no-result' => ['#4'],
        ] as $outcome => $expected) {
            $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs?outcome='.$outcome);
            self::assertResponseIsSuccessful();
            self::assertCount(\count($expected), $crawler->filter('[data-worker-run-id]'), 'outcome '.$outcome);
            $cards = $crawler->filter('[data-worker-run-id] .lp-data-table__number')->each(static fn ($node): string => trim($node->text()));
            sort($cards);
            self::assertSame($expected, $cards, 'outcome '.$outcome);
        }
    }

    public function test_the_open_runs_filter_keeps_queued_resumed_and_running_runs(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'open-filter-owner@example.com');
        $project = $this->project($em, $owner, 'Open Runs');
        $this->seedRun($em, $project, cardNumber: 1, state: WorkerRunState::Queued);
        $this->seedRun($em, $project, cardNumber: 2, state: WorkerRunState::Resumed);
        $this->seedRun($em, $project, cardNumber: 3, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);
        $this->seedRun($em, $project, cardNumber: 4, state: WorkerRunState::Blocked);
        $this->seedRun($em, $project, cardNumber: 5, exitCode: 0);

        $projectId = (string) $project->id;
        $em->clear();
        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs?outcome=open');

        self::assertResponseIsSuccessful();
        $cards = $crawler->filter('[data-worker-run-id] .lp-data-table__number')->each(static fn ($node): string => trim($node->text()));
        sort($cards);
        self::assertSame(['#1', '#2', '#3'], $cards);
        self::assertSame('Open runs', trim($crawler->filter('#worker-run-outcome option[value="open"]')->text()));
        self::assertCount(1, $crawler->filter('#worker-run-outcome option[value="open"][selected]'));
        self::assertCount(0, $crawler->filter('#worker-run-outcome option[value="queued"][selected]'));
    }

    public function test_the_outcome_filter_offers_no_result(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'outcome-offer@example.com');
        $project = $this->project($em, $owner, 'Offered Outcomes');
        $this->seedRun($em, $project, exitCode: 0, hasResult: false);

        $projectId = (string) $project->id;
        $em->clear();
        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        self::assertSame('No result', trim($crawler->filter('#worker-run-outcome option[value="no-result"]')->text()));
        self::assertStringContainsString('lp-status-chip--failed', $crawler->filter('[data-worker-run-id]')->html());
    }

    /** Each state is a filter value, and each row shows the state as a translated chip. */
    public function test_every_state_filters_and_shows_its_label(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'every-state-owner@example.com');
        $project = $this->project($em, $owner, 'Every state');
        foreach (WorkerRunState::cases() as $index => $state) {
            $this->seedRun($em, $project, cardNumber: $index + 1, state: $state, runKey: Uuid::v7());
        }

        $projectId = (string) $project->id;
        $em->clear();
        $client->loginUser($owner);
        $translator = static::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');
        self::assertSame(
            ['', 'open', ...array_map(static fn (WorkerRunState $state): string => $state->value, WorkerRunState::cases())],
            $crawler->filter('#worker-run-outcome option')->each(static fn (Crawler $option): string => (string) $option->attr('value')),
        );

        foreach (WorkerRunState::cases() as $index => $state) {
            $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs?outcome='.$state->value);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('[data-worker-run-id]'), 'state '.$state->value);
            self::assertSame('#'.($index + 1), $crawler->filter('[data-worker-run-id] .lp-data-table__number')->text());

            $label = $translator->trans($state->translationKey());
            self::assertNotSame($state->translationKey(), $label);
            foreach (['[data-worker-run-id] > .lp-worker-run-outcome > .lp-status-chip', '.lp-run-drawer__header .lp-status-chip'] as $chip) {
                self::assertSame($label, $crawler->filter($chip)->text(), $state->value.' '.$chip);
                self::assertStringContainsString('lp-status-chip--'.$state->chipModifier(), (string) $crawler->filter($chip)->attr('class'));
            }
        }
    }

    /** The drawer lists every state the run reached, oldest first, each with its time. */
    public function test_the_drawer_lists_the_state_history_in_order(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'history-owner@example.com');
        $project = $this->project($em, $owner, 'History');
        $received = new \DateTimeImmutable('2026-01-01 11:00:00');
        $run = $this->seedRun($em, $project, receivedAt: $received, state: WorkerRunState::Succeeded);
        $other = $this->seedRun($em, $project, exitCode: 1, state: WorkerRunState::Failed);
        foreach ([
            [WorkerRunState::Succeeded, '2026-01-01 10:05:00'],
            [WorkerRunState::Queued, '2026-01-01 09:59:00'],
            [WorkerRunState::Running, '2026-01-01 10:00:00'],
        ] as [$state, $at]) {
            $em->persist(new WorkerRunStateChange($run, $state, new \DateTimeImmutable($at), $received));
        }
        $em->persist(new WorkerRunStateChange($other, WorkerRunState::Failed, new \DateTimeImmutable('2026-01-01 10:05:00'), $received));
        $em->flush();

        $projectId = (string) $project->id;
        $runId = (string) $run->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        $timeline = $crawler->filter('[data-worker-run-id="'.$runId.'"] .lp-run-drawer__timeline li');
        self::assertSame(
            [
                'Queued 2026-01-01 09:59:00 UTC',
                'Running 2026-01-01 10:00:00 UTC',
                'Succeeded 2026-01-01 10:05:00 UTC',
                'Report received 2026-01-01 11:00:00 UTC',
            ],
            $timeline->each(static fn (Crawler $entry): string => $entry->text()),
        );
        self::assertSame('2026-01-01T09:59:00+00:00', $timeline->first()->filter('time')->attr('datetime'));
    }

    public function test_a_resumed_run_shows_the_run_it_continues_and_its_result(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'resume-owner@example.com');
        $project = $this->project($em, $owner, 'Resumes');
        $cardId = Uuid::v7();
        $first = $this->seedRun($em, $project, cardId: $cardId, state: WorkerRunState::Unfinished, hasResult: true);
        $resume = $this->seedRun($em, $project, cardId: $cardId, state: WorkerRunState::GaveUp, hasResult: true);
        $resume->continuesRun = $first;
        $resume->resultStatus = 'unfinished';
        $resume->resultReason = WorkerRunReason::Stacked;
        $resume->resultFields = ['branch' => '<b>feat/x</b>', 'tests' => 12];
        $resume->resumeSkipped = 'card_moved';
        $em->flush();

        $projectId = (string) $project->id;
        $firstId = (string) $first->id;
        $resumeId = (string) $resume->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-worker-run-id="'.$resumeId.'"]');
        $drawer = $row->filter('.lp-run-drawer__body');
        self::assertSame('plan', $drawer->filter('[data-worker-run-work-kind]')->text());

        $continues = $drawer->filter('a[data-worker-run-continues]');
        self::assertSame($firstId, $continues->text());
        self::assertStringContainsString('search='.$firstId, (string) $continues->attr('href'));
        self::assertStringContainsString('Unfinished', $drawer->filter('[data-worker-run-result-status]')->text());
        self::assertStringContainsString('Stacked on another pull request', $drawer->filter('[data-worker-run-result-reason]')->text());
        self::assertCount(0, $crawler->filter('[data-worker-run-id="'.$firstId.'"] [data-worker-run-result-reason]'));
        self::assertSame(
            ['branch: <b>feat/x</b>', 'tests: 12'],
            $drawer->filter('[data-worker-run-result-fields] > div')->each(static fn (Crawler $field): string => $field->filter('dt')->text().': '.$field->filter('dd')->text()),
        );
        self::assertStringContainsString('card_moved', $drawer->filter('[data-worker-run-resume-skipped]')->text());

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('&lt;b&gt;feat/x&lt;/b&gt;', $body);
        self::assertStringNotContainsString('<b>feat/x</b>', $body);
    }

    /** The live reload refreshes the counts with the list, and an empty page reloads whole to gain its filters. */
    public function test_the_live_reload_covers_the_counts_and_an_empty_page(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'live-counts-owner@example.com');
        $empty = $this->project($em, $owner, 'Empty runs');
        $full = $this->project($em, $owner, 'Full runs');
        $this->seedRun($em, $full);

        $emptyId = (string) $empty->id;
        $fullId = (string) $full->id;
        $em->clear();
        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$fullId.'/worker-runs');
        self::assertResponseIsSuccessful();
        $refresh = $crawler->filter('[data-controller="worker-run-refresh"]');
        self::assertSame('false', $refresh->attr('data-worker-run-refresh-whole-value'));
        self::assertSame(['worker-runs-shown', 'worker-runs-count'], json_decode((string) $refresh->attr('data-worker-run-refresh-frames-value'), true));
        self::assertCount(1, $crawler->filter('turbo-frame#worker-runs-shown .lp-topbar__meta'));
        self::assertCount(1, $crawler->filter('turbo-frame#worker-runs-count .lp-filter-count'));

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$emptyId.'/worker-runs');
        self::assertResponseIsSuccessful();
        self::assertSame('true', $crawler->filter('[data-controller="worker-run-refresh"]')->attr('data-worker-run-refresh-whole-value'));
    }

    /** A run with no history rows, as the previous image writes one during a deploy, shows its start and its outcome. */
    public function test_the_drawer_falls_back_to_the_start_and_end_without_history(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'no-history-owner@example.com');
        $project = $this->project($em, $owner, 'No history');
        $run = $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-01-01 11:00:00'), exitCode: 1);
        $em->flush();

        $projectId = (string) $project->id;
        $runId = (string) $run->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'Running 2026-01-01 10:00:00 UTC',
                'Failed 2026-01-01 10:05:00 UTC',
                'Report received 2026-01-01 11:00:00 UTC',
            ],
            $crawler->filter('[data-worker-run-id="'.$runId.'"] .lp-run-drawer__timeline li')->each(static fn (Crawler $entry): string => $entry->text()),
        );
    }

    /** A running run shows how long it has run so far, and a queued run shows no duration. */
    public function test_an_open_run_shows_its_running_time_and_a_queued_run_none(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'running-owner@example.com');
        $project = $this->project($em, $owner, 'Running');
        $running = new WorkerRun(
            project: $project,
            bridgeId: Uuid::v7(),
            cardId: Uuid::v7(),
            cardNumber: 1,
            workKind: 'running rule',
            state: WorkerRunState::Queued,
            runKey: Uuid::v7(),
        );
        $running->markRunning(Uuid::v4(), new \DateTimeImmutable('-90 seconds'));
        $queued = new WorkerRun(
            project: $project,
            bridgeId: Uuid::v7(),
            cardId: Uuid::v7(),
            cardNumber: 2,
            workKind: 'queued rule',
            state: WorkerRunState::Queued,
            runKey: Uuid::v7(),
        );
        $em->persist($running);
        $em->persist($queued);
        $em->flush();

        $projectId = (string) $project->id;
        $runningId = (string) $running->id;
        $queuedId = (string) $queued->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/^1m \d+s$/', trim($crawler->filter('[data-worker-run-id="'.$runningId.'"] > .lp-data-table__cell--end')->text()));
        self::assertSame('', trim($crawler->filter('[data-worker-run-id="'.$queuedId.'"] > .lp-data-table__cell--end')->text()));
        // A queued run has no session yet, so the drawer names none.
        self::assertStringNotContainsString('Session', $crawler->filter('[data-worker-run-id="'.$queuedId.'"] .lp-run-drawer__metadata')->text());
    }

    /** A live update reloads the list frame, and the page listens on the project's run topic. */
    public function test_the_list_sits_in_a_frame_that_live_updates_reload(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'live-list-owner@example.com');
        $project = $this->project($em, $owner, 'Live list');
        $run = $this->seedRun($em, $project);

        $projectId = (string) $project->id;
        $runTopic = $this->topics()->forWorkerRuns($project->id ?? throw new \LogicException('The project has no id.'));
        $runId = (string) $run->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller="worker-run-refresh"] turbo-frame#worker-runs-frame[target="_top"][data-worker-run-refresh-target="frame"] [data-worker-run-id]'));
        self::assertContains($runTopic, self::subscribedTopics($client->getResponse()) ?? []);
        self::assertContains($runTopic, $crawler->filter('form#mercure-subscriptions input[data-mercure-topic]')->each(static fn (Crawler $input): ?string => $input->attr('value')));
        // The filters stay outside the frame, so a reload never takes the search field from under the reader.
        self::assertCount(0, $crawler->filter('turbo-frame#worker-runs-frame form'));

        // A reload of the frame must not open the drawer that the run id search opened on arrival.
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs?search='.$runId, server: ['HTTP_TURBO_FRAME' => 'worker-runs-frame']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#worker-runs-frame [data-worker-run-id="'.$runId.'"]'));
        self::assertNull($crawler->filter('[data-worker-run-id="'.$runId.'"]')->attr('data-modal-reopen-value'));
    }

    public function test_the_bridge_filter_narrows_to_one_bridge(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'bridge-owner@example.com');
        $project = $this->project($em, $owner, 'Bridges');
        $wanted = Uuid::v7();
        $this->seedRun($em, $project, cardNumber: 11, bridgeId: $wanted);
        $this->seedRun($em, $project, cardNumber: 22, bridgeId: Uuid::v7());

        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs?bridge='.$wanted);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-worker-run-id]'));
        self::assertStringContainsString('#11', (string) $client->getResponse()->getContent());
    }

    public function test_the_bridge_filter_and_the_drawer_show_the_name_a_bridge_holds(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'bridge-named-owner@example.com');
        $project = $this->project($em, $owner, 'Named bridges');
        $named = $this->seedBridge($em, $owner);
        $named->name = 'laptop';
        $em->flush();
        $unnamed = Uuid::v7();
        $namedRun = $this->seedRun($em, $project, cardNumber: 11, bridgeId: $named->id);
        $unnamedRun = $this->seedRun($em, $project, cardNumber: 22, bridgeId: $unnamed);

        $projectId = (string) $project->id;
        $namedRunId = (string) $namedRun->id;
        $unnamedRunId = (string) $unnamedRun->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        self::assertSame('laptop', trim($crawler->filter('#worker-run-bridge option[value="'.$named->id.'"]')->text()));
        self::assertSame(substr((string) $unnamed, -12), trim($crawler->filter('#worker-run-bridge option[value="'.$unnamed.'"]')->text()));
        self::assertSame('laptop '.$named->id, trim($crawler->filter('[data-worker-run-id="'.$namedRunId.'"] [data-worker-run-bridge] dd')->text()));
        self::assertSame(substr((string) $unnamed, -12).' '.$unnamed, trim($crawler->filter('[data-worker-run-id="'.$unnamedRunId.'"] [data-worker-run-bridge] dd')->text()));
    }

    /** No bridge holds an interactive run, so the drawer names none and the bridge filter offers none. */
    public function test_an_interactive_run_shows_no_bridge(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'interactive-owner@example.com');
        $project = $this->project($em, $owner, 'Interactive');
        $this->seedRun($em, $project, cardNumber: 11);
        $closed = $this->seedRun($em, $project, cardNumber: 22, exitCode: null, workKind: 'loupe:product-design', state: WorkerRunState::Closed, kind: WorkerRunKind::Interactive);
        $open = $this->seedRun($em, $project, cardNumber: 33, exitCode: null, workKind: 'loupe:tech-design', state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);

        $projectId = (string) $project->id;
        $closedId = (string) $closed->id;
        $openId = (string) $open->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('[data-worker-run-id]'));
        self::assertCount(0, $crawler->filter('#worker-run-bridge'));

        $closedRow = $crawler->filter('[data-worker-run-id="'.$closedId.'"]');
        self::assertSame('Interactive session', $closedRow->filter('.lp-data-table__primary .lp-tag')->text());
        self::assertStringNotContainsString('Bridge', $closedRow->filter('.lp-run-drawer__metadata')->text());
        self::assertSame(
            ['#22 View attempt Interactive session', 'loupe:product-design', '5m 0s'],
            array_slice($crawler->filter('[data-worker-run-id="'.$closedId.'"] > .lp-data-table__cell')->each(static fn (Crawler $cell): string => trim($cell->text())), 0, 3),
        );
        self::assertStringNotContainsString('bridge does not report', $closedRow->filter('dialog')->text());

        $openRow = $crawler->filter('[data-worker-run-id="'.$openId.'"] > .lp-data-table__cell--end');
        self::assertStringStartsWith('running for ', trim($openRow->text()));
    }

    /** A command run has no agent, so its row says so and its drawer shows no session. */
    public function test_a_command_run_shows_the_command_tag_and_no_session(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'command-run-owner@example.com');
        $project = $this->project($em, $owner, 'Command Runs');
        $command = $this->seedRun($em, $project, cardNumber: 12, exitCode: -1, workKind: 'sync', runKey: Uuid::v4(), kind: WorkerRunKind::Command);
        $worker = $this->seedRun($em, $project, cardNumber: 13);

        $projectId = (string) $project->id;
        $commandId = (string) $command->id;
        $workerId = (string) $worker->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-worker-run-id="'.$commandId.'"]');
        self::assertSame('Command', $row->filter('.lp-data-table__primary [data-worker-run-command]')->text());
        self::assertCount(1, $row->filter('dialog [data-worker-run-command]'));
        self::assertStringNotContainsString('Session', $row->filter('.lp-run-drawer__metadata')->text());
        self::assertStringNotContainsString('bridge does not report', $row->filter('dialog')->text());
        self::assertCount(0, $crawler->filter('[data-worker-run-id="'.$workerId.'"] [data-worker-run-command]'));
    }

    public function test_a_failed_command_run_offers_to_run_again(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'command-rerun-owner@example.com');
        $project = $this->project($em, $owner, 'Command Rerun');
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id]);
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_RERUN_COMMAND];
        $em->flush();
        $run = $this->seedRun($em, $project, exitCode: -1, workKind: 'sync', bridgeId: $bridge->id, runKey: Uuid::v4(), kind: WorkerRunKind::Command);

        $projectId = (string) $project->id;
        $runId = (string) $run->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('[data-worker-run-id="'.$runId.'"] form[data-worker-run-control="rerun"]');
        self::assertCount(1, $form);
        self::assertSame('/projects/'.$projectId.'/worker-runs/'.$runId.'/rerun', $form->attr('action'));
        self::assertSame('Run again', trim($form->filter('button')->text()));
        self::assertNull($form->filter('button')->attr('disabled'));
    }

    public function test_an_interactive_run_a_bridge_launched_shows_the_bridge_and_the_kind(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'interactive-launched-owner@example.com');
        $project = $this->project($em, $owner, 'Interactive Launched');
        $bridgeId = Uuid::v4();
        $launched = $this->seedRun($em, $project, cardNumber: 11, exitCode: null, bridgeId: $bridgeId, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);

        $projectId = (string) $project->id;
        $launchedId = (string) $launched->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-worker-run-id="'.$launchedId.'"]');
        self::assertStringContainsString((string) $bridgeId, $row->filter('.lp-run-drawer__metadata')->text());
        self::assertSame('Interactive session', $row->filter('.lp-data-table__primary .lp-tag')->text());
    }

    /** The row reads as one line, and the drawer keeps the detail the row drops. */
    public function test_a_row_shows_the_card_title_and_leaves_the_detail_to_the_drawer(): void
    {
        $client = static::createClient();
        $em = $this->em();
        static::getContainer()->get(FeatureFlagRepository::class)->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = true;

        $owner = $this->user($em, 'row-owner@example.com');
        $project = $this->project($em, $owner, 'Rows');
        $column = new BoardColumn($project, 'Work', 'work', 0);
        $card = new Card($project, $column, 'Fix the login', '', 7);
        $em->persist($column);
        $em->persist($card);
        $em->flush();
        $bridgeId = Uuid::v7();
        $failed = $this->seedRun($em, $project, cardNumber: 7, exitCode: 2, failureReason: 'The worker ran out of turns', output: 'the failed output', bridgeId: $bridgeId, cardId: $card->id);
        $gone = $this->seedRun($em, $project, cardNumber: 8);

        $projectId = (string) $project->id;
        $cardId = (string) $card->id;
        $failedId = (string) $failed->id;
        $goneId = (string) $gone->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.lp-data-table--runs > .lp-data-table__row[data-worker-run-id]'));

        $target = $crawler->filter('[data-worker-run-id="'.$failedId.'"] .lp-data-table__target');
        self::assertSame('modal#open', $target->attr('data-action'));
        self::assertSame('#7', $target->filter('.lp-data-table__number')->text());
        self::assertSame('Fix the login', $target->filter('.lp-data-table__title')->text());
        self::assertSame('View attempt', $target->filter('.sr-only')->text());
        self::assertCount(0, $crawler->filter('[data-worker-run-id="'.$goneId.'"] .lp-data-table__title'));
        self::assertSame('#8', $crawler->filter('[data-worker-run-id="'.$goneId.'"] .lp-data-table__number')->text());

        $chip = $crawler->filter('[data-worker-run-id="'.$failedId.'"] > .lp-worker-run-outcome > .lp-status-chip');
        self::assertSame('The worker ran out of turns', $chip->filter('.lp-tooltip')->text());
        self::assertSame($chip->filter('.lp-tooltip')->attr('id'), $chip->attr('aria-describedby'));
        self::assertCount(0, $crawler->filter('[data-worker-run-id="'.$goneId.'"] > .lp-worker-run-outcome > .lp-status-chip .lp-tooltip'));

        $cells = $crawler->filter('[data-worker-run-id="'.$failedId.'"] > :not(dialog)');
        $rowText = implode(' ', $cells->each(static fn (Crawler $cell): string => $cell->text()));
        self::assertStringNotContainsString('exit 2', $rowText);
        self::assertStringNotContainsString(substr((string) $bridgeId, -12), $rowText);
        self::assertStringNotContainsString('the failed output', $rowText);
        self::assertCount(0, $cells->filter('a, details'));
        self::assertCount(1, $cells->filter('button'));

        $drawer = $crawler->filter('[data-worker-run-id="'.$failedId.'"] dialog');
        self::assertStringContainsString('exit 2', $drawer->filter('.lp-run-drawer__metadata')->text());
        self::assertStringContainsString((string) $bridgeId, $drawer->filter('.lp-run-drawer__metadata')->text());
        self::assertSame('The worker ran out of turns', $drawer->filter('.lp-worker-run__failure')->text());
        self::assertSame('the failed output', $drawer->filter('.lp-worker-run__output')->text());
        self::assertSame('/projects/'.$projectId.'/board/cards/'.$cardId, $drawer->filter('.lp-run-drawer__metadata a')->attr('href'));
    }

    /** An unknown filter value shows the unfiltered list rather than a 404. */
    public function test_an_unreadable_filter_value_is_dropped(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'garbage-owner@example.com');
        $project = $this->project($em, $owner, 'Garbage');
        $this->seedRun($em, $project, cardNumber: 5);

        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs?outcome=exploded&bridge=not-a-uuid');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-worker-run-id]'));
    }

    public function test_the_drawer_footer_offers_resume_next_to_copy_output(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'runs-control-resume@example.com');
        $project = $this->project($em, $owner, 'Runs control resume');
        $bridge = $this->commandBridge($owner);
        $run = $this->seedRun($em, $project, exitCode: 1, bridgeId: $bridge->id, state: WorkerRunState::Blocked);

        $projectId = (string) $project->id;
        $runId = (string) $run->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-worker-run-id="'.$runId.'"]');
        // The table row leaves the actions to the drawer.
        self::assertCount(1, $row->filter('form[data-worker-run-control]'));
        $form = $row->filter('dialog .lp-run-drawer__footer form[data-worker-run-control]');
        self::assertCount(1, $form);
        self::assertSame('/projects/'.$projectId.'/worker-runs/'.$runId.'/resume', $form->attr('action'));
        self::assertSame('_top', $form->attr('data-turbo-frame'));
        self::assertNotEmpty($form->filter('input[name="_csrf_token"]')->attr('value'));
        self::assertSame('Resume', $form->filter('button')->text());
        self::assertNull($form->filter('button')->attr('disabled'));
        self::assertCount(1, $row->filter('.lp-run-drawer__footer [data-action="worker-run-output#copy"]'));
    }

    public function test_the_drawer_disables_an_action_the_bridge_cannot_take(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'runs-control-outdated@example.com');
        $project = $this->project($em, $owner, 'Runs control outdated');
        $bridge = $this->commandBridge($owner, null);
        $run = $this->seedRun($em, $project, bridgeId: $bridge->id, state: WorkerRunState::Running);

        $projectId = (string) $project->id;
        $runId = (string) $run->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        $button = $crawler->filter('[data-worker-run-id="'.$runId.'"] .lp-run-drawer__footer form[data-worker-run-control] button');
        self::assertNotNull($button->attr('disabled'));
        self::assertSame('Update the bridge to 1.5.0 or later to control its runs.', $button->attr('title'));
        self::assertStringStartsWith('Stop', $button->text());
    }

    public function test_a_pending_command_shows_its_label_in_the_row_and_the_drawer(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'runs-control-pending@example.com');
        $project = $this->project($em, $owner, 'Runs control pending');
        $bridge = $this->commandBridge($owner);
        $run = $this->seedRun($em, $project, bridgeId: $bridge->id, state: WorkerRunState::Running);
        $this->seedCommand($em, $run, requestedAt: new \DateTimeImmutable(), kind: BridgeCommandKind::StopRun);

        $projectId = (string) $project->id;
        $runId = (string) $run->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-worker-run-id="'.$runId.'"]');
        self::assertSame(['Stop requested', 'Stop requested'], $row->filter('[data-worker-run-control-label]')->each(static fn (Crawler $label): string => $label->text()));
        $form = $row->filter('.lp-run-drawer__footer form[data-worker-run-control]');
        self::assertSame('/projects/'.$projectId.'/worker-runs/'.$runId.'/cancel-command', $form->attr('action'));
        self::assertSame('Cancel request', $form->filter('button')->text());
    }

    public function test_a_refused_command_shows_its_reason_on_the_page(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'runs-control-refused@example.com');
        $project = $this->project($em, $owner, 'Runs control refused');
        $bridge = $this->commandBridge($owner);
        $run = $this->seedRun($em, $project, bridgeId: $bridge->id, state: WorkerRunState::Running);

        $url = '/projects/'.$project->id.'/worker-runs/'.$run->id.'/resume';
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_POST, $url, ['_csrf_token' => 'csrf-token'], [], ['HTTP_REFERER' => 'http://localhost'.$url]);
        $crawler = $client->followRedirect();

        self::assertResponseIsSuccessful();
        $flash = $crawler->filter('turbo-frame#worker-runs-frame [data-worker-run-command-flash]');
        self::assertStringContainsString('Only an ended run can resume.', $flash->text());
    }

    public function test_another_users_project_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'runs-theirs@example.com');
        $stranger = $this->user($em, 'runs-stranger@example.com');
        $project = $this->project($em, $owner, 'Private');
        $this->seedRun($em, $project);

        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_an_anonymous_visitor_is_sent_to_the_login_page(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/projects/00000000-0000-0000-0000-000000000000/worker-runs');

        self::assertResponseRedirects('/login');
    }

    /** @param list<string>|null $capabilities */
    private function commandBridge(User $owner, ?array $capabilities = [Bridge::CAPABILITY_COMMANDS]): Bridge
    {
        $bridge = $this->seedBridge($this->em(), $owner);
        $bridge->capabilities = $capabilities;
        $this->em()->flush();

        return $bridge;
    }

    private function topics(): ProjectTopicBuilder
    {
        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);

        return $topics;
    }
}
