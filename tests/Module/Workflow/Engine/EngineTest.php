<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Command\ReleaseCardPauseHandler;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Action\MoveCard;
use App\Module\Workflow\Action\PauseCard;
use App\Module\Workflow\Action\ReleasePause;
use App\Module\Workflow\Action\RequestWork;
use App\Module\Workflow\Action\WorkRequestOpener;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Entity\WorkflowBinding;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Entity\WorkflowSlotLink;
use App\Module\Workflow\Event\CardPaused;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Module\Workflow\Service\CardPullRequests;
use App\Module\Workflow\Service\FactFingerprint;
use App\Module\Workflow\Service\FactsBuilder;
use App\Module\Workflow\Template\ProjectTemplateCopy;
use App\Module\Workflow\Template\TemplateParser;
use App\Outbox\OutboxWriter;
use App\Tests\Module\Workflow\Action\ActionScenario;
use App\Tests\Support\RecordingLogger;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Ubermuda\AuditBundle\Auditor;

final class EngineTest extends KernelTestCase
{
    use ActionScenario;

    private const string NOON = '2026-10-02 12:00:00';

    private const array ALWAYS = ['all' => []];

    private const array NOT_EPIC = ['not' => ['card.type' => ['type' => 'epic']]];

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

        $this->evaluate($card, '2026-10-02 12:05:00');
        self::assertSame(1, $this->ruleState($card, 'stuck')->attempts);

        $this->evaluate($card, '2026-10-02 12:10:00');
        self::assertSame([2, '2026-10-02 13:10:00'], [$this->ruleState($card, 'stuck')->attempts, $this->ruleState($card, 'stuck')->dueAt?->format('Y-m-d H:i:s')]);

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

    public function test_a_live_request_whose_rule_left_the_template_is_cancelled(): void
    {
        $card = $this->boundCard([]);
        $request = new WorkRequest($card->project, $card->id ?? throw new \LogicException('A flushed card has an id.'), $card->number, 'implement', null, 'gone', new \DateTimeImmutable(self::NOON));
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
        $this->approvedDocument($card, 'design');

        $this->evaluate($card);

        self::assertSame('in-progress', $card->column->slug);
        self::assertSame([], $this->liveRequests($card));

        $this->evaluate($card, '2026-10-02 12:01:00');

        $live = $this->liveRequests($card);
        self::assertCount(1, $live);
        self::assertSame(['implement', 'implement'], [$live[0]->kind, $live[0]->ruleId]);
    }

    /**
     * Binds a template whose slot "one" is the column "next", "two" is "in-progress" and "three" has no column.
     *
     * @param list<array<string, mixed>> $rules
     * @param list<int>                  $backoffMinutes
     */
    private function boundCard(array $rules, array $backoffMinutes = [10, 60]): Card
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
    private static function requestRule(string $id, array $when, ?int $limit = null): array
    {
        $request = ['kind' => $id];
        if (null !== $limit) {
            $request['limit'] = $limit;
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

    /** @return list<string> the ids of the rules that fired in the last evaluation */
    private function firedRules(): array
    {
        $records = array_values(array_filter($this->logger->records, static fn (array $record): bool => 'workflow.card_evaluated' === $record['message']));
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
        $opener = new WorkRequestOpener($this->openWorkRequestHandler());
        $forgePullRequests = $this->service(ForgePullRequestRepository::class);

        $engineEvents = new EventDispatcher();
        $engineEvents->addListener(CardPaused::class, function (CardPaused $event): void {
            $this->paused[] = $event;
        });

        return new Engine(
            $this->em(),
            $this->service(CardRepository::class),
            new ProjectTemplateCopy($this->service(WorkflowBindingRepository::class), $this->service(TemplateParser::class)),
            new FactsBuilder(
                $this->service(WorkflowSlotLinkRepository::class),
                $this->service(CardRepository::class),
                $this->service(CardDocumentRepository::class),
                new CardPullRequests($this->service(CardPullRequestRepository::class), $forgePullRequests),
                $forgePullRequests,
                $workRequests,
            ),
            new FactFingerprint(),
            $this->service(WorkflowRuleStateRepository::class),
            $workRequests,
            new WithdrawWorkRequestHandler($workRequests, $this->service(OutboxWriter::class), $this->em(), $clock, $auditor, $this->service(WorkRequestAnnouncer::class)),
            $cardPauses,
            new PauseCardHandler($cardPauses, $this->em(), $clock, $auditor, $dispatcher),
            $releaseCardPause,
            new Actions([
                new MoveCard($this->service(BoardColumnRepository::class), $this->service(WorkflowSlotLinkRepository::class), $this->service(UpdateCardHandler::class)),
                new RequestWork($opener),
                new PauseCard(),
                new ReleasePause($cardPauses, $releaseCardPause),
            ]),
            $engineEvents,
            $this->logger,
        );
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

    private function activePause(Card $card): ?CardPause
    {
        return $this->service(CardPauseRepository::class)->findActiveForCard($card);
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

    private function approvedDocument(Card $card, string $tagName): void
    {
        $document = new Document($card->project->owner, $card->project, 'Design');
        $document->status = DocumentStatus::Approved;
        $tag = new Tag($card->project, $tagName);
        $this->em()->persist($tag);
        $document->tags->add($tag);
        $this->em()->persist($document);
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();
    }
}
