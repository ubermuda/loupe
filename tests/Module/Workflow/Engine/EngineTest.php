<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Command\ReleaseCardPauseHandler;
use App\Module\Board\Command\SaveBoardAutomationSettingsCommand;
use App\Module\Board\Command\SaveBoardAutomationSettingsHandler;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\CardHoldsReleased;
use App\Module\Bridge\Repository\CardHoldRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Action\EvaluateChildren;
use App\Module\Workflow\Action\ForgeWrite;
use App\Module\Workflow\Action\MoveCard;
use App\Module\Workflow\Action\PauseCard;
use App\Module\Workflow\Action\ReleasePause;
use App\Module\Workflow\Action\RequestWork;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Command\EvaluateWorkflowCardHandler;
use App\Module\Workflow\Command\ReleaseWorkflowPauseCommand;
use App\Module\Workflow\Command\ReleaseWorkflowPauseHandler;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Engine\RuleSubject;
use App\Module\Workflow\Entity\WorkflowBinding;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Entity\WorkflowSlotLink;
use App\Module\Workflow\Event\CardPaused;
use App\Module\Workflow\EventListener\RearmCardsOnCardHoldsReleased;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Messenger\EvaluateCardHandler;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Repository\WorkflowPendingBaselineRepository;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Service\CardPullRequests;
use App\Module\Workflow\Service\EvaluationTrigger;
use App\Module\Workflow\Service\FactFingerprint;
use App\Module\Workflow\Service\FactProviders;
use App\Module\Workflow\Service\FactsBuilder;
use App\Module\Workflow\Service\WorkflowAutomation;
use App\Module\Workflow\Template\AppRules;
use App\Module\Workflow\Template\ProjectTemplateCopy;
use App\Module\Workflow\Template\TemplateParser;
use App\Outbox\OutboxWriter;
use App\Tests\Module\Workflow\Action\ActionScenario;
use App\Tests\Module\Workflow\Fact\ProvidedFacts;
use App\Tests\Module\Workflow\Fact\ProvidedFactsProvider;
use App\Tests\Module\Workflow\Fact\ProvidedFactsReady;
use App\Tests\Module\Workflow\Fact\UnprovidedFactsReady;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;

final class EngineTest extends KernelTestCase
{
    use ActionScenario;

    private const string NOON = '2026-10-02 12:00:00';

    private const array ALWAYS = ['all' => []];

    private const array NOT_EPIC = ['not' => ['card.type' => ['type' => 'epic']]];

    private const array PROVIDED_READY = [ProvidedFactsReady::KEY => []];

    private const array REPAIR = ['retryOn' => ['failed', 'timeout'], 'retries' => 1, 'backoffMinutes' => [10, 60], 'repair' => ['kind' => 'repair']];

    /** @var list<CardPaused> */
    private array $paused = [];

    private RecordingLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function test_a_rule_fires_on_its_rising_edge_once(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);

