<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

final class PullRequestCheckFailed extends \RuntimeException
{
    /**
     * @param string $cause             a short slug for the log, such as `permission`
     * @param bool   $permanent         true when the same check cannot succeed later without a change on the forge
     * @param ?int   $retryAfterSeconds how long the forge asks the caller to wait before a retry, when it says
     * @param ?int   $runId             the run that exists on the forge despite the failure, to pass as the run id of the retry
     * @param int    $annotationsSent   how many of the given annotations that run took before the failure
     */
    public function __construct(
        public readonly string $cause,
        public readonly bool $permanent,
        ?\Throwable $previous = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?int $runId = null,
        public readonly int $annotationsSent = 0,
    ) {
        parent::__construct('The pull request check cannot be posted: '.$cause, 0, $previous);
    }
}
