<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Asks one forge whether the commits after the covered head of a pull request only bring in its base. A forge module implements it. */
#[AutoconfigureTag('app.approval_coverage_reader')]
interface ApprovalCoverageReader
{
    /** @param string $forge the forge's slug, such as `github` */
    public function supports(string $forge): bool;

    /** Judges whether `coveredSha` still covers `headSha`. */
    public function read(ForgePullRequest $pullRequest): ApprovalCoverage;
}
