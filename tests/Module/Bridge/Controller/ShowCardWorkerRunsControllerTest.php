<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Project\Entity\Project;
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
        $this->seedRun($em, $project, ruleName: 'finished work', cardId: $cardId, state: WorkerRunState::Succeeded, hasResult: true);
        $this->seedRun($em, $project, ruleName: 'another card', state: WorkerRunState::Running);

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
        self::assertSame('Running', $frame->filter('[data-card-run-row="'.$runId.'"] .lp-status-chip')->text());
        // A finished run moves to the card history, so the overview leaves it out.
        self::assertStringNotContainsString('finished work', $frame->text());
        self::assertSame('Runs in progress', $frame->filter('[data-card-runs] h2')->text());
        self::assertSame('/projects/'.$projectId.'/worker-runs', $frame->filter('[data-card-runs] [data-card-runs-all]')->attr('href'));
    }

    public function test_a_resumed_run_names_its_place_in_the_series(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'card-fragment-resume@example.com');
        $project = $this->project($em, $owner, 'Fragment resume');
        $cardId = Uuid::v7();
        $run = $this->seedRun($em, $project, cardId: $cardId, state: WorkerRunState::Running);
        $run->resumeIndex = 3;
        $run->resumeCap = 3;
        $em->flush();

        $projectId = (string) $project->id;
        $runId = (string) $run->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/card/'.$cardId);

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-card-run-row="'.$runId.'"]');
        self::assertSame('Resume 3 of 3', $row->filter('[data-worker-run-resume]')->text());
        self::assertSame('Running', $row->filter('.lp-status-chip')->text());
    }

    public function test_a_running_interactive_session_offers_a_close_control_and_a_closed_one_is_gone(): void
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

        self::assertCount(0, $crawler->filter('[data-card-run-row="'.$closedId.'"]'));

        $workerRow = $crawler->filter('[data-card-run-row="'.$workerId.'"]');
        self::assertStringNotContainsString('Interactive session', $workerRow->text());
        self::assertCount(0, $workerRow->filter('form'));
    }

    public function test_an_ended_run_leaves_the_card_so_its_resume_lives_in_run_history(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'card-control-resume@example.com');
        $project = $this->project($em, $owner, 'Control resume');
        $bridge = $this->commandBridge($owner);
        $cardId = Uuid::v7();
        $run = $this->seedRun($em, $project, exitCode: 1, bridgeId: $bridge->id, cardId: $cardId, state: WorkerRunState::Blocked);

        $projectId = (string) $project->id;
        $runId = (string) $run->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/card/'.$cardId);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-card-run-row="'.$runId.'"]'));
        self::assertCount(0, $crawler->filter('form[action$="/resume"]'));
    }

    public function test_the_owner_stops_a_running_run_from_its_row(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'card-control-stop@example.com');
        $project = $this->project($em, $owner, 'Control stop');
        $bridge = $this->commandBridge($owner);
        $cardId = Uuid::v7();
        $run = $this->seedRun($em, $project, bridgeId: $bridge->id, cardId: $cardId, state: WorkerRunState::Running);

        $projectId = (string) $project->id;
        $runId = (string) $run->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/card/'.$cardId);

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('[data-card-run-row="'.$runId.'"] form[data-worker-run-control]');
        self::assertSame('/projects/'.$projectId.'/worker-runs/'.$runId.'/stop', $form->attr('action'));
        self::assertSame('Stop', $form->filter('button')->attr('aria-label'));
    }

    public function test_a_pending_stop_offers_cancel_and_says_it_was_requested(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'card-control-cancel@example.com');
        $project = $this->project($em, $owner, 'Control cancel');
        $bridge = $this->commandBridge($owner);
        $cardId = Uuid::v7();
        $run = $this->seedRun($em, $project, bridgeId: $bridge->id, cardId: $cardId, state: WorkerRunState::Running);
        $this->seedCommand($em, $run, requestedAt: new \DateTimeImmutable(), kind: BridgeCommandKind::StopRun);

        $projectId = (string) $project->id;
        $runId = (string) $run->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/card/'.$cardId);

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-card-run-row="'.$runId.'"]');
        $form = $row->filter('form[data-worker-run-control]');
        self::assertSame('/projects/'.$projectId.'/worker-runs/'.$runId.'/cancel-command', $form->attr('action'));
        self::assertSame('Cancel request', $form->filter('button')->attr('aria-label'));
        self::assertSame('Stop requested', $row->filter('[data-worker-run-control-label]')->text());
    }

    public function test_a_bridge_without_commands_disables_stop_and_says_why(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'card-control-outdated@example.com');
        $project = $this->project($em, $owner, 'Control outdated');
        $bridge = $this->commandBridge($owner, null);
        $cardId = Uuid::v7();
        $run = $this->seedRun($em, $project, bridgeId: $bridge->id, cardId: $cardId, state: WorkerRunState::Running);

        $projectId = (string) $project->id;
        $runId = (string) $run->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/card/'.$cardId);

        self::assertResponseIsSuccessful();
        $button = $crawler->filter('[data-card-run-row="'.$runId.'"] form[data-worker-run-control] button');
        self::assertNotNull($button->attr('disabled'));
        self::assertSame('Update the bridge to 1.5.0 or later to control its runs.', $button->attr('title'));
        self::assertStringContainsString('Update the bridge to 1.5.0 or later to control its runs.', $button->text());
    }

    public function test_a_held_card_says_that_it_is_held(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'card-control-held@example.com');
        $project = $this->project($em, $owner, 'Control held');
        $cardId = Uuid::v7();
        $this->seedRun($em, $project, cardId: $cardId, state: WorkerRunState::Stopped);

        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/card/'.$cardId);
        self::assertCount(0, $crawler->filter('[data-card-held]'));

        $project = $this->em()->find(Project::class, $projectId);
        self::assertNotNull($project);
        $holds = static::getContainer()->get(CardHolds::class);
        self::assertInstanceOf(CardHolds::class, $holds);
        $holds->hold($project, $cardId, null, null);
        $this->em()->clear();

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/card/'.$cardId);

        self::assertResponseIsSuccessful();
        self::assertSame(
            'Held: no worker starts on this card until you resume one of its runs in Run history, or move it.',
            $crawler->filter('turbo-frame#card-worker-runs [data-card-held]')->text(),
        );
    }

    public function test_a_refused_command_shows_its_reason_in_the_frame_once(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'card-control-refused@example.com');
        $project = $this->project($em, $owner, 'Control refused');
        $bridge = $this->commandBridge($owner);
        $cardId = Uuid::v7();
        $run = $this->seedRun($em, $project, bridgeId: $bridge->id, cardId: $cardId, state: WorkerRunState::Running);

        $url = '/projects/'.$project->id.'/worker-runs/'.$run->id.'/resume';
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_POST, $url, ['_csrf_token' => 'csrf-token'], [], ['HTTP_REFERER' => 'http://localhost'.$url, 'HTTP_TURBO_FRAME' => 'card-worker-runs']);

        self::assertResponseStatusCodeSame(422);
        $flash = $crawler->filter('turbo-frame#card-worker-runs [data-worker-run-command-flash]');
        self::assertStringContainsString('Only an ended run can resume.', $flash->text());

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/worker-runs/card/'.$cardId);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#card-worker-runs [data-card-runs]'));
        self::assertCount(0, $crawler->filter('[data-worker-run-command-flash]'));
    }

    public function test_a_refused_stop_of_a_run_that_just_ended_still_shows_its_reason(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'card-control-ended@example.com');
        $project = $this->project($em, $owner, 'Control ended');
        $bridge = $this->commandBridge($owner);
        $cardId = Uuid::v7();
        $run = $this->seedRun($em, $project, bridgeId: $bridge->id, cardId: $cardId);

        $url = '/projects/'.$project->id.'/worker-runs/'.$run->id.'/stop';
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_POST, $url, ['_csrf_token' => 'csrf-token'], [], ['HTTP_REFERER' => 'http://localhost'.$url, 'HTTP_TURBO_FRAME' => 'card-worker-runs']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $crawler->filter('[data-card-run]'));
        $flash = $crawler->filter('turbo-frame#card-worker-runs [data-worker-run-command-flash]');
        self::assertStringContainsString('Only a queued or running run can stop.', $flash->text());
    }

    /** @param list<string>|null $capabilities */
    private function commandBridge(User $owner, ?array $capabilities = [Bridge::CAPABILITY_COMMANDS]): Bridge
    {
        $bridge = $this->seedBridge($this->em(), $owner);
        $bridge->capabilities = $capabilities;
        $this->em()->flush();

        return $bridge;
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
        self::assertCount(0, $crawler->filter('[data-card-runs] [data-card-run]'));
        self::assertSame('Agent runs', $crawler->filter('[data-card-runs] h2')->text());
        self::assertCount(1, $crawler->filter('[data-card-runs] [data-card-runs-all]'));
        $total = $crawler->filter('turbo-frame#card-worker-runs [data-card-runs] [data-card-usage-total]');
        self::assertCount(1, $total);
        self::assertSame('$12.34', $total->filter('[data-card-usage-cost]')->text());
        self::assertSame('1.3M input · 40 output · 600 cache read · 80 cache write', $total->filter('[data-card-usage-tokens]')->text());
        self::assertSame('Estimated', $total->filter('[data-card-usage-estimated]')->text());
        self::assertSame('2 runs have no usage', $total->filter('[data-card-usage-partial]')->text());
        self::assertCount(0, $total->filter('[data-card-usage-unknown]'));
    }

    public function test_an_open_run_with_no_usage_shows_usage_unknown(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'card-fragment-usage-unknown@example.com');
        $project = $this->project($em, $owner, 'Fragment usage unknown');
        $cardId = Uuid::v7();
        $this->seedRun($em, $project, cardId: $cardId);
        $this->seedRun($em, $project, cardId: $cardId, state: WorkerRunState::Running);

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
        $total = $crawler->filter('[data-card-runs] [data-card-usage-total]');
        self::assertCount(1, $total);
        self::assertSame('$0.01', $total->filter('[data-card-usage-cost]')->text());
    }

    /** The frame stays, empty, so a live update can fill it when a run opens. */
    public function test_a_card_with_no_open_run_and_no_usage_gets_an_empty_frame(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'card-fragment-empty@example.com');
        $project = $this->project($em, $owner, 'Fragment empty');
        $cardId = Uuid::v7();
        $this->seedRun($em, $project, cardId: $cardId, state: WorkerRunState::Failed);
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/card/'.$cardId);

        self::assertResponseIsSuccessful();
        $frame = $crawler->filter('turbo-frame#card-worker-runs');
        self::assertCount(1, $frame);
        self::assertSame('', trim($frame->html()));
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
