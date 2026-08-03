<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * This application's full-text search policy: which language documents are
 * stemmed as, and how much a title outweighs a body.
 *
 * Kept here rather than pushed into a shared bundle, and kept at all rather than
 * dissolved into the martin-georgiev/postgresql-for-doctrine functions that now
 * emit the SQL. Both for the same reason: these are product decisions, not
 * Doctrine plumbing. The library supplies `to_tsvector`, `setweight` and `@@`;
 * it has no opinion about English or about titles mattering more, and neither
 * would a reusable bundle.
 *
 * Keeping them is also what stops the policy from splitting in two. The search
 * configuration is needed in a DQL call site (the repository, through the
 * library's functions) *and* in raw SQL (DocumentSearchIndexer's UPDATE, which
 * no DQL function can reach because it is not DQL). Delete this class and
 * 'english' becomes a string literal in both places, free to drift.
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
