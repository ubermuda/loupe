<?php

declare(strict_types=1);

namespace App\Module\Review\Query;

use Symfony\Component\Uid\Uuid;

final class DocumentNotFound extends \DomainException
{
    public static function forId(Uuid $id): self
    {
        return new self(\sprintf('Document "%s" not found or not accessible.', $id));
    }
}
