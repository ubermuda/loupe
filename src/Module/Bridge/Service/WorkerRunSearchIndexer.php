<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Doctrine\FullTextSearch;
use App\Doctrine\SearchLanguage;
use App\Module\Bridge\Entity\WorkerRun;
use Doctrine\DBAL\Connection;

/**
 * Builds a run's search vector from its card number, its rule name and its
 * output.
 *
 * The configuration is fixed at `simple`, which neither stems nor drops stop
 * words. Worker output is log text of unknown and often mixed language, and a
 * constant configuration is also what lets Postgres use the GIN index: a
 * per-row configuration turns the match into a scan of every run.
 */
final readonly class WorkerRunSearchIndexer
{
    public const SearchLanguage LANGUAGE = SearchLanguage::Simple;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function index(WorkerRun $run): void
    {
        $this->connection->executeStatement(
            \sprintf(
                <<<'SQL'
                    UPDATE bridge_worker_runs
                    SET search_vector = setweight(to_tsvector('%1$s', card_number::text), '%2$s')
                        || setweight(to_tsvector('%1$s', rule_name), '%2$s')
                        || setweight(to_tsvector('%1$s', output), '%3$s')
                    WHERE id = :id
                    SQL,
                self::LANGUAGE->value,
                FullTextSearch::TITLE_WEIGHT,
                FullTextSearch::BODY_WEIGHT,
            ),
            ['id' => (string) $run->id],
        );
    }
}
