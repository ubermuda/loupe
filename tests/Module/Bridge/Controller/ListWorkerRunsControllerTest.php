<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Bridge\Command\ListWorkerRunsHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\MercureCookies;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        $this->seedRun($em, $project, cardNumber: 42, ruleName: 'plan');
        $this->seedRun($em, $other, cardNumber: 99, ruleName: 'othersrule');

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
            ruleName: 'waiting rule',
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
        self::assertCount(1, $crawler->filter('.lp-worker-run__table-row > time[datetime="2026-03-04T05:06:00+00:00"]'));
    }

    /** The output shows on every run, collapsed, not on failures only. */
    public function test_a_successful_run_shows_its_output_collapsed(): void
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
        self::assertCount(1, $crawler->filter('.lp-worker-run__output-disclosure'));
        self::assertCount(0, $crawler->filter('.lp-worker-run__output-disclosure[open]'));
        self::assertStringContainsString('a successful worker still printed this', (string) $client->getResponse()->getContent());
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

    public function test_search_covers_the_card_number_the_rule_name_and_the_output(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'search-owner@example.com');
        $project = $this->project($em, $owner, 'Searched');
        $this->seedRun($em, $project, cardNumber: 512, output: 'nothing special', ruleName: 'plan');
        $this->seedRun($em, $project, cardNumber: 7, output: 'segmentation fault', ruleName: 'implement');

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

    public function test_the_outcome_filter_separates_the_three_endings(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'outcome-owner@example.com');
        $project = $this->project($em, $owner, 'Outcomes');
        $this->seedRun($em, $project, cardNumber: 1, exitCode: 0);
        $this->seedRun($em, $project, cardNumber: 2, exitCode: 3);
        $this->seedRun($em, $project, cardNumber: 3, exitCode: null, failureReason: 'binary missing');

        $projectId = (string) $project->id;
        $em->clear();
        $client->loginUser($owner);

        foreach (['succeeded' => '#1', 'failed' => '#2', 'not-started' => '#3'] as $outcome => $expected) {
            $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs?outcome='.$outcome);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('[data-worker-run-id]'), 'outcome '.$outcome);
            self::assertStringContainsString($expected, (string) $client->getResponse()->getContent());
        }
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
            ['', ...array_map(static fn (WorkerRunState $state): string => $state->value, WorkerRunState::cases())],
            $crawler->filter('#worker-run-outcome option')->each(static fn (Crawler $option): string => (string) $option->attr('value')),
        );

        foreach (WorkerRunState::cases() as $index => $state) {
            $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs?outcome='.$state->value);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('[data-worker-run-id]'), 'state '.$state->value);
            self::assertStringContainsString('#'.($index + 1), $crawler->filter('.lp-worker-run__card')->text());

            $label = $translator->trans($state->translationKey());
            self::assertNotSame($state->translationKey(), $label);
            foreach (['.lp-worker-run__table-row .lp-status-chip', '.lp-run-drawer__header .lp-status-chip'] as $chip) {
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
            ruleName: 'running rule',
            state: WorkerRunState::Queued,
            runKey: Uuid::v7(),
        );
        $running->markRunning(Uuid::v4(), new \DateTimeImmutable('-90 seconds'));
        $queued = new WorkerRun(
            project: $project,
            bridgeId: Uuid::v7(),
            cardId: Uuid::v7(),
            cardNumber: 2,
            ruleName: 'queued rule',
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
        self::assertMatchesRegularExpression('/^1m \d+s$/', trim($crawler->filter('[data-worker-run-id="'.$runningId.'"] .lp-worker-run__duration')->text()));
        self::assertSame('', trim($crawler->filter('[data-worker-run-id="'.$queuedId.'"] .lp-worker-run__duration')->text()));
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

    private function topics(): ProjectTopicBuilder
    {
        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);

        return $topics;
    }
}
