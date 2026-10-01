<?php

declare(strict_types=1);

namespace App\Tests\Module\Board;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestStateWriter;
use App\Module\Forge\Service\PullRequestWriteFailed;

final class FakePullRequestStateWriter implements PullRequestStateWriter
{
    /** @var list<array{0: string, 1: int, 2?: bool}> the action, the pull request number, and the draft target of a setDraft call, failed calls included */
    public array $calls = [];

    public ?PullRequestWriteFailed $failure = null;

    #[\Override]
    public function supports(string $forge): bool
    {
        return 'github' === $forge;
    }

    #[\Override]
    public function setDraft(ForgePullRequest $pullRequest, bool $draft): void
    {
        $this->calls[] = ['setDraft', $pullRequest->number, $draft];
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }

    #[\Override]
    public function close(ForgePullRequest $pullRequest): void
    {
        $this->calls[] = ['close', $pullRequest->number];
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
