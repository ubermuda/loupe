<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * The Postgres text-search configuration a document is stemmed and searched
 * with.
 *
 * The cases are the built-in `pg_ts_config` names of PostgreSQL 16. The value
 * reaches SQL as a regconfig, which Postgres resolves by name at run time, so an
 * unknown name is a database error rather than an empty result. This enum is the
 * allowlist that keeps free text out of that position, and every entry point
 * converts through it.
 */
enum SearchLanguage: string
{
    case Arabic = 'arabic';
    case Armenian = 'armenian';
    case Basque = 'basque';
    case Catalan = 'catalan';
    case Danish = 'danish';
    case Dutch = 'dutch';
    case English = 'english';
    case Finnish = 'finnish';
    case French = 'french';
    case German = 'german';
    case Greek = 'greek';
    case Hindi = 'hindi';
    case Hungarian = 'hungarian';
    case Indonesian = 'indonesian';
    case Irish = 'irish';
    case Italian = 'italian';
    case Lithuanian = 'lithuanian';
    case Nepali = 'nepali';
    case Norwegian = 'norwegian';
    case Portuguese = 'portuguese';
    case Romanian = 'romanian';
    case Russian = 'russian';
    case Serbian = 'serbian';

    /** No stemming and no stop words. The right answer for text of mixed or unknown language. */
    case Simple = 'simple';

    case Spanish = 'spanish';
    case Swedish = 'swedish';
    case Tamil = 'tamil';
    case Turkish = 'turkish';
    case Yiddish = 'yiddish';

    /** What a document gets when nothing states a language, and what every row held before this column existed. */
    public const self DEFAULT = self::English;

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
