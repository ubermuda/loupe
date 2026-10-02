<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestBaseChanger;
use App\Module\Forge\Service\PullRequestWriteFailed;

/** Records each base change for the `fake` forge, and throws the failure it holds. */
final class FakePullRequestBaseChanger implements PullRequestBaseChanger
{
    /** @var list<array{ForgePullRequest, string}> */
    public array $changes = [];

    /** Runs inside each change, such as a read that sees the new base while the forge answers. */
    public ?\Closure $duringChange = null;

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
    public function changeBase(ForgePullRequest $pullRequest, string $base): void
    {
        $this->changes[] = [$pullRequest, $base];
        if (null !== $this->duringChange) {
            ($this->duringChange)();
        }
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
