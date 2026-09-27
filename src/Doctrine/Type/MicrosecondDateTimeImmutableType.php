<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;

/**
 * Writes a timestamp with its microseconds. The stock type writes whole seconds.
 * The column needs `columnDefinition: 'TIMESTAMP(6) …'`, because the schema
 * comparator reads a timestamp column back with no precision.
 */
final class MicrosecondDateTimeImmutableType extends DateTimeImmutableType
{
    public const string NAME = 'datetime_immutable_us';

    #[\Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return $value instanceof \DateTimeImmutable ? $value->format('Y-m-d H:i:s.u') : parent::convertToDatabaseValue($value, $platform);
    }
}
