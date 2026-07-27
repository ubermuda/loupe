<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use Symfony\Component\Uid\Uuid;

/**
 * Thrown when a widget operation targets a comment that is not an editable
 * Draft of this project (unknown id, another project, or already sent). The
 * API controllers map this to a 404.
 */
final class CommentNotFound extends \DomainException
{
    public static function forId(Uuid $id): self
    {
        return new self(\sprintf('No editable site-review comment "%s" found.', $id));
    }
}
