<?php

declare(strict_types=1);

namespace App\Module\Project\Repository;

use App\Module\Project\Entity\Project;

/**
 * A handle that is the slug of one project and the name of another. The slug
 * backfill can leave that pair, such as "My App" with `my-app` beside a project
 * named "my-app" with `my-app-2`, and neither reading is safe to pick silently.
 */
final class AmbiguousProjectHandleException extends \RuntimeException
{
    public function __construct(
        public readonly string $handle,
        public readonly Project $bySlug,
        public readonly Project $byName,
    ) {
        parent::__construct(\sprintf(
            '"%s" is the slug of project "%s" (id %s) and the name of project "%s" (id %s). Pass the project id or its unique slug instead.',
            $handle,
            $bySlug->name,
            (string) $bySlug->id,
            $byName->name,
            (string) $byName->id,
        ));
    }
}
