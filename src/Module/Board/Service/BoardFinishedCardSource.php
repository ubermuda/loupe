<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Cost\FinishedCard;
use App\Module\Bridge\Cost\FinishedCardSourceInterface;
use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(FinishedCardSourceInterface::class)]
final readonly class BoardFinishedCardSource implements FinishedCardSourceInterface
{
    public function __construct(
        private CardRepository $cards,
    ) {
    }

    #[\Override]
    public function finishedCards(Project $project, ?\DateTimeImmutable $completedSince): array
    {
        return array_map(
            static fn (array $row): FinishedCard => new FinishedCard($row['id'], $row['number'], $row['title'], $row['completedAt']),
            $this->cards->findFinishedRows($project, $completedSince),
        );
    }
}
