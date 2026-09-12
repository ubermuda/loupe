<?php

declare(strict_types=1);

namespace App\Module\Board\Repository;

use App\Doctrine\SearchLanguage;
use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
    public function searchOpenForProject(Project $project, string $query, int $limit): array
    {
        $qb = $this->createQueryBuilder('c')
            ->join('c.column', 'k')
            ->where('c.project = :project')
            ->andWhere('k.terminal = false')
            ->setParameter('project', $project)
            ->orderBy('c.number', 'DESC')
            ->setMaxResults($limit);

        if ('' !== $query) {
            // Escaped with the character the ESCAPE clause declares, not with a
            // backslash: a backslash is a literal here, so addcslashes() would
            // leave % and _ as wildcards and quietly widen the match. The
            // escape character itself goes first, or it doubles the others.
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query));
            $qb->andWhere('LOWER(c.title) LIKE :q ESCAPE \'!\'')
                ->setParameter('q', '%'.$escaped.'%');
        }

        /* @var list<Card> */
        return $qb->getQuery()->getResult();
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
     * The cards of one (column, priority) group, in board order.
     *
     * @return list<Card>
     */
    public function findGroup(BoardColumn $column, CardPriority $priority): array
    {
        return $this->findBy(
            ['column' => $column, 'priority' => $priority],
            ['position' => 'ASC', 'createdAt' => 'ASC'],
        );
    }

    /**
     * Reads onto the card the two fields that say which group it is in.
     *
     * A board write locks the project row, and lock() leaves a card loaded
     * before that lock exactly as the request read it. EntityManager::refresh()
     * cannot stand in here, because it rehydrates every column and Doctrine
     * refuses to rewrite the readonly ones a Card carries. A card the database
     * no longer holds is left alone, which is what the flush already does with
     * it.
     */
    public function refreshGroup(Card $card): void
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT column_id, priority FROM board_cards WHERE id = :id',
            ['id' => (string) $card->id],
        );

        if (false === $row) {
            return;
        }

        $column = $this->getEntityManager()->find(BoardColumn::class, Uuid::fromString((string) $row['column_id']));
        if (null !== $column) {
            $card->column = $column;
        }
        $card->priority = CardPriority::from((int) $row['priority']);
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
     * @return list<Card>
     */
    public function findCompletedSince(BoardColumn $column, \DateTimeImmutable $since): array
    {
        return array_values(
            $this->withPullRequests($this->completedQuery($column))
                ->andWhere('c.completedAt >= :since')
                ->setParameter('since', $since)
                ->getQuery()
                ->getResult(),
        );
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

    /** The rank a card appended to the end of that group takes. */
    public function nextPosition(BoardColumn $column, CardPriority $priority): int
    {
        $highest = $this->createQueryBuilder('c')
            ->select('MAX(c.position)')
            ->andWhere('c.column = :column')
            ->andWhere('c.priority = :priority')
            ->setParameter('column', $column)
            ->setParameter('priority', $priority)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $highest ? 0 : ((int) $highest) + 1;
    }

    /**
     * The board's read query, one column at a time.
     *
     * A column is read on its own even when the caller asks for the whole
     * board, because a terminal column sorts by completion while every other
     * column sorts by priority then position. One query with both orderings in
     * it would have to rank finished rows by a priority they no longer use. The
     * cost is one query per column for an unfiltered read, each on the
     * composite index.
     *
     * @param list<BoardColumn> $columns in board order, which is the order the cards come back in
     *
     * @return list<Card>
     */
    public function findForBoard(array $columns, ?CardType $type = null, ?CardPriority $priority = null, ?CardReporter $reporter = null): array
    {
        $cards = [];
        foreach ($columns as $column) {
            $cards = [...$cards, ...$this->findColumn($column, $type, $priority, $reporter)];
        }

        return $cards;
    }

    /**
     * How many cards each project still has open, for the projects list.
     *
     * Open is every column that is not terminal, so a board whose work is
     * finished counts zero rather than counting its history.
     *
     * @param list<Project> $projects
     *
     * @return array<string, int> project id => count, projects with none omitted
     */
    public function countOpenByProjects(array $projects): array
    {
        if ([] === $projects) {
            return [];
        }

        /** @var list<array{id: mixed, total: mixed}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.project) AS id, COUNT(c.id) AS total')
            ->join('c.column', 'k')
            ->andWhere('c.project IN (:projects)')
            ->andWhere('k.terminal = false')
            ->setParameter('projects', $projects)
            ->groupBy('c.project')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Every card on every project the user owns, for the account data export.
     *
     * The pull request links and the column are fetch-joined, because the
     * export reads them on every row and they are lazy otherwise.
     *
     * @return list<Card>
     */
    public function findByOwner(User $user): array
    {
        return array_values($this->createQueryBuilder('c')
            ->join('c.project', 'p')
            ->join('c.column', 'k')
            ->addSelect('k')
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
    private function findColumn(BoardColumn $column, ?CardType $type, ?CardPriority $priority, ?CardReporter $reporter): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.column = :column')
            ->setParameter('column', $column);

        if (null !== $type) {
            $qb->andWhere('c.type = :type')->setParameter('type', $type);
        }
        if (null !== $priority) {
            $qb->andWhere('c.priority = :priority')->setParameter('priority', $priority);
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
            $qb->orderBy('c.priority', 'ASC')
                ->addOrderBy('c.position', 'ASC')
                ->addOrderBy('c.createdAt', 'ASC')
                ->addOrderBy('c.id', 'ASC');
        }

        return array_values($this->withPullRequests($qb)->getQuery()->getResult());
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
