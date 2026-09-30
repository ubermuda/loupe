<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Experiment\CardColumn;
use App\Module\Bridge\Experiment\CardReportSourceInterface;
use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(CardReportSourceInterface::class)]
final readonly class BoardCardReportSource implements CardReportSourceInterface
{
    public function __construct(
        private CardRepository $cards,
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
}
