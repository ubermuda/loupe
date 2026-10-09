<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\CardDirectory;
use App\Module\Workflow\Contract\CardSnapshot;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(CardDirectory::class)]
final readonly class BoardCardDirectory implements CardDirectory
{
    public function __construct(
        private CardRepository $cards,
    ) {
    }

    #[\Override]
    public function find(Uuid $cardId): ?CardSnapshot
    {
        return $this->cards->find($cardId)?->snapshot();
    }

    #[\Override]
    public function findInProject(Uuid $projectId, Uuid $cardId): ?CardSnapshot
    {
        return $this->cards->findOneByIdAndProjectId((string) $cardId, (string) $projectId)?->snapshot();
    }

    #[\Override]
    public function refresh(Uuid $cardId): ?CardSnapshot
    {
        $card = $this->cards->find($cardId);
        if (null === $card) {
            return null;
        }
        $this->cards->refreshColumn($card);
        $this->cards->refreshTypeAndParent($card);

        return $card->snapshot();
    }

    #[\Override]
    public function findWithParentColumn(Uuid $cardId): ?CardSnapshot
    {
        $card = $this->cards->find($cardId);
        if (null === $card) {
            return null;
        }
        if (null !== $card->parent) {
            $this->cards->refreshColumn($card->parent);
        }

        return $card->snapshot();
    }

    #[\Override]
    public function childIds(Uuid $cardId): array
    {
        $card = $this->cards->find($cardId);
        if (null === $card) {
            return [];
        }

        return array_map(static fn (Card $child): Uuid => $child->id ?? throw new \LogicException('A stored card has an id.'), $this->cards->findChildren($card));
    }
}
