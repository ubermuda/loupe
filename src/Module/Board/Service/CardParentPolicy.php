<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardRepository;

/**
 * The rules of a parent: only an epic is a parent, an epic has no parent, and
 * an epic with children stays an epic.
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
    ) {
    }

    /**
     * Call it under the project lock, with the type and the parent of $card
     * read again under that lock.
     *
     * @param Card|null $card      the card as it is now; null while it is being created
     * @param CardType  $type      the type the card has after the write
     * @param Card|null $parent    the parent the card has after the write
     * @param bool      $parentSet whether the write gives the card this parent, rather than keeping it
     */
    public function refusal(?Card $card, CardType $type, ?Card $parent, bool $parentSet): ?DomainErrors
    {
        if (null !== $parent) {
            // The resolver read the parent before the lock. Another write may
            // have deleted it or changed its type since.
            $parentType = $this->cards->freshType($parent);
            if (null === $parentType) {
                return new DomainErrors(['parent' => CardParentResolver::UNKNOWN]);
            }
            if (CardType::Epic === $type) {
                return $parentSet
                    ? new DomainErrors(['parent' => self::EPIC_WITH_PARENT])
                    : new DomainErrors(['type' => self::CHILD_TO_EPIC]);
            }
            if (CardType::Epic !== $parentType) {
                return new DomainErrors(['parent' => self::NOT_EPIC]);
            }
        }

        if (null !== $card && CardType::Epic === $card->type && CardType::Epic !== $type && 0 < $this->cards->countChildren($card)) {
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
