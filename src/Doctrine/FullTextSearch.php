<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * Shared constants for Postgres full-text search.
 */
final class FullTextSearch
{
    /**
     * The text-search configuration every stored vector is built with and every
     * query is parsed against. The two must be the same one: a vector stemmed as
     * English and a query parsed as `simple` never meet, so changing this means
     * rebuilding every vector in the same change.
     */
    public const string CONFIGURATION = 'english';

    /** Title matches outrank body matches; ts_rank reads the labels off the vector. */
    public const string TITLE_WEIGHT = 'A';

    public const string BODY_WEIGHT = 'B';
}
