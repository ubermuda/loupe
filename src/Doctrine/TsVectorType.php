<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Maps Postgres' `tsvector` so a search column can be declared on an entity and
 * round-trip through the schema comparator.
 *
 * Values pass through as strings in both directions. Nothing in PHP builds a
 * tsvector — only Postgres can — so the mapped property is read-only decoration
 * that lets DQL name the column.
 */
final class TsVectorType extends Type
{
    public const string NAME = 'tsvector';

    /** @param array<string, mixed> $column */
    #[\Override]
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'TSVECTOR';
    }

    /**
     * Claims the introspected `tsvector` column type. Without it the comparator
     * reverse-engineers the column as something else and every migrate-diff
     * proposes an ALTER that has already been applied.
     *
     * @return list<string>
     */
    #[\Override]
    public function getMappedDatabaseTypes(AbstractPlatform $platform): array
    {
        return [self::NAME];
    }
}
