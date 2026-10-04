<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\EventListener;

use App\Module\Bridge\Command\ReportWorkerRunStateCommand;
use App\Module\Bridge\Command\ReportWorkerRunStateHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Messenger\ResumeAskingSession;
use App\Module\Bridge\Messenger\ResumeAskingSessionHandler;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/** The run that asked still ran when the ask closed, so its end sends the resume. */
final class ResumeAskingSessionOnResumableWorkerRunEndedTest extends KernelTestCase
{
    use BridgeScenario;

    private const string RECEIVED = '2026-01-01 09:58:00';
    private const string STARTED = '2026-01-01 10:00:00';

    public function test_an_ask_closed_while_the_run_ran_resumes_the_run_once_it_ends(): void
    {
        [$project, $bridge, $run] = $this->runningRun('late-resume');
        $this->closedAsk($project, $bridge, $run, new \DateTimeImmutable('2026-01-01 10:02:00'));
        // The resume the close queued finds the run still running.
        $this->handle($this->resumeMessage($project, $bridge, $run, new \DateTimeImmutable('2026-01-01 10:02:00')));
        self::assertSame([], $this->commands());

        $this->end($project, $run, WorkerRunState::Blocked);
        $this->handleQueued();

        $commands = $this->commands();
        self::assertCount(1, $commands);
        self::assertSame(BridgeCommandKind::ResumeRun, $commands[0]->kind);
        self::assertSame((string) $run->id, (string) $commands[0]->workerRun->id);
    }

    public function test_the_end_sends_no_second_resume_when_the_close_already_resumed_the_run(): void
    {
        [$project, $bridge, $run] = $this->runningRun('late-resume-once');
        $closedAt = new \DateTimeImmutable('2026-01-01 10:02:00');
        $this->closedAsk($project, $bridge, $run, $closedAt);

        $this->end($project, $run, WorkerRunState::Blocked);
        $endMessages = $this->queued();
        // The queue reached the resume of the close after the run ended, and the bridge took it.
        $this->handle($this->resumeMessage($project, $bridge, $run, $closedAt));
        $first = $this->commands();
        self::assertCount(1, $first);
        $this->em()->getConnection()->executeStatement('UPDATE bridge_commands SET state = ?', [BridgeCommandState::Done->value]);
        foreach ($endMessages as $message) {
            $this->handle($message);
        }

        self::assertCount(1, $this->commands());
    }

    public function test_an_ask_closed_while_the_run_was_queued_resumes_the_run_once_it_ends(): void
    {
        [$project, $bridge, $run] = $this->runningRun('late-resume-queued');
        $this->closedAsk($project, $bridge, $run, new \DateTimeImmutable('2026-01-01 09:59:00'));

        $this->end($project, $run, WorkerRunState::Blocked);
        $this->handleQueued();

        self::assertCount(1, $this->commands());
    }

    public function test_an_ask_closed_before_the_first_report_of_the_run_queues_nothing(): void
    {
        [$project, $bridge, $run] = $this->runningRun('late-resume-earlier');
        $this->closedAsk($project, $bridge, $run, new \DateTimeImmutable('2026-01-01 09:57:00'));

        $this->end($project, $run, WorkerRunState::Blocked);

        self::assertSame([], $this->queued());
    }

    public function test_an_ask_with_no_blocking_item_queues_nothing(): void
    {
        [$project, $bridge, $run] = $this->runningRun('late-resume-nonblocking');
        $this->closedAsk($project, $bridge, $run, new \DateTimeImmutable('2026-01-01 10:02:00'), blocking: false);

        $this->end($project, $run, WorkerRunState::Blocked);

        self::assertSame([], $this->queued());
    }

    public function test_an_ask_of_another_bridge_queues_nothing(): void
    {
        [$project, $bridge, $run] = $this->runningRun('late-resume-other-bridge');
        $other = $this->seedBridge($this->em(), $project->owner);
        $this->closedAsk($project, $other, $run, new \DateTimeImmutable('2026-01-01 10:02:00'));

        $this->end($project, $run, WorkerRunState::Blocked);

        self::assertSame([], $this->queued());
    }

