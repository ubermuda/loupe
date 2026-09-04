<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * This application's full-text search policy: how much a title outweighs a body.
 *
 * Kept here rather than pushed into a shared bundle, and kept at all rather than
 * dissolved into the martin-georgiev/postgresql-for-doctrine functions that now
 * emit the SQL. Both for the same reason: these are product decisions, not
 * Doctrine plumbing. The library supplies `to_tsvector`, `setweight` and `@@`;
 * it has no opinion about titles mattering more, and neither would a reusable
 * bundle.
 *
 * The weights are needed in a DQL call site (the repository, through the
 * library's functions) *and* in raw SQL (DocumentSearchIndexer's UPDATE, which
 * no DQL function can reach because it is not DQL). Delete this class and 'A'
 * and 'B' become string literals in both places, free to drift.
 *
 * The stemming language is no longer here. It is a per-document column, so both
 * call sites read it off the row. {@see SearchLanguage}.
 */
final class FullTextSearch
{
    /** Title matches outrank body matches; ts_rank reads the labels off the vector. */
    public const string TITLE_WEIGHT = 'A';

    public const string BODY_WEIGHT = 'B';
}
