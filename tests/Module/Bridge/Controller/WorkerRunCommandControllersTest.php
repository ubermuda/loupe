<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Controller;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Repository\CardHoldRepository;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Uid\Uuid;

/** A person stops, resumes, runs again or withdraws a command on a worker run. */
final class WorkerRunCommandControllersTest extends WebTestCase
{
    use BridgeScenario;

    public function test_the_owner_stops_a_running_run_and_holds_nothing(): void
    {
        $client = static::createClient();
        [$owner, $project, $run] = $this->scenario('stop', WorkerRunState::Running);
        $url = $this->url($project, $run, 'stop');
        $cardId = $run->cardId;
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $url);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/projects/'.$project->id.'/worker-runs?search='.$run->id);
        $command = $this->onlyCommand();
        self::assertSame(BridgeCommandKind::StopRun, $command->kind);
        self::assertSame(BridgeCommandState::Pending, $command->state);
        self::assertSame((string) $owner->id, (string) $command->requestedBy?->id);
        $project = $this->em()->find(Project::class, $project->id);
        self::assertNotNull($project);
        self::assertNull($this->cardHolds()->findOneOfCard($project, $cardId));
    }

    public function test_the_owner_resumes_a_failed_run(): void
    {
        $client = static::createClient();
        [$owner, $project, $run] = $this->scenario('resume', WorkerRunState::Failed);
        $url = $this->url($project, $run, 'resume');
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $url);

        self::assertSame([], $this->flashes($client, 'worker-run-command'));
        self::assertResponseRedirects('/projects/'.$project->id.'/worker-runs?search='.$run->id);
        $command = $this->onlyCommand();
        self::assertSame(BridgeCommandKind::ResumeRun, $command->kind);
        self::assertNull($command->reason);
    }

    public function test_the_owner_runs_a_failed_command_run_again(): void
    {
        $client = static::createClient();
        [$owner, $project, $run] = $this->scenario('rerun', WorkerRunState::Failed, WorkerRunKind::Command);
        $url = $this->url($project, $run, 'rerun');
        $cardId = $run->cardId;
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $url);

        self::assertSame([], $this->flashes($client, 'worker-run-command'));
        self::assertResponseRedirects('/projects/'.$project->id.'/worker-runs?search='.$run->id);
        $command = $this->onlyCommand();
        self::assertSame(BridgeCommandKind::RerunCommand, $command->kind);
        self::assertSame(BridgeCommandState::Pending, $command->state);
        $project = $this->em()->find(Project::class, $project->id);
        self::assertNotNull($project);
        self::assertNull($this->cardHolds()->findOneOfCard($project, $cardId));
    }

    public function test_a_rerun_of_a_worker_run_flashes_the_reason(): void
    {
        $client = static::createClient();
        [$owner, $project, $run] = $this->scenario('rerun-worker', WorkerRunState::Failed);
        $url = $this->url($project, $run, 'rerun');
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $url);

        self::assertResponseRedirects('/projects/'.$project->id.'/worker-runs?search='.$run->id);
        self::assertSame(['Only a command run can run again.'], $this->flashes($client, 'worker-run-command'));
        self::assertSame(0, $this->countCommands($this->em()));
    }

    public function test_a_rerun_from_the_card_frame_returns_to_the_frame(): void
    {
        $client = static::createClient();
        [$owner, $project, $run] = $this->scenario('rerun-frame', WorkerRunState::Failed, WorkerRunKind::Command);
        $url = $this->url($project, $run, 'rerun');
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $url, ['HTTP_TURBO_FRAME' => 'card-worker-runs']);

        self::assertResponseRedirects('/projects/'.$project->id.'/worker-runs/card/'.$run->cardId);
        self::assertSame(1, $this->countCommands($this->em()));
    }

    public function test_a_rerun_from_a_user_who_cannot_manage_the_project_is_refused(): void
    {
        $client = static::createClient();
        [, $project, $run] = $this->scenario('rerun-theirs', WorkerRunState::Failed, WorkerRunKind::Command);
        $stranger = $this->user($this->em(), 'run-command-rerun-stranger@example.com');
        $url = $this->url($project, $run, 'rerun');
        $this->em()->clear();

        $client->loginUser($stranger);
        $this->post($client, $url);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->countCommands($this->em()));
    }

    public function test_a_rerun_without_a_valid_csrf_token_is_refused(): void
    {
        $client = static::createClient();
        [$owner, $project, $run] = $this->scenario('rerun-csrf', WorkerRunState::Failed, WorkerRunKind::Command);
        $url = $this->url($project, $run, 'rerun');
        $this->em()->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_POST, $url, ['_csrf_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->countCommands($this->em()));
    }

    public function test_a_request_from_the_card_frame_returns_to_the_frame(): void
    {
        $client = static::createClient();
        [$owner, $project, $run] = $this->scenario('frame', WorkerRunState::Running);
        $url = $this->url($project, $run, 'stop');
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $url, ['HTTP_TURBO_FRAME' => 'card-worker-runs']);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/projects/'.$project->id.'/worker-runs/card/'.$run->cardId);
    }

    public function test_the_owner_withdraws_a_pending_stop(): void
    {
        $client = static::createClient();
        [$owner, $project, $run] = $this->scenario('cancel', WorkerRunState::Running);
        $pending = $this->seedCommand($this->em(), $run);
        $url = $this->url($project, $run, 'cancel-command');
        $pendingId = $pending->id;
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $url);

        self::assertResponseRedirects('/projects/'.$project->id.'/worker-runs?search='.$run->id);
        self::assertSame(BridgeCommandState::Cancelled, $this->em()->find(BridgeCommand::class, $pendingId)?->state);
    }

    public function test_a_refused_resume_from_the_card_frame_renders_the_frame_with_the_reason(): void
    {
        $client = static::createClient();
        [$owner, $project, $run] = $this->scenario('refused', WorkerRunState::Running);
        $url = $this->url($project, $run, 'resume');
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $url, ['HTTP_TURBO_FRAME' => 'card-worker-runs']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('turbo-frame#card-worker-runs [data-worker-run-command-flash]', 'Only an ended run can resume.');
        self::assertSame([], $this->flashes($client, 'worker-run-command'));
        self::assertSame(0, $this->countCommands($this->em()));
    }

    public function test_a_cancel_with_nothing_pending_flashes_the_reason(): void
    {
        $client = static::createClient();
        [$owner, $project, $run] = $this->scenario('nothing', WorkerRunState::Running);
        $url = $this->url($project, $run, 'cancel-command');
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $url);

        self::assertResponseRedirects('/projects/'.$project->id.'/worker-runs?search='.$run->id);
        self::assertSame(['This run has no command that waits.'], $this->flashes($client, 'worker-run-command'));
    }

    public function test_a_request_without_a_valid_csrf_token_is_refused(): void
    {
        $client = static::createClient();
        [$owner, $project, $run] = $this->scenario('csrf', WorkerRunState::Running);
        $url = $this->url($project, $run, 'stop');
        $this->em()->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_POST, $url, ['_csrf_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->countCommands($this->em()));
    }

    public function test_a_user_who_cannot_manage_the_project_is_refused(): void
    {
        $client = static::createClient();
        [, $project, $run] = $this->scenario('theirs', WorkerRunState::Running);
        $stranger = $this->user($this->em(), 'run-command-stranger@example.com');
        $url = $this->url($project, $run, 'stop');
        $this->em()->clear();

        $client->loginUser($stranger);
        $this->post($client, $url);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->countCommands($this->em()));
    }

    public function test_a_run_of_another_project_is_not_found(): void
    {
        $client = static::createClient();
        [$owner, , $run] = $this->scenario('cross', WorkerRunState::Running);
        $mine = $this->project($this->em(), $owner, 'Run command mine');
        $url = $this->url($mine, $run, 'stop');
        $this->em()->clear();

        $client->loginUser($owner);
        $this->post($client, $url);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->countCommands($this->em()));
    }

    /** @return array{0: User, 1: Project, 2: WorkerRun} */
    private function scenario(string $name, WorkerRunState $state, WorkerRunKind $kind = WorkerRunKind::Worker): array
    {
        $em = $this->em();
        $owner = $this->user($em, 'run-command-'.$name.'@example.com');
        $project = $this->project($em, $owner, 'Run command '.$name);
        $bridge = $this->seedBridge($em, $owner, projects: [(string) $project->id]);
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_RERUN_COMMAND];
        $em->flush();
        $run = $this->seedRun($em, $project, exitCode: WorkerRunState::Failed === $state ? 1 : 0, bridgeId: $bridge->id, state: $state, runKey: Uuid::v4(), kind: $kind);

        return [$owner, $project, $run];
    }

    private function url(Project $project, WorkerRun $run, string $action): string
    {
        return '/projects/'.$project->id.'/worker-runs/'.$run->id.'/'.$action;
    }

    /**
     * The same-origin sentinel passes the CSRF check, so a refusal is the voter's or the lookup's.
     *
     * @param array<string, string> $server
     */
    private function post(KernelBrowser $client, string $url, array $server = []): void
    {
        $client->request(Request::METHOD_POST, $url, ['_csrf_token' => 'csrf-token'], [], ['HTTP_REFERER' => 'http://localhost'.$url] + $server);
    }

    private function onlyCommand(): BridgeCommand
    {
        $repository = static::getContainer()->get(BridgeCommandRepository::class);
        self::assertInstanceOf(BridgeCommandRepository::class, $repository);
        $commands = $repository->findAll();
        self::assertCount(1, $commands);

        return $commands[0];
    }

    private function cardHolds(): CardHoldRepository
    {
        $holds = static::getContainer()->get(CardHoldRepository::class);
        self::assertInstanceOf(CardHoldRepository::class, $holds);

        return $holds;
    }

    /** @return array<mixed> */
    private function flashes(KernelBrowser $client, string $type): array
    {
        $session = $client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);

        return $session->getFlashBag()->peek($type);
    }
}
