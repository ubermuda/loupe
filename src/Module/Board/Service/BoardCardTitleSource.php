<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\View\CardTitleSourceInterface;
use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(CardTitleSourceInterface::class)]
final readonly class BoardCardTitleSource implements CardTitleSourceInterface
{
    public function __construct(
        private CardRepository $cards,
    ) {
    }

    #[\Override]
    public function titlesFor(Project $project, array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        return $this->cards->findTitlesByIds($project, $cardIds);
    }
}
