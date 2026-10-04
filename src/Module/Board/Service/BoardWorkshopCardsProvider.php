<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Service\OpenCardRuns;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\View\OpenCardRun;
use App\Module\Project\Entity\Project;
use App\Module\Project\Workshop\WorkshopCard;
use App\Module\Project\Workshop\WorkshopCardsInMotion;
use App\Module\Project\Workshop\WorkshopCardsProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

#[AsAlias(WorkshopCardsProviderInterface::class)]
final readonly class BoardWorkshopCardsProvider implements WorkshopCardsProviderInterface
{
    private const int SHOWN = 6;

    public function __construct(
        private CardRepository $cards,
        private OpenCardRuns $openRuns,
        private BoardAvailability $board,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[\Override]
    public function forProject(Project $project): WorkshopCardsInMotion
    {
        if (!$this->board->isEnabled()) {
            return new WorkshopCardsInMotion();
        }

        $runs = $this->openRuns->forProject($project);
        $cards = [];
        foreach ($this->cards->findByIdsInProject($project, array_map(static fn (OpenCardRun $run): Uuid => $run->cardId, $runs)) as $card) {
            $cards[(string) $card->id] = $card;
        }

        $work = [];
        foreach ($runs as $run) {
            // A run outlives its card, so a deleted card still has open runs.
            $card = $cards[(string) $run->cardId] ?? null;
            if (null === $card) {
                continue;
            }
            $work[] = new WorkshopCard(
                $card->number,
                $card->title,
                $this->urls->generate('app_board_card', ['projectId' => (string) $project->id, 'cardId' => (string) $card->id]),
                $card->column->label,
                $card->column->tone->value,
                $run->state->translationKey(),
                $run->state->chipModifier(),
                WorkerRunKind::Interactive === $run->kind ? null : $run->workKind,
                $run->since,
                WorkerRunKind::Command === $run->kind,
            );
        }

        return new WorkshopCardsInMotion(\array_slice($work, 0, self::SHOWN), max(0, \count($work) - self::SHOWN));
    }
}
