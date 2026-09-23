<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

use Symfony\Component\Uid\Uuid;

/** Names one run a bridge holds. A run key is unique per project only, so the project is part of the name. */
final readonly class HeldRunKey
{
    public static function of(Uuid $projectId, Uuid $runKey): string
    {
        return $projectId->toRfc4122().':'.$runKey->toRfc4122();
    }
}
