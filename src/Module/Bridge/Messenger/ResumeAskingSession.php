<?php

declare(strict_types=1);

namespace App\Module\Bridge\Messenger;

/** An ask of the session closed, so its bridge resumes the run that asked. */
final readonly class ResumeAskingSession
{
    public function __construct(
        public string $projectId,
        public string $bridgeId,
        public string $sessionId,
    ) {
    }
}
