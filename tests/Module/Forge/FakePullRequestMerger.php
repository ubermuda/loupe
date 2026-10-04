<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestMerger;
use App\Module\Forge\Service\PullRequestWriteFailed;

/** Records each merge for the `fake` forge, and throws the failure it holds. */
final class FakePullRequestMerger implements PullRequestMerger
{
    /** @var list<array{ForgePullRequest, string, string}> */
    public array $merges = [];

    /** Runs inside each merge, such as a read that moves the head while the forge answers. */
    public ?\Closure $duringMerge = null;

    public function __construct(
        public ?PullRequestWriteFailed $failure = null,
    ) {
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return FakePullRequestStateReader::FORGE === $forge;
    }

    #[\Override]
    public function merge(ForgePullRequest $pullRequest, string $method, string $expectedHeadSha): void
    {
        $this->merges[] = [$pullRequest, $method, $expectedHeadSha];
        if (null !== $this->duringMerge) {
            ($this->duringMerge)();
        }
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
