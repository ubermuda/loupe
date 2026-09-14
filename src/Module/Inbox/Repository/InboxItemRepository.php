<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Doctrine\SearchLanguage;
use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<InboxItem>
 */
class InboxItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboxItem::class);
    }

    /** Read-then-write: a caller holds a lock on the project, or two items can take one number. */
    public function nextNumber(Project $project): int
    {
        $highest = $this->createQueryBuilder('i')
            ->select('MAX(i.number)')
            ->andWhere('i.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $highest ? 1 : ((int) $highest) + 1;
    }

    /**
     * The item's state as the database holds it, with the row locked for update.
     * The caller holds a transaction. A scalar read, because a refresh would
     * rewrite the readonly properties of the loaded entity.
     */
    public function lockedState(InboxItem $item): InboxItemState
    {
        $state = $this->createQueryBuilder('i')
            ->select('i.state')
            ->andWhere('i.id = :id')
            ->setParameter('id', $item->id, UuidType::NAME)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getSingleScalarResult();

        return $state instanceof InboxItemState ? $state : InboxItemState::from((string) $state);
    }

    /**
     * One page of the project's items, newest number first. Each filter that is
     * not null narrows the page, and an id from another project matches nothing.
     *
     * @return Paginator<InboxItem>
     */
    public function findPageForProject(
        Project $project,
        ?InboxItemState $state,
        ?Uuid $askId,
        ?Uuid $sessionId,
        ?Uuid $cardId,
        ?Uuid $documentId,
        int $page,
        int $perPage,
    ): Paginator {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.project = :project')
            ->setParameter('project', $project)
            ->orderBy('i.number', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if (null !== $state) {
            $qb->andWhere('i.state = :state')->setParameter('state', $state);
        }
        if (null !== $askId) {
            $qb->andWhere(\sprintf('EXISTS (SELECT la.id FROM %s la JOIN la.ask a WHERE la.item = i AND a.id = :askId)', InboxAskItem::class))
                ->setParameter('askId', $askId, UuidType::NAME);
        }
        if (null !== $sessionId) {
            $qb->andWhere(\sprintf('EXISTS (SELECT ls.id FROM %s ls JOIN ls.ask s WHERE ls.item = i AND s.sessionId = :sessionId)', InboxAskItem::class))
                ->setParameter('sessionId', $sessionId, UuidType::NAME);
        }
        if (null !== $cardId) {
            $qb->andWhere(\sprintf('EXISTS (SELECT lc.id FROM %s lc JOIN lc.card c WHERE lc.item = i AND c.id = :cardId)', InboxItemCard::class))
                ->setParameter('cardId', $cardId, UuidType::NAME);
        }
        if (null !== $documentId) {
            $qb->andWhere(\sprintf('EXISTS (SELECT ld.id FROM %s ld JOIN ld.document d WHERE ld.item = i AND d.id = :documentId)', InboxItemDocument::class))
                ->setParameter('documentId', $documentId, UuidType::NAME);
        }

        return new Paginator($qb->getQuery(), fetchJoinCollection: false);
    }

    /**
     * One page of the project's items matching a full-text query, best match
     * first, closed items included.
     *
     * @return Paginator<InboxItem>
     */
    public function searchByProject(Project $project, string $query, int $page, int $perPage): Paginator
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.project = :project')
            ->setParameter('project', $project)
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        // One branch per language with a constant configuration, so Postgres can
        // use the GIN index. The configuration comes from the enum, never from input.
        $branches = [];
        foreach ($this->searchLanguagesOf($project) as $index => $language) {
            $branches[] = \sprintf(
                "(i.searchLanguage = :searchLanguage%d AND TSMATCH(i.searchVector, WEBSEARCH_TO_TSQUERY('%s', :search)) = true)",
                $index,
                $language->value,
            );
            $qb->setParameter('searchLanguage'.$index, $language);
        }

        $qb->andWhere('('.implode(' OR ', $branches).')')
            ->setParameter('search', $query)
            ->orderBy('TS_RANK(i.searchVector, WEBSEARCH_TO_TSQUERY(CAST(i.searchLanguage AS regconfig), :search))', 'DESC')
            ->addOrderBy('i.number', 'DESC');

        return new Paginator($qb->getQuery(), fetchJoinCollection: false);
    }

    /** @return list<SearchLanguage> */
    public function searchLanguagesOf(Project $project): array
    {
        /** @var list<SearchLanguage|string> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('DISTINCT i.searchLanguage')
            ->andWhere('i.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleColumnResult();

        $languages = array_map(
            static fn (SearchLanguage|string $row): SearchLanguage => $row instanceof SearchLanguage ? $row : SearchLanguage::from($row),
            $rows,
        );

        return [] === $languages ? [SearchLanguage::DEFAULT] : $languages;
    }

    /**
     * The open items linked to the card whose every linked card sits in a
     * terminal column, locked for update. The caller holds a transaction.
     *
     * @return list<InboxItem>
     */
    public function findOpenWithEveryCardFinished(Card $card): array
    {
        /* @var list<InboxItem> */
        return $this->createQueryBuilder('i')
            ->join('i.cards', 'l')
            ->andWhere('l.card = :card')
            ->andWhere('i.state = :open')
            ->andWhere(\sprintf('NOT EXISTS (SELECT u.id FROM %s u JOIN u.card uc JOIN uc.column k WHERE u.item = i AND k.terminal = false)', InboxItemCard::class))
            ->setParameter('card', $card)
            ->setParameter('open', InboxItemState::Open)
            ->orderBy('i.number', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }

    /**
     * Every item in the projects the user owns, with its card and document links.
     *
     * @return list<InboxItem>
     */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('i')
            ->join('i.project', 'p')
            ->leftJoin('i.cards', 'c')
            ->addSelect('c')
            ->leftJoin('i.documents', 'd')
            ->addSelect('d')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('i.createdAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult());
    }
}