        $this->evaluate($card);
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertCount(1, $this->liveRequests($card));
        $state = $this->ruleState($card, 'work');
        self::assertTrue($state->truth);
        self::assertSame(1, $state->fires);
    }

    public function test_a_rule_outside_the_slot_resets_cancels_its_request_and_fires_again_on_reentry(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        $first = $this->liveRequests($card)[0];

        $this->moveTo($card, 'in-progress');
        $this->evaluate($card);

        self::assertSame(WorkRequestState::Cancelled, $first->state);
        self::assertSame([], $this->liveRequests($card));
        $state = $this->ruleState($card, 'work');
        self::assertFalse($state->truth);
        self::assertSame(0, $state->fires);

        $this->moveTo($card, 'next');
        $this->evaluate($card);

        self::assertCount(1, $this->liveRequests($card));
        self::assertSame(1, $this->ruleState($card, 'work')->fires);
    }

    public function test_a_refused_action_backs_off_retries_when_due_or_when_the_facts_change_and_pauses_after_the_last_retry(): void
    {
        $card = $this->boundCard([self::moveRule('stuck', 'three', self::NOT_EPIC)]);

        $this->evaluate($card);
        $state = $this->ruleState($card, 'stuck');
        self::assertSame([1, 'workflow-slot-missing', '2026-10-02 12:10:00'], [$state->attempts, $state->lastRefusal, $state->dueAt?->format('Y-m-d H:i:s')]);
        self::assertSame(self::NOON, $state->lastRefusalAt?->format('Y-m-d H:i:s'));

        $this->evaluate($card, '2026-10-02 12:05:00');
        self::assertSame(1, $this->ruleState($card, 'stuck')->attempts);

        $this->evaluate($card, '2026-10-02 12:10:00');
        self::assertSame([2, '2026-10-02 13:10:00'], [$this->ruleState($card, 'stuck')->attempts, $this->ruleState($card, 'stuck')->dueAt?->format('Y-m-d H:i:s')]);
        self::assertSame('2026-10-02 12:10:00', $this->ruleState($card, 'stuck')->lastRefusalAt?->format('Y-m-d H:i:s'));

        $this->setType($card, CardType::Bug);
        $this->evaluate($card, '2026-10-02 12:11:00');

        $state = $this->ruleState($card, 'stuck');
        self::assertSame([3, null], [$state->attempts, $state->dueAt]);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([CardPauseKind::Retries, 'workflow-slot-missing', 'stuck'], [$pause->kind, $pause->reason, $pause->ruleId]);
        self::assertCount(1, $this->paused);
        self::assertSame([CardPauseKind::Retries, 'workflow-slot-missing', $card->id?->toRfc4122(), $card->project->id?->toRfc4122()], [
            $this->paused[0]->kind,
            $this->paused[0]->reason,
            $this->paused[0]->cardId->toRfc4122(),
            $this->paused[0]->projectId->toRfc4122(),
        ]);
    }

    public function test_a_refused_request_is_retried_when_due_and_the_card_pauses_when_the_retries_run_out(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [10, 60]]);
        $this->evaluate($card);
        $first = $this->liveRequests($card)[0];

        $this->refuse($first, 'failed', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        $state = $this->ruleState($card, 'work');
        self::assertSame([1, 'failed', '2026-10-02 12:31:00', 0], [$state->attempts, $state->lastRefusal, $state->dueAt?->format('Y-m-d H:i:s'), $state->fires]);
        self::assertNull($this->activePause($card));
        self::assertSame([], $this->liveRequests($card));

        $this->evaluate($card, '2026-10-02 12:25:00');
        self::assertSame([], $this->liveRequests($card), 'The retry waits for its backoff.');

        $this->evaluate($card, '2026-10-02 12:31:00');
        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertNotSame($first, $live[0]);
        $state = $this->ruleState($card, 'work');
        self::assertSame([1, 1, $live[0]->id?->toRfc4122()], [$state->attempts, $state->fires, $state->workRequestId?->toRfc4122()]);

        $this->evaluate($card, '2026-10-02 12:32:00');
        self::assertSame(1, $this->ruleState($card, 'work')->attempts, 'The evaluation of a live retry counts nothing.');

        $this->refuse($live[0], 'failed', '2026-10-02 12:40:00');
        $this->evaluate($card, '2026-10-02 12:41:00');

        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([CardPauseKind::Retries, 'failed', 'work'], [$pause->kind, $pause->reason, $pause->ruleId]);
        self::assertSame(2, $this->ruleState($card, 'work')->attempts);
        self::assertSame([], $this->liveRequests($card), 'A policy with no repair opens no repair request.');

        $this->evaluate($card, '2026-10-02 12:50:00');
        self::assertCount(1, $this->paused, 'A counted refusal pauses the card once.');
    }

    public function test_a_refusal_code_that_the_template_does_not_retry_pauses_the_card_at_once(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [10, 60]]);
        $this->evaluate($card);

        $this->refuse($this->liveRequests($card)[0], 'needs-person', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');

        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([CardPauseKind::WorkStopped, 'needs-person', 'work'], [$pause->kind, $pause->reason, $pause->ruleId]);
        self::assertSame(0, $this->ruleState($card, 'work')->attempts);
    }

    public function test_a_work_stopped_pause_that_a_person_releases_does_not_pause_the_card_again_on_the_old_refusal(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [10, 60]]);
        $this->evaluate($card);
        $this->refuse($this->liveRequests($card)[0], 'needs-person', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        self::assertNotNull($this->activePause($card));

        $this->releaseByPerson($card);
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertNull($this->activePause($card));
        self::assertCount(1, $this->liveRequests($card), 'The rule fired a new request with a fresh budget.');
    }

    public function test_a_work_stopped_pause_ends_when_a_resumed_run_reports_and_the_rule_requests_no_second_worker(): void
    {
        $card = $this->stoppedCard('needs-person');
        $pause = $this->activePause($card);
        self::assertNotNull($pause);

        $this->resumedRun($card, WorkerRunState::Queued);
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('run-resumed', $pause->releaseReason);
        self::assertNull($this->activePause($card));
        self::assertSame([], $this->liveRequests($card), 'The resumed run is the worker of the card.');
        $state = $this->ruleState($card, 'work');
        self::assertSame([true, 0, null], [$state->truth, $state->attempts, $state->lastRefusal]);

        $this->evaluate($card, '2026-10-02 12:40:00');
        self::assertSame([], $this->liveRequests($card));
        self::assertCount(1, $this->paused);
    }

    public function test_a_resume_release_leaves_a_system_entry_in_the_card_history(): void
    {
        $card = $this->stoppedCard('needs-person');
        $this->resumedRun($card, WorkerRunState::Running);
        $this->evaluate($card, '2026-10-02 12:30:00');

        $events = $this->service(CardEventRepository::class)->findBy(['card' => $card, 'kind' => CardEventKind::PauseReleased]);
        self::assertCount(1, $events);
        self::assertSame(CardReporter::System, $events[0]->actorKind);
        self::assertSame(['kind' => 'work-stopped', 'reason' => 'needs-person', 'ruleId' => 'work'], $events[0]->detail);
    }

    public function test_a_retries_pause_ends_when_a_person_resumes_the_run(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: ['retryOn' => ['failed'], 'retries' => 0, 'backoffMinutes' => []]);
        $this->evaluate($card);
        $this->refuse($this->liveRequests($card)[0], 'failed', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        $pause = $this->activePause($card);
        self::assertSame(CardPauseKind::Retries, $pause?->kind);

        $this->resumedRun($card, WorkerRunState::Resumed);
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('run-resumed', $pause->releaseReason);
        self::assertSame([], $this->liveRequests($card));
    }

    public function test_a_work_limit_pause_ends_when_a_resumed_run_reports(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['card.type' => ['type' => 'bug']], limit: 1)]);
        $this->setType($card, CardType::Bug);
        $this->evaluate($card);
        $this->setType($card, CardType::Feature);
        $this->evaluate($card);
        $this->setType($card, CardType::Bug);
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertSame(CardPauseKind::WorkLimit, $pause?->kind);

        $this->resumedRun($card, WorkerRunState::Running);
        $this->evaluate($card, '2026-10-02 13:00:00');

        self::assertSame('run-resumed', $pause->releaseReason);
    }

    public function test_a_pause_stays_while_no_run_since_it_began_continues_an_earlier_one(): void
    {
        $card = $this->stoppedCard('needs-person');
        $pause = $this->activePause($card);

        $this->workerRun($card, 'work', WorkerRunState::Running);
        $this->resumedRun($card, WorkerRunState::Running, new \DateTimeImmutable('2026-10-02 11:00:00'));
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertNull($pause?->releasedAt, 'A fresh run and a resume from before the pause do not end it.');
    }

    public function test_a_pause_stays_when_a_person_stopped_the_resumed_run(): void
    {
        $card = $this->stoppedCard('needs-person');
        $pause = $this->activePause($card);

        $this->resumedRun($card, WorkerRunState::Stopped);
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertNull($pause?->releasedAt);
    }

    public function test_a_resumed_run_that_ended_before_the_evaluation_still_ends_the_pause_and_counts(): void
    {
        $card = $this->stoppedCard('needs-person', ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [10, 60]]);
        $pause = $this->activePause($card);

        $this->resumedRun($card, WorkerRunState::Failed);
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('run-resumed', $pause?->releaseReason);
        self::assertNull($this->activePause($card));
        self::assertSame([1, 'failed'], [$this->ruleState($card, 'work')->attempts, $this->ruleState($card, 'work')->lastRefusal]);
    }

    public function test_an_interactive_run_that_reports_later_does_not_hide_the_resumed_run(): void
    {
        $card = $this->stoppedCard('needs-person');
        $pause = $this->activePause($card);

        $this->resumedRun($card, WorkerRunState::Running);
        $this->workerRun($card, 'work', WorkerRunState::Running);
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('run-resumed', $pause?->releaseReason);
    }

    public function test_a_rule_pause_stays_when_a_run_resumes(): void
    {
        $card = $this->boundCard([[
            'id' => 'hold',
            'slot' => 'one',
            'when' => self::ALWAYS,
            'then' => ['pause' => ['reason' => 'on-hold', 'until' => ['card.type' => ['type' => 'bug']]]],
        ]]);
        $this->evaluate($card);
        $pause = $this->activePause($card);

        $this->resumedRun($card, WorkerRunState::Running);
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertNull($pause?->releasedAt);
    }

    public function test_a_resumed_run_that_fails_earns_a_retry_with_a_fresh_budget_and_counts_once(): void
    {
        $card = $this->stoppedCard('needs-person', ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [10, 60]]);
        $run = $this->resumedRun($card, WorkerRunState::Running);
        $this->evaluate($card, '2026-10-02 12:30:00');
        self::assertNull($this->activePause($card));

        $run->state = WorkerRunState::Failed;
        $run->endedAt = new \DateTimeImmutable('2026-10-02 12:35:00');
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:36:00');

        $state = $this->ruleState($card, 'work');
        self::assertSame([1, 'failed', '2026-10-02 12:46:00'], [$state->attempts, $state->lastRefusal, $state->dueAt?->format('Y-m-d H:i:s')]);
        self::assertNull($this->activePause($card));

        $this->evaluate($card, '2026-10-02 12:40:00');
        self::assertSame(1, $this->ruleState($card, 'work')->attempts, 'The same run counts once.');
        self::assertSame([], $this->liveRequests($card));

        $this->evaluate($card, '2026-10-02 12:46:00');
        self::assertCount(1, $this->liveRequests($card), 'The retry opens its request when it is due.');

        $this->finish($this->liveRequests($card)[0], '2026-10-02 12:50:00');
        $this->evaluate($card, '2026-10-02 12:51:00');
        self::assertSame(0, $this->ruleState($card, 'work')->attempts, 'A done retry does not count the old run again.');
        self::assertNull($this->activePause($card));
    }

    public function test_a_resumed_run_that_blocks_pauses_the_card_at_once(): void
    {
        $card = $this->stoppedCard('blocked');
        $run = $this->resumedRun($card, WorkerRunState::Running);
        $this->evaluate($card, '2026-10-02 12:30:00');

        $run->state = WorkerRunState::Blocked;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:31:00');

        $pause = $this->activePause($card);
        self::assertSame([CardPauseKind::WorkStopped, 'blocked'], [$pause?->kind, $pause?->reason]);
    }

    public function test_a_resumed_run_that_succeeds_counts_nothing(): void
    {
        $card = $this->stoppedCard('needs-person');
        $run = $this->resumedRun($card, WorkerRunState::Running);
        $this->evaluate($card, '2026-10-02 12:30:00');

        $run->state = WorkerRunState::Succeeded;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:35:00');

        self::assertSame([0, null], [$this->ruleState($card, 'work')->attempts, $this->ruleState($card, 'work')->lastRefusal]);
        self::assertNull($this->activePause($card));
        self::assertSame([], $this->liveRequests($card));
    }

    public function test_a_refusal_that_settles_while_another_pause_holds_the_card_is_counted_after_it_ends(): void
    {
        $card = $this->boundCard([
            [
                'id' => 'hold',
                'slot' => 'one',
                'when' => ['card.type' => ['type' => 'bug']],
                'then' => ['pause' => ['reason' => 'on-hold', 'until' => ['card.type' => ['type' => 'security']]]],
            ],
            self::requestRule('work', self::ALWAYS),
        ], onWorkFailed: ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [10, 60]]);
        $this->evaluate($card);
        $request = $this->liveRequests($card)[0];
        $this->setType($card, CardType::Bug);
        $this->evaluate($card, '2026-10-02 12:05:00');
        self::assertSame(CardPauseKind::Rule, $this->activePause($card)?->kind);

        $this->refuse($request, 'needs-person', '2026-10-02 12:10:00');
        $this->evaluate($card, '2026-10-02 12:11:00');
        self::assertNull($this->ruleState($card, 'work')->lastRefusal, 'The refusal waits while the rule pause holds the card.');

        $this->setType($card, CardType::Security);
        $this->evaluate($card, '2026-10-02 12:20:00');
        self::assertSame(CardPauseKind::WorkStopped, $this->activePause($card)?->kind);
    }

    public function test_releasing_a_hold_clears_the_tracked_request(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [10, 60]]);
        $this->evaluate($card);
        $this->refuse($this->liveRequests($card)[0], 'needs-person', '2026-10-02 12:20:00');
        $this->hold($card);
        $this->releaseHold($card, '2026-10-02 12:30:00');

        self::assertNull($this->ruleState($card, 'work')->workRequestId);
    }

    public function test_a_done_request_clears_the_attempts_of_a_retry(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: ['retryOn' => ['failed'], 'retries' => 2, 'backoffMinutes' => [10, 60]]);
        $this->evaluate($card);
        $this->refuse($this->liveRequests($card)[0], 'failed', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        $this->evaluate($card, '2026-10-02 12:31:00');

        $this->finish($this->liveRequests($card)[0]);
        $this->evaluate($card, '2026-10-02 12:40:00');

        $state = $this->ruleState($card, 'work');
        self::assertSame([0, null, null], [$state->attempts, $state->workRequestId, $state->dueAt]);
        self::assertNull($this->activePause($card));
    }

    public function test_a_retry_does_not_use_a_request_of_the_work_limit(): void
    {
        $card = $this->boundCard([self::requestRule('fix', self::ALWAYS, limit: 1)], onWorkFailed: ['retryOn' => ['failed'], 'retries' => 2, 'backoffMinutes' => [10, 60]]);
        $this->evaluate($card);
        $this->refuse($this->liveRequests($card)[0], 'failed', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        $this->evaluate($card, '2026-10-02 12:31:00');

        self::assertNull($this->activePause($card));
        self::assertCount(1, $this->liveRequests($card));
    }

    public function test_a_retry_waits_for_the_delay_of_the_failure_block_and_the_card_pauses_after_the_last_retry(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: ['retryOn' => ['failed', 'timeout'], 'retries' => 3, 'backoffMinutes' => [2, 3, 5]]);
        $this->evaluate($card);

        foreach (['12:12:00' => '12:10:00', '12:23:00' => '12:20:00', '12:35:00' => '12:30:00'] as $due => $refused) {
            self::assertNull($this->activePause($card));
            $this->refuse($this->liveRequests($card)[0], 'failed', '2026-10-02 '.$refused);
            $this->evaluate($card, '2026-10-02 '.$refused);
            self::assertSame('2026-10-02 '.$due, $this->ruleState($card, 'work')->dueAt?->format('Y-m-d H:i:s'));
            $this->evaluate($card, '2026-10-02 '.$due);
        }

        $this->refuse($this->liveRequests($card)[0], 'failed', '2026-10-02 12:40:00');
        $this->evaluate($card, '2026-10-02 12:40:00');

        self::assertSame(CardPauseKind::Retries, $this->activePause($card)?->kind);
        self::assertSame(4, $this->ruleState($card, 'work')->attempts);
    }

    public function test_a_refusal_code_outside_the_retry_list_pauses_the_card_with_no_retry(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: ['retryOn' => ['failed', 'timeout'], 'retries' => 3, 'backoffMinutes' => [2, 3, 5]]);
        $this->evaluate($card);

        $this->refuse($this->liveRequests($card)[0], 'unfinished', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');

        $pause = $this->activePause($card);
        self::assertSame([CardPauseKind::WorkStopped, 'unfinished'], [$pause?->kind, $pause?->reason]);
        self::assertSame(0, $this->ruleState($card, 'work')->attempts);
    }

    public function test_a_template_with_no_failure_block_ignores_a_refused_request(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        $this->refuse($this->liveRequests($card)[0], 'failed', '2026-10-02 12:20:00');

        $this->evaluate($card, '2026-10-02 12:21:00');

        self::assertNull($this->activePause($card));
        self::assertSame(0, $this->ruleState($card, 'work')->attempts);
    }

    public function test_a_rule_whose_retries_run_out_opens_a_repair_request_instead_of_a_pause(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::REPAIR);

        $repair = $this->repairing($card);

        self::assertNull($this->activePause($card));
        self::assertSame([], $this->paused);
        self::assertSame(['repair', 'work', null, 'failed'], [$repair->kind, $repair->ruleId, $repair->capability, $repair->context->reason]);
        $state = $this->ruleState($card, 'work');
        self::assertSame([2, true, null, $repair->id?->toRfc4122()], [$state->attempts, $state->repaired, $state->dueAt, $state->workRequestId?->toRfc4122()]);
    }

    public function test_a_repair_request_that_cannot_open_pauses_the_card(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::REPAIR);
        $this->evaluate($card);
        $this->refuse($this->liveRequestOfKind($card, 'work'), 'failed', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        $this->evaluate($card, '2026-10-02 12:31:00');
        // A live request of the repair kind makes the open refuse.
        $this->em()->persist(new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, 'repair', null, 'work', new \DateTimeImmutable(self::NOON)));
        $this->em()->flush();

        $this->refuse($this->liveRequestOfKind($card, 'work'), 'failed', '2026-10-02 12:40:00');
        $this->evaluate($card, '2026-10-02 12:41:00');

        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([CardPauseKind::Retries, 'failed', 'work'], [$pause->kind, $pause->reason, $pause->ruleId]);
        self::assertFalse($this->ruleState($card, 'work')->repaired);
    }

    public function test_a_done_repair_fires_the_rule_once_more_in_the_same_pass(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::REPAIR);
        $repair = $this->repairing($card);

        $this->finish($repair, '2026-10-02 12:50:00');
        $this->evaluate($card, '2026-10-02 12:51:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame('work', $live[0]->kind);
        $state = $this->ruleState($card, 'work');
        self::assertSame([2, true, $live[0]->id?->toRfc4122()], [$state->attempts, $state->repaired, $state->workRequestId?->toRfc4122()]);

        $this->evaluate($card, '2026-10-02 12:52:00');
        self::assertSame($live, $this->liveRequests($card), 'The read repair does not fire the rule again.');
    }

    public function test_a_last_try_that_finds_its_work_live_fires_once(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::REPAIR);
        $repair = $this->repairing($card);
        $this->finish($repair, '2026-10-02 12:50:00');
        $this->em()->persist(new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, 'work', null, 'work', new \DateTimeImmutable(self::NOON)));
        $this->em()->flush();

        $this->evaluate($card, '2026-10-02 12:51:00');
        self::assertSame(['work'], $this->firedRules());
        $records = \count($this->firedRecords());

        $this->evaluate($card, '2026-10-02 12:52:00');
        self::assertCount($records, $this->firedRecords(), 'The read repair does not fire the rule again.');
        self::assertNull($this->activePause($card));
    }

    public function test_a_last_try_whose_action_refuses_pauses_the_card_with_no_further_try(): void
    {
        $rule = ['id' => 'work', 'slot' => 'one', 'when' => self::ALWAYS, 'then' => ['request' => ['kind' => 'work', 'document' => ['tag' => 'missing']]]];
        $card = $this->boundCard([$rule], backoffMinutes: [10, 60, 360], onWorkFailed: self::REPAIR);
        $this->evaluate($card);
        $state = $this->ruleState($card, 'work');
        $state->attempts = 2;
        $state->repaired = true;
        $state->dueAt = new \DateTimeImmutable('2026-10-02 12:50:00');
        $this->em()->flush();

        $this->evaluate($card, '2026-10-02 12:51:00');

        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([CardPauseKind::Retries, 'document-not-found'], [$pause->kind, $pause->reason]);
    }

    public function test_a_refused_repair_pauses_the_card_with_the_repair_failed_code(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::REPAIR);
        $repair = $this->repairing($card);

        $this->refuse($repair, 'failed', '2026-10-02 12:50:00');
        $this->evaluate($card, '2026-10-02 12:51:00');

        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([CardPauseKind::Retries, 'repair-failed', 'work'], [$pause->kind, $pause->reason, $pause->ruleId]);
        self::assertSame('repair-failed', $this->ruleState($card, 'work')->lastRefusal);
        self::assertSame([], $this->liveRequests($card));
    }

    public function test_a_failed_last_try_after_a_repair_pauses_the_card_with_its_own_code(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::REPAIR);
        $this->finish($this->repairing($card), '2026-10-02 12:50:00');
        $this->evaluate($card, '2026-10-02 12:51:00');

        $this->refuse($this->liveRequests($card)[0], 'timeout', '2026-10-02 13:00:00');
        $this->evaluate($card, '2026-10-02 13:01:00');

        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([CardPauseKind::Retries, 'timeout', 'work'], [$pause->kind, $pause->reason, $pause->ruleId]);
        self::assertSame([], $this->liveRequests($card), 'The card gets one repair.');
    }

    public function test_a_live_repair_holds_the_rule_when_its_facts_change(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::NOT_EPIC)], onWorkFailed: self::REPAIR);
        $repair = $this->repairing($card);
        $fingerprint = $this->ruleState($card, 'work')->fingerprint;

        $this->setType($card, CardType::Bug);
        $this->evaluate($card, '2026-10-02 12:45:00');

        self::assertSame([$repair], $this->liveRequests($card));
        self::assertNotSame($fingerprint, $this->ruleState($card, 'work')->fingerprint);
        self::assertNull($this->activePause($card));
    }

    public function test_a_rule_that_turns_false_cancels_its_live_repair(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::NOT_EPIC)], onWorkFailed: self::REPAIR);
        $repair = $this->repairing($card);

        $this->setType($card, CardType::Epic);
        $this->evaluate($card, '2026-10-02 12:45:00');

        self::assertSame(WorkRequestState::Cancelled, $repair->state);
        self::assertFalse($this->ruleState($card, 'work')->repaired);
    }

    public function test_a_repair_that_no_bridge_takes_expires_and_pauses_the_card(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::REPAIR);
        $repair = $this->repairing($card);

        $this->evaluate($card, '2026-10-02 14:00:00');

        self::assertSame(WorkRequestState::Expired, $repair->state);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([CardPauseKind::WorkTimeout, 'no-bridge-took-work', 'work'], [$pause->kind, $pause->reason, $pause->ruleId]);
    }

    public function test_a_repair_that_expires_pauses_the_card_when_its_rule_expires_its_own_work(): void
    {
        $rule = ['id' => 'work', 'slot' => 'one', 'when' => self::ALWAYS, 'then' => ['request' => ['kind' => 'work', 'onTimeout' => 'expire']]];
        $card = $this->boundCard([$rule], onWorkFailed: self::REPAIR);
        $repair = $this->repairing($card);

        $this->evaluate($card, '2026-10-02 14:00:00');

        self::assertSame(WorkRequestState::Expired, $repair->state);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([CardPauseKind::WorkTimeout, 'no-bridge-took-work'], [$pause->kind, $pause->reason]);
    }

    public function test_a_card_that_leaves_the_slot_cancels_its_repair(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::REPAIR);
        $repair = $this->repairing($card);

        $this->moveTo($card, 'in-progress');
        $this->evaluate($card, '2026-10-02 12:45:00');

        self::assertSame(WorkRequestState::Cancelled, $repair->state);
        self::assertFalse($this->ruleState($card, 'work')->repaired);
    }

    public function test_a_refusal_code_with_no_retry_pauses_at_once_with_no_repair(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: self::REPAIR);
        $this->evaluate($card);

        $this->refuse($this->liveRequests($card)[0], 'needs-person', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');

        self::assertSame(CardPauseKind::WorkStopped, $this->activePause($card)?->kind);
        self::assertSame([], $this->liveRequests($card));
        self::assertFalse($this->ruleState($card, 'work')->repaired);
    }

    public function test_a_retries_pause_stays_with_the_same_facts_and_releases_when_they_change_and_the_rule_fires_again(): void
    {
        $card = $this->boundCard([self::moveRule('stuck', 'three', self::NOT_EPIC)], backoffMinutes: []);
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);

        $this->evaluate($card, '2026-10-02 13:00:00');
        self::assertNull($pause->releasedAt);

        $this->setType($card, CardType::Bug);
        $this->evaluate($card, '2026-10-02 13:01:00');

        self::assertSame('facts-changed', $pause->releaseReason);
        $next = $this->activePause($card);
        self::assertNotNull($next, 'The rule fired again at once and its first refusal paused the card again.');
        self::assertNotSame($pause, $next);
        self::assertSame(1, $this->ruleState($card, 'stuck')->attempts);
    }

    public function test_a_rule_pause_releases_when_its_until_is_met(): void
    {
        $card = $this->boundCard([[
            'id' => 'hold',
            'slot' => 'one',
            'when' => self::ALWAYS,
            'then' => ['pause' => ['reason' => 'on-hold', 'until' => ['card.type' => ['type' => 'bug']]]],
        ]]);

        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([CardPauseKind::Rule, 'on-hold'], [$pause->kind, $pause->reason]);

        $this->evaluate($card, '2026-10-02 12:30:00');
        self::assertNull($pause->releasedAt);

        $this->setType($card, CardType::Bug);
        $this->evaluate($card, '2026-10-02 12:31:00');

        self::assertSame('until-met', $pause->releaseReason);
        self::assertNull($this->activePause($card), 'The pause rule stays true, so it does not fire again.');
    }

    public function test_the_request_limit_pauses_and_the_work_limit_pause_stays_until_the_card_leaves_the_slot(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['card.type' => ['type' => 'bug']], limit: 1)]);
        $this->setType($card, CardType::Bug);
        $this->evaluate($card);
        $this->setType($card, CardType::Feature);
        $this->evaluate($card);
        $this->setType($card, CardType::Bug);
        $this->evaluate($card);

        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([CardPauseKind::WorkLimit, 'work-limit-reached', 'fix'], [$pause->kind, $pause->reason, $pause->ruleId]);

        $this->setType($card, CardType::Security);
        $this->evaluate($card);
        self::assertNull($pause->releasedAt);

        $this->moveTo($card, 'in-progress');
        $this->evaluate($card);
        self::assertSame('left-slot', $pause->releaseReason);
    }

    public function test_a_healthy_pull_request_refills_the_fix_budget_so_routine_fixes_never_pause_the_card(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 2, refill: true)]);
        $pullRequest = $this->pullRequest($card);
        $minute = 0;
        for ($round = 1; $round <= 4; ++$round) {
            $pullRequest->checks = PullRequestChecks::Failed;
            $this->em()->flush();
            $this->evaluate($card, \sprintf('2026-10-02 12:%02d:00', ++$minute));
            $live = $this->liveRequests($card);
            self::assertCount(1, $live, 'Round '.$round);
            self::assertSame(1, $this->ruleState($card, 'fix')->fires);
            $this->finish($live[0]);

            $pullRequest->checks = PullRequestChecks::Pending;
            $this->em()->flush();
            $this->evaluate($card, \sprintf('2026-10-02 12:%02d:00', ++$minute));
            self::assertSame(1, $this->ruleState($card, 'fix')->fires, 'A push alone refills nothing.');

            $pullRequest->checks = PullRequestChecks::Passed;
            $this->em()->flush();
            $this->evaluate($card, \sprintf('2026-10-02 12:%02d:00', ++$minute));
            self::assertSame(0, $this->ruleState($card, 'fix')->fires);
        }
        self::assertNull($this->activePause($card));
    }

    public function test_a_failing_loop_with_no_healthy_state_still_hits_the_fix_limit(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 1, refill: true)]);
        $pullRequest = $this->pullRequest($card);
        $pullRequest->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);
        $pullRequest->checks = PullRequestChecks::Pending;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:10:00');
        $pullRequest->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:20:00');

        self::assertSame(CardPauseKind::WorkLimit, $this->activePause($card)?->kind);
    }

    public function test_a_healthy_pull_request_does_not_refill_the_budget_of_another_one_that_is_broken(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 3, refill: true)]);
        [$base, $upper] = $this->stack($card);
        $base->checks = PullRequestChecks::Failed;
        $upper->checks = PullRequestChecks::Passed;
        $this->em()->flush();
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);
        $base->checks = PullRequestChecks::Pending;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:10:00');
        $base->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:20:00');

        self::assertSame(2, $this->ruleState($card, 'fix')->fires);
    }

    public function test_a_work_limit_pause_lifts_when_the_pull_request_turns_healthy(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 1, refill: true)]);
        $pullRequest = $this->pullRequest($card);
        $pullRequest->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);
        $pullRequest->checks = PullRequestChecks::Pending;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:10:00');
        $pullRequest->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:20:00');
        $pause = $this->activePause($card);
        self::assertSame(CardPauseKind::WorkLimit, $pause?->kind);

        $pullRequest->checks = PullRequestChecks::Passed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('refilled', $pause->releaseReason);
        self::assertNull($this->activePause($card));
        self::assertSame(0, $this->ruleState($card, 'fix')->fires);
    }

    public function test_a_work_limit_pause_with_no_refill_stays_when_the_pull_request_turns_healthy(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 1)]);
        $pullRequest = $this->pullRequest($card);
        $pullRequest->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);
        $pullRequest->checks = PullRequestChecks::Pending;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:10:00');
        $pullRequest->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:20:00');
        $pullRequest->checks = PullRequestChecks::Passed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame(CardPauseKind::WorkLimit, $this->activePause($card)?->kind);
    }

    public function test_a_retries_pause_that_a_person_releases_fires_the_rule_again_with_a_fresh_budget(): void
    {
        $card = $this->boundCard([self::moveRule('stuck', 'three', self::NOT_EPIC)], backoffMinutes: []);
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);

        $this->releaseByPerson($card);
        $this->evaluate($card, '2026-10-02 13:00:00');

        self::assertSame(['stuck'], $this->firedRules());
        $next = $this->activePause($card);
        self::assertNotNull($next, 'The rule fired again and its first refusal paused the card again.');
        self::assertNotSame((string) $pause->id, (string) $next->id);
        self::assertSame(1, $this->ruleState($card, 'stuck')->attempts);
    }

    public function test_a_work_limit_pause_that_a_person_releases_lets_the_rule_request_work_again(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['card.type' => ['type' => 'bug']], limit: 1)]);
        $this->setType($card, CardType::Bug);
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);
        $this->setType($card, CardType::Feature);
        $this->evaluate($card);
        $this->setType($card, CardType::Bug);
        $this->evaluate($card);
        self::assertSame(CardPauseKind::WorkLimit, $this->activePause($card)?->kind);

        $this->releaseByPerson($card);
        $this->evaluate($card, '2026-10-02 13:00:00');

        self::assertNull($this->activePause($card));
        self::assertCount(1, $this->liveRequests($card));
        self::assertSame(1, $this->ruleState($card, 'fix')->fires);
    }

    public function test_a_paused_card_runs_only_its_release_rules(): void
    {
        $card = $this->boundCard(self::holdRules());
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);

        $this->setType($card, CardType::Security);
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertNull($pause->releasedAt);
        self::assertSame([], $this->liveRequests($card));
        self::assertNull($this->ruleStateOrNull($card, 'work'));
        self::assertNotNull($this->ruleState($card, 'unhold')->fingerprint);
    }

    public function test_a_release_rule_that_lifts_the_pause_lets_the_other_rules_fire_in_the_same_evaluation(): void
    {
        $card = $this->boundCard(self::holdRules());
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);

        $this->setType($card, CardType::Bug);
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame(ReleasePause::RELEASE_REASON, $pause->releaseReason);
        self::assertNull($this->activePause($card), 'The pause rule stays true, so it does not fire again.');
        self::assertCount(1, $this->liveRequests($card));
        self::assertSame(1, $this->ruleState($card, 'work')->fires);
    }

    public function test_a_release_rule_that_was_true_before_the_pause_still_releases_it(): void
    {
        $card = $this->boundCard([
            ['id' => 'unhold', 'when' => ['card.type' => ['type' => 'bug']], 'then' => ['release' => ['reason' => 'on-hold']]],
            [
                'id' => 'hold',
                'slot' => 'one',
                'when' => ['card.type' => ['type' => 'bug']],
                'then' => ['pause' => ['reason' => 'on-hold', 'until' => ['card.type' => ['type' => 'epic']]]],
            ],
        ]);
        $this->setType($card, CardType::Bug);
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertTrue($this->ruleState($card, 'unhold')->truth);

        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame(ReleasePause::RELEASE_REASON, $pause->releaseReason);
    }

    public function test_a_second_release_rule_does_not_fire_after_the_first_lifts_the_pause(): void
    {
        $unhold = static fn (string $id): array => ['id' => $id, 'when' => ['card.type' => ['type' => 'bug']], 'then' => ['release' => ['reason' => 'on-hold']]];
        $card = $this->boundCard([
            $unhold('unhold-a'),
            $unhold('unhold-b'),
            [
                'id' => 'hold',
                'slot' => 'one',
                'when' => ['card.type' => ['type' => 'bug']],
                'then' => ['pause' => ['reason' => 'on-hold', 'until' => ['card.type' => ['type' => 'epic']]]],
            ],
        ]);
        $this->setType($card, CardType::Bug);
        $this->evaluate($card);
        self::assertNotNull($this->activePause($card));

        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertNull($this->activePause($card));
        self::assertSame(['unhold-a'], $this->firedRules());
    }

    public function test_a_pause_ends_the_evaluation_before_the_release_check_and_any_move(): void
    {
        $card = $this->boundCard([
            self::requestRule('work', ['card.type' => ['type' => 'bug']]),
            self::moveRule('advance', 'two', ['card.type' => ['type' => 'feature']]),
        ]);
        $this->setType($card, CardType::Bug);
        $this->evaluate($card);
        $this->setType($card, CardType::Feature);

        $this->evaluate($card, '2026-10-02 14:00:00');

        self::assertSame('next', $card->column->slug);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame(CardPauseKind::WorkTimeout, $pause->kind);
        self::assertCount(1, $this->paused);
    }

    public function test_a_request_that_is_already_live_does_not_count_toward_the_limit(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['card.type' => ['type' => 'bug']], limit: 2)]);
        foreach ([CardType::Bug, CardType::Feature, CardType::Bug, CardType::Feature, CardType::Bug] as $type) {
            $this->setType($card, $type);
            $this->evaluate($card);
        }

        self::assertCount(1, $this->liveRequests($card));
        self::assertSame(1, $this->ruleState($card, 'fix')->fires);
        self::assertNull($this->activePause($card));
    }

    public function test_a_fix_push_with_no_new_review_opens_no_second_fix_round(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['pr.changes_requested' => []], limit: 3)]);
        $pullRequest = $this->pullRequest($card, headSha: 'a');
        $this->requestChangesOnHead($pullRequest);
        $this->evaluate($card);
        $first = $this->liveRequests($card);
        self::assertCount(1, $first);
        $this->finish($first[0]);

        $pullRequest->headSha = 'b';
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame(WorkRequestState::Done, $first[0]->state);
        self::assertSame([], $this->liveRequests($card));
        $state = $this->ruleState($card, 'fix');
        self::assertFalse($state->truth, 'The old review no longer covers the head, so the edge resets.');
        self::assertSame(1, $state->fires);
    }

    public function test_a_second_changes_requested_review_on_a_new_head_opens_a_second_fix_round(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['pr.changes_requested' => []], limit: 3)]);
        $pullRequest = $this->pullRequest($card, headSha: 'a');
        $this->requestChangesOnHead($pullRequest);
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);
        $pullRequest->headSha = 'b';
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');

        $this->requestChangesOnHead($pullRequest);
        $this->evaluate($card, '2026-10-02 13:00:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame('fix', $live[0]->ruleId);
        self::assertSame(2, $this->ruleState($card, 'fix')->fires);
        self::assertNull($this->activePause($card));
    }

    public function test_a_fix_rule_on_a_stack_fixes_the_base_first_and_then_the_pull_request_stacked_on_it(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 3)]);
        [$base, $upper] = $this->stack($card);
        $base->checks = PullRequestChecks::Failed;
        $upper->checks = PullRequestChecks::Failed;
        $this->em()->flush();

        $this->evaluate($card);
        $state = $this->ruleState($card, 'fix');
        self::assertTrue($base->id?->equals($state->subjectPullRequestId));
        self::assertSame(1, $state->fires);
        $first = $this->liveRequests($card)[0];
        self::assertSame($base->number, $first->context->pullRequestNumber);
        $this->finish($first);

        $base->checks = PullRequestChecks::Passed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');

        $state = $this->ruleState($card, 'fix');
        self::assertTrue($upper->id?->equals($state->subjectPullRequestId));
        $live = $this->liveRequests($card);
        self::assertCount(1, $live, 'The rule stayed true, and the new subject fired it again.');
        self::assertSame($upper->number, $live[0]->context->pullRequestNumber);
        self::assertSame(1, $state->fires, 'A new subject starts a fresh fix budget.');
        self::assertEqualsCanonicalizing([
            ['reason' => 'checks-failed', 'pullRequest' => $base->number],
            ['reason' => 'checks-failed', 'pullRequest' => $upper->number],
        ], $this->fixEvents($card));
    }

    public function test_a_new_subject_waits_for_the_live_request_of_the_old_one_to_settle(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 3)]);
        [$base, $upper] = $this->stack($card);
        $base->checks = PullRequestChecks::Failed;
        $upper->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $first = $this->liveRequests($card)[0];

        $base->checks = PullRequestChecks::Passed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');

        $state = $this->ruleState($card, 'fix');
        self::assertTrue($base->id?->equals($state->subjectPullRequestId), 'The live request still serves the base.');
        self::assertSame(1, $state->fires);

        $this->finish($first);
        $this->evaluate($card, '2026-10-02 12:40:00');

        $state = $this->ruleState($card, 'fix');
        self::assertTrue($upper->id?->equals($state->subjectPullRequestId));
        self::assertCount(1, $this->liveRequests($card));
        self::assertNotSame($first, $this->liveRequests($card)[0]);
        self::assertSame(1, $state->fires);
    }

    public function test_a_work_limit_pause_releases_when_the_rule_moves_to_another_pull_request(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 1)]);
        [$base, $upper] = $this->stack($card);
        $base->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);

        $base->checks = PullRequestChecks::Pending;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:10:00');
        $base->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:20:00');
        $pause = $this->activePause($card);
        self::assertSame(CardPauseKind::WorkLimit, $pause?->kind);

        $base->checks = PullRequestChecks::Passed;
        $upper->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('subject-changed', $pause->releaseReason);
        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame($upper->number, $live[0]->context->pullRequestNumber);
        self::assertSame(1, $this->ruleState($card, 'fix')->fires);
    }

    public function test_a_work_limit_pause_released_for_a_new_subject_waits_for_the_live_request_of_the_old_one(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 1)]);
        [$base, $upper] = $this->stack($card);
        $base->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $first = $this->liveRequests($card)[0];

        $base->checks = PullRequestChecks::Pending;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:10:00');
        $base->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:20:00');
        $pause = $this->activePause($card);
        self::assertSame(CardPauseKind::WorkLimit, $pause?->kind);

        $base->checks = PullRequestChecks::Passed;
        $upper->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');
        self::assertSame('subject-changed', $pause->releaseReason);
        self::assertSame([$first], $this->liveRequests($card));
        self::assertTrue($base->id?->equals($this->ruleState($card, 'fix')->subjectPullRequestId));
        self::assertSame(1, $this->ruleState($card, 'fix')->fires, 'The base keeps its count while its request is live.');

        $this->finish($first);
        $this->evaluate($card, '2026-10-02 12:40:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame($upper->number, $live[0]->context->pullRequestNumber);
        self::assertSame(1, $this->ruleState($card, 'fix')->fires);
        self::assertNull($this->activePause($card));
    }

    public function test_a_work_limit_pause_released_from_a_hold_ends_when_the_rule_moves_to_another_pull_request(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 1)]);
        [$base, $upper] = $this->stack($card);
        $base->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);

        $base->checks = PullRequestChecks::Pending;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:10:00');
        $base->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:20:00');
        $pause = $this->activePause($card);
        self::assertSame(CardPauseKind::WorkLimit, $pause?->kind);

        $this->hold($card);
        $base->checks = PullRequestChecks::Passed;
        $upper->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->releaseHold($card, '2026-10-02 12:25:00');
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('subject-changed', $pause->releaseReason);
        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame($upper->number, $live[0]->context->pullRequestNumber);
        $state = $this->ruleState($card, 'fix');
        self::assertTrue($upper->id?->equals($state->subjectPullRequestId));
        self::assertSame(1, $state->fires);
    }

    public function test_a_work_limit_pause_released_from_a_hold_stays_on_the_same_pull_request(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['card.type' => ['type' => 'bug']], limit: 1)]);
        $this->setType($card, CardType::Bug);
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);
        $this->setType($card, CardType::Feature);
        $this->evaluate($card, '2026-10-02 12:30:00');
        $this->setType($card, CardType::Bug);
        $this->evaluate($card, '2026-10-02 12:31:00');
        $pause = $this->activePause($card);
        self::assertSame(CardPauseKind::WorkLimit, $pause?->kind);
        $this->hold($card);

        $this->releaseHold($card, '2026-10-02 12:40:00');
        $this->evaluate($card, '2026-10-02 12:41:00');

        self::assertNull($pause->releasedAt);
        self::assertSame([], $this->liveRequests($card));
        self::assertSame(0, $this->ruleState($card, 'fix')->fires);
    }

    public function test_a_rule_that_turns_false_before_it_moves_to_another_pull_request_starts_a_fresh_budget(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], limit: 1)]);
        [$base, $upper] = $this->stack($card);
        $base->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);

        $base->checks = PullRequestChecks::Passed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:10:00');
        $upper->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:20:00');

        self::assertNull($this->activePause($card));
        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame($upper->number, $live[0]->context->pullRequestNumber);
        self::assertSame(1, $this->ruleState($card, 'fix')->fires);
    }

    public function test_a_rule_pause_reads_its_until_on_the_pull_request_it_paused(): void
    {
        $card = $this->boundCard([[
            'id' => 'hold',
            'slot' => 'one',
            'when' => ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]],
            'then' => ['pause' => ['reason' => 'red', 'until' => ['pr.checks_passed' => []]]],
        ]]);
        [$base, $upper] = $this->stack($card);
        $base->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertSame(CardPauseKind::Rule, $pause?->kind);

        $base->checks = PullRequestChecks::Passed;
        $upper->checks = PullRequestChecks::Failed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame('until-met', $pause->releaseReason);
    }

    public function test_a_true_rule_with_no_stored_subject_adopts_its_subject_and_fires_nothing(): void
    {
        $card = $this->boundCard([self::requestRule('fix', ['pr.checks_failed' => []], limit: 3)]);
        $pullRequest = $this->pullRequest($card);
        $pullRequest->checks = PullRequestChecks::Failed;
        $state = new WorkflowRuleState($card, $card->project, 'fix', new \DateTimeImmutable(self::NOON));
        $state->truth = true;
        $state->fires = 2;
        $this->em()->persist($state);
        $this->em()->flush();

        $this->evaluate($card, '2026-10-02 12:30:00');

        self::assertSame([], $this->liveRequests($card));
        $state = $this->ruleState($card, 'fix');
        self::assertSame(2, $state->fires);
        self::assertTrue($pullRequest->id?->equals($state->subjectPullRequestId));
    }

    public function test_a_merge_rule_binds_the_base_of_a_stack_although_the_stacked_pull_request_opened_later(): void
    {
        $card = $this->boundCard([self::requestRule('merge', ['all' => [['pr.open' => []], ['pr.base_is_merge_target' => []]]])]);
        [$base] = $this->stack($card);

        $this->evaluate($card);

        self::assertCount(1, $this->liveRequests($card));
        self::assertTrue($base->id?->equals($this->ruleState($card, 'merge')->subjectPullRequestId));
    }

    /** @return array{ForgePullRequest, ForgePullRequest} a base pull request and one stacked on it, opened later */
    private function stack(Card $card): array
    {
        $base = $this->pullRequest($card, head: 'base-branch');
        $base->openedAt = new \DateTimeImmutable('2026-10-01 09:00:00');
        $upper = $this->pullRequest($card, base: 'base-branch', head: 'upper-branch');
        $upper->openedAt = new \DateTimeImmutable('2026-10-01 10:00:00');
        $this->em()->flush();

        return [$base, $upper];
    }

    /** @return list<array<string, mixed>> */
    private static function holdRules(): array
    {
        return [
            [
                'id' => 'hold',
                'slot' => 'one',
                'when' => self::ALWAYS,
                'then' => ['pause' => ['reason' => 'on-hold', 'until' => ['card.type' => ['type' => 'epic']]]],
            ],
            self::requestRule('work', self::ALWAYS),
            ['id' => 'unhold', 'when' => ['card.type' => ['type' => 'bug']], 'then' => ['release' => ['reason' => 'on-hold']]],
        ];
    }

    public function test_a_successful_move_stops_the_loop(): void
    {
        $card = $this->boundCard([
            self::moveRule('advance', 'two', self::ALWAYS),
            ['id' => 'work', 'when' => self::ALWAYS, 'then' => ['request' => ['kind' => 'implement']]],
        ]);

        $this->evaluate($card);

        self::assertSame('in-progress', $card->column->slug);
        self::assertSame([], $this->liveRequests($card));
        self::assertNull($this->ruleStateOrNull($card, 'work'));
    }

    public function test_an_open_request_past_the_timeout_expires_and_pauses_the_card(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        $request = $this->liveRequests($card)[0];

        $this->evaluate($card, '2026-10-02 13:59:00');
        self::assertSame(WorkRequestState::Open, $request->state);

        $this->evaluate($card, '2026-10-02 14:00:00');

        self::assertSame(WorkRequestState::Expired, $request->state);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        self::assertSame([CardPauseKind::WorkTimeout, 'no-bridge-took-work', 'work'], [$pause->kind, $pause->reason, $pause->ruleId]);
        self::assertCount(1, $this->paused);

        $this->evaluate($card, '2026-10-02 14:30:00');
        self::assertNull($pause->releasedAt);
        self::assertSame([], $this->liveRequests($card));
    }

    public function test_an_open_request_that_expires_with_no_pause_leaves_the_card_running(): void
    {
        $rule = self::requestRule('work', self::ALWAYS);
        $rule['then']['request']['onTimeout'] = 'expire';
        $card = $this->boundCard([$rule]);
        $this->evaluate($card);
        $request = $this->liveRequests($card)[0];

        $this->evaluate($card, '2026-10-02 14:00:00');

        self::assertSame(WorkRequestState::Expired, $request->state);
        self::assertNull($this->activePause($card));
        self::assertSame([], $this->paused);
        self::assertSame([], $this->liveRequests($card));
    }

    public function test_a_reopened_request_counts_its_timeout_from_the_reopen(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        $request = $this->liveRequests($card)[0];
        $request->reopenedAt = new \DateTimeImmutable('2026-10-02 13:30:00');
        $this->em()->flush();

        $this->evaluate($card, '2026-10-02 15:29:00');
        self::assertSame(WorkRequestState::Open, $request->state);
        self::assertNull($this->activePause($card));

        $this->evaluate($card, '2026-10-02 15:30:00');
        self::assertSame(WorkRequestState::Expired, $request->state);
    }

    public function test_a_live_request_whose_rule_left_the_template_is_cancelled(): void
    {
        $card = $this->boundCard([]);
        $request = new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, 'implement', null, 'gone', new \DateTimeImmutable(self::NOON));
        $this->em()->persist($request);
        $this->em()->flush();

        $this->evaluate($card);

        self::assertSame(WorkRequestState::Cancelled, $request->state);
    }

    public function test_a_pause_whose_rule_left_the_template_is_released(): void
    {
        $card = $this->boundCard([]);
        $pause = new CardPause($card, $card->project, 'on-hold', 'gone', CardPauseKind::Rule, new \DateTimeImmutable(self::NOON));
        $this->em()->persist($pause);
        $this->em()->flush();

        $this->evaluate($card);

        self::assertSame('rule-removed', $pause->releaseReason);
    }

    public function test_a_refusal_time_clears_when_the_rule_turns_false(): void
    {
        $card = $this->boundCard([self::moveRule('stuck', 'three', self::NOT_EPIC)]);
        $this->evaluate($card);
        self::assertNotNull($this->ruleState($card, 'stuck')->lastRefusalAt);

        $this->setType($card, CardType::Epic);
        $this->evaluate($card, '2026-10-02 12:01:00');

        $state = $this->ruleState($card, 'stuck');
        self::assertSame([null, null], [$state->lastRefusal, $state->lastRefusalAt]);
    }

    public function test_a_held_card_fires_nothing_and_settles_no_request_until_the_hold_goes(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $stale = new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, 'implement', null, 'gone', new \DateTimeImmutable(self::NOON));
        $this->em()->persist($stale);
        $this->em()->flush();
        $this->hold($card);

        $this->evaluate($card);

        self::assertSame(WorkRequestState::Open, $stale->state);
        self::assertSame([], $this->service(WorkflowRuleStateRepository::class)->findForCard($card));
        self::assertSame([], $this->firedRecords());

        $this->dropHold($card);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame(WorkRequestState::Cancelled, $stale->state);
        self::assertCount(1, $this->liveRequests($card));
    }

    public function test_a_release_fires_every_rule_that_matches_the_card_now(): void
    {
        $card = $this->boundCard([
            self::requestRule('work', self::ALWAYS),
            self::requestRule('fix', ['card.type' => ['type' => 'bug']]),
            self::moveRule('stuck', 'three', self::NOT_EPIC),
        ]);
        $this->hold($card);
        $work = new WorkflowRuleState($card, $card->project, 'work');
        $work->truth = true;
        $work->fires = 1;
        $stuck = new WorkflowRuleState($card, $card->project, 'stuck');
        $stuck->truth = true;
        $stuck->attempts = 2;
        $stuck->dueAt = new \DateTimeImmutable('2026-10-02 18:00:00');
        $stuck->lastRefusal = 'workflow-slot-missing';
        $stuck->lastRefusalAt = new \DateTimeImmutable(self::NOON);
        $this->em()->persist($work);
        $this->em()->persist($stuck);
        $this->em()->flush();

        $this->releaseHold($card);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame(['work', 'stuck'], $this->firedRules());
        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame('work', $live[0]->ruleId);
        self::assertSame(1, $this->ruleState($card, 'work')->fires);
        self::assertFalse($this->ruleState($card, 'fix')->truth);
        $state = $this->ruleState($card, 'stuck');
        self::assertSame([true, 1, '2026-10-02 12:11:00', 'workflow-slot-missing'], [$state->truth, $state->attempts, $state->dueAt?->format('Y-m-d H:i:s'), $state->lastRefusal]);
    }

    public function test_a_release_fires_the_rules_of_a_card_that_got_a_baseline_mark_while_held(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->hold($card);
        $this->saveAutomation($card, false);
        $this->saveAutomation($card, true);
        self::assertTrue($this->service(WorkflowPendingBaselineRepository::class)->isMarked($card->id ?? throw new \LogicException('A flushed card has an id.')));

        $this->releaseHold($card);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame(['work'], $this->firedRules());
        self::assertCount(1, $this->liveRequests($card));
    }

    public function test_a_release_opens_no_second_request_while_one_is_live(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        $request = $this->liveRequests($card)[0];
        $this->hold($card);

        $this->releaseHold($card, '2026-10-02 12:01:00');
        $this->evaluate($card, '2026-10-02 12:02:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame($request, $live[0]);
        self::assertSame(WorkRequestState::Open, $request->state);
    }

    public function test_a_release_opens_a_new_request_when_the_last_one_settled(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        $first = $this->liveRequests($card)[0];
        $this->finish($first);
        $this->evaluate($card, '2026-10-02 12:30:00');
        self::assertSame([], $this->liveRequests($card), 'A rule that stays true fires once.');
        $this->hold($card);

        $this->releaseHold($card, '2026-10-02 12:40:00');
        $this->evaluate($card, '2026-10-02 12:41:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertNotSame($first, $live[0]);
    }

    public function test_a_release_gives_a_limited_rule_a_fresh_work_budget(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS, limit: 1)]);
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);
        self::assertSame(1, $this->ruleState($card, 'work')->fires);
        $this->hold($card);

        $this->releaseHold($card, '2026-10-02 12:40:00');
        $this->evaluate($card, '2026-10-02 12:41:00');

        self::assertNull($this->activePause($card));
        self::assertCount(1, $this->liveRequests($card));
        self::assertSame(1, $this->ruleState($card, 'work')->fires);
    }

    public function test_a_release_fires_a_move_rule_and_the_move_ends_the_pass(): void
    {
        $card = $this->boundCard([
            self::moveRule('advance', 'two', self::ALWAYS),
            self::requestRule('work', self::ALWAYS),
        ]);
        $this->hold($card);
        $advance = new WorkflowRuleState($card, $card->project, 'advance');
        $advance->truth = true;
        $this->em()->persist($advance);
        $this->em()->flush();

        $this->releaseHold($card);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame('in-progress', $card->column->slug);
        self::assertSame(['advance'], $this->firedRules());
        self::assertSame([], $this->liveRequests($card));
        self::assertNull($this->ruleStateOrNull($card, 'work'));
    }

    public function test_a_release_restarts_the_timeout_of_an_open_request(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        $request = $this->liveRequests($card)[0];
        $this->hold($card);

        $this->releaseHold($card, '2026-10-02 14:30:00');
        $this->evaluate($card, '2026-10-02 15:00:00');

        self::assertSame(WorkRequestState::Open, $request->state);
        self::assertNull($this->activePause($card));

        $this->evaluate($card, '2026-10-02 16:30:00');

        self::assertSame(WorkRequestState::Expired, $request->state);
        self::assertSame(CardPauseKind::WorkTimeout, $this->activePause($card)?->kind);
    }

    public function test_a_release_keeps_a_retries_pause_whose_facts_did_not_change(): void
    {
        $card = $this->boundCard([self::moveRule('stuck', 'three', self::NOT_EPIC)], backoffMinutes: []);
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        $fired = $this->firedRecords();
        $this->hold($card);

        $this->releaseHold($card, '2026-10-02 12:01:00');
        $this->evaluate($card, '2026-10-02 12:02:00');

        self::assertNull($pause->releasedAt);
        self::assertSame($fired, $this->firedRecords());
    }

    public function test_a_release_cancels_a_request_whose_rule_no_longer_applies(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        $request = $this->liveRequests($card)[0];
        $this->hold($card);
        $this->moveTo($card, 'done');
        $this->evaluate($card, '2026-10-02 12:01:00');
        self::assertSame(WorkRequestState::Open, $request->state);
        $fired = $this->firedRecords();

        $this->releaseHold($card);
        $this->evaluate($card, '2026-10-02 12:02:00');

        self::assertSame(WorkRequestState::Cancelled, $request->state);
        self::assertSame($fired, $this->firedRecords());
    }

    public function test_a_release_resets_the_rules_of_the_slot_the_card_left_so_they_fire_on_return(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        self::assertTrue($this->ruleState($card, 'work')->truth);
        $this->hold($card);
        $this->moveTo($card, 'in-progress');

        $this->releaseHold($card);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertFalse($this->ruleState($card, 'work')->truth);
        self::assertSame(0, $this->ruleState($card, 'work')->fires);

        $this->moveTo($card, 'next');
        $this->evaluate($card, '2026-10-02 12:02:00');

        self::assertCount(1, $this->liveRequests($card));
    }

    public function test_a_retries_pause_whose_facts_changed_while_held_releases_and_the_rule_fires_again(): void
    {
        $card = $this->boundCard([self::moveRule('stuck', 'three', self::NOT_EPIC)], backoffMinutes: []);
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        $this->hold($card);
        $this->setType($card, CardType::Bug);

        $this->releaseHold($card);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame('facts-changed', $pause->releaseReason);
        self::assertSame(['stuck'], $this->firedRules());
        $next = $this->activePause($card);
        self::assertNotNull($next, 'The rule fired again and its first refusal paused the card again.');
        self::assertNotSame($pause, $next);
    }

    public function test_a_card_held_again_right_after_a_release_fires_its_rules_when_the_hold_goes(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        $this->finish($this->liveRequests($card)[0]);
        $this->hold($card);
        $this->releaseHold($card);
        $this->hold($card);

        $this->evaluate($card, '2026-10-02 12:30:00');
        self::assertSame([], $this->liveRequests($card));

        $this->dropHold($card);
        $this->evaluate($card, '2026-10-02 12:31:00');

        self::assertCount(1, $this->liveRequests($card));
        self::assertSame(1, $this->ruleState($card, 'work')->fires);
    }

    public function test_a_card_whose_board_automation_is_off_fires_nothing_and_turning_it_on_again_is_quiet(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS), self::requestRule('fix', ['card.type' => ['type' => 'bug']])]);
        $this->saveAutomation($card, false);

        $this->evaluate($card);

        self::assertSame([], $this->liveRequests($card));
        self::assertSame([], $this->service(WorkflowRuleStateRepository::class)->findForCard($card));

        $this->saveAutomation($card, true);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame([], $this->liveRequests($card));
        self::assertSame([], $this->firedRecords());
        self::assertTrue($this->ruleState($card, 'work')->truth);

        $this->setType($card, CardType::Bug);
        $this->evaluate($card, '2026-10-02 12:02:00');

        self::assertSame(['fix'], $this->firedRules());
    }

    public function test_a_project_with_no_workflow_is_left_alone(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('engine-unbound'), 'next');

        $this->evaluate($card);

        self::assertSame([], $this->service(WorkflowRuleStateRepository::class)->findForCard($card));
        self::assertSame([], $this->liveRequests($card));
    }

    public function test_a_lifecycle_card_with_an_approved_design_moves_to_implementation_and_then_requests_the_implementation(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-lifecycle');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'tech-design');
        $this->document($card, 'tech-design');

        $this->evaluate($card);

        self::assertSame('in-progress', $card->column->slug);
        self::assertSame([], $this->liveRequests($card));

        $this->evaluate($card, '2026-10-02 12:01:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame(['implement', 'implement'], [$live[0]->kind, $live[0]->ruleId]);
    }

    public function test_a_revised_design_document_back_in_review_asks_for_no_new_design(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-design-revision');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'tech-design');
        $design = $this->document($card, 'tech-design', DocumentStatus::ChangesRequested);
        $this->evaluate($card);
        self::assertSame(['tech-design-revise'], $this->requestRuleIds($card));

        $this->setStatus($design, DocumentStatus::InReview);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame('tech-design', $card->column->slug);
        self::assertSame(['tech-design-revise'], $this->requestRuleIds($card));
    }

    public function test_an_undone_design_approval_asks_for_no_new_design(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-design-undo');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'tech-design');
        $this->block($card);
        $design = $this->document($card, 'tech-design');
        $this->evaluate($card);
        self::assertSame('tech-design', $card->column->slug);

        $this->setStatus($design, DocumentStatus::InReview);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame('tech-design', $card->column->slug);
        self::assertSame([], $this->requestRuleIds($card));
    }

    public function test_a_design_approved_straight_after_a_change_request_only_moves_the_card(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-design-approved-after-changes');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'tech-design');
        $design = $this->document($card, 'tech-design', DocumentStatus::ChangesRequested);
        $this->evaluate($card);
        self::assertSame(['tech-design-revise'], $this->requestRuleIds($card));

        $this->setStatus($design, DocumentStatus::Approved);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame('in-progress', $card->column->slug);
        self::assertSame(['tech-design-approved'], $this->firedRules());
        self::assertSame(['tech-design-revise'], $this->requestRuleIds($card));
    }

    public function test_a_revised_product_document_back_in_review_asks_for_no_new_session(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-product-revision');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'product-design');
        $product = $this->document($card, 'product-design', DocumentStatus::ChangesRequested);
        $this->evaluate($card);
        self::assertSame(['product-design-revise'], $this->requestRuleIds($card));

        $this->setStatus($product, DocumentStatus::InReview);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertSame('product-design', $card->column->slug);
        self::assertSame(['product-design-revise'], $this->requestRuleIds($card));
    }

    /** The board updates live from CardChanged, so each change the engine makes to a card dispatches one. */
    public function test_an_engine_move_and_its_work_request_reach_the_live_board(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-live');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'tech-design');
        $this->document($card, 'tech-design');
        $changed = [];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(CardChanged::class, static function (CardChanged $event) use (&$changed): void {
            $changed[] = (string) $event->cardId;
        });

        $this->evaluate($card);
        $afterMove = \count($changed);
        $this->evaluate($card, '2026-10-02 12:01:00');

        self::assertGreaterThanOrEqual(1, $afterMove);
        self::assertGreaterThan($afterMove, \count($changed));
        self::assertSame([(string) $card->id], array_values(array_unique($changed)));
    }

    public function test_a_lifecycle_card_that_a_person_moves_to_the_backlog_stays_there_until_its_pull_request_reopens(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-reopen');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'in-progress');
        $pullRequest = $this->pullRequest($card);
        $this->evaluate($card);

        $this->moveTo($card, 'backlog');
        $this->evaluate($card, '2026-10-02 12:01:00');
        self::assertSame('backlog', $card->column->slug);

        $pullRequest->state = PullRequestState::Closed;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:02:00');
        self::assertSame('backlog', $card->column->slug);

        $pullRequest->state = PullRequestState::Open;
        $this->em()->flush();
        $this->evaluate($card, '2026-10-02 12:03:00');
        self::assertSame('in-progress', $card->column->slug);
    }

    #[DataProvider('shippedTemplates')]
    public function test_a_card_that_reaches_a_terminal_column_requests_one_teardown_per_arrival(string $template): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-teardown');
        if ('lifecycle' === $template) {
            $this->bindLifecycle($project);
        } else {
            $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));
        }
        $card = $this->card($project, 'done');

        $this->evaluate($card);
        $this->evaluate($card, '2026-10-02 12:05:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame(['teardown', 'teardown', null], [$live[0]->kind, $live[0]->ruleId, $live[0]->capability]);

        $this->evaluate($card, '2026-10-02 14:05:00');
        self::assertSame(WorkRequestState::Expired, $live[0]->state);
        self::assertNull($this->activePause($card));

        $this->moveTo($card, 'next');
        $this->evaluate($card, '2026-10-02 14:06:00');
        $this->moveTo($card, 'done');
        $this->evaluate($card, '2026-10-02 14:07:00');

        $again = $this->liveRequests($card);
        self::assertCount(1, $again);
        self::assertNotSame($live[0], $again[0]);
    }

    /** @return iterable<string, array{string}> */
    public static function shippedTemplates(): iterable
    {
        yield 'lifecycle' => ['lifecycle'];
        yield 'simple' => ['simple'];
    }

    public function test_a_lifecycle_card_in_an_open_column_whose_pull_requests_all_closed_unmerged_stays_in_its_column(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-lifecycle-closed');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'in-progress');
        $closed = $this->pullRequest($card, PullRequestState::Closed);
        $closed->refreshedAt = new \DateTimeImmutable('2026-10-02 11:00:00');
        $this->em()->flush();

        $this->evaluate($card);

        self::assertSame('in-progress', $card->column->slug);
    }

    public function test_a_later_rule_reads_the_work_request_an_earlier_rule_opened_in_the_same_pass(): void
    {
        $card = $this->boundCard([
            self::requestRule('work', self::ALWAYS),
            self::requestRule('follow', ['run.work_active' => ['kind' => 'work']]),
        ]);

        $this->evaluate($card);

        self::assertSame(['work', 'follow'], array_map(static fn (WorkRequest $request): string => $request->kind, $this->liveRequests($card)));
    }

    public function test_a_lifecycle_epic_with_an_open_child_gets_one_breakdown_per_arrival_in_implementation(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-breakdown');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $this->childOf($epic, 'next');

        $this->evaluate($epic);
        $first = $this->liveRequests($epic);
        self::assertSame([['breakdown', 'breakdown']], array_map(static fn (WorkRequest $request): array => [$request->kind, $request->ruleId], $first));

        $this->childOf($epic, 'next');
        $this->evaluate($epic, '2026-10-02 12:01:00');
        self::assertSame($first, $this->liveRequests($epic));

        $this->moveTo($epic, 'next');
        $this->evaluate($epic, '2026-10-02 12:02:00');
        self::assertSame(WorkRequestState::Cancelled, $first[0]->state);
        $this->moveTo($epic, 'in-progress');
        $this->evaluate($epic, '2026-10-02 12:03:00');

        $second = $this->liveRequests($epic);
        self::assertCount(1, $second);
        self::assertSame('breakdown', $second[0]->kind);
        self::assertNotSame($first[0], $second[0]);
    }

    public function test_a_lifecycle_epic_whose_children_are_finished_waits_for_its_breakdown_before_it_moves_to_done(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-breakdown-done');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $this->childOf($epic, 'done');

        $this->evaluate($epic);
        $breakdown = $this->liveRequests($epic);
        self::assertCount(1, $breakdown);
        self::assertSame('in-progress', $epic->column->slug);

        $breakdown[0]->state = WorkRequestState::Claimed;
        $this->em()->flush();
        $this->evaluate($epic, '2026-10-02 12:05:00');
        self::assertSame('in-progress', $epic->column->slug);

        $this->finish($breakdown[0]);
        $this->evaluate($epic, '2026-10-02 12:25:00');
        self::assertSame('done', $epic->column->slug);
    }

    public function test_a_lifecycle_epic_whose_breakdown_stopped_for_a_person_pauses_with_the_work_stopped_kind(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-breakdown-blocked');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $this->childOf($epic, 'done');
        $this->evaluate($epic);
        $breakdown = $this->liveRequests($epic);
        self::assertCount(1, $breakdown);

        $this->refuse($breakdown[0], 'needs-person', '2026-10-02 12:20:00');
        $this->evaluate($epic, '2026-10-02 12:25:00');

        $pause = $this->activePause($epic);
        self::assertNotNull($pause);
        self::assertSame([CardPauseKind::WorkStopped, 'needs-person', 'breakdown'], [$pause->kind, $pause->reason, $pause->ruleId]);
        self::assertSame('in-progress', $epic->column->slug);
    }

    public function test_a_lifecycle_child_waits_in_the_backlog_while_the_breakdown_of_its_epic_runs_and_moves_when_it_ends(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-breakdown-ended');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $breakdown = $this->workerRun($epic, 'breakdown', WorkerRunState::Running);
        $this->evaluate($epic);
        self::assertFalse($this->ruleState($epic, 'breakdown-ended')->truth);

        $child = $this->childOf($epic, 'backlog');
        $this->evaluate($child, '2026-10-02 12:05:00');
        self::assertSame('backlog', $child->column->slug);

        $breakdown->moveTo(WorkerRunState::Succeeded);
        $this->em()->flush();
        $records = \count($this->firedRecords());
        $this->evaluate($epic, '2026-10-02 12:10:00');
        self::assertCount($records + 1, $this->firedRecords());
        self::assertContains('breakdown-ended', $this->firedRules());

        self::assertSame(1, $this->evaluateQueued($child, '2026-10-02 12:10:00'));
        self::assertSame('in-progress', $child->column->slug);
    }

    public function test_a_lifecycle_epic_child_waits_to_merge_while_a_run_of_its_epic_is_open_and_merges_when_it_ends(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-epic-child-merge-hold');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $run = $this->workerRun($epic, 'fix', WorkerRunState::Running);
        $this->evaluate($epic);
        self::assertFalse($this->ruleState($epic, 'breakdown-ended')->truth);

        $child = $this->childOf($epic, 'in-review');
        $pullRequest = $this->pullRequest($child, base: 'epic/'.$epic->number);
        $pullRequest->checks = PullRequestChecks::Passed;
        $this->em()->flush();
        $this->evaluate($child, '2026-10-02 12:05:00');
        self::assertFalse($this->ruleState($child, 'merge-ready-epic-child')->truth);
        self::assertSame([], array_filter($this->liveRequests($child), static fn (WorkRequest $request): bool => 'merge' === $request->kind));

        $run->moveTo(WorkerRunState::Succeeded);
        $this->em()->flush();
        $this->evaluate($epic, '2026-10-02 12:10:00');
        self::assertContains('breakdown-ended', $this->firedRules());

        self::assertSame(1, $this->evaluateQueued($child, '2026-10-02 12:10:00'));
        self::assertTrue($this->ruleState($child, 'merge-ready-epic-child')->truth);
        $this->liveRequestOfKind($child, 'merge');
    }

    public function test_a_lifecycle_child_with_an_open_blocker_stays_in_the_backlog_when_the_breakdown_of_its_epic_ends(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-breakdown-ended-blocked');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $breakdown = $this->workerRun($epic, 'breakdown', WorkerRunState::Running);
        $this->evaluate($epic);
        $child = $this->childOf($epic, 'backlog');
        $this->block($child);

        $breakdown->moveTo(WorkerRunState::Succeeded);
        $this->em()->flush();
        $this->evaluate($epic, '2026-10-02 12:10:00');

        self::assertSame(1, $this->evaluateQueued($child, '2026-10-02 12:10:00'));
        self::assertSame('backlog', $child->column->slug);
    }

    public function test_a_resumed_breakdown_of_the_epic_still_holds_the_child_in_the_backlog(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-breakdown-resumed');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $this->workerRun($epic, 'breakdown', WorkerRunState::Blocked);
        $resumed = $this->workerRun($epic, 'breakdown', WorkerRunState::Resumed);
        $this->evaluate($epic);
        $child = $this->childOf($epic, 'backlog');

        $this->evaluate($child, '2026-10-02 12:05:00');
        self::assertSame('backlog', $child->column->slug);

        $resumed->moveTo(WorkerRunState::Succeeded);
        $this->em()->flush();
        $this->evaluate($epic, '2026-10-02 12:10:00');

        self::assertSame(1, $this->evaluateQueued($child, '2026-10-02 12:10:00'));
        self::assertSame('in-progress', $child->column->slug);
    }

    public function test_a_lifecycle_child_waits_in_the_backlog_while_any_worker_run_of_its_epic_is_open(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-parent-run-ended');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $run = $this->workerRun($epic, 'implement', WorkerRunState::Running);
        $this->evaluate($epic);
        $child = $this->childOf($epic, 'backlog');

        $this->evaluate($child, '2026-10-02 12:05:00');
        self::assertSame('backlog', $child->column->slug);

        $run->moveTo(WorkerRunState::Succeeded);
        $this->em()->flush();
        $this->evaluate($epic, '2026-10-02 12:10:00');

        self::assertSame(1, $this->evaluateQueued($child, '2026-10-02 12:10:00'));
        self::assertSame('in-progress', $child->column->slug);
    }

    public function test_a_lifecycle_epic_in_a_design_column_wakes_its_children_when_its_run_ends(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-design-run-ended');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $this->moveTo($epic, 'tech-design');
        $run = $this->workerRun($epic, 'tech-design', WorkerRunState::Running);
        $this->evaluate($epic);
        self::assertFalse($this->ruleState($epic, 'breakdown-ended')->truth);
        $child = $this->childOf($epic, 'backlog');
        $this->evaluate($child, '2026-10-02 12:05:00');
        self::assertSame('backlog', $child->column->slug);

        $run->moveTo(WorkerRunState::Succeeded);
        $this->em()->flush();
        $this->evaluate($epic, '2026-10-02 12:10:00');

        self::assertContains('breakdown-ended', $this->firedRules());
        self::assertSame(1, $this->evaluateQueued($child, '2026-10-02 12:10:00'));
        self::assertSame('in-progress', $child->column->slug);
    }

    public function test_a_lifecycle_merged_epic_with_finished_children_waits_for_its_open_run_before_it_moves_to_done(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-merged-epic-run');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $this->moveTo($epic, 'in-review');
        $this->childOf($epic, 'done');
        $this->pullRequest($epic, PullRequestState::Merged);
        $run = $this->workerRun($epic, 'fix', WorkerRunState::Running);

        $this->evaluate($epic);
        self::assertSame('in-review', $epic->column->slug);

        $run->moveTo(WorkerRunState::Succeeded);
        $this->em()->flush();
        $this->evaluate($epic, '2026-10-02 12:10:00');

        self::assertSame('done', $epic->column->slug);
    }

    public function test_a_lifecycle_card_with_a_merged_pull_request_moves_to_done_while_its_run_is_open(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-merged-card-run');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'in-review');
        $this->pullRequest($card, PullRequestState::Merged);
        $this->workerRun($card, 'fix', WorkerRunState::Running);

        $this->evaluate($card);

        self::assertSame('done', $card->column->slug);
    }

    public function test_a_lifecycle_epic_whose_last_child_merged_into_the_epic_branch_fires_the_open_rule_and_stays_out_of_done(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-epic-open');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $child = $this->childOf($epic, 'done');
        $this->pullRequest($child, PullRequestState::Merged, base: 'epic/'.$epic->number);
        $this->evaluate($epic);
        $this->finish($this->liveRequests($epic)[0]);

        $this->evaluate($epic, '2026-10-02 12:25:00');

        self::assertSame('in-progress', $epic->column->slug);
        $state = $this->ruleState($epic, 'epic-open-pull-request');
        self::assertSame('open-epic-off', $state->lastRefusal);
        self::assertNotNull($state->dueAt);
    }

    public function test_a_lifecycle_child_merged_into_the_epic_branch_asks_for_an_epic_preview_and_one_merged_into_main_does_not(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('engine-epic-preview');
        $this->bindLifecycle($project);
        $epic = $this->epic($project);
        $intoEpic = $this->childOf($epic, 'done');
        $this->pullRequest($intoEpic, PullRequestState::Merged, base: 'epic/'.$epic->number);
        $intoMain = $this->childOf($epic, 'done');
        $this->pullRequest($intoMain, PullRequestState::Merged);

        $this->evaluate($intoEpic);
        $this->evaluate($intoMain);

        $kinds = fn (Card $card): array => array_map(static fn (WorkRequest $request): string => $request->kind, $this->liveRequests($card));
        self::assertEqualsCanonicalizing(['epic-preview', 'teardown'], $kinds($intoEpic));
        self::assertSame(['teardown'], $kinds($intoMain));
    }

    public function test_a_card_evaluations_call_evaluates_the_card_and_its_provided_fact_fires_the_rule(): void
    {
        $card = $this->boundCard([self::requestRule('provided', self::PROVIDED_READY)]);
        $this->provider()->facts = new ProvidedFacts(ready: true);
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $this->service(CardEvaluations::class)->forCards([$card->id ?? throw new \LogicException('A flushed card has an id.')]);

        $handler = new EvaluateCardHandler(new EvaluateWorkflowCardHandler($this->service(Engine::class), new MockClock(self::NOON)));
        $evaluations = 0;
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof EvaluateCard && $message->cardId === $card->id->toRfc4122()) {
                $handler($message);
                ++$evaluations;
            }
        }

        self::assertSame(1, $evaluations);
        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame('provided', $live[0]->ruleId);
    }

    public function test_a_refused_rule_fires_again_at_once_when_its_provided_facts_change(): void
    {
        $card = $this->boundCard([self::moveRule('stuck', 'three', self::PROVIDED_READY)]);
        $this->provider()->facts = new ProvidedFacts(ready: true);

        $this->evaluate($card);
        self::assertSame([1, '2026-10-02 12:10:00'], [$this->ruleState($card, 'stuck')->attempts, $this->ruleState($card, 'stuck')->dueAt?->format('Y-m-d H:i:s')]);

        $this->evaluate($card, '2026-10-02 12:05:00');
        self::assertSame(1, $this->ruleState($card, 'stuck')->attempts);

        $this->provider()->facts = new ProvidedFacts(ready: true, version: 2);
        $this->evaluate($card, '2026-10-02 12:06:00');

        self::assertSame(2, $this->ruleState($card, 'stuck')->attempts);
    }

    public function test_a_failing_source_skips_the_rule_that_reads_it_logs_one_error_and_runs_the_later_rules(): void
    {
        $card = $this->boundCard([
            self::requestRule('provided', self::PROVIDED_READY),
            self::requestRule('built-in', self::NOT_EPIC),
        ]);
        $this->provider()->facts = new ProvidedFacts(ready: true);
        $this->provider()->failure = new \RuntimeException('The source is down.');

        $this->evaluate($card);

        self::assertSame(['built-in'], $this->firedRules());
        self::assertNull($this->ruleStateOrNull($card, 'provided'));
        $errors = $this->errors();
        self::assertCount(1, $errors);
        self::assertSame('workflow.fact_source_failed', $errors[0]['message']);
        self::assertSame($this->provider()->failure, $errors[0]['context']['exception'] ?? null);
    }

    public function test_a_facts_class_that_no_provider_gives_skips_the_rule_that_reads_it_and_logs_one_error(): void
    {
        $card = $this->boundCard([
            self::requestRule('unprovided', [UnprovidedFactsReady::KEY => []]),
            self::requestRule('negated', ['not' => [UnprovidedFactsReady::KEY => []]]),
            self::requestRule('work', self::ALWAYS),
        ]);

        $this->evaluate($card);

        self::assertSame(['work'], $this->firedRules());
        self::assertNull($this->ruleStateOrNull($card, 'unprovided'));
        self::assertNull($this->ruleStateOrNull($card, 'negated'));
        $errors = $this->errors();
        self::assertCount(1, $errors);
        self::assertSame('workflow.fact_provider_missing', $errors[0]['message']);
        self::assertSame(\stdClass::class, $errors[0]['context']['factsClass'] ?? null);
    }

    public function test_a_failing_source_logs_once_when_a_withdrawal_rebuilds_the_facts(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        $request = $this->liveRequests($card)[0];

        $this->moveTo($card, 'in-progress');
        $this->provider()->failure = new \RuntimeException('The source is down.');
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame(WorkRequestState::Cancelled, $request->state);
        self::assertCount(1, $this->errors());
    }

    public function test_a_source_that_fails_only_when_a_withdrawal_rebuilds_the_facts_logs_one_error(): void
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)]);
        $this->evaluate($card);
        $request = $this->liveRequests($card)[0];

        $this->moveTo($card, 'in-progress');
        $this->provider()->failure = new \RuntimeException('The source is down.');
        $this->provider()->buildsBeforeFailure = $this->provider()->builds + 1;
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame(WorkRequestState::Cancelled, $request->state);
        $errors = $this->errors();
        self::assertCount(1, $errors);
        self::assertSame('workflow.fact_source_failed', $errors[0]['message']);
    }

    public function test_a_missing_provider_in_a_rule_of_another_slot_logs_nothing(): void
    {
        $card = $this->boundCard([
            ['id' => 'elsewhere', 'slot' => 'two', 'when' => [UnprovidedFactsReady::KEY => []], 'then' => ['request' => ['kind' => 'elsewhere']]],
            self::requestRule('work', self::ALWAYS),
        ]);

        $this->evaluate($card);

        self::assertSame(['work'], $this->firedRules());
        self::assertSame([], $this->errors());
    }

    public function test_a_rule_that_cannot_read_its_facts_after_a_release_fires_once_when_its_source_is_back_and_it_is_true(): void
    {
        $card = $this->boundCard([self::requestRule('provided', self::PROVIDED_READY)]);
        $this->provider()->facts = new ProvidedFacts(ready: true);
        $this->hold($card);
        $this->releaseHold($card);
        $this->provider()->failure = new \RuntimeException('The source is down.');
        $this->evaluate($card);
        self::assertNull($this->ruleStateOrNull($card, 'provided'));

        $this->provider()->failure = null;
        $this->evaluate($card, '2026-10-02 12:05:00');
        $this->evaluate($card, '2026-10-02 12:10:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame('provided', $live[0]->ruleId);
        self::assertSame(1, $this->ruleState($card, 'provided')->fires);
    }

    public function test_a_pause_rule_whose_until_names_a_removed_condition_does_not_pause_the_card(): void
    {
        $card = $this->boundCard([[
            'id' => 'hold',
            'slot' => 'one',
            'when' => self::ALWAYS,
            'then' => ['pause' => ['reason' => 'on-hold', 'until' => ['not' => ['card.gone' => []]]]],
        ], self::requestRule('work', self::ALWAYS)]);

        $this->evaluate($card);

        self::assertNull($this->activePause($card));
        self::assertNull($this->ruleStateOrNull($card, 'hold'));
        self::assertSame(['work'], $this->firedRules());
    }

    public function test_a_pause_rule_with_an_unreadable_until_keeps_its_falling_edge(): void
    {
        $card = $this->boundCard([[
            'id' => 'hold',
            'slot' => 'one',
            'when' => self::NOT_EPIC,
            'then' => ['pause' => ['reason' => 'on-hold', 'until' => self::PROVIDED_READY]],
        ]]);
        $this->provider()->facts = new ProvidedFacts(ready: false);
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);
        $this->provider()->facts = new ProvidedFacts(ready: true);
        $this->evaluate($card, '2026-10-02 12:05:00');
        self::assertSame('until-met', $pause->releaseReason);

        $this->provider()->failure = new \RuntimeException('The source is down.');
        $this->setType($card, CardType::Epic);
        $this->evaluate($card, '2026-10-02 12:10:00');
        self::assertFalse($this->ruleState($card, 'hold')->truth);

        $this->provider()->failure = null;
        $this->provider()->facts = new ProvidedFacts(ready: false);
        $this->setType($card, CardType::Feature);
        $this->evaluate($card, '2026-10-02 12:15:00');
        self::assertNotNull($this->activePause($card));
    }

    public function test_a_source_that_is_off_makes_its_rule_wait_with_no_error(): void
    {
        $card = $this->boundCard([self::requestRule('provided', self::PROVIDED_READY)]);
        $this->provider()->facts = new ProvidedFacts(ready: true);
        $this->provider()->on = false;

        $this->evaluate($card);

        self::assertSame([], $this->liveRequests($card));
        self::assertNull($this->ruleStateOrNull($card, 'provided'));
        self::assertSame([], $this->errors());

        $this->provider()->on = true;
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame(['provided'], $this->firedRules());
    }

    public function test_a_request_rule_waits_when_its_refill_source_is_off(): void
    {
        $rule = self::requestRule('provided', self::NOT_EPIC, limit: 3);
        $rule['then']['request']['refill'] = self::PROVIDED_READY;
        $card = $this->boundCard([$rule]);
        $this->provider()->facts = new ProvidedFacts(ready: true);
        $this->provider()->on = false;

        $this->evaluate($card);

        self::assertSame([], $this->liveRequests($card));
        self::assertNull($this->ruleStateOrNull($card, 'provided'));

        $this->provider()->on = true;
        $this->evaluate($card, '2026-10-02 12:05:00');

        self::assertSame(['provided'], $this->firedRules());
    }

    public function test_a_rule_pause_whose_until_is_unreadable_stays(): void
    {
        $card = $this->boundCard([[
            'id' => 'hold',
            'slot' => 'one',
            'when' => self::ALWAYS,
            'then' => ['pause' => ['reason' => 'on-hold', 'until' => self::PROVIDED_READY]],
        ]]);
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);

        $this->provider()->facts = new ProvidedFacts(ready: true);
        $this->provider()->failure = new \RuntimeException('The source is down.');
        $this->evaluate($card, '2026-10-02 12:05:00');
        self::assertNull($pause->releasedAt);

        $this->provider()->failure = null;
        $this->evaluate($card, '2026-10-02 12:10:00');
        self::assertSame('until-met', $pause->releaseReason);
    }

    public function test_a_retries_pause_whose_rule_is_unreadable_stays(): void
    {
        $card = $this->boundCard([self::moveRule('stuck', 'three', self::PROVIDED_READY)], backoffMinutes: []);
        $this->provider()->facts = new ProvidedFacts(ready: true);
        $this->evaluate($card);
        $pause = $this->activePause($card);
        self::assertNotNull($pause);

        $this->provider()->facts = new ProvidedFacts(ready: true, version: 2);
        $this->provider()->failure = new \RuntimeException('The source is down.');
        $this->evaluate($card, '2026-10-02 13:00:00');
        self::assertNull($pause->releasedAt);

        $this->provider()->failure = null;
        $this->evaluate($card, '2026-10-02 13:01:00');
        self::assertSame('facts-changed', $pause->releaseReason);
    }

    public function test_after_a_release_a_rule_that_cannot_read_its_facts_waits_and_the_others_fire(): void
    {
        $card = $this->boundCard([
            self::requestRule('provided', self::PROVIDED_READY),
            self::requestRule('work', self::ALWAYS),
        ]);
        $this->hold($card);
        $this->releaseHold($card);
        $this->provider()->failure = new \RuntimeException('The source is down.');

        $this->evaluate($card);

        self::assertSame(['work'], $this->firedRules());
        self::assertNull($this->ruleStateOrNull($card, 'provided'));
        self::assertCount(1, $this->liveRequests($card));
    }

    public function test_after_a_release_a_true_pause_rule_whose_until_cannot_be_read_waits(): void
    {
        $card = $this->boundCard([[
            'id' => 'hold',
            'slot' => 'one',
            'when' => self::ALWAYS,
            'then' => ['pause' => ['reason' => 'on-hold', 'until' => self::PROVIDED_READY]],
        ]]);
        $this->hold($card);
        $this->releaseHold($card);
        $this->provider()->failure = new \RuntimeException('The source is down.');

        $this->evaluate($card);

        self::assertNull($this->ruleStateOrNull($card, 'hold'));
        self::assertNull($this->activePause($card));
    }

    public function test_a_stored_copy_with_a_condition_this_instance_lacks_runs_every_other_rule(): void
    {
        $card = $this->boundCard([
            self::requestRule('gone', ['card.gone' => ['any' => 'value']]),
            self::requestRule('negated-gone', ['not' => ['card.gone' => []]]),
            self::requestRule('work', self::ALWAYS),
        ]);

        $this->evaluate($card);

        self::assertSame(['work'], $this->firedRules());
        self::assertNull($this->ruleStateOrNull($card, 'gone'));
        self::assertNull($this->ruleStateOrNull($card, 'negated-gone'));
        self::assertSame([], $this->errors());
    }

    /**
     * Binds a template whose slot "one" is the column "next", "two" is "in-progress" and "three" has no column.
     *
     * @param list<array<string, mixed>> $rules
     * @param list<int>                  $backoffMinutes
     * @param array<string, mixed>       $onWorkFailed
     */
    private function boundCard(array $rules, array $backoffMinutes = [10, 60], array $onWorkFailed = []): Card
    {
        self::bootKernel();
        $project = $this->workflowProject('engine');
        $this->em()->persist(new WorkflowBinding($project, 'test', 1, [
            'key' => 'test',
            'version' => 1,
            'slots' => [['key' => 'one', 'label' => 'one'], ['key' => 'two', 'label' => 'two'], ['key' => 'three', 'label' => 'three']],
            'manualMoves' => [],
            'backoffMinutes' => $backoffMinutes,
            'workTimeoutMinutes' => 120,
            ...([] === $onWorkFailed ? [] : ['onWorkFailed' => $onWorkFailed]),
            'rules' => $rules,
        ]));
        $this->em()->persist(new WorkflowSlotLink($project, 'one', $this->column($project, 'next')));
        $this->em()->persist(new WorkflowSlotLink($project, 'two', $this->column($project, 'in-progress')));
        $this->em()->flush();

        return $this->card($project, 'next');
    }

    /**
     * @param array<string, mixed> $when
     *
     * @return array<string, mixed>
     */
    private static function requestRule(string $id, array $when, ?int $limit = null, bool $refill = false): array
    {
        $request = ['kind' => $id];
        if (null !== $limit) {
            $request['limit'] = $limit;
        }
        if ($refill) {
            $request['refill'] = ['all' => [['pr.checks_passed' => []], ['not' => ['pr.conflicting' => []]], ['not' => ['pr.changes_requested' => []]]]];
        }

        return ['id' => $id, 'slot' => 'one', 'when' => $when, 'then' => ['request' => $request]];
    }

    /**
     * @param array<string, mixed> $when
     *
     * @return array<string, mixed>
     */
    private static function moveRule(string $id, string $to, array $when): array
    {
        return ['id' => $id, 'slot' => 'one', 'when' => $when, 'then' => ['move' => ['to' => $to]]];
    }

    /** @return list<array<string, mixed>> */
    private function firedRecords(): array
    {
        return array_values(array_filter($this->logger->records, static fn (array $record): bool => 'workflow.card_evaluated' === $record['message']));
    }

    /** @return list<string> the ids of the rules that fired in the last evaluation */
    private function firedRules(): array
    {
        $records = $this->firedRecords();
        self::assertNotSame([], $records);
        $fired = $records[\count($records) - 1]['context']['fired'] ?? null;
        self::assertIsArray($fired);

        $rules = [];
        foreach ($fired as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['rule'] ?? null);
            $rules[] = $entry['rule'];
        }

        return $rules;
    }

    /** @return list<array<string, mixed>> */
    private function errors(): array
    {
        return array_values(array_filter($this->logger->records, static fn (array $record): bool => LogLevel::ERROR === $record['level']));
    }

    private function provider(): ProvidedFactsProvider
    {
        return $this->service(ProvidedFactsProvider::class);
    }

    private function providers(): FactProviders
    {
        return new FactProviders([$this->provider()]);
    }

    private function evaluate(Card $card, string $at = self::NOON): void
    {
        $this->engine()->evaluate($card->id ?? throw new \LogicException('A flushed card has an id.'), new \DateTimeImmutable($at));
    }

    private function engine(): Engine
    {
        $clock = new MockClock(self::NOON);
        $auditor = $this->service(Auditor::class);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $cardPauses = $this->service(CardPauseRepository::class);
        $workRequests = $this->service(WorkRequestRepository::class);
        $releaseCardPause = new ReleaseCardPauseHandler($this->em(), $clock, $auditor, $dispatcher);
        $opener = $this->opener();
        $forgePullRequests = $this->service(ForgePullRequestRepository::class);

        $engineEvents = new EventDispatcher();
        $engineEvents->addListener(CardPaused::class, function (CardPaused $event): void {
            $this->paused[] = $event;
        });

        return new Engine(
            $this->em(),
            $this->service(CardRepository::class),
            new ProjectTemplateCopy($this->service(WorkflowBindingRepository::class), $this->service(TemplateParser::class), $this->service(AppRules::class)),
            new FactsBuilder(
                $this->service(WorkflowSlotLinkRepository::class),
                $this->service(CardRepository::class),
                $this->service(CardDocumentRepository::class),
                new CardPullRequests($this->service(CardPullRequestRepository::class), $forgePullRequests),
                $forgePullRequests,
                $workRequests,
                $this->service(WorkerRunRepository::class),
                $this->providers(),
                $this->service(BoardAutomation::class),
                $this->em()->getConnection(),
            ),
            new FactFingerprint(),
            $this->service(WorkflowRuleStateRepository::class),
            $this->service(CardHolds::class),
            $this->service(WorkflowAutomation::class),
            $this->service(WorkflowPendingBaselineRepository::class),
            $workRequests,
            $this->service(WorkerRunRepository::class),
            $this->service(CardEventRepository::class),
            new WithdrawWorkRequestHandler($workRequests, $this->service(OutboxWriter::class), $this->em(), $clock, $auditor, $this->service(WorkRequestAnnouncer::class), new WorkSubjectHandlers([])),
            $cardPauses,
            new PauseCardHandler($cardPauses, $this->em(), $clock, $auditor, $dispatcher, $this->service(CardEventRepository::class)),
            $releaseCardPause,
            new Actions([
                new MoveCard($this->service(BoardColumnRepository::class), $this->service(WorkflowSlotLinkRepository::class), $this->service(UpdateCardHandler::class)),
                new RequestWork($opener, $this->service(CardPullRequests::class), $this->service(CardEventRepository::class)),
                new PauseCard(),
                new ReleasePause($cardPauses, $releaseCardPause),
                $this->service(ForgeWrite::class),
                new EvaluateChildren($this->service(CardRepository::class), new EvaluationTrigger($this->service(MessageBusInterface::class))),
            ]),
            $opener,
            new RuleSubject(),
            $engineEvents,
            $this->logger,
        );
    }

    /** @param array<string, mixed> $onWorkFailed */
    private function stoppedCard(string $code, array $onWorkFailed = ['retryOn' => ['failed'], 'retries' => 1, 'backoffMinutes' => [10, 60]]): Card
    {
        $card = $this->boundCard([self::requestRule('work', self::ALWAYS)], onWorkFailed: $onWorkFailed);
        $this->evaluate($card);
        $this->refuse($this->liveRequests($card)[0], $code, '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        self::assertNotNull($this->activePause($card));

        return $card;
    }

    private function resumedRun(Card $card, WorkerRunState $state, ?\DateTimeImmutable $receivedAt = null): WorkerRun
    {
        return $this->workerRun($card, 'work', $state, $this->workerRun($card, 'work', WorkerRunState::Blocked), $receivedAt);
    }

    private function workerRun(Card $card, string $workKind, WorkerRunState $state, ?WorkerRun $continues = null, ?\DateTimeImmutable $receivedAt = null): WorkerRun
    {
        $run = new WorkerRun(
            project: $card->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('A flushed card has an id.'),
            cardNumber: $card->number,
            workKind: $workKind,
            state: $state,
            receivedAt: $receivedAt ?? new \DateTimeImmutable(),
            continuesRun: $continues,
        );
        $this->em()->persist($run);
        $this->em()->flush();

        return $run;
    }

    /** Runs the queued evaluations of the card, then empties the queue. Answers how many ran. */
    private function evaluateQueued(Card $card, string $at): int
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $cardId = $card->id ?? throw new \LogicException('A flushed card has an id.');
        $evaluations = 0;
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof EvaluateCard && $message->cardId === $cardId->toRfc4122()) {
                $this->evaluate($card, $at);
                ++$evaluations;
            }
        }
        $transport->reset();

        return $evaluations;
    }

    private function ruleState(Card $card, string $ruleId): WorkflowRuleState
    {
        return $this->ruleStateOrNull($card, $ruleId) ?? throw new \LogicException(\sprintf('The rule "%s" has no state.', $ruleId));
    }

    private function ruleStateOrNull(Card $card, string $ruleId): ?WorkflowRuleState
    {
        return $this->service(WorkflowRuleStateRepository::class)->findForCard($card)[$ruleId] ?? null;
    }

    /** @return list<WorkRequest> */
    private function liveRequests(Card $card): array
    {
        return $this->service(WorkRequestRepository::class)->findLiveForCard($card->id ?? throw new \LogicException('A flushed card has an id.'));
    }

    private function liveRequestOfKind(Card $card, string $kind): WorkRequest
    {
        return array_find($this->liveRequests($card), static fn (WorkRequest $request): bool => $kind === $request->kind)
            ?? throw new \LogicException(\sprintf('The card has no live request of the kind "%s".', $kind));
    }

    private function activePause(Card $card): ?CardPause
    {
        return $this->service(CardPauseRepository::class)->findActiveForCard($card);
    }

    private function hold(Card $card): void
    {
        $this->service(CardHolds::class)->hold($card->project, $card->id ?? throw new \LogicException('A flushed card has an id.'), null);
    }

    /** The hold goes through the listener. The engine shares the entity manager, so the rows the listener rewrote are read again. */
    private function releaseHold(Card $card, string $at = self::NOON): void
    {
        $clock = new MockClock($at);
        $events = new EventDispatcher();
        $events->addListener(CardHoldsReleased::class, new RearmCardsOnCardHoldsReleased(
            $this->service(WorkflowRuleStateRepository::class),
            $this->service(WorkRequestRepository::class),
            $this->service(WorkflowPendingBaselineRepository::class),
            new EvaluationTrigger($this->service(MessageBusInterface::class)),
            $clock,
        ));
        $holds = new CardHolds($this->service(CardHoldRepository::class), $this->em(), $clock, $events);

        $holds->release($card->project, [$card->id ?? throw new \LogicException('A flushed card has an id.')]);
        foreach ([...$this->service(WorkflowRuleStateRepository::class)->findForCard($card), ...$this->liveRequests($card)] as $row) {
            $this->em()->refresh($row);
        }
    }

    /** The hold goes with no listener, so the rules keep their state. */
    private function dropHold(Card $card): void
    {
        $this->service(CardHoldRepository::class)->deleteOfCards($card->project, [$card->id ?? throw new \LogicException('A flushed card has an id.')]);
    }

    private function saveAutomation(Card $card, bool $enabled): void
    {
        $settings = $this->service(BoardAutomation::class)->settingsOf($card->project);
        $this->service(SaveBoardAutomationSettingsHandler::class)(new SaveBoardAutomationSettingsCommand(
            $card->project,
            $enabled,
            $settings->commentOnFixQueued,
            $settings->commentOnStaleApproval,
            $settings->syncBehind,
            $settings->mergePullRequests,
            $settings->changeBase,
        ));
    }

    private function requestChangesOnHead(ForgePullRequest $pullRequest): void
    {
        $pullRequest->review = PullRequestReview::ChangesRequested;
        $pullRequest->changesRequestedSha = $pullRequest->headSha;
        $this->em()->flush();
    }

    private function releaseByPerson(Card $card): void
    {
        $this->service(ReleaseWorkflowPauseHandler::class)(new ReleaseWorkflowPauseCommand($card, $card->project->owner, CardReporter::Human, null));
    }

    private function refuse(WorkRequest $request, string $code, string $at): void
    {
        $request->state = WorkRequestState::Claimed;
        $request->settle(WorkRequestState::Refused, $code, new \DateTimeImmutable($at));
        $this->em()->flush();
    }

    private function finish(WorkRequest $request, string $at = '2026-10-02 12:20:00'): void
    {
        $request->state = WorkRequestState::Claimed;
        $request->settle(WorkRequestState::Done, null, new \DateTimeImmutable($at));
        $this->em()->flush();
    }

    /** Refuses the request of the rule "work" and its one retry, so the engine opens the repair request at 12:41. */
    private function repairing(Card $card): WorkRequest
    {
        $this->evaluate($card);
        $this->refuse($this->liveRequests($card)[0], 'failed', '2026-10-02 12:20:00');
        $this->evaluate($card, '2026-10-02 12:21:00');
        $this->evaluate($card, '2026-10-02 12:31:00');
        $this->refuse($this->liveRequests($card)[0], 'failed', '2026-10-02 12:40:00');
        $this->evaluate($card, '2026-10-02 12:41:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);

        return $live[0];
    }

    private function epic(Project $project): Card
    {
        $epic = $this->card($project, 'in-progress');
        $this->setType($epic, CardType::Epic);

        return $epic;
    }

    private function childOf(Card $epic, string $column): Card
    {
        $child = $this->card($epic->project, $column);
        $child->parent = $epic;
        $this->em()->flush();

        return $child;
    }

    private function setType(Card $card, CardType $type): void
    {
        $card->type = $type;
        $this->em()->flush();
    }

    private function moveTo(Card $card, string $slug): void
    {
        $card->column = $this->column($card->project, $slug);
        $this->em()->flush();
    }

    private function document(Card $card, string $tagName, DocumentStatus $status = DocumentStatus::Approved): Document
    {
        $document = new Document($card->project->owner, $card->project, 'Design');
        $document->status = $status;
        $tag = new Tag($card->project, $tagName);
        $this->em()->persist($tag);
        $document->tags->add($tag);
        $this->em()->persist($document);
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();

        return $document;
    }

    private function setStatus(Document $document, DocumentStatus $status): void
    {
        $document->status = $status;
        $this->em()->flush();
    }

    private function block(Card $card): void
    {
        $this->em()->persist(new CardLink($this->card($card->project, 'next'), $card, CardLinkKind::Blocks));
        $this->em()->flush();
    }

    /** @return list<string> the rule ids of every work request of the card, live or settled */
    private function requestRuleIds(Card $card): array
    {
        return array_map(
            static fn (WorkRequest $request): string => $request->ruleId,
            $this->service(WorkRequestRepository::class)->findBy(['subjectType' => WorkSubject::CARD, 'subjectId' => $card->id]),
        );
    }
}
