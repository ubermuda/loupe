<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\ApprovalCoverage;
use App\Module\Forge\Service\ApprovalCoverageReader;

/** Answers the next coverage in line for the `fake` forge, and counts its reads. */
final class FakeApprovalCoverageReader implements ApprovalCoverageReader
{
    public int $reads = 0;

    /** @param list<ApprovalCoverage> $answers */
    public function __construct(
        public array $answers = [],
    ) {
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return FakePullRequestStateReader::FORGE === $forge;
    }

    #[\Override]
    public function read(ForgePullRequest $pullRequest): ApprovalCoverage
    {
        ++$this->reads;

        return array_shift($this->answers) ?? ApprovalCoverage::Unknown;
    }
}
