<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Workflow\WorkRequestContexts;
use App\Module\Board\Workflow\WorkRequestOpener;
use App\Module\Bridge\Command\CancelBridgeCommandHandler;
use App\Module\Bridge\Command\OpenWorkRequestHandler;
use App\Module\Bridge\Command\RequestBridgeCommandHandler;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\BridgeCommandCause;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\View\WorkerRunControls;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Inbox\Command\CancelInboxActionCommand;
use App\Module\Inbox\Command\CancelInboxActionHandler;
use App\Module\Inbox\Command\DeclineInboxItemHandler;
use App\Module\Inbox\Command\RunInboxActionCommand;
use App\Module\Inbox\Command\RunInboxActionHandler;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitReason;
use App\Module\Inbox\Entity\InboxCardWaitTrigger;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxActionRepository;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Service\AskingBridge;
use App\Module\Inbox\Service\ItemActions;
use App\Module\Inbox\Service\RunCommandResolver;
use App\Module\Inbox\Service\RunCommandRuns;
use App\Module\Inbox\Service\SessionBridges;
use App\Module\Inbox\Service\SessionPromptLimit;
use App\Module\Inbox\Workflow\InboxRetryNotes;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\ReleaseWorkflowPauseHandler;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardDirectory;
use App\Module\Workflow\Contract\CardPauses;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\ColumnRef;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Service\TemplateStageWorks;
use App\Tests\Module\Workflow\Fact\FactsMother;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\RecordingAuditor;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class RunInboxActionHandlerTest extends KernelTestCase
{
    use WorkflowProjects;

    private Project $project;
    private Card $card;
    private Bridge $bridge;
    private RecordingAuditor $audit;
    private int $number = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->audit = RecordingAuditor::installedIn(self::getContainer());
        $this->project = $this->workflowProject('inbox-run-action');
        $this->bindLifecycle($this->project);
        $this->card = $this->service(CreateCardHandler::class)(new CreateCardCommand(
            project: $this->project,
            title: 'Card',
            body: 'Body',
            type: 'feature',
            column: $this->column($this->project, 'tech-design'),
            reporter: Actor::Human,
        ));
        $this->bridge = new Bridge($this->project->owner, Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em()->persist($this->bridge);
        $this->em()->flush();
        $this->audit->forget();
    }

    public function test_a_resume_queues_a_pending_command_with_the_cause_and_records_the_action(): void
    {
        $item = $this->runItem();
        $run = $this->em()->find(WorkerRun::class, $this->waitOf($item)->runId);

        $action = $this->runAction($item, InboxActionKind::ResumeRun, '  Carry on with the tests.  ');

        self::assertInstanceOf(InboxAction::class, $action);
        self::assertSame('Carry on with the tests.', $action->note);
        self::assertSame($this->project->owner, $action->requestedBy);
        $command = $this->em()->find(BridgeCommand::class, $action->bridgeCommandId);
        self::assertInstanceOf(BridgeCommand::class, $command);
        self::assertSame([BridgeCommandKind::ResumeRun, BridgeCommandState::Pending, BridgeCommandCause::InboxAction], [$command->kind, $command->state, $command->cause]);
        self::assertSame((string) $run?->id, (string) $command->workerRun->id);
        self::assertNull($command->reason);
        self::assertSame((string) $this->waitOf($item)->id, (string) $action->waitId);
        self::assertSame($action, $this->service(InboxActionRepository::class)->findOneByIdAndItem($action->id ?? throw new \LogicException('Stored.'), $item));
    }

    public function test_a_second_resume_is_refused_while_the_command_is_pending(): void
    {
        $item = $this->runItem();
        $this->runAction($item, InboxActionKind::ResumeRun);

        $this->assertRefused($item, InboxActionKind::ResumeRun, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);
        self::assertCount(1, $this->service(InboxActionRepository::class)->findByItem($item));
    }

    /** The card pauses after the menu fit, so the bridge refuses the resume the menu offered. */
    public function test_a_refused_bridge_request_leaves_no_row_and_no_command(): void
    {
        $item = $this->runItem();
        $this->service(CardHolds::class)->hold($this->project, $this->card->id ?? throw new \LogicException('Stored.'), $this->project->owner);

        $this->assertRefused($item, InboxActionKind::ResumeRun, ['action' => RequestBridgeCommandHandler::CARD_HELD]);

        $this->em()->clear();
        self::assertSame([], $this->service(InboxActionRepository::class)->findByItem($item));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_commands'));
        self::assertSame([], $this->audit->records('inbox.action_requested'));
    }

    public function test_a_refusal_of_the_release_keeps_its_key_under_the_action_field(): void
    {
        $item = $this->pauseItem(PauseKind::Retries);
        $this->service(BoardAutomation::class)->settingsForUpdate($this->project)->enabled = false;
        $this->em()->flush();

        $this->assertRefused($item, InboxActionKind::RetryCard, ['action' => ReleaseWorkflowPauseHandler::CARD_UNMANAGED]);
        self::assertSame([], $this->service(InboxActionRepository::class)->findByItem($item));
        self::assertSame([], $this->audit->records('inbox.action_requested'));
    }

    public function test_a_retry_releases_the_pause_and_stores_its_id(): void
    {
        $item = $this->pauseItem(PauseKind::Retries);

        $action = $this->runAction($item, InboxActionKind::RetryCard, 'Try again');

        self::assertInstanceOf(InboxAction::class, $action);
        self::assertSame((string) $this->waitOf($item)->pauseId, (string) $action->pauseId);
        self::assertSame((string) $this->waitOf($item)->id, (string) $action->waitId);
        self::assertNull($action->bridgeCommandId);
        self::assertNull($this->service(CardPauses::class)->findActive($this->card->id ?? throw new \LogicException('Stored.')));
    }

    public function test_the_first_request_of_the_retried_rule_names_the_retry_and_its_item(): void
    {
        $item = $this->pauseItem(PauseKind::Retries);
        $action = $this->runAction($item, InboxActionKind::RetryCard, 'Use the other fixture') ?? throw new \LogicException('A retry records a row.');

        self::assertNull($this->openedRequest($this->openRuleWork('other-rule', 'review')->requestId)->inboxActionId);
        $request = $this->openedRequest($this->openRuleWork('review-rule', 'fix')->requestId);

        self::assertSame(
            [(string) $action->id, (string) $item->id, $item->number],
            [(string) $request->inboxActionId, (string) $request->inboxItemId, $request->inboxItemNumber],
        );
        $payload = json_decode((string) $this->em()->getConnection()->fetchOne("SELECT payload FROM outbox_events WHERE type = 'bridge.work_request' AND payload::jsonb->>'workRequestId' = ?", [(string) $request->id]), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([(string) $action->id, (string) $item->id], [$payload['inboxActionId'], $payload['inboxItemId']]);
        self::assertStringNotContainsString('other fixture', json_encode($payload, \JSON_THROW_ON_ERROR));
        self::assertNull($action->workRequestId);
        self::assertNull($this->retryNotes()->forRuleWork($this->card->id ?? throw new \LogicException('Stored.'), 'review-rule'));
    }

    public function test_a_retry_names_no_request_of_another_rule_and_a_cancelled_retry_names_none(): void
    {
        $item = $this->pauseItem(PauseKind::Retries);
        $action = $this->runAction($item, InboxActionKind::RetryCard, 'Go') ?? throw new \LogicException('A retry records a row.');
        $cardId = $this->card->id ?? throw new \LogicException('Stored.');

        self::assertNull($this->retryNotes()->forRuleWork($cardId, 'other-rule'));
        self::assertNotNull($this->retryNotes()->forRuleWork($cardId, 'review-rule'));

        $action->cancelledAt = new \DateTimeImmutable();
        $this->em()->flush();
        self::assertNull($this->retryNotes()->forRuleWork($cardId, 'review-rule'));
    }

    public function test_a_newer_pause_ends_the_match_of_an_earlier_retry(): void
    {
        $item = $this->pauseItem(PauseKind::Retries);
        $this->runAction($item, InboxActionKind::RetryCard, 'Go');
        $this->service(PauseCardHandler::class)(new PauseCardCommand($this->card, 'on-hold', 'review-rule', PauseKind::Retries));

        self::assertNull($this->retryNotes()->forRuleWork($this->card->id ?? throw new \LogicException('Stored.'), 'review-rule'));
    }

    public function test_a_retry_of_a_rule_pause_is_refused(): void
    {
        $item = $this->pauseItem(PauseKind::Rule);

        $this->assertRefused($item, InboxActionKind::RetryCard, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);
        self::assertNotNull($this->service(CardPauses::class)->findActive($this->card->id ?? throw new \LogicException('Stored.')));
    }

    public function test_a_dismiss_closes_the_item_as_declined_with_the_note_and_records_no_row(): void
    {
        $item = $this->runItem();

        $action = $this->runAction($item, InboxActionKind::Dismiss, 'Not now');

        self::assertNull($action);
        self::assertSame([InboxItemState::Declined, 'Not now'], [$item->state, $item->closeNote]);
        self::assertSame([], $this->service(InboxActionRepository::class)->findByItem($item));
        self::assertSame(
            ['itemId' => (string) $item->id, 'projectId' => (string) $this->project->id, 'kind' => 'dismiss', 'hasNote' => true],
            $this->audit->record('inbox.action_requested')->context,
        );
    }

    public function test_a_dismiss_of_a_closed_item_is_refused(): void
    {
        $item = $this->runItem();
        $this->runAction($item, InboxActionKind::Dismiss);

        $this->assertRefused($item, InboxActionKind::Dismiss, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);
    }

    public function test_a_kind_that_is_not_run_here_is_refused_even_when_it_fits(): void
    {
        $item = $this->runItem();

        foreach ([InboxActionKind::Open, InboxActionKind::Review] as $kind) {
            $this->assertRefused($item, $kind, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);
        }
    }

    public function test_a_kind_that_does_not_fit_is_refused(): void
    {
        $item = $this->runItem(WorkerRunState::Running);

        $this->assertRefused($item, InboxActionKind::ResumeRun, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);
        $this->assertRefused($item, InboxActionKind::RetryCard, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);
    }

    public function test_a_note_over_the_limit_is_refused_and_one_at_the_limit_is_kept(): void
    {
        $item = $this->runItem();

        $this->assertRefused($item, InboxActionKind::ResumeRun, ['note' => RunInboxActionHandler::NOTE_TOO_LONG], note: str_repeat('é', InboxAction::MAX_NOTE_LENGTH + 1));
        $action = $this->runAction($item, InboxActionKind::ResumeRun, ' '.str_repeat('é', InboxAction::MAX_NOTE_LENGTH).' ');

        self::assertInstanceOf(InboxAction::class, $action);
        self::assertSame(InboxAction::MAX_NOTE_LENGTH, mb_strlen($action->note));
    }

    public function test_the_request_is_audited_with_ids_and_no_note_text(): void
    {
        $item = $this->runItem();

        $action = $this->runAction($item, InboxActionKind::ResumeRun, 'Secret words');

        $record = $this->audit->record('inbox.action_requested');
        self::assertSame([
            'itemId' => (string) $item->id,
            'projectId' => (string) $this->project->id,
            'kind' => 'resume-run',
            'hasNote' => true,
            'actionId' => (string) $action?->id,
        ], $record->context);
        self::assertStringNotContainsString('Secret words', json_encode($record->context, \JSON_THROW_ON_ERROR));
        self::assertSame([], $this->audit->records('inbox.action_cancelled'));
    }

    public function test_a_refused_action_leaves_no_audit_record(): void
    {
        $item = $this->runItem(WorkerRunState::Running);

        $this->assertRefused($item, InboxActionKind::ResumeRun, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);

        self::assertSame([], $this->audit->records('inbox.action_requested'));
    }

    public function test_a_cancel_withdraws_the_pending_command_and_marks_the_action(): void
    {
        $item = $this->runItem();
        $action = $this->runAction($item, InboxActionKind::ResumeRun) ?? throw new \LogicException('A resume records a row.');

        ($this->cancelHandler())(new CancelInboxActionCommand($action, $item, $this->project->owner));

        self::assertNotNull($action->cancelledAt);
        $command = $this->em()->find(BridgeCommand::class, $action->bridgeCommandId);
        self::assertSame(BridgeCommandState::Cancelled, $command?->state);
        $record = $this->audit->record('inbox.action_cancelled');
        self::assertSame(['itemId' => (string) $item->id, 'projectId' => (string) $this->project->id, 'kind' => 'resume-run', 'actionId' => (string) $action->id], $record->context);
    }

    public function test_a_cancel_with_another_item_is_refused_and_changes_nothing(): void
    {
        $item = $this->runItem();
        $action = $this->runAction($item, InboxActionKind::ResumeRun) ?? throw new \LogicException('A resume records a row.');
        $other = new InboxItem(project: $this->project, number: ++$this->number + 50, kind: InboxItemKind::Wait, title: 'Other', blocking: true);
        $this->em()->persist($other);
        $this->em()->flush();

        $this->assertCancelRefused($action, $other, CancelInboxActionHandler::NOT_FOUND);

        self::assertSame(BridgeCommandState::Pending, $this->em()->find(BridgeCommand::class, $action->bridgeCommandId)?->state);
    }

    public function test_a_cancel_of_a_retry_is_refused(): void
    {
        $item = $this->pauseItem(PauseKind::Retries);
        $action = $this->runAction($item, InboxActionKind::RetryCard) ?? throw new \LogicException('A retry records a row.');

        $this->assertCancelRefused($action, $item, CancelInboxActionHandler::NOT_CANCELLABLE);
    }

    public function test_a_cancel_of_a_cancelled_or_settled_resume_is_refused(): void
    {
        $item = $this->runItem();
        $action = $this->runAction($item, InboxActionKind::ResumeRun) ?? throw new \LogicException('A resume records a row.');
        ($this->cancelHandler())(new CancelInboxActionCommand($action, $item, $this->project->owner));

        $this->assertCancelRefused($action, $item, CancelInboxActionHandler::NOT_CANCELLABLE);

        $settled = new InboxAction($item, InboxActionKind::ResumeRun, $this->project->owner);
        $settled->bridgeCommandId = $action->bridgeCommandId;
        $this->em()->persist($settled);
        $this->em()->flush();
        $this->assertCancelRefused($settled, $item, CancelInboxActionHandler::NOT_CANCELLABLE);
        self::assertNull($settled->cancelledAt);
    }

    public function test_a_command_that_is_no_longer_pending_is_not_cancelled(): void
    {
        $item = $this->runItem();
        $action = $this->runAction($item, InboxActionKind::ResumeRun) ?? throw new \LogicException('A resume records a row.');
        $command = $this->em()->find(BridgeCommand::class, $action->bridgeCommandId) ?? throw new \LogicException('Stored.');
        $command->state = BridgeCommandState::Done;
        $this->em()->flush();

        $this->assertCancelRefused($action, $item, CancelInboxActionHandler::NOT_CANCELLABLE);
        self::assertNull($action->cancelledAt);
        self::assertSame([], $this->audit->records('inbox.action_cancelled'));
    }

    public function test_send_agent_fits_a_wait_item_of_a_card_in_a_stage_that_names_work(): void
    {
        $item = $this->runItem();

        self::assertSame(
            [InboxActionKind::ResumeRun, InboxActionKind::SendAgent, InboxActionKind::Open, InboxActionKind::StartSession, InboxActionKind::Dismiss],
            array_map(static fn ($action): InboxActionKind => $action->kind, $this->itemActions()->forItem($item)),
        );
        $target = $this->itemActions()->sendAgentFor($item);
        self::assertSame([(string) $this->card->id, 'tech-design'], [(string) $target?->cardId, $target?->kind]);
    }

    public function test_send_agent_fits_a_question_that_links_one_card_and_no_other(): void
    {
        $one = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Question, title: 'Which?', blocking: false);
        $two = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Question, title: 'Which?', blocking: false);
        $none = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Question, title: 'Which?', blocking: false);
        $other = $this->cardIn('in-progress');
        foreach ([$one, $two, $none] as $item) {
            $this->em()->persist($item);
        }
        $one->cards->add(new InboxItemCard($one, $this->card));
        $two->cards->add(new InboxItemCard($two, $this->card));
        $two->cards->add(new InboxItemCard($two, $other));
        $this->em()->flush();

        self::assertSame([InboxActionKind::SendAgent, InboxActionKind::Open, InboxActionKind::StartSession], array_map(static fn ($action): InboxActionKind => $action->kind, $this->itemActions()->forItem($one)));
        self::assertNull($this->itemActions()->sendAgentFor($two));
        self::assertNull($this->itemActions()->sendAgentFor($none));
    }

    public function test_a_todo_acts_on_the_linked_card_that_its_suggestion_names(): void
    {
        $other = $this->cardIn('in-progress');
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Todo, title: 'Export it', blocking: false);
        $this->em()->persist($item);
        $item->cards->add(new InboxItemCard($item, $other));
        $item->cards->add(new InboxItemCard($item, $this->card));
        $cardId = (string) $this->card->id;
        $item->suggestedActions = [
            ['action' => 'send-agent', 'reason' => 'Let an agent do it.', 'recommended' => true, 'cardId' => $cardId],
            ['action' => 'open', 'reason' => 'Read the card.', 'recommended' => false, 'cardId' => $cardId],
            ['action' => 'start-session', 'reason' => 'Pair on it.', 'recommended' => false, 'cardId' => $cardId],
        ];
        $this->em()->flush();

        self::assertContains(InboxActionKind::SendAgent, array_map(static fn ($action): InboxActionKind => $action->kind, $this->itemActions()->forItem($item)));
        self::assertSame($cardId, (string) $this->itemActions()->sendAgentFor($item)?->cardId);
        self::assertSame($cardId, (string) $this->itemActions()->cardIdOf($item, InboxActionKind::Open));
        self::assertSame($cardId, (string) $this->itemActions()->cardIdOf($item, InboxActionKind::StartSession));
        self::assertSame((string) $other->id, (string) $this->itemActions()->cardIdOf($item, InboxActionKind::Review), 'A kind with no suggestion falls back to the first linked card.');
    }

    public function test_a_card_with_no_wait_item_fits_send_agent_as_a_question_on_it_does(): void
    {
        $cardId = $this->card->id ?? throw new \LogicException('Stored.');
        $other = $this->cardIn('product-design');

        self::assertSame([InboxActionKind::Open, InboxActionKind::SendAgent], $this->itemActions()->forCard($this->project, $cardId, null));
        self::assertSame([InboxActionKind::Open], $this->itemActions()->forCard($this->project, $other->id ?? throw new \LogicException('Stored.'), null));

        $this->seedRequest('tech-design', WorkRequestState::Open);
        self::assertSame([InboxActionKind::Open], $this->itemActions()->forCard($this->project, $cardId, null));
    }

    public function test_send_agent_does_not_fit_a_card_in_a_stage_that_names_no_work(): void
    {
        $item = $this->runItem(card: $this->cardIn('product-design'));

        self::assertNotContains(InboxActionKind::SendAgent, array_map(static fn ($action): InboxActionKind => $action->kind, $this->itemActions()->forItem($item)));
    }

    public function test_send_agent_does_not_fit_while_a_live_request_of_the_stage_work_exists(): void
    {
        $item = $this->runItem();
        $live = $this->seedRequest('tech-design', WorkRequestState::Claimed);

        self::assertNull($this->itemActions()->sendAgentFor($item));

        $live->state = WorkRequestState::Done;
        $this->seedRequest('fix', WorkRequestState::Open);
        $this->em()->flush();
        self::assertNotNull($this->itemActions()->sendAgentFor($item));
    }

    public function test_send_agent_does_not_fit_while_a_worker_runs_on_the_card(): void
    {
        $item = $this->runItem(WorkerRunState::Running);

        self::assertNull($this->itemActions()->sendAgentFor($item));
    }

    public function test_send_agent_opens_a_fresh_request_for_the_stage_work_and_records_the_action(): void
    {
        $item = $this->runItem();

        $action = $this->runAction($item, InboxActionKind::SendAgent, ' Start with the tests. ');

        self::assertInstanceOf(InboxAction::class, $action);
        self::assertSame('Start with the tests.', $action->note);
        $request = $this->em()->find(WorkRequest::class, $action->workRequestId);
        self::assertInstanceOf(WorkRequest::class, $request);
        self::assertSame(
            ['tech-design', 'inbox:send-agent', WorkRequestState::Open, WorkSubject::CARD, (string) $this->card->id, $this->card->number, null, null, $item->number],
            [$request->kind, $request->ruleId, $request->state, $request->subjectType, (string) $request->subjectId, $request->cardNumber, $request->capability, $request->resumeSessionId, $request->inboxItemNumber],
        );
        self::assertSame((string) $action->id, (string) $request->inboxActionId);
        self::assertNull($action->bridgeCommandId);
        $payload = json_decode((string) $this->em()->getConnection()->fetchOne("SELECT payload FROM outbox_events WHERE type = 'bridge.work_request'"), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([(string) $action->id, $item->number, (string) $item->id], [$payload['inboxActionId'], $payload['inboxItemNumber'], $payload['inboxItemId']]);
        self::assertSame((string) $item->id, (string) $request->inboxItemId);
        self::assertSame(
            ['itemId' => (string) $item->id, 'projectId' => (string) $this->project->id, 'kind' => 'send-agent', 'hasNote' => true, 'actionId' => (string) $action->id],
            $this->audit->record('inbox.action_requested')->context,
        );
        self::assertStringNotContainsString('Start with the tests', json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    public function test_a_second_send_agent_is_refused_while_the_request_is_live(): void
    {
        $item = $this->runItem();
        $this->runAction($item, InboxActionKind::SendAgent);

        $this->assertRefused($item, InboxActionKind::SendAgent, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);
        self::assertCount(1, $this->service(InboxActionRepository::class)->findByItem($item));
    }

    /** Another request opens the work after the menu was computed, so the unique index refuses this one. */
    public function test_a_request_that_loses_the_race_leaves_no_row(): void
    {
        $item = $this->runItem();
        $this->seedRequest('tech-design', WorkRequestState::Open);
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        try {
            ($this->runHandler(new BlindWorkRequestRepository($doctrine)))(new RunInboxActionCommand($item, InboxActionKind::SendAgent, '', $this->project->owner));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['action' => RunInboxActionHandler::NOT_AVAILABLE], $e->errors);
        }

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_actions'));
        self::assertSame([], $this->audit->records('inbox.action_requested'));
    }

    public function test_a_cancel_withdraws_the_open_request_of_a_send_agent(): void
    {
        $item = $this->runItem();
        $action = $this->runAction($item, InboxActionKind::SendAgent) ?? throw new \LogicException('A send records a row.');

        ($this->cancelHandler())(new CancelInboxActionCommand($action, $item, $this->project->owner));

        self::assertNotNull($action->cancelledAt);
        $request = $this->em()->find(WorkRequest::class, $action->workRequestId);
        self::assertSame(WorkRequestState::Cancelled, $request?->state);
        $record = $this->audit->record('inbox.action_cancelled');
        self::assertSame(['itemId' => (string) $item->id, 'projectId' => (string) $this->project->id, 'kind' => 'send-agent', 'actionId' => (string) $action->id], $record->context);
        self::assertNotNull($this->itemActions()->sendAgentFor($item), 'The cancel frees the card for another send.');
    }

    public function test_a_cancel_of_a_send_agent_that_a_bridge_claimed_is_refused(): void
    {
        $item = $this->runItem();
        $action = $this->runAction($item, InboxActionKind::SendAgent) ?? throw new \LogicException('A send records a row.');
        $request = $this->em()->find(WorkRequest::class, $action->workRequestId) ?? throw new \LogicException('Stored.');
        $request->state = WorkRequestState::Claimed;
        $this->em()->flush();

        $this->assertCancelRefused($action, $item, CancelInboxActionHandler::NOT_CANCELLABLE);

        self::assertNull($action->cancelledAt);
        self::assertSame(WorkRequestState::Claimed, $request->state);
        self::assertSame([], $this->audit->records('inbox.action_cancelled'));
    }

    public function test_a_cancel_of_a_send_agent_twice_is_refused(): void
    {
        $item = $this->runItem();
        $action = $this->runAction($item, InboxActionKind::SendAgent) ?? throw new \LogicException('A send records a row.');
        ($this->cancelHandler())(new CancelInboxActionCommand($action, $item, $this->project->owner));

        $this->assertCancelRefused($action, $item, CancelInboxActionHandler::NOT_CANCELLABLE);
    }

    public function test_a_resume_names_its_action_and_item_on_the_bridge_command(): void
    {
        $item = $this->runItem();

        $action = $this->runAction($item, InboxActionKind::ResumeRun) ?? throw new \LogicException('A resume records a row.');

        $command = $this->em()->find(BridgeCommand::class, $action->bridgeCommandId);
        self::assertSame([(string) $action->id, $item->number, (string) $item->id], [(string) $command?->inboxActionId, $command?->inboxItemNumber, (string) $command?->inboxItemId]);
    }

    private function cardIn(string $slug): Card
    {
        return $this->service(CreateCardHandler::class)(new CreateCardCommand(
            project: $this->project,
            title: 'Other card',
            body: 'Body',
            type: 'feature',
            column: $this->column($this->project, $slug),
            reporter: Actor::Human,
        ));
    }

    private function seedRequest(string $kind, WorkRequestState $state): WorkRequest
    {
        $request = new WorkRequest($this->project, WorkSubject::CARD, $this->card->id ?? throw new \LogicException('Stored.'), $this->card->number, $kind, null, 'implement-on-entry', new \DateTimeImmutable());
        $request->state = $state;
        $this->em()->persist($request);
        $this->em()->flush();

        return $request;
    }

    private function retryNotes(): InboxRetryNotes
    {
        return new InboxRetryNotes($this->service(CardPauses::class), $this->service(InboxActionRepository::class));
    }

    /** Opens the work of a rule of the card as the engine does after a release. */
    private function openRuleWork(string $ruleId, string $kind): ActionOutcome
    {
        $opener = new WorkRequestOpener(
            $this->service(OpenWorkRequestHandler::class),
            $this->service(CardRepository::class),
            $this->service(WorkRequestContexts::class),
            $this->retryNotes(),
        );
        $card = new CardSnapshot($this->card->id ?? throw new \LogicException('Stored.'), $this->project->id ?? throw new \LogicException('Stored.'), $this->card->number, 'feature', new ColumnRef(Uuid::v7(), false, false), null, null, null);

        return $opener->open(new ActionContext($card, $ruleId, false, [], FactsMother::facts(), 0), $kind, null);
    }

    private function openedRequest(?Uuid $requestId): WorkRequest
    {
        $request = null === $requestId ? null : $this->em()->find(WorkRequest::class, $requestId);
        self::assertInstanceOf(WorkRequest::class, $request);

        return $request;
    }

    public function test_a_session_opens_a_work_request_for_the_card_and_records_the_action(): void
    {
        $this->sessionBridge();
        $item = $this->runItem();

        $action = $this->runAction($item, InboxActionKind::StartSession, 'Pair with me') ?? throw new \LogicException('A session records a row.');

        $request = $this->em()->find(WorkRequest::class, $action->workRequestId);
        self::assertInstanceOf(WorkRequest::class, $request);
        self::assertSame(
            [WorkSubject::CARD, (string) $this->card->id, $this->card->number, 'session', 'session', 'inbox:start-session', WorkRequestState::Open, null],
            [$request->subjectType, (string) $request->subjectId, $request->cardNumber, $request->kind, $request->capability, $request->ruleId, $request->state, $request->bridgeId],
        );
        self::assertSame([(string) $action->id, $item->number, (string) $item->id], [$request->context->inboxActionId, $request->context->inboxItemNumber, $request->context->inboxItemId]);
        self::assertNull($request->prompt);
        self::assertNull($action->bridgeCommandId);
        self::assertSame('Pair with me', $action->note);
        self::assertSame((string) $action->id, $this->audit->record('inbox.action_requested')->context['actionId']);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM outbox_events WHERE type = 'bridge.work_request'"));
    }

    public function test_a_session_without_a_prompt_sends_none_and_audits_no_prompt(): void
    {
        $this->sessionBridge();
        $item = $this->runItem();

        $action = $this->runAction($item, InboxActionKind::StartSession, '', "  \n ") ?? throw new \LogicException('A session records a row.');

        self::assertNull($action->sessionPrompt);
        self::assertNull($this->em()->find(WorkRequest::class, $action->workRequestId)?->prompt);
        self::assertFalse($this->audit->record('inbox.action_requested')->context['hasPrompt']);
    }

    public function test_a_session_sends_the_prefilled_prompt_the_owner_left_unchanged(): void
    {
        $this->sessionBridge();
        $item = $this->todoWithSessionPrompt('Use the skill.');

        $action = $this->runAction($item, InboxActionKind::StartSession, '', 'Use the skill.') ?? throw new \LogicException('A session records a row.');

        self::assertSame('Use the skill.', $action->sessionPrompt);
        self::assertSame('Use the skill.', $this->em()->find(WorkRequest::class, $action->workRequestId)?->prompt);
        $context = $this->audit->record('inbox.action_requested')->context;
        self::assertTrue($context['hasPrompt']);
        self::assertStringNotContainsString('Use the skill.', (string) json_encode($context));
    }

    public function test_a_session_sends_the_prompt_the_owner_edited(): void
    {
        $this->sessionBridge();
        $item = $this->todoWithSessionPrompt('Use the skill.');

        $action = $this->runAction($item, InboxActionKind::StartSession, '', "  Use the skill. Part 1 only.\n") ?? throw new \LogicException('A session records a row.');

        self::assertSame('Use the skill. Part 1 only.', $action->sessionPrompt);
        self::assertSame('Use the skill. Part 1 only.', $this->em()->find(WorkRequest::class, $action->workRequestId)?->prompt);
    }

    public function test_a_cleared_field_starts_a_session_without_the_suggested_prompt(): void
    {
        $this->sessionBridge();
        $item = $this->todoWithSessionPrompt('Use the skill.');

        $action = $this->runAction($item, InboxActionKind::StartSession, '', '   ') ?? throw new \LogicException('A session records a row.');

        self::assertNull($action->sessionPrompt);
        self::assertNull($this->em()->find(WorkRequest::class, $action->workRequestId)?->prompt);
    }

    public function test_a_suggestion_stored_before_the_prompt_existed_starts_a_plain_session(): void
    {
        $this->sessionBridge();
        $item = $this->todoWithSessionPrompt(null);

        $action = $this->runAction($item, InboxActionKind::StartSession) ?? throw new \LogicException('A session records a row.');

        self::assertNull($action->sessionPrompt);
        self::assertNull($this->em()->find(WorkRequest::class, $action->workRequestId)?->prompt);
    }

    public function test_an_edited_prompt_over_the_flag_limit_is_refused_and_leaves_no_row(): void
    {
        $this->sessionBridge();
        $item = $this->todoWithSessionPrompt('Use the skill.');
        $flags = $this->service(FeatureFlagRepository::class);
        $flags->findAllIndexed()[SessionPromptLimit::FLAG]->value = 5;
        $this->em()->flush();

        try {
            $this->runAction($item, InboxActionKind::StartSession, '', 'abcdef');
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['action' => RunInboxActionHandler::SESSION_PROMPT_TOO_LONG], $e->errors);
        }
        self::assertSame([], $this->service(InboxActionRepository::class)->findByItem($item));
        self::assertNotNull($this->runAction($item, InboxActionKind::StartSession, '', 'abcde'));
    }

    public function test_a_session_never_resumes_the_session_of_an_earlier_run(): void
    {
        $this->sessionBridge();
        $item = $this->runItem();
        $previous = new WorkRequest($this->project, WorkSubject::CARD, $this->card->id ?? throw new \LogicException('Stored.'), $this->card->number, 'session', 'session', RunInboxActionHandler::SESSION_RULE_ID, new \DateTimeImmutable('-1 hour'));
        $previous->state = WorkRequestState::Done;
        $this->em()->persist($previous);
        $this->em()->flush();
        $this->em()->persist(new WorkerRun(
            project: $this->project,
            bridgeId: $this->bridge->id,
            subjectType: WorkSubject::CARD,
            subjectId: $this->card->id,
            cardNumber: $this->card->number,
            workKind: 'session',
            state: WorkerRunState::Unfinished,
            runKey: Uuid::v7(),
            sessionId: Uuid::v4(),
            endedAt: new \DateTimeImmutable('-30 minutes'),
            workRequestId: $previous->id,
        ));
        $this->em()->flush();

        $action = $this->runAction($item, InboxActionKind::StartSession) ?? throw new \LogicException('A session records a row.');

        self::assertNull($this->em()->find(WorkRequest::class, $action->workRequestId)?->resumeSessionId);
    }

    public function test_a_session_is_refused_while_no_bridge_can_open_one(): void
    {
        $item = $this->runItem();

        $this->assertRefused($item, InboxActionKind::StartSession, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);

        self::assertSame([], $this->service(InboxActionRepository::class)->findByItem($item));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM work_requests'));
        self::assertSame([], $this->audit->records('inbox.action_requested'));
    }

    public function test_a_session_is_refused_for_an_item_with_no_card(): void
    {
        $this->sessionBridge();
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Wait, title: 'No card', blocking: true);
        $this->em()->persist($item);
        $this->em()->flush();

        $this->assertRefused($item, InboxActionKind::StartSession, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);
    }

    public function test_a_session_is_refused_while_a_session_request_of_the_card_is_live_and_leaves_no_row(): void
    {
        $this->sessionBridge();
        $first = $this->runItem();
        $this->runAction($first, InboxActionKind::StartSession);
        $second = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Question, title: 'Which column?', blocking: true);
        $second->cards->add(new InboxItemCard($second, $this->card));
        $this->em()->persist($second);
        $this->em()->flush();

        $this->assertRefused($second, InboxActionKind::StartSession, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);

        $this->em()->clear();
        self::assertSame([], $this->service(InboxActionRepository::class)->findByItem($second));
        self::assertCount(1, $this->audit->records('inbox.action_requested'));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM work_requests'));
    }

    public function test_a_cancel_withdraws_the_open_session_request_and_marks_the_action(): void
    {
        $this->sessionBridge();
        $item = $this->runItem();
        $action = $this->runAction($item, InboxActionKind::StartSession) ?? throw new \LogicException('A session records a row.');

        ($this->cancelHandler())(new CancelInboxActionCommand($action, $item, $this->project->owner));

        self::assertNotNull($action->cancelledAt);
        self::assertSame(WorkRequestState::Cancelled, $this->em()->find(WorkRequest::class, $action->workRequestId)?->state);
        self::assertSame('start-session', $this->audit->record('inbox.action_cancelled')->context['kind']);
    }

    public function test_a_cancel_of_a_claimed_session_request_is_refused(): void
    {
        $this->sessionBridge();
        $item = $this->runItem();
        $action = $this->runAction($item, InboxActionKind::StartSession) ?? throw new \LogicException('A session records a row.');
        $request = $this->em()->find(WorkRequest::class, $action->workRequestId) ?? throw new \LogicException('Stored.');
        $request->state = WorkRequestState::Claimed;
        $this->em()->flush();

        $this->assertCancelRefused($action, $item, CancelInboxActionHandler::NOT_CANCELLABLE);

        self::assertNull($action->cancelledAt);
        self::assertSame(WorkRequestState::Claimed, $this->em()->find(WorkRequest::class, $action->workRequestId)?->state);
        self::assertSame([], $this->audit->records('inbox.action_cancelled'));
    }

    public function test_a_cancel_of_a_session_with_no_request_is_refused(): void
    {
        $item = $this->runItem();
        $action = new InboxAction($item, InboxActionKind::StartSession, $this->project->owner);
        $this->em()->persist($action);
        $this->em()->flush();

        $this->assertCancelRefused($action, $item, CancelInboxActionHandler::NOT_CANCELLABLE);
    }

    public function test_a_run_command_opens_a_request_for_the_asking_bridge_on_the_only_card(): void
    {
        $this->commandBridge();
        $item = $this->commandTodo();

        $action = $this->runAction($item, InboxActionKind::RunCommand, ' Run it. ') ?? throw new \LogicException('A command records a row.');

        self::assertSame('Run it.', $action->note);
        $request = $this->em()->find(WorkRequest::class, $action->workRequestId) ?? throw new \LogicException('The request is stored.');
        self::assertSame([WorkSubject::CARD, (string) $this->card->id, $this->card->number], [$request->subjectType, (string) $request->subjectId, $request->cardNumber]);
        self::assertSame(['design-export', 'run-command', 'inbox:run-command', WorkRequestState::Open], [$request->kind, $request->capability, $request->ruleId, $request->state]);
        self::assertSame([(string) $this->bridge->id, 'epic/42', ['out' => 'var/out']], [(string) $request->targetBridgeId, $request->branch, $request->params]);
        self::assertSame([(string) $action->id, $item->number, (string) $item->id], [(string) $request->inboxActionId, $request->inboxItemNumber, (string) $request->inboxItemId]);
        self::assertNull($action->waitId);
        self::assertSame($this->project->owner, $action->requestedBy);
        $record = $this->audit->record('inbox.action_requested');
        self::assertSame([$action->id?->toRfc4122(), 'run-command'], [$record->context['actionId'], $record->context['kind']]);
    }

    public function test_a_todo_with_no_card_runs_on_the_item_itself(): void
    {
        $this->commandBridge();
        $item = $this->commandTodo(cards: 0);

        $action = $this->runAction($item, InboxActionKind::RunCommand) ?? throw new \LogicException('A command records a row.');

        $request = $this->em()->find(WorkRequest::class, $action->workRequestId) ?? throw new \LogicException('The request is stored.');
        self::assertSame([InboxItem::SUBJECT_TYPE, (string) $item->id, null], [$request->subjectType, (string) $request->subjectId, $request->cardNumber]);
    }

    public function test_a_todo_with_two_cards_runs_on_the_item_itself(): void
    {
        $this->commandBridge();
        $other = $this->service(CreateCardHandler::class)(new CreateCardCommand(project: $this->project, title: 'Other', body: 'Body', type: 'feature', column: $this->column($this->project, 'tech-design'), reporter: Actor::Human));
        $item = $this->commandTodo(cards: 0);
        $item->cards->add(new InboxItemCard($item, $this->card));
        $item->cards->add(new InboxItemCard($item, $other));
        $this->em()->flush();

        $action = $this->runAction($item, InboxActionKind::RunCommand) ?? throw new \LogicException('A command records a row.');

        self::assertSame(InboxItem::SUBJECT_TYPE, $this->em()->find(WorkRequest::class, $action->workRequestId)?->subjectType);
    }

    public function test_a_wait_item_runs_on_the_watched_card_and_records_the_wait(): void
    {
        $this->commandBridge();
        $item = $this->runItem();
        $run = $this->em()->find(WorkerRun::class, $this->waitOf($item)->runId) ?? throw new \LogicException('Stored.');
        $run->resultFields = ['suggestedActions' => [['action' => 'run-command', 'reason' => 'Export first.', 'recommended' => true, 'command' => ['entry' => 'design-export', 'branch' => 'main', 'params' => [['name' => 'out', 'value' => 'var/out']]]]]];
        $this->em()->flush();

        $action = $this->runAction($item, InboxActionKind::RunCommand) ?? throw new \LogicException('A command records a row.');

        $request = $this->em()->find(WorkRequest::class, $action->workRequestId) ?? throw new \LogicException('The request is stored.');
        self::assertSame([WorkSubject::CARD, (string) $this->card->id, 'main'], [$request->subjectType, (string) $request->subjectId, $request->branch]);
        self::assertSame((string) $this->waitOf($item)->id, (string) $action->waitId);
    }

    public function test_the_values_the_owner_confirmed_are_the_ones_the_request_carries(): void
    {
        $this->commandBridge();
        $item = $this->commandTodo();

        $action = ($this->runHandler())(new RunInboxActionCommand($item, InboxActionKind::RunCommand, '', $this->project->owner, 'main', ['out' => 'other/folder'], self::shownDigest())) ?? throw new \LogicException('A command records a row.');

        $request = $this->em()->find(WorkRequest::class, $action->workRequestId) ?? throw new \LogicException('The request is stored.');
        self::assertSame(['main', ['out' => 'other/folder'], ['bin/console', 'app:export', '{out}']], [$request->branch, $request->params, $request->run]);
    }

    /** @return iterable<string, array{?string}> */
    public static function staleDigests(): iterable
    {
        $before = ['bin/console', 'app:export', '--force', '{out}'];
        yield 'the run list before an edit' => [hash('sha256', json_encode([$before, $before], \JSON_THROW_ON_ERROR))];
        yield 'the run list alone, with no filled values' => [hash('sha256', json_encode(['bin/console', 'app:export', '{out}'], \JSON_THROW_ON_ERROR))];
        yield 'no digest' => [null];
    }

    #[DataProvider('staleDigests')]
    public function test_a_run_list_other_than_the_one_the_dialog_showed_is_refused_and_leaves_no_request(?string $digest): void
    {
        $this->commandBridge();
        $item = $this->commandTodo();

        try {
            ($this->runHandler())(new RunInboxActionCommand($item, InboxActionKind::RunCommand, '', $this->project->owner, runDigest: $digest));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['action' => RunInboxActionHandler::COMMAND_CHANGED], $e->errors);
        }

        self::assertSame([], $this->service(InboxActionRepository::class)->findByItem($item));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM work_requests'));
    }

    public function test_a_pull_request_head_that_moved_after_the_dialog_opened_is_refused_and_the_request_carries_the_head_shown(): void
    {
        $this->commandBridge();
        $this->bridge->commandEntries = [[
            'name' => 'design-export',
            'description' => null,
            'params' => [['name' => 'out', 'pattern' => '[a-z/]+', 'description' => null]],
            'run' => ['bin/deploy', '{headSha}', '{out}'],
            'timeout' => '10m',
        ]];
        $this->em()->persist(new CardPullRequest($this->card, 'https://github.com/Acme/Widgets/pull/7', Forge::GitHub, 'Acme/Widgets', 7));
        $pullRequest = new ForgePullRequest($this->project, 'github', 'acme/widgets', 7);
        $pullRequest->state = PullRequestState::Open;
        $pullRequest->headSha = str_repeat('a', 40);
        $this->em()->persist($pullRequest);
        $this->em()->flush();
        $item = $this->commandTodo();
        $shown = $this->itemActions()->runCommandFor($item) ?? throw new \LogicException('The item has a command.');
        self::assertSame(['bin/deploy', str_repeat('a', 40), 'var/out'], $shown->argv);

        $pullRequest->headSha = str_repeat('b', 40);
        $this->em()->flush();
        try {
            ($this->runHandler())(new RunInboxActionCommand($item, InboxActionKind::RunCommand, '', $this->project->owner, runDigest: $shown->runDigest()));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['action' => RunInboxActionHandler::COMMAND_CHANGED], $e->errors);
        }

        $reread = $this->itemActions()->runCommandFor($item) ?? throw new \LogicException('The item has a command.');
        $action = ($this->runHandler())(new RunInboxActionCommand($item, InboxActionKind::RunCommand, '', $this->project->owner, runDigest: $reread->runDigest())) ?? throw new \LogicException('A command records a row.');
        $request = $this->em()->find(WorkRequest::class, $action->workRequestId) ?? throw new \LogicException('The request is stored.');
        self::assertSame(str_repeat('b', 40), $request->context->headSha);
    }

    public function test_a_quiet_bridge_takes_the_run_as_a_request_that_waits_for_it(): void
    {
        $this->commandBridge();
        $item = $this->commandTodo();
        $this->bridge->lastSeenAt = new \DateTimeImmutable('-1 day');
        $this->em()->flush();

        $action = $this->runAction($item, InboxActionKind::RunCommand) ?? throw new \LogicException('A command records a row.');

        $request = $this->em()->find(WorkRequest::class, $action->workRequestId) ?? throw new \LogicException('The request is stored.');
        self::assertSame([(string) $this->bridge->id, WorkRequestState::Open], [(string) $request->targetBridgeId, $request->state]);
    }

    /** @return iterable<string, array{?string, ?array<string, string>, callable(self): void, string}> */
    public static function refusedCommands(): iterable
    {
        yield 'a value that fails its pattern' => [null, ['out' => 'Var/Out'], static function (self $test): void {}, 'inbox.action.run-command.disabled.param_invalid'];
        yield 'a value for a param the entry lacks' => [null, ['out' => 'x', 'extra' => 'y'], static function (self $test): void {}, 'inbox.action.run-command.disabled.param_invalid'];
        yield 'a branch with no worktree' => ['no-such-branch', null, static function (self $test): void {}, 'inbox.action.run-command.disabled.no_worktree'];
        yield 'a branch that is no git branch name' => ['--upload-pack=x', null, static function (self $test): void {}, 'inbox.action.run-command.disabled.no_worktree'];
        yield 'an entry the bridge no longer lists' => [null, null, static function (self $test): void {
            $test->bridge->commandEntries = [];
        }, 'inbox.action.run-command.disabled.entry_missing'];
        yield 'a bridge that lost the capability' => [null, null, static function (self $test): void {
            $test->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        }, 'inbox.action.run-command.disabled.unsupported'];
    }

    /**
     * @param ?array<string, string> $params
     * @param callable(self): void   $change
     */
    #[DataProvider('refusedCommands')]
    public function test_a_command_that_fails_a_check_again_is_refused_and_leaves_no_row_and_no_request(?string $branch, ?array $params, callable $change, string $key): void
    {
        $this->commandBridge();
        $item = $this->commandTodo();
        $change($this);
        $this->em()->flush();

        try {
            ($this->runHandler())(new RunInboxActionCommand($item, InboxActionKind::RunCommand, '', $this->project->owner, $branch, $params, self::shownDigest()));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['action' => $key], $e->errors);
        }

        self::assertSame([], $this->service(InboxActionRepository::class)->findByItem($item));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM work_requests'));
        self::assertSame([], $this->audit->records('inbox.action_requested'));
    }

    public function test_a_todo_without_a_run_command_suggestion_refuses_the_action(): void
    {
        $this->commandBridge();
        $item = $this->commandTodo(cards: 1, suggestions: [['action' => 'open', 'reason' => 'Read it.', 'recommended' => true, 'cardId' => null]]);

        $this->assertRefused($item, InboxActionKind::RunCommand, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);
    }

    public function test_a_closed_item_refuses_the_action(): void
    {
        $this->commandBridge();
        $item = $this->commandTodo();
        $item->state = InboxItemState::Done;
        $this->em()->flush();

        $this->assertRefused($item, InboxActionKind::RunCommand, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);
    }

    public function test_a_second_run_while_the_first_is_queued_is_refused_and_keeps_the_first(): void
    {
        $this->commandBridge();
        $item = $this->commandTodo();
        $first = $this->runAction($item, InboxActionKind::RunCommand) ?? throw new \LogicException('A command records a row.');

        $this->assertRefused($item, InboxActionKind::RunCommand, ['action' => 'inbox.action.run-command.disabled.running']);

        self::assertSame([$first], $this->service(InboxActionRepository::class)->findByItem($item));
    }

    public function test_a_run_again_after_a_refused_request_opens_a_new_request(): void
    {
        $this->commandBridge();
        $item = $this->commandTodo();
        $first = $this->runAction($item, InboxActionKind::RunCommand) ?? throw new \LogicException('A command records a row.');
        $request = $this->em()->find(WorkRequest::class, $first->workRequestId) ?? throw new \LogicException('Stored.');
        $request->state = WorkRequestState::Refused;
        $request->reason = 'worktree-missing';
        $this->em()->flush();

        $second = $this->runAction($item, InboxActionKind::RunCommand);

        self::assertInstanceOf(InboxAction::class, $second);
        self::assertNotSame((string) $first->workRequestId, (string) $second->workRequestId);
        self::assertCount(2, $this->service(InboxActionRepository::class)->findByItem($item));
    }

    public function test_the_workflow_request_of_the_same_kind_on_the_card_blocks_the_run_and_leaves_no_row(): void
    {
        $this->commandBridge();
        $item = $this->commandTodo();
        $live = new WorkRequest($this->project, WorkSubject::CARD, $this->card->id ?? throw new \LogicException('Stored.'), $this->card->number, 'design-export', null, 'teardown-on-done', new \DateTimeImmutable());
        $this->em()->persist($live);
        $this->em()->flush();

        $this->assertRefused($item, InboxActionKind::RunCommand, ['action' => RunInboxActionHandler::COMMAND_LIVE]);

        self::assertSame([], $this->service(InboxActionRepository::class)->findByItem($item));
    }

    public function test_a_queued_command_request_can_be_cancelled(): void
    {
        $this->commandBridge();
        $item = $this->commandTodo();
        $action = $this->runAction($item, InboxActionKind::RunCommand) ?? throw new \LogicException('A command records a row.');

        ($this->cancelHandler())(new CancelInboxActionCommand($action, $item, $this->project->owner));

        self::assertNotNull($action->cancelledAt);
        self::assertSame(WorkRequestState::Cancelled, $this->em()->find(WorkRequest::class, $action->workRequestId)?->state);
    }

    public function test_an_add_entry_opens_a_request_for_the_asking_bridge_on_the_item_even_with_a_card(): void
    {
        $this->addBridge();
        $item = $this->commandTodo(suggestions: [self::proposalSuggestion()]);

        $action = $this->runAction($item, InboxActionKind::AddEntry, ' Add it. ') ?? throw new \LogicException('An add records a row.');

        self::assertSame('Add it.', $action->note);
        self::assertNull($action->waitId);
        $request = $this->em()->find(WorkRequest::class, $action->workRequestId) ?? throw new \LogicException('The request is stored.');
        self::assertSame([InboxItem::SUBJECT_TYPE, (string) $item->id, null], [$request->subjectType, (string) $request->subjectId, $request->cardNumber]);
        self::assertSame(['add-entry', 'add-entry', 'inbox:add-entry', WorkRequestState::Open], [$request->kind, $request->capability, $request->ruleId, $request->state]);
        self::assertSame((string) $this->bridge->id, (string) $request->targetBridgeId);
        self::assertSame("design-export:\n  action: command\n  params:\n    out: { pattern: '[a-z/]+' }\n  run:\n    - make\n    - export\n", $request->prompt);
        self::assertSame([(string) $action->id, $item->number, (string) $item->id], [(string) $request->inboxActionId, $request->inboxItemNumber, (string) $request->inboxItemId]);
        self::assertNull($request->branch);
        self::assertNull($request->params);
        $record = $this->audit->record('inbox.action_requested');
        self::assertSame([$action->id?->toRfc4122(), 'add-entry'], [$record->context['actionId'], $record->context['kind']]);
    }

    /** @return iterable<string, array{\Closure(self): void}> */
    public static function refusedAdds(): iterable
    {
        yield 'an entry the bridge already has' => [static function (self $test): void {
            $test->bridge->commandEntries = [['name' => 'design-export', 'description' => null, 'params' => [], 'run' => ['make'], 'timeout' => '10m']];
        }];
        yield 'a bridge that did not opt in' => [static function (self $test): void {
            $test->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_RUN_COMMAND];
        }];
    }

    #[DataProvider('refusedAdds')]
    public function test_an_add_the_view_does_not_offer_is_refused_and_leaves_no_row_and_no_request(\Closure $change): void
    {
        $this->addBridge();
        $item = $this->commandTodo(suggestions: [self::proposalSuggestion()]);
        $change($this);
        $this->em()->flush();

        $this->assertRefused($item, InboxActionKind::AddEntry, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);

        self::assertSame([], $this->service(InboxActionRepository::class)->findByItem($item));
    }

    public function test_an_add_without_a_proposed_entry_is_refused(): void
    {
        $this->addBridge();
        $item = $this->commandTodo();

        $this->assertRefused($item, InboxActionKind::AddEntry, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);
    }

    public function test_an_add_of_an_item_with_no_run_command_suggestion_is_refused(): void
    {
        $this->addBridge();
        $item = $this->commandTodo(suggestions: [['action' => 'open', 'reason' => 'See it.', 'recommended' => true, 'cardId' => null, 'command' => null, 'proposedEntry' => null]]);

        $this->assertRefused($item, InboxActionKind::AddEntry, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);
    }

    public function test_an_add_of_a_closed_item_is_refused(): void
    {
        $this->addBridge();
        $item = $this->commandTodo(suggestions: [self::proposalSuggestion()]);
        $item->state = InboxItemState::Done;
        $this->em()->flush();

        $this->assertRefused($item, InboxActionKind::AddEntry, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);
    }

    public function test_a_second_add_while_the_first_is_live_is_refused_and_keeps_the_first(): void
    {
        $this->addBridge();
        $item = $this->commandTodo(suggestions: [self::proposalSuggestion()]);
        $first = $this->runAction($item, InboxActionKind::AddEntry) ?? throw new \LogicException('An add records a row.');

        $this->assertRefused($item, InboxActionKind::AddEntry, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);

        self::assertSame([$first->id?->toRfc4122()], array_map(static fn (InboxAction $action): ?string => $action->id?->toRfc4122(), $this->service(InboxActionRepository::class)->findByItem($item)));
    }

    public function test_a_request_that_loses_the_race_leaves_no_add_row(): void
    {
        $this->addBridge();
        $item = $this->commandTodo(suggestions: [self::proposalSuggestion()]);
        $live = new WorkRequest(
            project: $this->project,
            subjectType: InboxItem::SUBJECT_TYPE,
            subjectId: $item->id ?? throw new \LogicException('The item is stored.'),
            cardNumber: null,
            kind: 'add-entry',
            capability: Bridge::CAPABILITY_ADD_ENTRY,
            ruleId: 'inbox:add-entry',
            createdAt: new \DateTimeImmutable(),
        );
        $this->em()->persist($live);
        $this->em()->flush();

        $this->assertRefused($item, InboxActionKind::AddEntry, ['action' => RunInboxActionHandler::NOT_AVAILABLE]);

        self::assertSame([], $this->service(InboxActionRepository::class)->findByItem($item));
    }

    public function test_a_queued_add_can_be_cancelled(): void
    {
        $this->addBridge();
        $item = $this->commandTodo(suggestions: [self::proposalSuggestion()]);
        $action = $this->runAction($item, InboxActionKind::AddEntry) ?? throw new \LogicException('An add records a row.');

        ($this->cancelHandler())(new CancelInboxActionCommand($action, $item, $this->project->owner));

        self::assertNotNull($action->cancelledAt);
        self::assertSame(WorkRequestState::Cancelled, $this->em()->find(WorkRequest::class, $action->workRequestId)?->state);
    }

    public function test_a_claimed_add_is_not_cancelled(): void
    {
        $this->addBridge();
        $item = $this->commandTodo(suggestions: [self::proposalSuggestion()]);
        $action = $this->runAction($item, InboxActionKind::AddEntry) ?? throw new \LogicException('An add records a row.');
        $request = $this->em()->find(WorkRequest::class, $action->workRequestId) ?? throw new \LogicException('The request is stored.');
        $request->state = WorkRequestState::Claimed;
        $this->em()->flush();

        $this->assertCancelRefused($action, $item, CancelInboxActionHandler::NOT_CANCELLABLE);
    }

    private function addBridge(): void
    {
        $this->commandBridge();
        $this->bridge->commandEntries = [];
        $this->bridge->capabilities = [...($this->bridge->capabilities ?? []), Bridge::CAPABILITY_ADD_ENTRY];
        $this->em()->flush();
    }

    /** @return array{action: string, reason: string, recommended: bool, cardId: ?string, command: array{entry: string, branch: string, params: array<string, string>}, proposedEntry: string} */
    private static function proposalSuggestion(): array
    {
        return [
            'action' => 'run-command',
            'reason' => 'Export it.',
            'recommended' => true,
            'cardId' => null,
            'command' => ['entry' => 'design-export', 'branch' => 'epic/42', 'params' => ['out' => 'var/out']],
            'proposedEntry' => "action: command\nparams:\n  out: { pattern: '[a-z/]+' }\nrun: [make, export]",
        ];
    }

    private function commandBridge(): void
    {
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_RUN_COMMAND];
        $this->bridge->projects = [(string) $this->project->id];
        $this->bridge->lastSeenAt = new \DateTimeImmutable();
        $this->bridge->commandEntries = [[
            'name' => 'design-export',
            'description' => null,
            'params' => [['name' => 'out', 'pattern' => '[a-z/]+', 'description' => null]],
            'run' => ['bin/console', 'app:export', '{out}'],
            'timeout' => '10m',
        ]];
        $this->bridge->worktrees = [
            ['projectId' => (string) $this->project->id, 'branch' => 'main', 'path' => '/work/loupe'],
            ['projectId' => (string) $this->project->id, 'branch' => 'epic/42', 'path' => '/work/loupe/.worktrees/card-42'],
        ];
        $this->em()->flush();
    }

    /** @param list<array{action: string, reason: string, recommended: bool, cardId: ?string, command?: array{entry: string, branch: string, params: array<string, string>}|null, proposedEntry?: string|null}>|null $suggestions */
    private function commandTodo(int $cards = 1, ?array $suggestions = null): InboxItem
    {
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Todo, title: 'Export the design system', blocking: false);
        $item->suggestedActions = $suggestions ?? [[
            'action' => 'run-command',
            'reason' => 'Export it.',
            'recommended' => true,
            'cardId' => null,
            'command' => ['entry' => 'design-export', 'branch' => 'epic/42', 'params' => ['out' => 'var/out']],
            'proposedEntry' => null,
        ]];
        if ($cards > 0) {
            $item->cards->add(new InboxItemCard($item, $this->card));
        }
        $this->em()->persist($item);
        $ask = new InboxAsk(project: $this->project, sessionId: Uuid::v4(), bridgeId: $this->bridge->id);
        $this->em()->persist($ask);
        $this->em()->persist(new InboxAskItem($ask, $item));
        $this->em()->flush();

        return $item;
    }

    private function todoWithSessionPrompt(?string $prompt): InboxItem
    {
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Todo, title: 'Rebuild it', blocking: false);
        $item->cards->add(new InboxItemCard($item, $this->card));
        $suggestion = ['action' => 'start-session', 'reason' => 'Talk it through.', 'recommended' => true, 'cardId' => (string) $this->card->id];
        $item->suggestedActions = [null === $prompt ? $suggestion : [...$suggestion, 'prompt' => $prompt]];
        $this->em()->persist($item);
        $this->em()->flush();

        return $item;
    }

    private function sessionBridge(): void
    {
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_SESSION];
        $this->bridge->projects = [(string) $this->project->id];
        $this->em()->flush();
    }

    /** @param array<string, string> $errors */
    private function assertRefused(InboxItem $item, InboxActionKind $kind, array $errors, string $note = ''): void
    {
        try {
            $this->runAction($item, $kind, $note);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame($errors, $e->errors);
        }
    }

    private function assertCancelRefused(InboxAction $action, InboxItem $item, string $key): void
    {
        try {
            ($this->cancelHandler())(new CancelInboxActionCommand($action, $item, $this->project->owner));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['action' => $key], $e->errors);
        }
    }

    private function runAction(InboxItem $item, InboxActionKind $kind, string $note = '', ?string $sessionPrompt = null): ?InboxAction
    {
        return ($this->runHandler())(new RunInboxActionCommand($item, $kind, $note, $this->project->owner, runDigest: InboxActionKind::RunCommand === $kind ? self::shownDigest() : null, sessionPrompt: $sessionPrompt));
    }

    /** The digest of the run list of the entry that commandBridge() reports, as the dialog shows it. */
    private static function shownDigest(): string
    {
        $run = ['bin/console', 'app:export', '{out}'];

        return hash('sha256', json_encode([$run, $run], \JSON_THROW_ON_ERROR));
    }

    /** Built by hand, because nothing injects the handlers yet and the compiler drops an unused private service. */
    private function runHandler(?WorkRequestRepository $workRequests = null): RunInboxActionHandler
    {
        return new RunInboxActionHandler(
            $this->itemActions($workRequests),
            $this->service(RequestBridgeCommandHandler::class),
            $this->service(OpenWorkRequestHandler::class),
            $this->service(WorkRequestContexts::class),
            $this->service(CardRepository::class),
            $this->service(ReleaseWorkflowPauseHandler::class),
            $this->service(DeclineInboxItemHandler::class),
            $this->service(WorkerRunRepository::class),
            $this->service(CardDirectory::class),
            $this->em(),
            $this->audit->auditor,
            $this->service(SessionPromptLimit::class),
        );
    }

    private function itemActions(?WorkRequestRepository $workRequests = null): ItemActions
    {
        return new ItemActions(
            $this->service(InboxCardWatchRepository::class),
            $this->service(WorkerRunRepository::class),
            $this->service(WorkerRunControls::class),
            $this->service(CardPauses::class),
            new SessionBridges($this->service(BridgeRepository::class), $this->service(BridgeLiveness::class)),
            $workRequests ?? $this->service(WorkRequestRepository::class),
            $this->service(TemplateStageWorks::class),
            $this->service(AskingBridge::class),
            $this->service(RunCommandResolver::class),
            $this->service(RunCommandRuns::class),
        );
    }

    private function cancelHandler(): CancelInboxActionHandler
    {
        return new CancelInboxActionHandler(
            $this->service(BridgeCommandRepository::class),
            $this->service(CancelBridgeCommandHandler::class),
            $this->service(WithdrawWorkRequestHandler::class),
            $this->em(),
            $this->audit->auditor,
        );
    }

    private function runItem(WorkerRunState $state = WorkerRunState::Blocked, ?Card $card = null): InboxItem
    {
        $card ??= $this->card;
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: $this->bridge->id,
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('Stored.'),
            cardNumber: $card->number,
            workKind: 'implement',
            state: $state,
            runKey: Uuid::v7(),
            sessionId: Uuid::v4(),
        );
        $this->em()->persist($run);
        $this->em()->flush();

        return $this->itemWithWait(static fn (InboxCardWatch $watch): InboxCardWait => new InboxCardWait($watch, InboxCardWaitTrigger::RunBlocked, InboxCardWaitType::WorkerRun, InboxCardWaitReason::Blocked, runId: $run->id), $card);
    }

    private function pauseItem(PauseKind $kind): InboxItem
    {
        $pause = $this->service(PauseCardHandler::class)(new PauseCardCommand($this->card, 'on-hold', 'review-rule', $kind))
            ?? throw new \LogicException('The card had no pause.');

        return $this->itemWithWait(static fn (InboxCardWatch $watch): InboxCardWait => new InboxCardWait($watch, InboxCardWaitTrigger::CardPaused, InboxCardWaitType::CardPause, InboxCardWaitReason::forPause($kind), pauseId: $pause->id));
    }

    /** @param \Closure(InboxCardWatch): InboxCardWait $wait */
    private function itemWithWait(\Closure $wait, ?Card $card = null): InboxItem
    {
        $card ??= $this->card;
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Wait, title: '#1 Ship it', blocking: true);
        $this->em()->persist($item);
        $watch = new InboxCardWatch($item, $card->id ?? throw new \LogicException('Stored.'), $card->number);
        $this->em()->persist($watch);
        $watch->waits->add($wait($watch));
        $this->em()->flush();

        return $item;
    }

    private function waitOf(InboxItem $item): InboxCardWait
    {
        $watch = $this->service(InboxCardWatchRepository::class)->findOneForItem($item) ?? throw new \LogicException('A wait item has a watch.');

        return $watch->waits->first() ?: throw new \LogicException('The watch has a wait.');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
