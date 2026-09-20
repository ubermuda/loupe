<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Project\Entity\Project;

/** A client that holds a live grant for the user, with what each grant allows. */
final readonly class ConnectedApp
{
    /** @param list<array{scope: ApiTokenScope, project: ?Project}> $grants */
    public function __construct(
        public string $clientId,
        public string $clientName,
        public array $grants,
    ) {
    }
}
