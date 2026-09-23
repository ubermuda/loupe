<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Repository\CardLinkRepository;
use App\Module\Board\Repository\CardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Replaces every link that touches a card, whichever card wrote it. A pair
 * matches its row by the other card alone, so a change of kind or direction
 * updates the row in place. The caller holds the project lock. The sync
 * flushes, so a later sync never detaches a change it has not written.
 */
final readonly class CardLinkSync
{
    public function __construct(
        private CardLinkRepository $cardLinks,
        private CardRepository $cards,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * The pairs are resolved before the project lock, so a card deleted since
     * then would fail the foreign key at the flush. Call it under the lock.
     *
     * @param list<array{Card, CardLinkKind}> $wanted
     */
    public function anyCardGone(array $wanted): bool
    {
        $ids = array_map(static fn (array $pair): Uuid => $pair[0]->id ?? throw new \LogicException('A resolved card has an id.'), $wanted);

        // The resolver refuses a card named twice, so the ids are distinct.
        return $this->cards->countByIds($ids) < \count($ids);
    }

    /** @param list<array{Card, CardLinkKind}> $wanted each other card with the kind the card reads it by */
    public function sync(Card $card, array $wanted): void
    {
        // A card not yet flushed has no rows to read. A link loaded before the
        // lock may be stale, so the read starts from no managed link at all.
        $unitOfWork = $this->em->getUnitOfWork();
        foreach ($unitOfWork->getIdentityMap()[CardLink::class] ?? [] as $link) {
            $this->em->detach($link);
        }
        $existing = $unitOfWork->isScheduledForInsert($card) ? [] : $this->cardLinks->findForCard($card);

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

        $this->em->flush();
    }
}
