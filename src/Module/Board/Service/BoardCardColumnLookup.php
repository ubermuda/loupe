<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Service\CardColumnLookupInterface;
use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** Answers with the board flag off too: a null here means the card does not exist. */
#[AsAlias(CardColumnLookupInterface::class)]
final readonly class BoardCardColumnLookup implements CardColumnLookupInterface
{
    public function __construct(
        private CardRepository $cards,
    ) {
    }

    #[\Override]
    public function columnOf(Project $project, Uuid $cardId): ?string
    {
        return $this->cards->findColumnSlug($project, $cardId);
    }

    #[\Override]
    public function cardIdOfNumber(Project $project, int $number): ?Uuid
    {
        return $this->cards->findOneByProjectAndNumber($project, $number)?->id;
    }
}
