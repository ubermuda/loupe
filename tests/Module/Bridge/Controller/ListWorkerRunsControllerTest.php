<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\Command\ListWorkerRunsHandler;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class ListWorkerRunsControllerTest extends WebTestCase
{
    use BridgeScenario;

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
}
