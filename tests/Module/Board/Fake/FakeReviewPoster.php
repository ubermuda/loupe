<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Fake;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestReviewKind;
use App\Module\Forge\Service\PullRequestReviewPoster;

/** Records each review posted to a `github` pull request, and throws the failure it holds. */
final class FakeReviewPoster implements PullRequestReviewPoster
{
    /** @var list<array{number: int, kind: PullRequestReviewKind, body: string, userId: string}> */
    public array $posts = [];

    public ?\Throwable $failure = null;

    public bool $withoutUrl = false;

    #[\Override]
    public function supports(string $forge): bool
    {
        return 'github' === $forge;
    }

    #[\Override]
    public function post(ForgePullRequest $pullRequest, PullRequestReviewKind $kind, string $body, string $userId): ?string
    {
        $this->posts[] = ['number' => $pullRequest->number, 'kind' => $kind, 'body' => $body, 'userId' => $userId];
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->withoutUrl ? null : 'https://github.com/acme/widgets/pull/'.$pullRequest->number.'#pullrequestreview-1';
    }
}
