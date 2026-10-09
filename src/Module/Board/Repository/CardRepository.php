<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Doctrine\SearchLanguage;
use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\View\BacklogListQuery;
use App\Module\Board\View\BacklogSort;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\AbstractLazyCollection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Card>
 */
class CardRepository extends ServiceEntityRepository
{
    /** Joins the card's column as k, and its parent as p with the parent's column as pk. */
    private const string SHOWN_JOINS = 'JOIN board_columns k ON k.id = c.column_id
        LEFT JOIN board_cards p ON p.id = c.parent_card_id
        LEFT JOIN board_columns pk ON pk.id = p.column_id';

    /** Whether the board shows card c. A terminal column reads as findCompletedSince() does. */
    private const string SHOWN = '(NOT k.terminal OR (c.completed_at >= :since AND (p.id IS NULL OR NOT pk.terminal)))';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Card::class);
    }

    /** @param list<Uuid> $ids */
    public function countByIds(array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The cards of a project among the ids given, with their columns. An id of
     * another project, or of a deleted card, finds nothing.
     *
     * @param list<Uuid> $ids
     *
     * @return list<Card>
     */
    public function findByIdsInProject(Project $project, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return array_values($this->createQueryBuilder('c')
            ->join('c.column', 'k')
            ->addSelect('k')
            ->andWhere('c.project = :project')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('project', $project)
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids))
            ->getQuery()
            ->getResult());
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return array<string, string> card id => title
     */
    public function findTitlesByIds(Project $project, array $ids): array
    {
        /** @var list<array{id: Uuid, title: string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c.id, c.title')
            ->andWhere('c.project = :project')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('project', $project)
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids))
            ->getQuery()
            ->getArrayResult();

        $titles = [];
        foreach ($rows as $row) {
            $titles[(string) $row['id']] = $row['title'];
        }

        return $titles;
    }

    /**
     * @param non-empty-list<Uuid> $ids
     *
     * @return list<array{id: Uuid, label: string, terminal: bool}>
     */
    public function findColumnsByIds(Project $project, array $ids): array
    {
        /** @var list<array{id: Uuid, label: string, terminal: bool}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c.id, k.label, k.terminal')
            ->join('c.column', 'k')
            ->andWhere('c.project = :project')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('project', $project)
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids))
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<array{id: Uuid, type: string}>
     */
    public function findTypesByIds(Project $project, array $ids): array
    {
        /** @var list<array{id: Uuid, type: string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c.id, c.type')
            ->andWhere('c.project = :project')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('project', $project)
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids))
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }

    /** A scalar read, so a card already in the identity map cannot give a stale column. */
    public function findColumnSlug(Project $project, Uuid $cardId): ?string
    {
        /** @var array{slug: string}|null $row */
        $row = $this->createQueryBuilder('c')
            ->select('k.slug')
            ->join('c.column', 'k')
            ->andWhere('c.project = :project')
            ->andWhere('c.id = :id')
            ->setParameter('project', $project)
            ->setParameter('id', $cardId, UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();

        return $row['slug'] ?? null;
    }

    public function findOneByProjectAndNumber(Project $project, int $number): ?Card
    {
        return $this->findOneBy(['project' => $project, 'number' => $number]);
    }

    /**
     * Open cards of a project for the widget's picker, newest first, optionally
     * narrowed by a substring of the title.
     *
     * Terminal columns are excluded: the picker exists to attach feedback to
     * work in flight, and a finished card is the one answer a reviewer almost
     * never wants.
     *
     * @param list<string> $types the types to list, or every type when empty
     *
     * @return list<Card>
     */
    public function searchOpenForProject(Project $project, string $query, int $limit, array $types = []): array
    {
        $qb = $this->createQueryBuilder('c')
            ->join('c.column', 'k')
            ->addSelect('k')
            ->where('c.project = :project')
            ->andWhere('k.terminal = false')
            ->setParameter('project', $project)
            ->orderBy('c.number', 'DESC')
            ->setMaxResults($limit);

        if ('' !== $query) {
            $qb->andWhere('LOWER(c.title) LIKE :q ESCAPE \'!\'')
                ->setParameter('q', self::titleContains($query));
        }
        if ([] !== $types) {
            $qb->andWhere('c.type IN (:types)')->setParameter('types', $types);
        }

        /* @var list<Card> */
        return $qb->getQuery()->getResult();
    }

    /**
     * The cards a card may link to: the project's cards, newest first, less
     * the card itself. A null project matches no card.
     */
    public function linkCandidates(?Uuid $projectId, ?Uuid $excludeCardId): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c')
            ->orderBy('c.number', 'DESC');

        if (null === $projectId) {
            return $qb->andWhere('1 = 0');
        }
        $qb->andWhere('c.project = :linkProject')->setParameter('linkProject', $projectId, UuidType::NAME);
        if (null !== $excludeCardId) {
            $qb->andWhere('c.id != :linkExclude')->setParameter('linkExclude', $excludeCardId, UuidType::NAME);
        }

        return $qb;
    }

    /**
     * The cards a card may take as its parent: the cards of the project whose type may have children, less the card itself.
     *
     * @param list<string> $parentTypes the type keys with the children capability
     */
    public function parentCandidates(?Uuid $projectId, ?Uuid $excludeCardId, array $parentTypes): QueryBuilder
    {
        $qb = $this->linkCandidates($projectId, $excludeCardId);
        if ([] === $parentTypes) {
            return $qb->andWhere('1 = 0');
        }

        return $qb
            ->andWhere('c.type IN (:parentTypes)')
            ->setParameter('parentTypes', $parentTypes);
    }

    /**
     * Narrows {@see linkCandidates()} to what a person typed: `12` or `#12`
     * names a card number, anything else is a fragment of the title.
     */
    public function matchLinkQuery(QueryBuilder $qb, string $query): void
    {
        $query = trim($query);
        if ('' === $query) {
            return;
        }

        // Nine digits at most, so the number always fits the integer column.
        if (1 === preg_match('/^#?(\d{1,9})$/', $query, $match)) {
            $qb->andWhere('c.number = :linkNumber')->setParameter('linkNumber', (int) $match[1]);

            return;
        }

        $qb->andWhere('LOWER(c.title) LIKE :linkTitle ESCAPE \'!\'')
            ->setParameter('linkTitle', self::titleContains($query));
    }

    /**
     * One page of the project's cards matching a full-text query, best match
     * first.
     *
     * Title and body are both searched, and every column is in scope. A done
     * card is the answer to "has anyone raised this already?" as often as an
     * open one, which is the opposite of what the picker's
     * {@see searchOpenForProject()} wants.
     *
     * @return Paginator<Card>
     */
    public function searchByProject(Project $project, string $query, int $page, int $perPage): Paginator
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.project = :project')
            ->setParameter('project', $project)
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $this->andMatchesSearch($qb, $project, $query);

        // The rank runs on the matches only, so the per-row cast costs nothing
        // here. CAST because websearch_to_tsquery has no (varchar, text)
        // overload, only (regconfig, text).
        $qb->orderBy('TS_RANK(c.searchVector, WEBSEARCH_TO_TSQUERY(CAST(c.searchLanguage AS regconfig), :search))', 'DESC')
            // Ranks tie often, and without a unique tiebreak an offset page can
            // repeat or skip a card.
            ->addOrderBy('c.number', 'DESC');

        // No collection is fetch-joined, so the page LIMIT counts cards and the
        // extra distinct-id query a fetch-join needs would buy nothing.
        return new Paginator($qb->getQuery(), fetchJoinCollection: false);
    }

    /**
     * Narrows card alias c to the cards whose title or body matches the query.
     * One branch per language the project's cards hold, each with a constant
     * configuration, because Postgres uses the GIN index only when the tsquery
     * is the same for every row.
     */
    private function andMatchesSearch(QueryBuilder $qb, Project $project, string $query): void
    {
        $branches = [];
        foreach ($this->searchLanguagesOf($project) as $index => $language) {
            // The configuration is concatenated rather than bound: Postgres
            // overloads websearch_to_tsquery as (regconfig, text) and (text), so
            // a bound parameter has no type to resolve against and picks the
            // wrong arity. It comes from the enum, never from user input.
            $branches[] = \sprintf(
                "(c.searchLanguage = :searchLanguage%d AND TSMATCH(c.searchVector, WEBSEARCH_TO_TSQUERY('%s', :search)) = true)",
                $index,
                $language->value,
            );
            $qb->setParameter('searchLanguage'.$index, $language);
        }

        $qb->andWhere('('.implode(' OR ', $branches).')')
            ->setParameter('search', $query);
    }

    /**
     * The distinct languages the project's cards are stemmed in. An index-only
     * scan of idx_board_cards_project_search_language, which is why the index
     * exists.
     *
     * A project with no cards answers with the default, so the search query
     * always has one branch to build. It matches nothing either way.
     *
     * @return list<SearchLanguage>
     */
    public function searchLanguagesOf(Project $project): array
    {
        /** @var list<SearchLanguage|string> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('DISTINCT c.searchLanguage')
            ->andWhere('c.project = :project')
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
     * The cards of one column, in rank order.
     *
     * @return list<Card>
     */
    public function findRanked(BoardColumn $column): array
    {
        return $this->findBy(
            ['column' => $column],
            ['position' => 'ASC', 'createdAt' => 'ASC', 'id' => 'ASC'],
        );
    }

    /**
     * Reads onto the card the column it is in.
     *
     * A board write locks the project row, and lock() leaves a card loaded
     * before that lock exactly as the request read it. EntityManager::refresh()
     * cannot stand in here, because it rehydrates every column and Doctrine
     * refuses to rewrite the readonly ones a Card carries. A card the database
     * no longer holds is left alone, which is what the flush already does with
     * it.
     */
    public function refreshColumn(Card $card): void
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT column_id FROM board_cards WHERE id = :id',
            ['id' => (string) $card->id],
        );

        if (false === $row) {
            return;
        }

        $card->column = $this->getEntityManager()->find(BoardColumn::class, Uuid::fromString((string) $row['column_id']))
            ?? throw new \LogicException('Card row points at a missing column.');
    }

    /**
     * Reads onto the card its type, its parent and its lane setting, for the
     * reason in refreshColumn(). A card the database no longer holds is left alone.
     */
    public function refreshTypeAndParent(Card $card): void
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT type, parent_card_id, lane_enabled FROM board_cards WHERE id = :id',
            ['id' => (string) $card->id],
        );

        if (false === $row) {
            return;
        }

        $card->type = (string) $row['type'];
        $card->laneEnabled = (bool) $row['lane_enabled'];
        $card->parent = null === $row['parent_card_id']
            ? null
            : $this->getEntityManager()->find(Card::class, Uuid::fromString((string) $row['parent_card_id']));
    }

    /** Reads onto the card its rank, for the reason in refreshColumn(). A card the database no longer holds is left alone. */
    public function refreshPosition(Card $card): void
    {
        $position = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT position FROM board_cards WHERE id = :id',
            ['id' => (string) $card->id],
        );

        if (false !== $position) {
            $card->position = (int) $position;
        }
    }

    /** The type the database holds for the card now, or null when the row is gone. */
    public function freshType(Card $card): ?string
    {
        $type = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT type FROM board_cards WHERE id = :id',
            ['id' => (string) $card->id],
        );

        return false === $type ? null : (string) $type;
    }

    /**
     * The children of one card, in the order the board shows them.
     *
     * @return list<Card>
     */
    public function findChildren(Card $parent): array
    {
        /** @var list<Card> $children */
        $children = $this->createQueryBuilder('c')
            ->join('c.column', 'k')
            ->addSelect('k')
            ->andWhere('c.parent = :parent')
            ->setParameter('parent', $parent)
            ->getQuery()
            ->getResult();

        return self::inBoardOrder($children);
    }

    /**
     * The children of many cards in one query, in the order of findChildren().
     *
     * @param list<Card> $parents
     *
     * @return array<string, list<Card>> parent id => its children; a parent with none has no key
     */
    public function findChildrenOfCards(array $parents): array
    {
        if ([] === $parents) {
            return [];
        }

        /** @var list<Card> $children */
        $children = $this->createQueryBuilder('c')
            ->join('c.column', 'k')
            ->addSelect('k')
            ->andWhere('c.parent IN (:parents)')
            ->setParameter('parents', $parents)
            ->getQuery()
            ->getResult();

        $byParent = [];
        foreach (self::inBoardOrder($children) as $child) {
            $byParent[(string) $child->parent?->id][] = $child;
        }

        return $byParent;
    }

    /**
     * Loads, in one query, the parent of each card that the identity map does
     * not hold yet. The query fills the lazy parent objects in place.
     *
     * @param list<Card> $cards
     */
    public function loadParentsOf(array $cards): void
    {
        $em = $this->getEntityManager();
        $ids = [];
        foreach ($cards as $card) {
            if (null !== $card->parent && $em->isUninitializedObject($card->parent)) {
                $ids[(string) $card->parent->id] = true;
            }
        }
        if ([] === $ids) {
            return;
        }

        $this->createQueryBuilder('c')
            ->join('c.column', 'k')
            ->addSelect('k')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('ids', array_map(strval(...), array_keys($ids)))
            ->getQuery()
            ->getResult();
    }

    /**
     * Loads, in one query, the pull request links of each card whose collection is still lazy. The
     * query fills the collections in place, so a page read from a query without a join costs one
     * query rather than one for each card.
     *
     * @param list<Card> $cards
     */
    public function loadPullRequestsOf(array $cards): void
    {
        $lazy = array_values(array_filter($cards, static fn (Card $card): bool => $card->pullRequests instanceof AbstractLazyCollection && !$card->pullRequests->isInitialized()));
        if ([] === $lazy) {
            return;
        }

        $this->createQueryBuilder('c')
            ->leftJoin('c.pullRequests', 'l')
            ->addSelect('l')
            ->andWhere('c IN (:cards)')
            ->setParameter('cards', $lazy)
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether the database shows anyone worked on the card: a body, a title
     * other than the one it was created with, another type, a pull request, a
     * document, or a link to or from another card.
     */
    public function hasWork(Card $card, string $createdTitle, string $createdType): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM board_cards WHERE id = :id AND (body <> '' OR title <> :title OR type <> :type))
                 OR EXISTS (SELECT 1 FROM board_card_pull_requests WHERE card_id = :id)
                 OR EXISTS (SELECT 1 FROM board_card_documents WHERE card_id = :id)
                 OR EXISTS (SELECT 1 FROM board_card_links WHERE source_card_id = :id OR target_card_id = :id)",
            ['id' => (string) $card->id, 'title' => $createdTitle, 'type' => $createdType],
        );
    }

    /**
     * The ids of the children of a card, as the database holds them now.
     *
     * @return list<string>
     */
    public function findChildIds(Uuid $parentId): array
    {
        return array_values(array_map(strval(...), $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM board_cards WHERE parent_card_id = :id',
            ['id' => $parentId->toRfc4122()],
        )));
    }

    public function countChildren(Card $card): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM board_cards WHERE parent_card_id = :id',
            ['id' => (string) $card->id],
        );
    }

    /**
     * The numbers of the card's children in a column that is not terminal, as
     * the database holds them now.
     *
     * @return list<int>
     */
    public function openChildNumbers(Card $card): array
    {
        return array_map(intval(...), $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT c.number FROM board_cards c JOIN board_columns k ON k.id = c.column_id
             WHERE c.parent_card_id = :id AND k.terminal = false
             ORDER BY c.number',
            ['id' => (string) $card->id],
        ));
    }

    /**
     * The children the card blocks that wait in the Backlog and whose
     * every blocker now sits in a terminal column.
     *
     * @return list<Card>
     */
    public function findChildrenFreedBy(Card $blocker): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            "SELECT t.id FROM board_card_links l
             JOIN board_cards t ON t.id = l.target_card_id
             JOIN board_columns k ON k.id = t.column_id
             WHERE l.source_card_id = :id AND l.kind = 'blocks'
               AND t.parent_card_id IS NOT NULL AND k.is_default = true
               AND NOT EXISTS (
                   SELECT 1 FROM board_card_links b
                   JOIN board_cards s ON s.id = b.source_card_id
                   JOIN board_columns sk ON sk.id = s.column_id
                   WHERE b.target_card_id = t.id AND b.kind = 'blocks' AND sk.terminal = false
               )
             ORDER BY t.position, t.number",
            ['id' => (string) $blocker->id],
        );

        return array_values(array_filter(array_map(
            fn (mixed $id): ?Card => \is_string($id) ? $this->getEntityManager()->find(Card::class, Uuid::fromString($id)) : null,
            $ids,
        )));
    }

    /**
     * The cards that block this one from a column that is not terminal, as the
     * database holds them now, by number.
     *
     * @return list<Card>
     */
    public function findOpenBlockersOf(Card $card): array
    {
        return $this->cardsByIds($this->getEntityManager()->getConnection()->fetchFirstColumn(
            "SELECT s.id FROM board_card_links l
             JOIN board_cards s ON s.id = l.source_card_id
             JOIN board_columns k ON k.id = s.column_id
             WHERE l.target_card_id = :id AND l.kind = 'blocks' AND k.terminal = false
             ORDER BY s.number",
            ['id' => (string) $card->id],
        ));
    }

    /**
     * The cards this one blocks, as the database holds them now, by number.
     *
     * @return list<Card>
     */
    public function findBlockedBy(Card $blocker): array
    {
        return $this->findBlockedByAny([(string) $blocker->id]);
    }

    /**
     * The cards any of these cards block, each once, as the database holds them now, by number.
     *
     * @param list<string> $blockerIds
     *
     * @return list<Card>
     */
    public function findBlockedByAny(array $blockerIds): array
    {
        if ([] === $blockerIds) {
            return [];
        }

        return $this->cardsByIds($this->getEntityManager()->getConnection()->fetchFirstColumn(
            "SELECT t.id FROM board_cards t
             WHERE EXISTS (
                 SELECT 1 FROM board_card_links l
                 WHERE l.target_card_id = t.id AND l.kind = 'blocks' AND l.source_card_id IN (:ids)
             )
             ORDER BY t.number",
            ['ids' => $blockerIds],
            ['ids' => ArrayParameterType::STRING],
        ));
    }

    /**
     * @param list<mixed> $ids
     *
     * @return list<Card>
     */
    private function cardsByIds(array $ids): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $id): ?Card => \is_string($id) ? $this->getEntityManager()->find(Card::class, Uuid::fromString($id)) : null,
            $ids,
        )));
    }

    /** Reads the title and body as the database holds them now, like refreshColumn(). */
    public function refreshContent(Card $card): void
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT title, body FROM board_cards WHERE id = :id',
            ['id' => (string) $card->id],
        );

        if (false === $row) {
            return;
        }

        // The originals too: a caller that writes back the text it loaded
        // earlier must still reach the database.
        $unitOfWork = $this->getEntityManager()->getUnitOfWork();
        foreach (['title', 'body'] as $field) {
            $card->{$field} = (string) $row[$field];
            $unitOfWork->setOriginalEntityProperty(spl_object_id($card), $field, (string) $row[$field]);
        }
    }

    /** The number the project's next card takes. The first card of a project is 1. */
    public function nextNumber(Project $project): int
    {
        $highest = $this->createQueryBuilder('c')
            ->select('MAX(c.number)')
            ->andWhere('c.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $highest ? 1 : ((int) $highest) + 1;
    }

    public function isInOpenColumn(Card $card): bool
    {
        return 0 < (int) $this->createQueryBuilder('card')
            ->select('COUNT(card.id)')
            ->join('card.column', 'column')
            ->where('card.id = :id')
            ->andWhere('column.terminal = false')
            ->setParameter('id', $card->id)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * One card, scoped to its project.
     *
     * The project id is part of the lookup rather than checked afterwards, so a
     * URL that pairs one project with another project's card is a 404.
     */
    public function findOneByIdAndProjectId(string $cardId, string $projectId): ?Card
    {
        if (!Uuid::isValid($cardId) || !Uuid::isValid($projectId)) {
            return null;
        }

        return $this->createQueryBuilder('c')
            ->andWhere('c.id = :cardId')
            ->andWhere('c.project = :projectId')
            ->setParameter('cardId', Uuid::fromString($cardId), UuidType::NAME)
            ->setParameter('projectId', Uuid::fromString($projectId), UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The cards of a terminal column finished on or after the given moment,
     * newest first.
     *
     * The board shows a recent slice of a terminal column rather than all of
     * it, so a column that only ever grows does not become the page's whole
     * height.
     *
     * A card whose epic sits in a terminal column is left out: the done epic
     * stands for it on the board, and the history page still lists it.
     *
     * @return list<Card>
     */
    public function findCompletedSince(BoardColumn $column, \DateTimeImmutable $since): array
    {
        return array_values(
            $this->withPullRequests(
                $this->completedQuery($column)
                    ->leftJoin('c.parent', 'parent')
                    ->addSelect('parent')
                    ->leftJoin('parent.column', 'parentColumn')
                    ->andWhere('c.completedAt >= :since')
                    ->andWhere('parent.id IS NULL OR parentColumn.terminal = false')
                    ->setParameter('since', $since),
            )
                ->getQuery()
                ->getResult(),
        );
    }

    /**
     * The cards of the project in a terminal column, oldest completion first.
     *
     * @return list<array{id: Uuid, number: int, title: string, completedAt: \DateTimeImmutable}>
     */
    public function findFinishedRows(Project $project, ?\DateTimeImmutable $completedSince): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.id, c.number, c.title, c.completedAt')
            ->join('c.column', 'k')
            ->andWhere('c.project = :project')
            ->andWhere('k.terminal = true')
            ->andWhere('c.completedAt IS NOT NULL')
            ->setParameter('project', $project)
            ->orderBy('c.completedAt', 'ASC')
            ->addOrderBy('c.number', 'ASC');

        if (null !== $completedSince) {
            $qb->andWhere('c.completedAt >= :since')->setParameter('since', $completedSince, Types::DATETIME_IMMUTABLE);
        }

        /** @var list<array{id: Uuid, number: int, title: string, completedAt: \DateTimeImmutable}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return $rows;
    }

    /**
     * How many children each epic of the project has, and how many of them
     * sit in a terminal column. An epic with no children has no key.
     *
     * @return array<string, array{done: int, total: int}> epic id => its counts
     */
    public function childProgressForProject(Project $project): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT c.parent_card_id AS id, COUNT(*) AS total, SUM(CASE WHEN k.terminal THEN 1 ELSE 0 END) AS done
             FROM board_cards c JOIN board_columns k ON k.id = c.column_id
             WHERE c.project_id = :project AND c.parent_card_id IS NOT NULL
             GROUP BY c.parent_card_id',
            ['project' => (string) $project->id],
        );

        $progress = [];
        foreach ($rows as $row) {
            $progress[(string) $row['id']] = ['done' => (int) $row['done'], 'total' => (int) $row['total']];
        }

        return $progress;
    }

    /**
     * How many children one epic has, and how many of them sit in a terminal column.
     *
     * @return array{done: int, total: int}
     */
    public function childProgressOf(Card $epic): array
    {
        /** @var array{done: int|string|null, total: int|string} $row */
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT COUNT(*) AS total, SUM(CASE WHEN k.terminal THEN 1 ELSE 0 END) AS done
             FROM board_cards c JOIN board_columns k ON k.id = c.column_id
             WHERE c.parent_card_id = :epic',
            ['epic' => (string) $epic->id],
        );

        return ['done' => (int) $row['done'], 'total' => (int) $row['total']];
    }

    /**
     * The first cards of the column under each parent, in rank order, at most
     * $size per parent: parent by parent, then down the rank.
     *
     * @param list<string> $parentIds
     *
     * @return list<Card>
     */
    public function findDeckCards(BoardColumn $column, array $parentIds, int $size): array
    {
        $rsm = new ResultSetMappingBuilder($this->getEntityManager());
        $rsm->addRootEntityFromClassMetadata(Card::class, 'c');

        /** @var list<Card> $cards */
        $cards = $this->getEntityManager()->createNativeQuery(
            'SELECT '.$rsm->generateSelectClause(['c' => 'c']).' FROM (
                SELECT d.*, ROW_NUMBER() OVER (PARTITION BY d.parent_card_id ORDER BY d.position, d.created_at, d.id) AS deck_rank
                FROM board_cards d
                WHERE d.column_id = :column AND d.parent_card_id IN (:parents)
             ) c
             WHERE c.deck_rank <= :size
             ORDER BY c.parent_card_id, c.deck_rank',
            $rsm,
        )
            ->setParameter('column', (string) $column->id)
            ->setParameter('parents', $parentIds, ArrayParameterType::STRING)
            ->setParameter('size', $size)
            ->getResult();

        return $cards;
    }

    /**
     * How many cards of the column each parent has. A parent with none has no key.
     *
     * @param list<string> $parentIds
     *
     * @return array<string, int> parent id => its count
     */
    public function countChildrenIn(BoardColumn $column, array $parentIds): array
    {
        /** @var array<string, int|string> $counts */
        $counts = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT parent_card_id, COUNT(*) FROM board_cards
             WHERE column_id = :column AND parent_card_id IN (:parents)
             GROUP BY parent_card_id',
            ['column' => (string) $column->id, 'parents' => $parentIds],
            ['parents' => ArrayParameterType::STRING],
        );

        return array_map(intval(...), $counts);
    }

    /**
     * The column the board shows the card in, read from its row, or null when
     * the board does not show it. findCompletedSince() says which terminal
     * cards the board shows.
     */
    public function shownColumnIdOf(Card $card, \DateTimeImmutable $since): ?string
    {
        $columnId = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT c.column_id FROM board_cards c '.self::SHOWN_JOINS.'
             WHERE c.id = :card AND k.project_id = :project AND '.self::SHOWN,
            ['card' => (string) $card->id, 'project' => (string) $card->project->id, 'since' => $since],
            ['since' => Types::DATETIME_IMMUTABLE],
        );

        return false === $columnId ? null : (string) $columnId;
    }

    /**
     * The id of the shown card just before this card in its column, in the
     * order findColumn() reads. The card's own rank comes from its row, which
     * a bulk renumber may have changed under the loaded entity.
     *
     * With a lane, only the cards of that lane count, and a lane epic never
     * does: "other" is every card whose parent is no lane epic.
     *
     * @param list<string> $laneEpicIds
     */
    public function previousShownIdOf(Card $card, BoardColumn $column, \DateTimeImmutable $since, ?string $lane, array $laneEpicIds): ?string
    {
        $params = ['card' => (string) $card->id, 'column' => (string) $column->id];
        $types = [];
        $inLane = '';
        if (null !== $lane) {
            $inLane = 'other' === $lane
                ? ' AND c.id NOT IN (:epics) AND (c.parent_card_id IS NULL OR c.parent_card_id NOT IN (:epics))'
                : ' AND c.id NOT IN (:epics) AND c.parent_card_id = :lane';
            $params['epics'] = $laneEpicIds;
            $types['epics'] = ArrayParameterType::STRING;
            if ('other' !== $lane) {
                $params['lane'] = $lane;
            }
        }

        $sql = $column->terminal
            ? 'SELECT c.id FROM board_cards c '.self::SHOWN_JOINS.'
               JOIN board_cards s ON s.id = :card
               WHERE c.column_id = :column AND '.self::SHOWN.$inLane.'
                 AND (c.completed_at, c.created_at, c.id) > (s.completed_at, s.created_at, s.id)
               ORDER BY c.completed_at ASC, c.created_at ASC, c.id ASC LIMIT 1'
            : 'SELECT c.id FROM board_cards c
               JOIN board_cards s ON s.id = :card
               WHERE c.column_id = :column'.$inLane.'
                 AND (c.position, c.created_at, c.id) < (s.position, s.created_at, s.id)
               ORDER BY c.position DESC, c.created_at DESC, c.id DESC LIMIT 1';
        if ($column->terminal) {
            $params['since'] = $since;
            $types['since'] = Types::DATETIME_IMMUTABLE;
        }

        $id = $this->getEntityManager()->getConnection()->fetchOne($sql, $params, $types);

        return false === $id ? null : (string) $id;
    }

    /**
     * The epics the board draws as lanes, Backlog included, in board order:
     * column by column, then down each column. Card::drawsLane() says which.
     *
     * @param list<string> $laneTypes the type keys with the lane capability
     *
     * @return list<Card>
     */
    public function findLaneEpics(Project $project, array $laneTypes): array
    {
        if ([] === $laneTypes) {
            return [];
        }

        return array_values($this->createQueryBuilder('c')
            ->join('c.column', 'k')
            ->addSelect('k')
            ->andWhere('c.project = :project')
            ->andWhere('c.type IN (:laneTypes)')
            ->andWhere('c.laneEnabled = true')
            ->andWhere('k.terminal = false')
            ->setParameter('project', $project)
            ->setParameter('laneTypes', $laneTypes)
            ->orderBy('k.position', 'ASC')
            ->addOrderBy('k.id', 'ASC')
            ->addOrderBy('c.position', 'ASC')
            ->addOrderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** The id of the last card the board shows in the column, or null when it shows none. */
    public function lastShownIdIn(BoardColumn $column, \DateTimeImmutable $since): ?string
    {
        $sql = $column->terminal
            ? 'SELECT c.id FROM board_cards c '.self::SHOWN_JOINS.'
               WHERE c.column_id = :column AND '.self::SHOWN.'
               ORDER BY c.completed_at ASC, c.created_at ASC, c.id ASC LIMIT 1'
            : 'SELECT c.id FROM board_cards c WHERE c.column_id = :column
               ORDER BY c.position DESC, c.created_at DESC, c.id DESC LIMIT 1';

        $id = $this->getEntityManager()->getConnection()->fetchOne(
            $sql,
            ['column' => (string) $column->id, ...($column->terminal ? ['since' => $since] : [])],
            $column->terminal ? ['since' => Types::DATETIME_IMMUTABLE] : [],
        );

        return false === $id ? null : (string) $id;
    }

    /**
     * For each column of the project that holds a card, how many cards it
     * holds and how many of them the board shows.
     *
     * @return array<string, array{total: int, shown: int}> column id => its counts
     */
    public function shownCountsOf(Project $project, \DateTimeImmutable $since): array
    {
        /** @var list<array{column_id: string, total: int|string, shown: int|string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT c.column_id, COUNT(*) AS total, COUNT(*) FILTER (WHERE '.self::SHOWN.') AS shown
             FROM board_cards c '.self::SHOWN_JOINS.'
             WHERE k.project_id = :project
             GROUP BY c.column_id',
            ['project' => (string) $project->id, 'since' => $since],
            ['since' => Types::DATETIME_IMMUTABLE],
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['column_id']] = ['total' => (int) $row['total'], 'shown' => (int) $row['shown']];
        }

        return $counts;
    }

    /**
     * One page of a terminal column's whole history, newest first.
     *
     * Through a Paginator, because the fetch-join multiplies the rows a LIMIT
     * counts: without it a page of 25 cards is cut short by their links.
     *
     * @return list<Card>
     */
    public function findCompletedPage(BoardColumn $column, int $offset, int $limit): array
    {
        $query = $this->withPullRequests($this->completedQuery($column))
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery();

        return array_values(iterator_to_array(new Paginator($query, fetchJoinCollection: true), false));
    }

    /**
     * One page of the Backlog cards that match the filters, in the order the
     * query asks for, with the epic of each card.
     *
     * @return list<Card>
     */
    public function findBacklogPage(BoardColumn $backlog, BacklogListQuery $listQuery, int $offset, int $limit): array
    {
        return array_values($this->backlogPage($backlog, $listQuery, $offset, $limit)
            ->addSelect('parent')
            ->getQuery()
            ->getResult());
    }

    /**
     * The ids findBacklogPage() returns, as scalars, so a read before a move
     * puts no card in the identity map ahead of the move's lock.
     *
     * @return list<string>
     */
    public function findBacklogPageIds(BoardColumn $backlog, BacklogListQuery $listQuery, int $offset, int $limit): array
    {
        $rows = $this->backlogPage($backlog, $listQuery, $offset, $limit, 'c.id')->getQuery()->getArrayResult();

        return array_values(array_map(static fn (array $row): string => (string) $row['id'], $rows));
    }

    /** The select comes first, so the hidden sort column of the epic order survives it. */
    private function backlogPage(BoardColumn $backlog, BacklogListQuery $listQuery, int $offset, int $limit, string $select = 'c'): QueryBuilder
    {
        $qb = $this->backlogMatching($backlog, $listQuery)
            ->select($select)
            ->leftJoin('c.parent', 'parent')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $dir = strtoupper($listQuery->dir->value);

        match ($listQuery->sort) {
            BacklogSort::Created => $qb->orderBy('c.createdAt', $dir),
            BacklogSort::Type => $qb->orderBy('c.type', $dir),
            // Cards with no epic go last in both directions.
            BacklogSort::Epic => $qb
                ->addSelect('CASE WHEN parent.id IS NULL THEN 1 ELSE 0 END AS HIDDEN noEpic')
                ->orderBy('noEpic', 'ASC')
                ->addOrderBy('parent.number', $dir),
        };

        // The id ends each order, so an offset page never repeats or skips a card.
        return $qb->addOrderBy('c.id', $dir);
    }

    public function countBacklogMatching(BoardColumn $backlog, BacklogListQuery $listQuery): int
    {
        return (int) $this->backlogMatching($backlog, $listQuery)
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The epics that hold at least one card of the column, by number, for the
     * epic filter of the Backlog page.
     *
     * @return list<Card>
     */
    public function findEpicsOfColumn(BoardColumn $column): array
    {
        return array_values($this->createQueryBuilder('e')
            ->andWhere('e.project = :project')
            ->andWhere('EXISTS (SELECT 1 FROM '.Card::class.' child WHERE child.parent = e AND child.column = :column)')
            ->setParameter('project', $column->project)
            ->setParameter('column', $column)
            ->orderBy('e.number', 'ASC')
            ->getQuery()
            ->getResult());
    }

    private function backlogMatching(BoardColumn $backlog, BacklogListQuery $listQuery): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.column = :column')
            ->setParameter('column', $backlog);

        if (null !== $listQuery->search) {
            $this->andMatchesSearch($qb, $backlog->project, $listQuery->search);
        }
        if (null !== $listQuery->type) {
            $qb->andWhere('c.type = :type')->setParameter('type', $listQuery->type);
        }
        if (BacklogListQuery::NO_EPIC === $listQuery->epic) {
            $qb->andWhere('c.parent IS NULL');
        } elseif (null !== $listQuery->epic) {
            $qb->andWhere('c.parent = :epic')->setParameter('epic', Uuid::fromString($listQuery->epic), UuidType::NAME);
        }

        return $qb;
    }

    public function countInColumn(BoardColumn $column): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.column = :column')
            ->setParameter('column', $column)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The id and number of every card in one column, in the order the board
     * shows them, without loading the cards.
     *
     * @return list<array{id: string, number: int}>
     */
    public function findRowsInColumn(BoardColumn $column): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id, number FROM board_cards WHERE column_id = :column
             ORDER BY position, completed_at, created_at, number',
            ['column' => (string) $column->id],
        );

        return array_map(
            static fn (array $row): array => ['id' => (string) $row['id'], 'number' => (int) $row['number']],
            $rows,
        );
    }

    /**
     * The rank of every card in one column.
     *
     * @return array<string, int> card id => position
     */
    public function positionsInColumn(BoardColumn $column): array
    {
        return array_map(
            intval(...),
            $this->getEntityManager()->getConnection()->fetchAllKeyValue(
                'SELECT id, position FROM board_cards WHERE column_id = :column',
                ['column' => (string) $column->id],
            ),
        );
    }

    /**
     * Moves every card of one column to another in one statement. The cards
     * join the end of the target, in the order the
     * source showed them. A terminal target keeps no rank and stamps a card
     * that was not finished, and any other target clears the completion.
     *
     * A card loaded before the call keeps its old column in memory. Call
     * refreshLoadedFrom() once the rows are final. Move through
     * CardMover::moveAll(), which also closes the interactive runs.
     *
     * @return list<string> the ids of the moved cards
     */
    public function moveAll(BoardColumn $from, BoardColumn $to, \DateTimeImmutable $now): array
    {
        /* @var list<string> */
        return $this->getEntityManager()->getConnection()->executeQuery(
            \sprintf(
                'UPDATE board_cards c
                 SET column_id = :to, updated_at = :now, completed_at = %s, position = %s
                 FROM (
                     SELECT s.id,
                            row_number() OVER (ORDER BY s.position, s.completed_at, s.created_at, s.number) - 1 AS rank,
                            (SELECT COALESCE(MAX(t.position) + 1, 0) FROM board_cards t WHERE t.column_id = :to) AS tail
                     FROM board_cards s
                     WHERE s.column_id = :from
                 ) ranked
                 WHERE c.id = ranked.id
                 RETURNING c.id',
                $to->terminal ? 'COALESCE(c.completed_at, :now)' : 'NULL',
                $to->terminal ? '0' : 'ranked.tail + ranked.rank',
            ),
            ['from' => (string) $from->id, 'to' => (string) $to->id, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        )->fetchFirstColumn();
    }

    /**
     * Reads the moved fields back onto every loaded card that still sits in
     * $from in memory, after a bulk statement moved its row. A later flush then
     * neither meets a deleted column nor writes a stale rank back. A proxy
     * nobody loaded costs no query.
     *
     * EntityManager::refresh() cannot do this, for the reason in refreshColumn().
     */
    public function refreshLoadedFrom(BoardColumn $from): void
    {
        $em = $this->getEntityManager();
        $connection = $em->getConnection();
        $dateTime = Type::getType(Types::DATETIME_IMMUTABLE);
        foreach ($em->getUnitOfWork()->getIdentityMap()[Card::class] ?? [] as $card) {
            if (!$card instanceof Card || $em->isUninitializedObject($card) || $card->column !== $from) {
                continue;
            }

            $row = $connection->fetchAssociative(
                'SELECT column_id, position, completed_at, updated_at FROM board_cards WHERE id = :id',
                ['id' => (string) $card->id],
            );
            $column = false === $row ? null : $em->find(BoardColumn::class, Uuid::fromString((string) $row['column_id']));
            if (false === $row || null === $column) {
                continue;
            }

            $card->column = $column;
            $card->position = (int) $row['position'];
            $card->completedAt = $dateTime->convertToPHPValue($row['completed_at'], $connection->getDatabasePlatform());
            $card->updatedAt = $dateTime->convertToPHPValue($row['updated_at'], $connection->getDatabasePlatform());
        }
    }

    /**
     * Numbers a column from 0 with no gaps, in the order it already has. Only a card whose rank changes is written.
     */
    public function renumberColumn(BoardColumn $column, \DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE board_cards c
             SET position = ranked.rank, updated_at = :now
             FROM (
                 SELECT id,
                        row_number() OVER (ORDER BY position, completed_at, created_at, number) - 1 AS rank
                 FROM board_cards
                 WHERE column_id = :column
             ) ranked
             WHERE c.id = ranked.id AND c.position <> ranked.rank',
            ['column' => (string) $column->id, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * Ranks the column from 0 without $card, and leaves the rank $gapAt free
     * when it is given. Only a card whose rank changes is written. $card is
     * left out by id, because its row may still sit in the column until the
     * caller flushes.
     */
    public function rankWithout(BoardColumn $column, Card $card, ?int $gapAt = null): void
    {
        $parameters = [
            'column' => (string) $column->id,
            'card' => (string) ($card->id ?? throw new \LogicException('Only a persisted card is ranked.')),
        ];
        if (null !== $gapAt) {
            $parameters['gap'] = $gapAt;
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            \sprintf(
                'UPDATE board_cards c
                 SET position = ranked.rank
                 FROM (
                     SELECT id, %s AS rank
                     FROM (
                         SELECT id, row_number() OVER (ORDER BY position, created_at, id) - 1 AS r
                         FROM board_cards
                         WHERE column_id = :column AND id <> :card
                     ) numbered
                 ) ranked
                 WHERE c.id = ranked.id AND c.position <> ranked.rank',
                null === $gapAt ? 'r' : 'CASE WHEN r >= :gap THEN r + 1 ELSE r END',
            ),
            $parameters,
        );
    }

    /**
     * Reads the ranks of the column back onto every loaded card that sits in
     * it both in memory and in the database, except $skipped, and marks them
     * clean so a flush writes no rank again.
     *
     * @return array<string, int> card id => position, for the whole column
     */
    public function refreshLoadedRanks(BoardColumn $column, Card $skipped): array
    {
        $positions = $this->positionsInColumn($column);
        $em = $this->getEntityManager();
        $unitOfWork = $em->getUnitOfWork();
        foreach ($unitOfWork->getIdentityMap()[Card::class] ?? [] as $card) {
            if (!$card instanceof Card || $card === $skipped || $em->isUninitializedObject($card) || $card->column !== $column) {
                continue;
            }
            $position = $positions[(string) $card->id] ?? null;
            if (null === $position) {
                continue;
            }

            $card->position = $position;
            $unitOfWork->setOriginalEntityProperty(spl_object_id($card), 'position', $position);
        }

        return $positions;
    }

    /** Stamps every card of a column that turned terminal and was not finished yet. */
    public function stampCompletion(BoardColumn $column, \DateTimeImmutable $now): void
    {
        $this->createQueryBuilder('c')
            ->update()
            ->set('c.completedAt', ':now')
            ->set('c.updatedAt', ':now')
            ->set('c.position', 0)
            ->andWhere('c.column = :column')
            ->andWhere('c.completedAt IS NULL')
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('column', $column)
            ->getQuery()
            ->execute();
    }

    /** Clears the completion of every card in a column that stopped being terminal. */
    public function clearCompletion(BoardColumn $column, \DateTimeImmutable $now): void
    {
        $this->createQueryBuilder('c')
            ->update()
            ->set('c.completedAt', 'NULL')
            ->set('c.updatedAt', ':now')
            ->andWhere('c.column = :column')
            ->andWhere('c.completedAt IS NOT NULL')
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('column', $column)
            ->getQuery()
            ->execute();
    }

    /** The rank a card appended to the end of that column takes. */
    public function nextPosition(BoardColumn $column): int
    {
        $highest = $this->createQueryBuilder('c')
            ->select('MAX(c.position)')
            ->andWhere('c.column = :column')
            ->setParameter('column', $column)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $highest ? 0 : ((int) $highest) + 1;
    }

    /**
     * The board's read query, one column at a time.
     *
     * A column is read on its own even when the caller asks for the whole
     * board, because a terminal column sorts by completion while every other
     * column sorts by position. The cost is one query per column for an
     * unfiltered read, each on the composite index.
     *
     * @param list<BoardColumn> $columns in board order, which is the order the cards come back in
     *
     * @return list<Card>
     */
    public function findForBoard(array $columns, ?string $type = null, ?CardReporter $reporter = null, ?Card $parent = null, ?bool $paused = null): array
    {
        $cards = [];
        foreach ($columns as $column) {
            $cards = [...$cards, ...$this->findColumn($column, $type, $reporter, $parent, $paused)];
        }

        return $cards;
    }

    /**
     * @param list<Project> $projects
     *
     * @return array<string, array{open: int, completed: int}>
     */
    public function countByProjects(array $projects): array
    {
        if ([] === $projects) {
            return [];
        }

        /** @var list<array{id: mixed, open: mixed, completed: mixed}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.project) AS id, SUM(CASE WHEN k.terminal = false THEN 1 ELSE 0 END) AS open, SUM(CASE WHEN k.terminal = true THEN 1 ELSE 0 END) AS completed')
            ->join('c.column', 'k')
            ->andWhere('c.project IN (:projects)')
            ->setParameter('projects', $projects)
            ->groupBy('c.project')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['id']] = ['open' => (int) $row['open'], 'completed' => (int) $row['completed']];
        }

        return $counts;
    }

    /**
     * Every card on every project the user owns, for the account data export.
     *
     * The pull request links, the column and the parent are fetch-joined,
     * because the export reads them on every row and they are lazy otherwise.
     *
     * @return list<Card>
     */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('c')
            ->join('c.project', 'p')
            ->join('c.column', 'k')
            ->addSelect('k')
            ->leftJoin('c.parent', 'parent')
            ->addSelect('parent')
            ->leftJoin('c.pullRequests', 'l')
            ->addSelect('l')
            ->andWhere('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->addOrderBy('l.addedAt', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /** @return list<Card> */
    private function findColumn(BoardColumn $column, ?string $type, ?CardReporter $reporter, ?Card $parent, ?bool $paused): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.column = :column')
            ->setParameter('column', $column);

        if (null !== $type) {
            $qb->andWhere('c.type = :type')->setParameter('type', $type);
        }
        if (null !== $parent) {
            $qb->andWhere('c.parent = :parent')->setParameter('parent', $parent);
        }
        if (null !== $paused) {
            $qb->andWhere(\sprintf('%sEXISTS (SELECT pause.id FROM %s pause WHERE pause.card = c AND pause.releasedAt IS NULL)', $paused ? '' : 'NOT ', CardPause::class));
        }
        if (null !== $reporter) {
            // COALESCE, not c.reporter: a row an older image wrote after this
            // release carries origin alone, and it still has to match.
            $qb->andWhere('COALESCE(c.storedReporter, c.origin) = :reporter')
                ->setParameter('reporter', $reporter->value);
        }

        // The tie-break runs with its column, not after both branches. A
        // terminal column sorts newest first and completed_at holds whole
        // seconds, so two cards finished in the same second need a tie-break
        // that also runs newest first.
        if ($column->terminal) {
            $qb->orderBy('c.completedAt', 'DESC')
                ->addOrderBy('c.createdAt', 'DESC')
                ->addOrderBy('c.id', 'DESC');
        } else {
            $qb->orderBy('c.position', 'ASC')
                ->addOrderBy('c.createdAt', 'ASC')
                ->addOrderBy('c.id', 'ASC');
        }

        return array_values($this->withPullRequests($qb)->getQuery()->getResult());
    }

    /**
     * Sorts cards as the board shows them: by column, then in each column
     * the order of findColumn(). The sort runs in PHP, because a terminal
     * and an open column sort on different keys in opposite directions.
     *
     * @param list<Card> $cards
     *
     * @return list<Card>
     */
    private static function inBoardOrder(array $cards): array
    {
        usort($cards, static function (Card $a, Card $b): int {
            $column = $a->column->position <=> $b->column->position;
            if (0 !== $column || $a->column !== $b->column) {
                return 0 !== $column ? $column : strcmp((string) $a->column->id, (string) $b->column->id);
            }

            return $a->column->terminal
                ? [$b->completedAt, $b->createdAt, (string) $b->id] <=> [$a->completedAt, $a->createdAt, (string) $a->id]
                : [$a->position, $a->createdAt, (string) $a->id] <=> [$b->position, $b->createdAt, (string) $b->id];
        });

        return $cards;
    }

    /**
     * A LIKE pattern for a lower-case title that contains $query, escaped with
     * the ESCAPE clause's `!`. A backslash is a literal here, so addcslashes()
     * would leave % and _ as wildcards. The `!` goes first, or it doubles the others.
     */
    private static function titleContains(string $query): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query)).'%';
    }

    /**
     * Hydrates each card's pull request links with the card.
     *
     * Every board and history row reads the link count, and the association is
     * lazy, so without this each card on the page costs its own query.
     *
     * Call it after the card ordering is set, never before: it appends the
     * association's own order, which a later orderBy() would drop. A fetch-join
     * ignores the #[ORM\OrderBy] on the property, so the DQL has to carry it.
     */
    private function withPullRequests(QueryBuilder $qb): QueryBuilder
    {
        return $qb
            ->leftJoin('c.pullRequests', 'pullRequest')
            ->addSelect('pullRequest')
            ->addOrderBy('pullRequest.addedAt', 'ASC');
    }

    /** A terminal column in the order it reads: by completion, newest first. */
    private function completedQuery(BoardColumn $column): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.column = :column')
            ->setParameter('column', $column)
            ->orderBy('c.completedAt', 'DESC')
            ->addOrderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC');
    }
}
