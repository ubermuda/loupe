<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

/** One action that a worker suggests for a stopped run, with its one-line reason. */
final readonly class SuggestedAction
{
    /**
     * @param array{entry: string, branch: string, params: array<string, string>}|null $command the entry a run-command suggestion names
     */
    public function __construct(
        public string $action,
        public string $reason,
        public bool $recommended = false,
        public ?array $command = null,
        public ?string $proposedEntry = null,
        /** The instruction a start-session suggestion starts the session with. */
        public ?string $prompt = null,
    ) {
    }
}
