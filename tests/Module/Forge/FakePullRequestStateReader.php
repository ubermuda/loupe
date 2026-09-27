<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Forge\Service\PullRequestStateReader;
use App\Module\Forge\Service\PullRequestUnreadable;

/** Answers the next snapshot in line for the `fake` forge, and counts its reads. */
final class FakePullRequestStateReader implements PullRequestStateReader
{
    public const string FORGE = 'fake';

    public int $reads = 0;

    /** @param list<PullRequestSnapshot|PullRequestUnreadable> $answers */
    public function __construct(
        public array $answers = [],
    ) {
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return self::FORGE === $forge;
    }

    #[\Override]
    public function read(ForgePullRequest $pullRequest): PullRequestSnapshot
    {
        ++$this->reads;
        $answer = array_shift($this->answers) ?? new PullRequestSnapshot();
        if ($answer instanceof PullRequestUnreadable) {
            throw $answer;
        }

        return $answer;
    }
}
