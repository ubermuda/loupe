<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Query;

use Symfony\Component\Uid\Uuid;

final class BatchNotFound extends \DomainException
{
    public static function forId(Uuid $id): self
    {
        return new self(\sprintf('No site-review batch "%s" found.', $id));
    }
}
