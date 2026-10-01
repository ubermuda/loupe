<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

final class PullRequestWriteFailed extends \RuntimeException
{
    /**
     * @param string $cause             a short slug for the log, such as `permission`
     * @param bool   $permanent         true when the same write cannot succeed later without a change on the forge
     * @param ?int   $retryAfterSeconds how long the forge asks the caller to wait before a retry, when it says
     */
    public function __construct(
        public readonly string $cause,
        public readonly bool $permanent,
        ?\Throwable $previous = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct('The pull request state cannot be changed: '.$cause, 0, $previous);
    }
}
