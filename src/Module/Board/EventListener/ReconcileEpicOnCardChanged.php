<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Event\CardMoved;
use App\Module\Board\Event\CardParentChanged;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardAutomationRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\AbandonedCardMoves;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Board\Service\CardEventCause;
use App\Module\Board\Service\LifecycleStages;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Keeps an epic in step with its children, and starts a child whose last
 * blocker finished. Each move goes through UpdateCardHandler as the app, in a
 * SAVEPOINT of the change that caused it, so a failure here refuses that change.
 *
 * The chain ends: an epic has no parent, and a card this releases lands in an
 * open column, which closes no epic and releases nothing.
 */
final readonly class ReconcileEpicOnCardChanged
{
    public const string REOPEN_SLUG = 'implementation';

    public function __construct(
        private CardRepository $cards,
        private BoardColumnRepository $boardColumns,
        private UpdateCardHandler $updateCard,
        private BoardAvailability $board,
        private LifecycleStages $stages,
        private CardPullRequestRepository $cardPullRequests,
        private ForgePullRequestRepository $forgePullRequests,
        private AbandonedCardMoves $abandonedMoves,
        private BoardAutomation $automation,
        private CardAutomationRepository $cardAutomations,
    ) {
    }

    // Below the default priority, so the outbox row of the causing move is written first.
    #[AsEventListener(priority: -5)]
    public function onCardMoved(CardMoved $event): void
    {
        if (!$this->board->isEnabled()) {
            return;
        }

        $card = $event->card;
        // The move itself does not read the parent under the lock.
        $this->cards->refreshTypeAndParent($card);
        if (null !== $card->parent) {
            $this->reconcile($card->parent, $card->number);
        }

        if ($card->column->terminal && !$event->move->fromColumn->terminal) {
            $this->release($card);
        }
    }

    #[AsEventListener(priority: -5)]
    public function onCardParentChanged(CardParentChanged $event): void
    {
        if (!$this->board->isEnabled()) {
            return;
        }

        foreach ([$event->oldParent, $event->newParent] as $epic) {
            if (null !== $epic) {
                $this->reconcile($epic, $event->card->number);
            }
        }
    }

    /**
     * Closes an epic whose children are all finished, or holds it in review
     * while its own pull request is open. An abandoned pull request queues its Backlog move.
     * Reopens a closed or held epic with an open child.
     */
    private function reconcile(Card $epic, int $childNumber): void
    {
        $columns = $this->boardColumns->findForProjectFresh($epic->project);
        $this->cards->refreshColumn($epic);
        if (0 === $this->cards->countChildren($epic)) {
            return;
        }

        $stage = $this->stages->forPassedChecks();
        $review = array_find($columns, static fn (BoardColumn $column): bool => $column->slug === $stage['to'] && !$column->terminal);
        $inReview = null !== $review && $epic->column === $review;
        $open = [] !== $this->cards->openChildNumbers($epic);
        $state = $open || $epic->column->terminal ? null : $this->pullRequestState($epic);
        if (PullRequestState::Closed === $state) {
            // The move queued at the close skips an epic with an open child, so queue it again.
            // A token still set is a move that will act on its own, and a new one would delay it.
            if (!$epic->column->backlog
                && $this->automation->settingsOf($epic->project)->enabled
                && null === $this->cardAutomations->findOrCreateForUpdate($epic)->abandonedMoveToken) {
                $this->abandonedMoves->queue($epic);
            }

            return;
        }

        $target = match (true) {
            $open => $epic->column->terminal || $inReview ? self::reopenColumn($columns) : null,
            $epic->column->terminal => null,
            PullRequestState::Open === $state => $inReview ? null : ($review ?? self::firstTerminal($columns)),
            default => self::firstTerminal($columns),
        };

        if (null !== $target) {
            $this->move($epic, $target, CardEventCause::epicReconciled($childNumber));
        }
    }

    /** Starts each waiting child that the finished card was the last open blocker of. */
    private function release(Card $blocker): void
    {
        $freed = $this->cards->findChildrenFreedBy($blocker);
        if ([] === $freed) {
            return;
        }

        $target = self::reopenColumn($this->boardColumns->findForProjectFresh($blocker->project));
        if (null === $target) {
            return;
        }

        foreach ($freed as $card) {
            $this->move($card, $target, CardEventCause::unblocked($blocker->number));
        }
    }

    /**
     * Open when a link is open, Closed when every link closed and none merged,
     * and null when the epic links nothing or one link merged. A link that
     * Forge never read, or that no parser could read, counts as open.
     */
    private function pullRequestState(Card $epic): ?PullRequestState
    {
        $keys = [];
        foreach ($this->cardPullRequests->findCurrentKeys($epic) as $link) {
            if (null === $link['repository'] || null === $link['number']) {
                return PullRequestState::Open;
            }
            $keys[] = ['forge' => $link['forge'], 'repository' => $link['repository'], 'number' => $link['number']];
        }
        if ([] === $keys) {
            return null;
        }

        $projectId = $epic->project->id ?? throw new \LogicException('A stored card has a project id.');
        $states = $this->forgePullRequests->findCurrentStatesByKeys($projectId, $keys);
        $merged = false;
        foreach ($keys as $key) {
            $state = $states[ForgePullRequestRepository::stateKey($key['forge'], $key['repository'], $key['number'])] ?? null;
            if (null === $state || PullRequestState::Open === $state) {
                return PullRequestState::Open;
            }
            $merged = $merged || PullRequestState::Merged === $state;
        }

        return $merged ? null : PullRequestState::Closed;
    }

    /** @param list<BoardColumn> $columns */
    private static function firstTerminal(array $columns): ?BoardColumn
    {
        return array_find($columns, static fn (BoardColumn $column): bool => $column->terminal);
    }

    private function move(Card $card, BoardColumn $column, CardEventCause $cause): void
    {
        ($this->updateCard)(new UpdateCardCommand(card: $card, actor: CardReporter::System, column: $column, cause: $cause));
    }

    /**
     * A terminal column of that slug counts as none, because a move into it
     * would finish the card rather than open it.
     *
     * @param list<BoardColumn> $columns
     */
    private static function reopenColumn(array $columns): ?BoardColumn
    {
        return array_find($columns, static fn (BoardColumn $column): bool => self::REOPEN_SLUG === $column->slug && !$column->terminal);
    }
}
