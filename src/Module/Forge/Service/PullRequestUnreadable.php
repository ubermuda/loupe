<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

final class PullRequestUnreadable extends \RuntimeException
{
    /** @param string $reason a short slug for the log, such as `no_installation` */
    public function __construct(
        public readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('The pull request state cannot be read: '.$reason, 0, $previous);
    }
}
