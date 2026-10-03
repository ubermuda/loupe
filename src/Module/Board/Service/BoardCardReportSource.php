<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Experiment\CardColumn;
use App\Module\Bridge\Experiment\CardOutcome;
use App\Module\Bridge\Experiment\CardReportSourceInterface;
use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(CardReportSourceInterface::class)]
final readonly class BoardCardReportSource implements CardReportSourceInterface
{
    public function __construct(
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
        private CardPullRequestRepository $cardPullRequests,
        private BoardAvailability $board,
    ) {
    }

    #[\Override]
    public function columnsFor(Project $project, array $cardIds): array
    {
        if ([] === $cardIds || !$this->board->isEnabled()) {
            return [];
        }

        $columns = [];
        foreach ($this->cards->findColumnsByIds($project, $cardIds) as $row) {
            $columns[(string) $row['id']] = new CardColumn($row['label'], $row['terminal']);
        }

        return $columns;
    }

    #[\Override]
    public function outcomesFor(Project $project, array $cardIds): array
    {
        if ([] === $cardIds || !$this->board->isEnabled()) {
            return [];
        }

        $cards = [];
        foreach ($this->cardEvents->findKindsOfCards($project, $cardIds, [CardEventKind::FixRequested, CardEventKind::Moved]) as $row) {
            $card = $cards[$row['cardId']] ?? ['fixRounds' => [], 'merged' => false];
            $detail = $row['detail'];
            switch ($row['kind']) {
                case CardEventKind::FixRequested:
                    $reason = \is_string($detail['reason'] ?? null) ? $detail['reason'] : 'unknown';
                    $card['fixRounds'][$reason] = ($card['fixRounds'][$reason] ?? 0) + 1;
                    break;
                case CardEventKind::Moved:
                    // A move by hand to a terminal column carries no cause, so it is no merge.
                    $cause = $detail['cause'] ?? null;
                    if (\is_array($cause) && ('merged' === ($cause['type'] ?? null) || ('workflow-rule' === ($cause['type'] ?? null) && 'merged' === ($cause['rule'] ?? null)))) {
                        $card['merged'] = true;
                    }
                    break;
                default:
                    break;
            }
            $cards[$row['cardId']] = $card;
        }

        $times = $this->cardPullRequests->findPullRequestTimesOfCards($project, $cardIds);
        $outcomes = [];
        foreach ($cards as $cardId => $card) {
            ksort($card['fixRounds']);
            $outcomes[$cardId] = new CardOutcome(
                fixRounds: $card['fixRounds'],
                merged: $card['merged'],
                openedAt: $times[$cardId]['openedAt'] ?? null,
                mergedAt: $times[$cardId]['mergedAt'] ?? null,
            );
        }

        return $outcomes;
    }

    #[\Override]
    public function historyStartFor(Project $project): ?\DateTimeImmutable
    {
        if (!$this->board->isEnabled()) {
            return null;
        }

        return $this->cardEvents->findFirstOccurredAt($project);
    }
}
