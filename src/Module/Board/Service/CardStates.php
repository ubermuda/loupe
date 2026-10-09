<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Bridge\Service\CardLiveWork;
use App\Module\Bridge\View\CardLiveWorkItem;
use App\Module\Bridge\View\CardRunWarning;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use App\Observability\RequestTimeline;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The state each card of a board page shows: Stuck, Needs you, Working or Waiting. It reads the facts
 * of all cards in a fixed set of queries, so the cost does not grow with the card count.
 */
final readonly class CardStates
{
    public const int STUCK_DELAY_MINUTES = 15;

    private const array FIX_KINDS = ['fix', 'repair'];

    private const array MERGE_KINDS = ['merge'];

    /** @param iterable<CardStateSignalsInterface> $signals */
    public function __construct(
        private CardPauseRepository $cardPauses,
        private CardLiveWork $liveWork,
        private ClockInterface $clock,
        private RequestTimeline $timeline,

        #[AutowireIterator('app.card_state_signals')]
        private iterable $signals,
    ) {
    }

    /**
     * @param list<Card>                    $cards       the cards of the project, in any column
     * @param array<string, CardRunWarning> $runWarnings keyed by card id
     * @param array<string, CardPause>|null $paused      the active pauses by card id, when the caller holds them
     *
     * @return array<string, CardState> card id => its state; a card in a terminal column, or with no reason, has no key
     */
    public function forCards(Project $project, array $cards, PullRequestStates $pullRequests, array $runWarnings, ?array $paused = null): array
    {
        $open = array_values(array_filter($cards, static fn (Card $card): bool => !$card->column->terminal));
        if ([] === $open) {
            return [];
        }

        return $this->timeline->span('board.card_states', function () use ($project, $open, $pullRequests, $runWarnings, $paused): array {
            $ids = array_map(static fn (Card $card): string => (string) $card->id, $open);
            $now = $this->clock->now();
            $paused ??= $this->cardPauses->findActiveForCardIds($ids);
            $work = $this->liveWork->forCards($project, $ids);

            $reasons = [];
            foreach ($open as $card) {
                $id = (string) $card->id;
                foreach ($this->reasonsOf($card, $paused[$id] ?? null, $runWarnings[$id] ?? null, $work[$id] ?? [], $pullRequests, $now) as $reason) {
                    self::add($reasons, $id, $reason);
                }
            }
            foreach ($this->signals as $source) {
                foreach ($source->signalsFor($project, $open) as $id => $found) {
                    foreach ($found as $reason) {
                        self::add($reasons, $id, $reason);
                    }
                }
            }

            $states = [];
            foreach ($ids as $id) {
                $found = array_values($reasons[$id] ?? []);
                if ([] !== $found) {
                    $states[$id] = CardState::of($found);
                }
            }

            return $states;
        }, data: ['cards' => \count($open)]);
    }

    /**
     * @param list<CardLiveWorkItem> $work
     *
     * @return list<CardStateReason>
     */
    private function reasonsOf(Card $card, ?CardPause $pause, ?CardRunWarning $warning, array $work, PullRequestStates $pullRequests, \DateTimeImmutable $now): array
    {
        $reasons = [];
        if (null !== $pause) {
            $reasons[] = new CardStateReason(CardStateCode::Paused, ['%reason%' => $pause->reason], $pause->createdAt);
        }
        if (null !== $warning) {
            $reasons[] = new CardStateReason(CardStateCode::RunStopped, ['%state%' => $warning->state->value], $warning->endedAt);
        }

        $kinds = array_map(static fn (CardLiveWorkItem $item): ?string => $item->kind, $work);
        $fixLive = [] !== array_intersect($kinds, self::FIX_KINDS);
        $mergeLive = [] !== array_intersect($kinds, self::MERGE_KINDS);
        foreach ($work as $item) {
            $reasons[] = new CardStateReason(
                $item->isRun ? CardStateCode::RunOpen : CardStateCode::WorkRequested,
                ['%kind%' => $item->kind ?? ''],
                $item->since,
            );
        }

        $delay = new \DateInterval(\sprintf('PT%dM', self::STUCK_DELAY_MINUTES));
        foreach ($card->pullRequests as $link) {
            $view = $pullRequests->of($link);
            if (null === $view || null === $view->refreshedAt || PullRequestState::Open !== $view->state) {
                continue;
            }
            $params = ['%number%' => $link->number ?? 0];
            $waitsForApproval = !$view->draft
                && PullRequestChecks::Passed === $view->checks
                && null !== $view->defaultBranch
                && $view->baseBranch === $view->defaultBranch
                && !$view->approvalCoversHead;

            if (PullRequestChecks::Failed === $view->checks && !$fixLive) {
                $reasons[] = new CardStateReason(CardStateCode::ChecksFailed, $params, $view->checksFailedSince);
            }
            if (PullRequestMergeability::Conflicting === $view->mergeability && !$fixLive) {
                $reasons[] = new CardStateReason(CardStateCode::Conflicting, $params, $view->conflictingSince);
            }
            if ($waitsForApproval) {
                $reasons[] = new CardStateReason(CardStateCode::WaitsForApproval, $params, $view->waitsForApprovalSince);
            }
            if ($view->readyToMerge && null !== $view->readySince && !$waitsForApproval && !$view->mergeInFlight && !$mergeLive) {
                $stuckAt = $view->readySince->add($delay);
                if ($stuckAt <= $now) {
                    $reasons[] = new CardStateReason(CardStateCode::ReadyNotMerged, $params, $stuckAt);
                }
            }
            if (null !== $view->forgeRequestedAt) {
                $reasons[] = new CardStateReason(CardStateCode::ForgeRequestPending, $params, $view->forgeRequestedAt);
            }
        }

        return $reasons;
    }

    /**
     * Keeps one reason for each code of a card, the one that began first.
     *
     * @param array<string, array<string, CardStateReason>> $reasons
     */
    private static function add(array &$reasons, string $cardId, CardStateReason $reason): void
    {
        $kept = $reasons[$cardId][$reason->code->value] ?? null;
        if (null === $kept || (null !== $reason->since && (null === $kept->since || $reason->since < $kept->since))) {
            $reasons[$cardId][$reason->code->value] = $reason;
        }
    }
}
