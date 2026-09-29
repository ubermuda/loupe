<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

/** No live installation of the App delivers the repository of a pull request to its project. */
final class GitHubInstallationUnavailable extends \RuntimeException
{
    /** @param 'no_installation'|'installation_suspended' $reason */
    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct($reason);
    }
}
