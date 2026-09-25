<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Doctrine\SearchLanguage;
use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
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
     * @return list<Card>
     */
    public function searchOpenForProject(Project $project, string $query, int $limit, ?CardType $type = null): array
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
        if (null !== $type) {
            $qb->andWhere('c.type = :type')->setParameter('type', $type);
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

    /** The cards a card may take as its parent: the epics of the project, less the card itself. */
    public function parentCandidates(?Uuid $projectId, ?Uuid $excludeCardId): QueryBuilder
    {
        return $this->linkCandidates($projectId, $excludeCardId)
            ->andWhere('c.type = :parentType')
            ->setParameter('parentType', CardType::Epic->value);
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

        // One branch per language the project's cards hold, each with a constant
        // configuration, because Postgres uses the GIN index only when the
        // tsquery is the same for every row. Deriving the configuration from the
        // row instead turns the match into a filter over every card.
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
     * Reads onto the card its type and its parent, for the reason in
     * refreshColumn(). A card the database no longer holds is left alone.
     */
    public function refreshTypeAndParent(Card $card): void
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT type, parent_card_id FROM board_cards WHERE id = :id',
            ['id' => (string) $card->id],
        );

        if (false === $row) {
            return;
        }

        $card->type = CardType::from((string) $row['type']);
        $card->parent = null === $row['parent_card_id']
            ? null
            : $this->getEntityManager()->find(Card::class, Uuid::fromString((string) $row['parent_card_id']));
    }

    /** The type the database holds for the card now, or null when the row is gone. */
    public function freshType(Card $card): ?CardType
    {
        $type = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT type FROM board_cards WHERE id = :id',
            ['id' => (string) $card->id],
        );

        return false === $type ? null : CardType::from((string) $type);
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
     * Whether the database shows anyone worked on the card: a body, a pull
     * request, a document, or a link to or from another card.
     */
    public function hasWork(Card $card): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM board_cards WHERE id = :id AND body <> '')
                 OR EXISTS (SELECT 1 FROM board_card_pull_requests WHERE card_id = :id)
                 OR EXISTS (SELECT 1 FROM board_card_documents WHERE card_id = :id)
                 OR EXISTS (SELECT 1 FROM board_card_links WHERE source_card_id = :id OR target_card_id = :id)",
            ['id' => (string) $card->id],
        );
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
     * The children the card blocks that wait in the default column and whose
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
     * refreshLoadedFrom() once the rows are final.
     */
    public function moveAll(BoardColumn $from, BoardColumn $to, \DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
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
                 WHERE c.id = ranked.id',
                $to->terminal ? 'COALESCE(c.completed_at, :now)' : 'NULL',
                $to->terminal ? '0' : 'ranked.tail + ranked.rank',
            ),
            ['from' => (string) $from->id, 'to' => (string) $to->id, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
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
    public function findForBoard(array $columns, ?CardType $type = null, ?CardReporter $reporter = null, ?Card $parent = null): array
    {
        $cards = [];
        foreach ($columns as $column) {
            $cards = [...$cards, ...$this->findColumn($column, $type, $reporter, $parent)];
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
    private function findColumn(BoardColumn $column, ?CardType $type, ?CardReporter $reporter, ?Card $parent): array
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
