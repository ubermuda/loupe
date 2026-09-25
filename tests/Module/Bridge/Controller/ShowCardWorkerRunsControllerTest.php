<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
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
    }

    public function test_another_users_project_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = $this->user($em, 'card-fragment-theirs@example.com');
        $stranger = $this->user($em, 'card-fragment-stranger@example.com');
        $project = $this->project($em, $owner, 'Fragment private');
        $cardId = Uuid::v7();
        $this->seedRun($em, $project, ruleName: 'private rule', cardId: $cardId);

        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/worker-runs/card/'.$cardId);

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('private rule', (string) $client->getResponse()->getContent());
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
