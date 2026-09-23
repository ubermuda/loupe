<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Repository\CardLinkRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Makes a card's links match a wanted set. The set replaces every link that
 * touches the card, whichever card wrote it.
 *
 * A pair matches its row by the other card alone, so a change of kind or of
 * direction updates the row in place and never adds a reverse duplicate. The
 * caller holds the project lock and flushes.
 */
final readonly class CardLinkSync
{
    public function __construct(
        private CardLinkRepository $cardLinks,
        private EntityManagerInterface $em,
    ) {
    }

    /** @param list<array{Card, CardLinkKind}> $wanted each other card with the kind the card reads it by */
    public function sync(Card $card, array $wanted): void
    {
        // A card not yet flushed has no rows to read.
        $existing = $this->em->getUnitOfWork()->isScheduledForInsert($card) ? [] : $this->cardLinks->findForCard($card);

        $rows = [];
        foreach ($existing as $link) {
            $rows[(string) $link->otherThan($card)->id] = $link;
        }

        foreach ($wanted as [$other, $kind]) {
            $key = (string) $other->id;
            $row = $rows[$key] ?? null;
            unset($rows[$key]);

            if (null !== $row && $row->kindFor($card) === $kind) {
                continue;
            }

            [$source, $target, $stored] = CardLinkKind::BlockedBy === $kind
                ? [$other, $card, CardLinkKind::Blocks]
                : [$card, $other, $kind];

            if (null === $row) {
                $this->em->persist(new CardLink($source, $target, $stored));
                continue;
            }

            $row->source = $source;
            $row->target = $target;
            $row->kind = $stored;
        }

        foreach ($rows as $row) {
            $this->em->remove($row);
        }
    }
}