    /** @return iterable<string, array{WorkerRunState}> */
    public static function noResumeEnds(): iterable
    {
        yield 'a success' => [WorkerRunState::Succeeded];
        yield 'a stop by a person' => [WorkerRunState::Stopped];
        yield 'a wait on the forge' => [WorkerRunState::WaitingOnForge];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('noResumeEnds')]
    public function test_an_end_that_takes_no_resume_queues_nothing(WorkerRunState $state): void
    {
        [$project, $bridge, $run] = $this->runningRun('late-resume-'.$state->value);
        $this->closedAsk($project, $bridge, $run, new \DateTimeImmutable('2026-01-01 10:02:00'));

        $this->end($project, $run, $state);

        self::assertSame([], $this->queued());
    }

    /** @return array{Project, Bridge, WorkerRun} */
    private function runningRun(string $name): array
    {
        self::bootKernel();
        $owner = $this->user($this->em(), $name.'@example.com');
        $project = $this->project($this->em(), $owner, 'Project '.substr(md5($name), 0, 8));
        $bridge = $this->seedBridge($this->em(), $owner);
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em()->flush();
        $run = $this->seedRun($this->em(), $project, receivedAt: new \DateTimeImmutable(self::RECEIVED), bridgeId: $bridge->id, state: WorkerRunState::Running, runKey: Uuid::v4());
        $this->transport()->reset();

        return [$project, $bridge, $run];
    }

    private function closedAsk(Project $project, Bridge $bridge, WorkerRun $run, \DateTimeImmutable $closedAt, bool $blocking = true): void
    {
        $item = new InboxItem(project: $project, number: 1, kind: InboxItemKind::Question, title: 'Which column?', blocking: $blocking);
        $ask = new InboxAsk(project: $project, sessionId: $run->sessionId, bridgeId: $bridge->id);
        $ask->items->add(new InboxAskItem($ask, $item));
        $ask->closedAt = $closedAt;
        $this->em()->persist($item);
        $this->em()->persist($ask);
        $this->em()->flush();
    }

    private function end(Project $project, WorkerRun $run, WorkerRunState $state): void
    {
        $handler = self::getContainer()->get(ReportWorkerRunStateHandler::class);
        self::assertInstanceOf(ReportWorkerRunStateHandler::class, $handler);
        $at = new \DateTimeImmutable('2026-01-01 10:10:00');
        $outcome = $state->isOutcome();
        $handler(new ReportWorkerRunStateCommand(
            owner: $project->owner,
            handle: (string) $project->id,
            runKey: $run->runKey ?? throw new \LogicException('The run has a key.'),
            bridgeId: $run->bridgeId ?? throw new \LogicException('The run has a bridge.'),
            state: $state,
            at: $at,
            cardId: $run->cardId,
            cardNumber: $run->cardNumber,
            workKind: $run->workKind,
            sessionId: $run->sessionId,
            startedAt: new \DateTimeImmutable(self::STARTED),
            endedAt: $at,
            exitCode: $outcome ? 0 : null,
            hasResult: $outcome ? true : null,
            output: 'worker output',
            resultStatus: match ($state) {
                WorkerRunState::Blocked => 'blocked',
                WorkerRunState::WaitingOnForge => 'waiting',
                default => null,
            },
        ));
    }

    private function resumeMessage(Project $project, Bridge $bridge, WorkerRun $run, \DateTimeImmutable $closedAt): ResumeAskingSession
    {
        return new ResumeAskingSession((string) $project->id, (string) $bridge->id, (string) $run->sessionId, $closedAt);
    }

    private function handleQueued(): void
    {
        foreach ($this->queued() as $message) {
            $this->handle($message);
        }
    }

    /** @return list<ResumeAskingSession> */
    private function queued(): array
    {
        $messages = array_map(static fn ($envelope) => $envelope->getMessage(), $this->transport()->getSent());

        return array_values(array_filter($messages, static fn (object $message): bool => $message instanceof ResumeAskingSession));
    }

    private function handle(ResumeAskingSession $message): void
    {
        $handler = self::getContainer()->get(ResumeAskingSessionHandler::class);
        self::assertInstanceOf(ResumeAskingSessionHandler::class, $handler);
        $handler($message);
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @return list<BridgeCommand> */
    private function commands(): array
    {
        $repository = self::getContainer()->get(BridgeCommandRepository::class);
        self::assertInstanceOf(BridgeCommandRepository::class, $repository);

        return array_values($repository->findAll());
    }
}
