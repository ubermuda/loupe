<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Workshop\WorkshopCard;
use App\Module\Project\Workshop\WorkshopCardsProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsAlias(WorkshopCardsProviderInterface::class)]
final readonly class BoardWorkshopCardsProvider implements WorkshopCardsProviderInterface
{
    public function __construct(
        private CardRepository $cards,
        private BoardAvailability $board,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[\Override]
    public function forProject(Project $project): array
    {
        if (!$this->board->isEnabled()) {
            return [];
        }
        $work = [];
        foreach ($this->cards->searchOpenForProject($project, '', 6) as $card) {
            $work[] = new WorkshopCard(
                $card->number,
                $card->title,
                $this->urls->generate('app_board_card', ['projectId' => (string) $project->id, 'cardId' => (string) $card->id]),
                $card->column->label,
                $card->column->tone->value,
            );
        }

        return $work;
    }
}
