<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

final class PullRequestCommentFailed extends \RuntimeException
{
    /**
     * @param string $cause     a short slug for the log, such as `permission`
     * @param bool   $permanent true when the same comment cannot succeed later without a change on the forge
     */
    public function __construct(
        public readonly string $cause,
        public readonly bool $permanent,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('The pull request comment cannot be posted: '.$cause, 0, $previous);
    }
}
