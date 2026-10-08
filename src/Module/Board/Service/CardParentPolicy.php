<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;

/**
 * The rules of a parent: only a type with the children capability is a parent,
 * such a card has no parent, and it keeps its type while it has children.
 *
 * It returns the refusal rather than throwing it, because the caller runs it
 * inside a transaction, and a throw there closes the EntityManager.
 */
final readonly class CardParentPolicy
{
    public const string NOT_EPIC = 'board.card.error.parent_not_epic';
    public const string EPIC_WITH_PARENT = 'board.card.error.epic_cannot_have_parent';
    public const string CHILD_TO_EPIC = 'board.card.error.parent_card_cannot_be_epic';
    public const string TYPE_LOCKED = 'board.card.error.epic_type_locked';
    public const string DELETE_HAS_CHILDREN = 'board.card.error.epic_delete_has_children';

    public function __construct(
        private CardRepository $cards,
        private CardTypeCatalog $catalog,
    ) {
    }

    /**
     * Call it under the project lock, with the type and the parent of $card
     * read again under that lock.
     *
     * @param Card|null $card      the card as it is now; null while it is being created
     * @param string    $type      the type key the card has after the write
     * @param Card|null $parent    the parent the card has after the write
     * @param bool      $parentSet whether the write gives the card this parent, rather than keeping it
     */
    public function refusal(Project $project, ?Card $card, string $type, ?Card $parent, bool $parentSet): ?DomainErrors
    {
        $types = $this->catalog->forProject($project);
        if (null !== $parent) {
            // The resolver read the parent before the lock. Another write may
            // have deleted it or changed its type since.
            $parentType = $this->cards->freshType($parent);
            if (null === $parentType) {
                return new DomainErrors(['parent' => CardParentResolver::UNKNOWN]);
            }
            if ($types->get($type)->children) {
                return $parentSet
                    ? new DomainErrors(['parent' => self::EPIC_WITH_PARENT])
                    : new DomainErrors(['type' => self::CHILD_TO_EPIC]);
            }
            // The parent's type is read before this write, so a card that stops being a parent type could still name itself.
            if (!$types->get($parentType)->children || $parent->id?->toRfc4122() === $card?->id?->toRfc4122()) {
                return new DomainErrors(['parent' => self::NOT_EPIC]);
            }
        }

        if (null !== $card && $types->get($card->type)->children && !$types->get($type)->children && 0 < $this->cards->countChildren($card)) {
            return new DomainErrors(['type' => self::TYPE_LOCKED]);
        }

        return null;
    }

    /** Call it under the project lock. */
    public function deleteRefusal(Card $card): ?DomainErrors
    {
        return 0 < $this->cards->countChildren($card)
            ? new DomainErrors(['card' => self::DELETE_HAS_CHILDREN])
            : null;
    }
}
