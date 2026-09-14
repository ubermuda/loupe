<?php

declare(strict_types=1);

namespace App\Module\Inbox\Repository;

use App\Doctrine\SearchLanguage;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
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
     * The open items linked to any of the cards whose every linked card sits in
     * a terminal column, locked for update. The caller holds a transaction.
     *
     * @param list<string> $cardIds
     *
     * @return list<InboxItem>
     */
    public function findOpenWithEveryCardFinished(array $cardIds): array
    {
        /* @var list<InboxItem> */
        return $this->createQueryBuilder('i')
            ->andWhere(\sprintf('EXISTS (SELECT m.id FROM %s m WHERE m.item = i AND m.card IN (:cards))', InboxItemCard::class))
            ->andWhere('i.state = :open')
            ->andWhere(\sprintf('NOT EXISTS (SELECT u.id FROM %s u JOIN u.card uc JOIN uc.column k WHERE u.item = i AND k.terminal = false)', InboxItemCard::class))
            ->setParameter('cards', array_map(static fn (string $id): string => Uuid::fromString($id)->toRfc4122(), $cardIds))
            ->setParameter('open', InboxItemState::Open)
            ->orderBy('i.number', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }

    /**
     * The ids of the ask's blocking items that are open as stored.
     *
     * @return list<string>
     */
    public function findOpenBlockingIdsOf(InboxAsk $ask): array
    {
        /** @var list<array{id: mixed}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('i.id')
            ->join(InboxAskItem::class, 'l', 'WITH', 'l.item = i')
            ->andWhere('l.ask = :ask')
            ->andWhere('i.blocking = true')
            ->andWhere('i.state = :open')
            ->setParameter('ask', $ask)
            ->setParameter('open', InboxItemState::Open)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
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

    /** The item with this id inside this project, so a URL cannot reach another project's item. */
    public function findOneByIdAndProjectId(string $itemId, string $projectId): ?InboxItem
    {
        if (!Uuid::isValid($itemId) || !Uuid::isValid($projectId)) {
            return null;
        }

        return $this->createQueryBuilder('i')
            ->andWhere('i.id = :itemId')
            ->andWhere('i.project = :projectId')
            ->setParameter('itemId', Uuid::fromString($itemId), UuidType::NAME)
            ->setParameter('projectId', Uuid::fromString($projectId), UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Open items that no open ask holds, such as a to-do left open in an ask
     * that already closed.
     *
     * @return list<InboxItem>
     */
    public function findOpenOutsideOpenAsks(Project $project): array
    {
        return array_values($this->createQueryBuilder('i')
            ->andWhere('i.project = :project')
            ->andWhere('i.state = :open')
            ->andWhere('NOT EXISTS (SELECT 1 FROM '.InboxAskItem::class.' l JOIN l.ask a WHERE l.item = i AND a.closedAt IS NULL)')
            ->setParameter('project', $project)
            ->setParameter('open', InboxItemState::Open)
            ->orderBy('i.number', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Copies onto each entity every column that can change, as stored now. A
     * query hydrates no fresh copy of an entity already managed, and refresh()
     * would also reload the readonly columns, which Doctrine refuses.
     *
     * @param list<InboxItem> $items
     */
    public function reloadChangeableColumns(array $items): void
    {
        if ([] === $items) {
            return;
        }

        /** @var list<array{id: Uuid, title: string, body: ?string, blocking: bool, options: list<string>, multiple: bool, freeText: bool, state: InboxItemState, selectedOptions: list<int>, answerText: ?string, closeNote: ?string, updatedAt: \DateTimeImmutable, closedAt: ?\DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('i.id, i.title, i.body, i.blocking, i.options, i.multiple, i.freeText, i.state, i.selectedOptions, i.answerText, i.closeNote, i.updatedAt, i.closedAt')
            ->andWhere('i IN (:items)')
            ->setParameter('items', $items)
            ->getQuery()
            ->getArrayResult();

        $byId = [];
        foreach ($rows as $row) {
            $byId[(string) $row['id']] = $row;
        }

        $unitOfWork = $this->getEntityManager()->getUnitOfWork();
        foreach ($items as $item) {
            $row = $byId[(string) $item->id] ?? null;
            if (null === $row) {
                continue;
            }
            $item->title = $row['title'];
            $item->body = $row['body'];
            $item->blocking = $row['blocking'];
            $item->options = $row['options'];
            $item->multiple = $row['multiple'];
            $item->freeText = $row['freeText'];
            $item->state = $row['state'];
            $item->selectedOptions = $row['selectedOptions'];
            $item->answerText = $row['answerText'];
            $item->closeNote = $row['closeNote'];
            $item->updatedAt = $row['updatedAt'];
            $item->closedAt = $row['closedAt'];

            // The snapshot moves with the copy, so a later flush writes no stored value back.
            unset($row['id']);
            foreach ($row as $property => $value) {
                $unitOfWork->setOriginalEntityProperty(spl_object_id($item), $property, $value);
            }
        }
    }

    /**
     * Writes every response column from the entity, changed or not.
     *
     * A flush writes only what differs from the copy Doctrine loaded, so a
     * response cleared on a stale copy would leave another request's answer in place.
     */
    public function writeResponse(InboxItem $item): void
    {
        $this->createQueryBuilder('i')
            ->update()
            ->set('i.state', ':state')
            ->set('i.selectedOptions', ':selectedOptions')
            ->set('i.answerText', ':answerText')
            ->set('i.closeNote', ':closeNote')
            ->set('i.closedAt', ':closedAt')
            ->set('i.updatedAt', ':updatedAt')
            ->andWhere('i.id = :id')
            ->setParameter('state', $item->state->value)
            ->setParameter('selectedOptions', $item->selectedOptions, Types::JSON)
            ->setParameter('answerText', $item->answerText)
            ->setParameter('closeNote', $item->closeNote)
            ->setParameter('closedAt', $item->closedAt, Types::DATETIME_IMMUTABLE)
            ->setParameter('updatedAt', $item->updatedAt, Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $item->id, UuidType::NAME)
            ->getQuery()
            ->execute();
    }

    public function countOpenByProject(Project $project): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.project = :project')
            ->andWhere('i.state = :open')
            ->setParameter('project', $project)
            ->setParameter('open', InboxItemState::Open)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<Project> $projects
     *
     * @return array<string, int> keyed by project id; a project with no open item is absent
     */
    public function countOpenByProjects(array $projects): array
    {
        if ([] === $projects) {
            return [];
        }

        /** @var list<array{id: mixed, total: mixed}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.project) AS id, COUNT(i.id) AS total')
            ->andWhere('i.project IN (:projects)')
            ->andWhere('i.state = :open')
            ->setParameter('projects', $projects)
            ->setParameter('open', InboxItemState::Open)
            ->groupBy('i.project')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['id']] = (int) $row['total'];
        }

        return $counts;
    }
}
