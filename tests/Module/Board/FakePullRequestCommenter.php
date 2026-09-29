<?php

declare(strict_types=1);

namespace App\Tests\Module\Board;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestCommenter;
use App\Module\Forge\Service\PullRequestCommentFailed;

final class FakePullRequestCommenter implements PullRequestCommenter
{
    /** @var list<array{ForgePullRequest, string}> */
    public array $comments = [];

    public ?PullRequestCommentFailed $failure = null;

    #[\Override]
    public function supports(string $forge): bool
    {
        return 'github' === $forge;
    }

    #[\Override]
    public function comment(ForgePullRequest $pullRequest, string $body): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        $this->comments[] = [$pullRequest, $body];
    }
}
