<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Workflow\WorkRequestContexts;
use App\Module\Bridge\Command\OpenWorkRequestCommand;
use App\Module\Bridge\Command\OpenWorkRequestHandler;
use App\Module\Bridge\Command\RequestBridgeCommandCommand;
use App\Module\Bridge\Command\RequestBridgeCommandHandler;
use App\Module\Bridge\Command\WorkRequestAlreadyLive;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\BridgeCommandCause;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Service\ItemActions;
use App\Module\Inbox\Service\SessionPromptLimit;
use App\Module\Workflow\Command\ReleaseWorkflowPauseCommand;
use App\Module\Workflow\Command\ReleaseWorkflowPauseHandler;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardDirectory;
use App\Module\Workflow\Contract\PersonWork;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Runs one action that the owner chose on an inbox item. A resume asks the bridge, a retry releases
 * the pause, send an agent, a session, a command and an added entry each open a work request, and a dismiss closes the item.
 * The note stays in Loupe: no command carries its text.
 * A dismiss records no row, because the closed item holds the note as its close note.
 */
final readonly class RunInboxActionHandler
{
    public const string NOTE_TOO_LONG = 'inbox.action.error.note_too_long';
    public const string SESSION_PROMPT_TOO_LONG = 'inbox.action.error.session_prompt_too_long';
    public const string NOT_AVAILABLE = 'inbox.action.error.not_available';

    public const string COMMAND_LIVE = 'inbox.action.error.run_command_live';
    public const string COMMAND_CHANGED = 'inbox.action.error.run_command_changed';

    public const string SESSION_RULE_ID = PersonWork::RULE_PREFIX.'start-session';
    public const string RUN_COMMAND_RULE_ID = PersonWork::RULE_PREFIX.'run-command';
    public const string ADD_ENTRY_RULE_ID = PersonWork::RULE_PREFIX.'add-entry';

    public function __construct(
        private ItemActions $itemActions,
        private RequestBridgeCommandHandler $requestBridgeCommand,
        private OpenWorkRequestHandler $openWorkRequest,
        private WorkRequestContexts $contexts,
        private CardRepository $cards,
        private ReleaseWorkflowPauseHandler $releasePause,
        private DeclineInboxItemHandler $declineItem,
        private WorkerRunRepository $workerRuns,
        private CardDirectory $cardDirectory,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private SessionPromptLimit $promptLimit,
    ) {
    }

    public function __invoke(RunInboxActionCommand $command): ?InboxAction
    {
        $item = $command->item;
        $kind = $command->kind;
        $note = trim($command->note);
        if (mb_strlen($note) > InboxAction::MAX_NOTE_LENGTH) {
            throw new DomainErrors(['note' => self::NOTE_TOO_LONG]);
        }
        if (InboxItemState::Open !== $item->state || !self::runsHere($kind) || (InboxActionKind::AddEntry !== $kind && !array_any($this->itemActions->forItem($item), static fn ($action): bool => $action->kind === $kind && (InboxActionKind::RunCommand === $kind || null === $action->disabledReasonKey)))) {
            throw new DomainErrors(['action' => self::NOT_AVAILABLE]);
        }

        try {
            $action = match ($kind) {
                InboxActionKind::ResumeRun => $this->resume($command, $note),
                InboxActionKind::RetryCard => $this->retry($command, $note),
                InboxActionKind::SendAgent => $this->sendAgent($command, $note),
                InboxActionKind::StartSession => $this->startSession($command, $note),
                InboxActionKind::RunCommand => $this->runCommand($command, $note),
                InboxActionKind::AddEntry => $this->addEntry($command, $note),
                default => $this->dismiss($command, $note),
            };
        } catch (DomainErrors $e) {
            throw new DomainErrors(['action' => array_first($e->errors)]);
        }

        $context = [
            'itemId' => (string) $item->id,
            'projectId' => (string) $item->project->id,
            'kind' => $kind->value,
            'hasNote' => '' !== $note,
        ];
        if (InboxActionKind::StartSession === $kind) {
            $context['hasPrompt'] = null !== $action?->sessionPrompt;
        }
        if (null !== $action) {
            $context['actionId'] = (string) $action->id;
        }
        // After the commits, so a refused action leaves no record. No note text, because a person wrote it.
        $this->auditor->record('inbox.action_requested', AuditOutcome::Success, $context, new AuditSubject('inbox_item', (string) $item->id));

        return $action;
    }

    /** The kinds this handler runs. Open and review are links. */
    private static function runsHere(InboxActionKind $kind): bool
    {
        return \in_array($kind, [InboxActionKind::ResumeRun, InboxActionKind::RetryCard, InboxActionKind::SendAgent, InboxActionKind::StartSession, InboxActionKind::RunCommand, InboxActionKind::AddEntry, InboxActionKind::Dismiss], true);
    }

    private function resume(RunInboxActionCommand $command, string $note): InboxAction
    {
        $wait = $this->waitOf($command, InboxActionKind::ResumeRun);
        $run = $this->workerRuns->find($wait->runId ?? throw new \LogicException('A run wait has a run.'))
            ?? throw new DomainErrors(['action' => self::NOT_AVAILABLE]);

        // The row commits first, so no queued command lacks a row. A refused request removes it again.
        $action = new InboxAction($command->item, InboxActionKind::ResumeRun, $command->user, $note);
        $action->waitId = $wait->id;
        $this->em->persist($action);
        $this->em->flush();

        try {
            $bridgeCommand = ($this->requestBridgeCommand)(new RequestBridgeCommandCommand($run, BridgeCommandKind::ResumeRun, $command->user, null, BridgeCommandCause::InboxAction, $action->id, $command->item->number, $command->item->id));
        } catch (DomainErrors $e) {
            // The bridge closes the entity manager when a race trips its unique index, so delete through the connection.
            $this->em->getConnection()->delete('inbox_actions', ['id' => $action->id?->toRfc4122()]);
            throw $e;
        }

        $action->bridgeCommandId = $bridgeCommand->id;
        $this->em->flush();

        return $action;
    }

    private function retry(RunInboxActionCommand $command, string $note): InboxAction
    {
        $wait = $this->waitOf($command, InboxActionKind::RetryCard);
        $card = $this->cardDirectory->findInProject($command->item->project->id ?? throw new \LogicException('A stored project has an id.'), $wait->watch->cardId)
            ?? throw new DomainErrors(['action' => self::NOT_AVAILABLE]);

        // The row commits before the release, so the rule's next request finds it. A refused release removes it again.
        $action = new InboxAction($command->item, InboxActionKind::RetryCard, $command->user, $note);
        $action->pauseId = $wait->pauseId;
        $action->waitId = $wait->id;
        $this->em->persist($action);
        $this->em->flush();

        try {
            ($this->releasePause)(new ReleaseWorkflowPauseCommand($card, $command->user->id ?? throw new \LogicException('A stored user has an id.'), Actor::Human, $wait->pauseId));
        } catch (DomainErrors $e) {
            $this->em->getConnection()->delete('inbox_actions', ['id' => $action->id?->toRfc4122()]);
            throw $e;
        }

        return $action;
    }

    private function sendAgent(RunInboxActionCommand $command, string $note): InboxAction
    {
        $item = $command->item;
        $target = $this->itemActions->sendAgentFor($item) ?? throw new DomainErrors(['action' => self::NOT_AVAILABLE]);
        $card = $this->cards->find($target->cardId) ?? throw new DomainErrors(['action' => self::NOT_AVAILABLE]);

        // The row commits first, so the request names an action that exists. A refused request removes it again.
        $action = new InboxAction($item, InboxActionKind::SendAgent, $command->user, $note);
        $action->waitId = $this->itemActions->newestOpenWait($item)?->id;
        $this->em->persist($action);
        $this->em->flush();

        try {
            $request = ($this->openWorkRequest)(new OpenWorkRequestCommand(
                project: $item->project,
                subject: WorkSubject::card($target->cardId),
                cardNumber: $card->number,
                kind: $target->kind,
                capability: null,
                ruleId: PersonWork::RULE_PREFIX.'send-agent',
                context: $this->contexts->forCard($card, null),
                fresh: true,
                inboxActionId: $action->id,
                inboxItemNumber: $item->number,
                inboxItemId: $item->id,
            ));
        } catch (WorkRequestAlreadyLive|DomainErrors) {
            // A race can trip the unique index of live requests, which closes the entity manager.
            $this->em->getConnection()->delete('inbox_actions', ['id' => $action->id?->toRfc4122()]);
            throw new DomainErrors(['action' => self::NOT_AVAILABLE]);
        }

        $action->workRequestId = $request->id;
        $this->em->flush();

        return $action;
    }

    private function startSession(RunInboxActionCommand $command, string $note): InboxAction
    {
        $item = $command->item;
        $projectId = $item->project->id ?? throw new \LogicException('A stored project has an id.');
        $card = $this->cardDirectory->findInProject($projectId, $this->itemActions->cardIdOf($item, InboxActionKind::StartSession) ?? throw new DomainErrors(['action' => self::NOT_AVAILABLE]))
            ?? throw new DomainErrors(['action' => self::NOT_AVAILABLE]);

        if (mb_strlen($command->sessionPrompt ?? '') > $this->promptLimit->maxLength()) {
            throw new DomainErrors(['action' => self::SESSION_PROMPT_TOO_LONG]);
        }
        // The field is the prompt, so a field the owner cleared starts a session with no prompt.
        $prompt = trim($command->sessionPrompt ?? '');
        if ('' === $prompt) {
            $prompt = null;
        }

        // The row commits first, because the request names it. A refused request removes it again.
        $action = new InboxAction($item, InboxActionKind::StartSession, $command->user, $note);
        $action->sessionPrompt = $prompt;
        $action->waitId = $this->itemActions->newestOpenWait($item)?->id;
        $this->em->persist($action);
        $this->em->flush();

        try {
            $request = ($this->openWorkRequest)(new OpenWorkRequestCommand(
                project: $item->project,
                subject: WorkSubject::card($card->id),
                cardNumber: $card->number,
                kind: WorkRequest::SESSION_KIND,
                capability: Bridge::CAPABILITY_SESSION,
                ruleId: self::SESSION_RULE_ID,
                context: new WorkRequestContext(inboxActionId: $action->id?->toRfc4122(), inboxItemNumber: $item->number, inboxItemId: $item->id?->toRfc4122()),
                prompt: $prompt,
                fresh: true,
            ));
        } catch (WorkRequestAlreadyLive|DomainErrors $e) {
            // The bridge closes the entity manager when a race trips its unique index, so delete through the connection.
            $this->em->getConnection()->delete('inbox_actions', ['id' => $action->id?->toRfc4122()]);
            throw $e instanceof DomainErrors ? $e : new DomainErrors(['action' => self::NOT_AVAILABLE]);
        }

        $action->workRequestId = $request->id;
        $this->em->flush();

        return $action;
    }

    /**
     * Checks the entry, the values and the worktree again, as the owner confirmed them. The bridge checks them once more at the run.
     * The run list and its filled values must be the ones the dialog showed. The request carries the run list, so the bridge
     * refuses an entry edited since, and the context of those values, so a new pull request head cannot change the argv.
     * A to-do with one card, and a wait item, run on the card, so the bridge queue rule holds the run for a worker of that card.
     */
    private function runCommand(RunInboxActionCommand $command, string $note): InboxAction
    {
        $item = $command->item;
        $view = $this->itemActions->runCommandFor($item, $command->branch, $command->params);
        if (null === $view) {
            throw new DomainErrors(['action' => self::NOT_AVAILABLE]);
        }
        if (null !== $view->disabledReasonKey) {
            throw new DomainErrors(['action' => $view->disabledReasonKey]);
        }
        if (null === $command->runDigest || !hash_equals($view->runDigest(), $command->runDigest)) {
            throw new DomainErrors(['action' => self::COMMAND_CHANGED]);
        }
        $bridgeId = Uuid::fromString($view->bridgeId ?? throw new \LogicException('A command with no disabled reason has a bridge.'));
        $cardId = $this->itemActions->runCommandCardId($item);
        $card = null === $cardId ? null : ($this->cards->find($cardId) ?? throw new DomainErrors(['action' => self::NOT_AVAILABLE]));

        // The row commits first, because the request names it. A refused request removes it again.
        $action = new InboxAction($item, InboxActionKind::RunCommand, $command->user, $note);
        $action->waitId = $this->itemActions->newestOpenWait($item)?->id;
        $this->em->persist($action);
        $this->em->flush();

        try {
            $request = ($this->openWorkRequest)(new OpenWorkRequestCommand(
                project: $item->project,
                subject: null === $card ? new WorkSubject(InboxItem::SUBJECT_TYPE, $item->id ?? throw new \LogicException('A stored item has an id.')) : WorkSubject::card($card->id ?? throw new \LogicException('A stored card has an id.')),
                cardNumber: $card?->number,
                kind: $view->entry,
                capability: Bridge::CAPABILITY_RUN_COMMAND,
                ruleId: self::RUN_COMMAND_RULE_ID,
                context: $view->context ?? (null === $card ? new WorkRequestContext() : $this->contexts->forCard($card, null)),
                fresh: true,
                inboxActionId: $action->id,
                inboxItemNumber: $item->number,
                inboxItemId: $item->id,
                targetBridgeId: $bridgeId,
                branch: $view->branch,
                params: $view->values(),
                run: $view->runList,
            ));
        } catch (WorkRequestAlreadyLive|DomainErrors $e) {
            // The bridge closes the entity manager when a race trips its unique index, so delete through the connection.
            $this->em->getConnection()->delete('inbox_actions', ['id' => $action->id?->toRfc4122()]);
            throw new DomainErrors(['action' => $e instanceof WorkRequestAlreadyLive ? self::COMMAND_LIVE : self::NOT_AVAILABLE]);
        }

        $action->workRequestId = $request->id;
        $this->em->flush();

        return $action;
    }

    /**
     * Asks the bridge to write the proposed entry into its rule file. The bridge starts no worker run. The request belongs to the
     * item and never to a card, so it waits for no worker. Nothing runs: a later run asks the owner again.
     */
    private function addEntry(RunInboxActionCommand $command, string $note): InboxAction
    {
        $item = $command->item;
        $view = $this->itemActions->runCommandFor($item);
        if (null === $view || !$view->canAddEntry()) {
            throw new DomainErrors(['action' => self::NOT_AVAILABLE]);
        }

        // The row commits first, because the request names it. A refused request removes it again.
        $action = new InboxAction($item, InboxActionKind::AddEntry, $command->user, $note);
        $this->em->persist($action);
        $this->em->flush();

        try {
            $request = ($this->openWorkRequest)(new OpenWorkRequestCommand(
                project: $item->project,
                subject: new WorkSubject(InboxItem::SUBJECT_TYPE, $item->id ?? throw new \LogicException('A stored item has an id.')),
                cardNumber: null,
                kind: InboxActionKind::AddEntry->value,
                capability: Bridge::CAPABILITY_ADD_ENTRY,
                ruleId: self::ADD_ENTRY_RULE_ID,
                context: new WorkRequestContext(),
                prompt: $view->entryYaml,
                fresh: true,
                inboxActionId: $action->id,
                inboxItemNumber: $item->number,
                inboxItemId: $item->id,
                targetBridgeId: Uuid::fromString($view->bridgeId ?? throw new \LogicException('An entry that can be added has a bridge.')),
            ));
        } catch (WorkRequestAlreadyLive|DomainErrors) {
            // The bridge closes the entity manager when a race trips its unique index, so delete through the connection.
            $this->em->getConnection()->delete('inbox_actions', ['id' => $action->id?->toRfc4122()]);
            throw new DomainErrors(['action' => self::NOT_AVAILABLE]);
        }

        $action->workRequestId = $request->id;
        $this->em->flush();

        return $action;
    }

    private function dismiss(RunInboxActionCommand $command, string $note): null
    {
        ($this->declineItem)(new DeclineInboxItemCommand($command->item, $note));

        return null;
    }

    private function waitOf(RunInboxActionCommand $command, InboxActionKind $kind): InboxCardWait
    {
        return $this->itemActions->waitFor($command->item, $kind) ?? throw new DomainErrors(['action' => self::NOT_AVAILABLE]);
    }
}
