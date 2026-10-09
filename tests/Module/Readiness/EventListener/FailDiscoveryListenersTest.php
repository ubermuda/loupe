<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\EventListener;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Command\StartDiscoveryCommand;
use App\Module\Readiness\Command\StartDiscoveryHandler;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Tests\Module\Readiness\DiscoveryScenario;
use App\Tests\Support\AgentCredential;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class FailDiscoveryListenersTest extends KernelTestCase
{
    use DiscoveryScenario;

    public function test_a_request_that_expires_with_no_taker_fails_the_run(): void
    {
        self::bootKernel();
        [$card, $run] = $this->requestedDiscovery();
        $request = $this->liveRequest($card);

        // The request takes the time of the container clock, so the pass runs past its timeout from there.
        $this->evaluate($card, $request->createdAt->modify('+3 hours')->format('Y-m-d H:i:s'));

        self::assertSame(WorkRequestState::Expired, $request->state);
        self::assertSame([DiscoveryRunState::Failed, 'no-taker'], [$run->state, $run->failureReason]);
        self::assertNotNull($run->endedAt);
        self::assertSame([], $this->liveRequests($card));
        self::assertNull($this->activePauseReason($card));
    }

    public function test_a_card_moved_out_of_the_backlog_fails_the_run_and_a_new_start_succeeds(): void
    {
        self::bootKernel();
        [$card, $run] = $this->requestedDiscovery();
        $request = $this->liveRequest($card);
        $this->liveBridge($card->project);
        $card->column = $this->column($card->project, 'next');
        $this->em()->flush();

        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame(WorkRequestState::Cancelled, $request->state);
        self::assertSame([DiscoveryRunState::Failed, 'card-moved'], [$run->state, $run->failureReason]);
        $next = $this->startHandler()(new StartDiscoveryCommand($card->project, Actor::Human));
        self::assertSame(DiscoveryRunState::Requested, $next->state);
        self::assertNotSame($card->id, $next->card->id);
    }

    /** A person or a template change can cancel the request while the card stays in the Backlog. The work stopped all the same. */
    public function test_a_request_cancelled_in_the_backlog_fails_the_run(): void
    {
        self::bootKernel();
        [$card, $run] = $this->requestedDiscovery();
        $request = $this->liveRequest($card);
        $request->withdraw(WorkRequestState::Cancelled, new \DateTimeImmutable());
        $this->em()->flush();

        $this->dispatch($this->requestChanged($request));

        self::assertSame([DiscoveryRunState::Failed, 'card-moved'], [$run->state, $run->failureReason]);
    }

    public function test_a_cancelled_request_of_another_rule_leaves_the_run_requested(): void
    {
        self::bootKernel();
        [$card, $run] = $this->requestedDiscovery();
        $other = new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException(), $card->number, 'groom', null, 'groom', new \DateTimeImmutable());
        $other->withdraw(WorkRequestState::Cancelled, new \DateTimeImmutable());
        $this->em()->persist($other);
        $this->em()->flush();

        $this->dispatch($this->requestChanged($other));

        self::assertSame(DiscoveryRunState::Requested, $run->state);
    }

    public function test_an_expired_request_of_another_rule_leaves_the_run_requested(): void
    {
        self::bootKernel();
        [$card, $run] = $this->requestedDiscovery();
        $other = new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException(), $card->number, 'groom', null, 'groom', new \DateTimeImmutable());
        $other->withdraw(WorkRequestState::Expired, new \DateTimeImmutable());
        $this->em()->persist($other);
        $this->em()->flush();

        $this->dispatch($this->requestChanged($other));

        self::assertSame(DiscoveryRunState::Requested, $run->state);
    }

    /** @param ?string $failureReason the reason the bridge reported, when it reported one */
    #[DataProvider('endedRuns')]
    public function test_a_discovery_worker_that_ends_fails_the_run_and_cancels_its_request(WorkerRunState $state, ?string $failureReason, string $expectedReason): void
    {
        self::bootKernel();
        [$card, $run] = $this->requestedDiscovery();
        $request = $this->liveRequest($card);
        $request->state = WorkRequestState::Claimed;
        $this->em()->flush();
        $this->transport()->reset();

        $this->dispatch(...WorkerRunChanged::ofRuns([$this->workerRun($card, 'discovery', $state, $failureReason)]));

        self::assertSame([DiscoveryRunState::Failed, $expectedReason], [$run->state, $run->failureReason]);
        self::assertSame(WorkRequestState::Cancelled, $request->state);
        self::assertContains((string) $card->id, $this->queuedEvaluations());
    }

    /** @return iterable<string, array{WorkerRunState, ?string, string}> */
    public static function endedRuns(): iterable
    {
        yield 'stopped by a person' => [WorkerRunState::Stopped, null, 'stopped'];
        yield 'timed out' => [WorkerRunState::TimedOut, null, 'timed-out'];
        yield 'lost' => [WorkerRunState::Lost, null, 'lost'];
        yield 'succeeded with no report' => [WorkerRunState::Succeeded, null, 'succeeded'];
        yield 'not started, with a reason' => [WorkerRunState::NotStarted, 'claude: command not found', 'claude: command not found'];
    }

    /** These runs leave the work to a later run, so discovery goes on. */
    #[DataProvider('continuingRuns')]
    public function test_a_discovery_worker_whose_work_goes_on_leaves_the_run_requested(WorkerRunState $state): void
    {
        self::bootKernel();
        [$card, $run] = $this->requestedDiscovery();
        $request = $this->liveRequest($card);

        $this->dispatch(...WorkerRunChanged::ofRuns([$this->workerRun($card, 'discovery', $state)]));

        self::assertSame(DiscoveryRunState::Requested, $run->state);
        self::assertSame(WorkRequestState::Open, $request->state);
    }

    /** @return iterable<string, array{WorkerRunState}> */
    public static function continuingRuns(): iterable
    {
        yield 'running' => [WorkerRunState::Running];
        yield 'resumed' => [WorkerRunState::Resumed];
        yield 'replaced' => [WorkerRunState::Replaced];
        yield 'skipped' => [WorkerRunState::Skipped];
        yield 'dropped' => [WorkerRunState::Dropped];
    }

    /** The kind is a label the rule chooses, so a run of another rule can carry the discovery kind. */
    public function test_a_worker_of_another_rule_leaves_the_run_requested(): void
    {
        self::bootKernel();
        [$card, $run] = $this->requestedDiscovery();

        $this->dispatch(...WorkerRunChanged::ofRuns([$this->workerRun($card, 'implement', WorkerRunState::Failed)]));

        self::assertSame(DiscoveryRunState::Requested, $run->state);
    }

    public function test_a_run_that_already_reported_or_failed_is_not_touched(): void
    {
        self::bootKernel();
        $reportedCard = $this->discoveryCard($this->boundProject());
        $reported = $this->discoveryRun($reportedCard, DiscoveryRunState::Reported);
        $failedCard = $this->discoveryCard($reportedCard->project);
        $failed = $this->discoveryRun($failedCard, DiscoveryRunState::Failed);
        $failed->failureReason = 'no-taker';
        $this->em()->flush();

        $this->dispatch(...WorkerRunChanged::ofRuns([
            $this->workerRun($reportedCard, 'discovery', WorkerRunState::Succeeded),
            $this->workerRun($failedCard, 'discovery', WorkerRunState::Lost),
        ]));

        self::assertSame([DiscoveryRunState::Reported, null], [$reported->state, $reported->failureReason]);
        self::assertSame([DiscoveryRunState::Failed, 'no-taker'], [$failed->state, $failed->failureReason]);
    }

    /** @return array{Card, DiscoveryRun} a backlog card whose requested run opened a discovery request at noon */
    private function requestedDiscovery(): array
    {
        $card = $this->discoveryCard($this->boundProject());
        $run = $this->discoveryRun($card);
        $this->evaluate($card, '2026-10-02 12:00:00');

        return [$card, $run];
    }

    private function boundProject(): Project
    {
        $project = $this->workflowProject('discovery-fail');
        $this->bindLifecycle($project);

        return $project;
    }

    private function evaluate(Card $card, string $at): void
    {
        $engine = self::getContainer()->get(Engine::class);
        self::assertInstanceOf(Engine::class, $engine);
        $engine->evaluate($card->id ?? throw new \LogicException('A flushed card has an id.'), new \DateTimeImmutable($at));
    }

    private function liveRequest(Card $card): WorkRequest
    {
        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame('discovery', $live[0]->kind);

        return $live[0];
    }

    /** @return list<WorkRequest> */
    private function liveRequests(Card $card): array
    {
        $repository = self::getContainer()->get(WorkRequestRepository::class);
        self::assertInstanceOf(WorkRequestRepository::class, $repository);

        return $repository->findLiveForCard($card->id ?? throw new \LogicException('A flushed card has an id.'));
    }

    private function activePauseReason(Card $card): ?string
    {
        $reason = $this->em()->getConnection()->fetchOne('SELECT reason FROM card_pauses WHERE card_id = :id AND released_at IS NULL', ['id' => (string) $card->id]);

        return false === $reason ? null : (string) $reason;
    }

    private function workerRun(Card $card, string $ruleId, WorkerRunState $state, ?string $failureReason = null): WorkerRun
    {
        $run = new WorkerRun(
            project: $card->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('A flushed card has an id.'),
            cardNumber: $card->number,
            workKind: 'discovery',
            state: $state,
            failureReason: $failureReason,
            ruleId: $ruleId,
        );
        $this->em()->persist($run);
        $this->em()->flush();

        return $run;
    }

    private function liveBridge(Project $project): void
    {
        $this->em()->persist(new Bridge(AgentCredential::managed($this->em(), $project->owner, $project->owner->id), Uuid::v4(), [(string) $project->id], 'b4e39aa7', new \DateTimeImmutable()));
        $this->em()->flush();
    }

    private function startHandler(): StartDiscoveryHandler
    {
        $handler = self::getContainer()->get(StartDiscoveryHandler::class);
        self::assertInstanceOf(StartDiscoveryHandler::class, $handler);

        return $handler;
    }

    private function requestChanged(WorkRequest $request): WorkRequestChanged
    {
        return new WorkRequestChanged(
            $request->project->id ?? throw new \LogicException(),
            $request->subjectType,
            $request->subjectId,
            $request->id ?? throw new \LogicException(),
            $request->state,
        );
    }

    private function dispatch(object ...$events): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach ($events as $event) {
            $dispatcher->dispatch($event);
        }
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @return list<string> the card ids of the queued evaluations */
    private function queuedEvaluations(): array
    {
        $cardIds = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof EvaluateCard) {
                $cardIds[] = $message->cardId;
            }
        }

        return $cardIds;
    }
}
