<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** The runs section of a card, alone, for the frame that reloads it. */
final class ShowCardWorkerRunsControllerTest extends WebTestCase
{
    use BridgeScenario;

    public function test_a_viewer_gets_the_cards_runs_in_their_frame(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'card-fragment-owner@example.com');
        $project = $this->project($em, $owner, 'Fragment');
        $cardId = Uuid::v7();
        $run = $this->seedRun($em, $project, ruleName: 'plan the card', cardId: $cardId, state: WorkerRunState::Running);
        $this->seedRun($em, $project, ruleName: 'another card');

        $projectId = (string) $project->id;
        $runId = (string) $run->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/card/'.$cardId);

        self::assertResponseIsSuccessful();
        // Only the frame: the page around it stays as it is.
        self::assertCount(0, $crawler->filter('html body main'));
        $frame = $crawler->filter('turbo-frame#card-worker-runs');
        self::assertCount(1, $frame);
        self::assertSame('_top', $frame->attr('target'));
        self::assertCount(1, $frame->filter('[data-card-runs] [data-card-run]'));
        $row = $frame->filter('[data-card-run="'.$runId.'"]');
        self::assertStringContainsString('plan the card', $row->text());
        self::assertSame('Running', $row->filter('.lp-status-chip')->text());
    }

    public function test_a_resumed_run_names_its_place_in_the_series(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'card-fragment-resume@example.com');
        $project = $this->project($em, $owner, 'Fragment resume');
        $cardId = Uuid::v7();
        $run = $this->seedRun($em, $project, cardId: $cardId, state: WorkerRunState::GaveUp, hasResult: true);
        $run->resumeIndex = 3;
        $run->resumeCap = 3;
        $em->flush();

        $projectId = (string) $project->id;
        $runId = (string) $run->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/card/'.$cardId);

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-card-run="'.$runId.'"]');
        self::assertSame('Resume 3 of 3', $row->filter('[data-worker-run-resume]')->text());
        self::assertSame('Gave up', $row->filter('.lp-status-chip')->text());
    }

    public function test_only_a_running_interactive_session_offers_a_close_control(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'card-fragment-close@example.com');
        $project = $this->project($em, $owner, 'Fragment close');
        $cardId = Uuid::v7();
        $open = $this->seedRun($em, $project, ruleName: 'loupe:product-design', cardId: $cardId, state: WorkerRunState::Running, kind: WorkerRunKind::Interactive);
        $closed = $this->seedRun($em, $project, ruleName: 'loupe:tech-design', cardId: $cardId, state: WorkerRunState::Closed, kind: WorkerRunKind::Interactive);
        $worker = $this->seedRun($em, $project, ruleName: 'plan', cardId: $cardId, state: WorkerRunState::Running);

        $projectId = (string) $project->id;
        $openId = (string) $open->id;
        $closedId = (string) $closed->id;
        $workerId = (string) $worker->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/card/'.$cardId);

        self::assertResponseIsSuccessful();
        $forms = $crawler->filter('[data-card-runs] form[data-card-run-close]');
        self::assertCount(1, $forms);
        self::assertSame('/projects/'.$projectId.'/worker-runs/'.$openId.'/close', $forms->attr('action'));
        self::assertSame('card-worker-runs', $forms->attr('data-turbo-frame'));
        self::assertNotEmpty($forms->filter('input[name="_csrf_token"]')->attr('value'));

        $openRow = $crawler->filter('[data-card-run-row="'.$openId.'"]');
        self::assertCount(1, $openRow->filter('form[data-card-run-close]'));
        self::assertStringContainsString('loupe:product-design', $openRow->text());
        self::assertStringContainsString('Interactive session', $openRow->text());
        self::assertStringContainsString('running for', $openRow->text());

        $closedRow = $crawler->filter('[data-card-run-row="'.$closedId.'"]');
        self::assertStringContainsString('Interactive session', $closedRow->text());
        self::assertStringNotContainsString('running for', $closedRow->text());
        self::assertStringContainsString('5m 0s', $closedRow->text());

        $workerRow = $crawler->filter('[data-card-run-row="'.$workerId.'"]');
        self::assertStringNotContainsString('Interactive session', $workerRow->text());
        self::assertCount(0, $workerRow->filter('form'));
    }

    public function test_the_usage_total_shows_dollars_tokens_and_its_marks(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'card-fragment-usage@example.com');
        $project = $this->project($em, $owner, 'Fragment usage');
        $cardId = Uuid::v7();
        $this->seedUsage($em, $this->seedRun($em, $project, cardId: $cardId), costUsd: '12.3412', inputTokens: 1_234_567);
        $this->seedUsage($em, $this->seedRun($em, $project, cardId: $cardId), source: WorkerRunUsageSource::Estimated, costUsd: '0.0005', inputTokens: 45_300);
        $this->seedRun($em, $project, cardId: $cardId, state: WorkerRunState::Failed);
        $this->seedRun($em, $project, cardId: $cardId, state: WorkerRunState::Lost);

        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/card/'.$cardId);

        self::assertResponseIsSuccessful();
        $total = $crawler->filter('turbo-frame#card-worker-runs [data-card-runs] [data-card-usage-total]');
        self::assertCount(1, $total);
        self::assertSame('$12.34', $total->filter('[data-card-usage-cost]')->text());
        self::assertSame('1.3M input · 40 output · 600 cache read · 80 cache write', $total->filter('[data-card-usage-tokens]')->text());
        self::assertSame('Estimated', $total->filter('[data-card-usage-estimated]')->text());
        self::assertSame('2 runs have no usage', $total->filter('[data-card-usage-partial]')->text());
        self::assertCount(0, $total->filter('[data-card-usage-unknown]'));
    }

    public function test_a_card_whose_runs_report_no_usage_shows_usage_unknown(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'card-fragment-usage-unknown@example.com');
        $project = $this->project($em, $owner, 'Fragment usage unknown');
        $cardId = Uuid::v7();
        $this->seedRun($em, $project, cardId: $cardId);

        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/card/'.$cardId);

        self::assertResponseIsSuccessful();
        $total = $crawler->filter('[data-card-usage-total]');
        self::assertSame('Usage unknown', $total->filter('[data-card-usage-unknown]')->text());
        self::assertCount(0, $total->filter('[data-card-usage-cost]'));
        self::assertCount(0, $total->filter('[data-card-usage-partial]'));
        self::assertStringNotContainsString('$0.00', $total->text());
    }

    public function test_a_card_whose_runs_were_all_deleted_still_shows_its_usage(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'card-fragment-usage-swept@example.com');
        $project = $this->project($em, $owner, 'Fragment usage swept');
        $cardId = Uuid::v7();
        $run = $this->seedRun($em, $project, cardId: $cardId);
        $this->seedUsage($em, $run);
        $em->getConnection()->executeStatement('DELETE FROM bridge_worker_runs WHERE id = :id', ['id' => (string) $run->id]);

        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/card/'.$cardId);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-card-runs] [data-card-run]'));
        self::assertSelectorTextContains('[data-card-runs] .lp-card-detail__empty', 'No agent has run on this card yet.');
        $total = $crawler->filter('[data-card-runs] .lp-card-detail__empty + [data-card-usage-total]');
        self::assertCount(1, $total);
        self::assertSame('$0.01', $total->filter('[data-card-usage-cost]')->text());
    }

    public function test_a_card_with_no_runs_says_so(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'card-fragment-empty@example.com');
        $project = $this->project($em, $owner, 'Fragment empty');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/card/'.Uuid::v7());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#card-worker-runs [data-card-runs]', 'No agent has run on this card yet.');
        self::assertSelectorNotExists('[data-card-usage-total]');
    }

    public function test_another_users_project_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'card-fragment-theirs@example.com');
        $stranger = $this->user($em, 'card-fragment-stranger@example.com');
        $project = $this->project($em, $owner, 'Fragment private');
        $cardId = Uuid::v7();
        $this->seedUsage($em, $this->seedRun($em, $project, ruleName: 'private rule', cardId: $cardId));

        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/card/'.$cardId);

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('private rule', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('data-card-usage-total', (string) $client->getResponse()->getContent());
    }

    public function test_a_card_id_that_is_not_a_uuid_is_not_found(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'card-fragment-bad-id@example.com');
        $project = $this->project($em, $owner, 'Fragment bad id');
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/card/not-a-uuid');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_an_anonymous_visitor_is_sent_to_the_login_page(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/projects/00000000-0000-0000-0000-000000000000/worker-runs/card/'.Uuid::v7());

        self::assertResponseRedirects('/login');
    }
}
