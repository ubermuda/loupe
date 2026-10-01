<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\ApprovalCoverage;
use App\Module\Forge\Service\ApprovalCoverageReader;
use App\Module\GitHub\GitHubDelivery;
use Psr\Log\LoggerInterface;

/**
 * The approval covers the head when every commit since the covered head that
 * is not on the base is a merge that brings in a commit of the base.
 */
final readonly class GitHubApprovalCoverageReader implements ApprovalCoverageReader
{
    public function __construct(
        private GitHubAppApi $api,
        private GitHubPullRequestInstallations $installations,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return GitHubDelivery::FORGE === $forge;
    }

    #[\Override]
    public function read(ForgePullRequest $pullRequest): ApprovalCoverage
    {
        $base = $pullRequest->baseBranch;
        $head = $pullRequest->headSha;
        $covered = $pullRequest->coveredSha;
        if (null === $base || '' === $base || null === $head || '' === $head || null === $covered || '' === $covered) {
            return ApprovalCoverage::Unknown;
        }

        try {
            [$installationId, $path] = $this->installations->for($pullRequest);
            $sinceApproval = $this->compare($installationId, $path, $covered, $head);
            // A rebase or an amend leaves the covered head off the new history.
            if (\in_array($sinceApproval['status'], ['diverged', 'behind'], true) || !$sinceApproval['complete']) {
                return ApprovalCoverage::NotCovered;
            }
            if ('identical' === $sinceApproval['status'] || [] === $sinceApproval['commits']) {
                return ApprovalCoverage::Covered;
            }

            $againstBase = $this->compare($installationId, $path, $base, $head);
            if (!$againstBase['complete']) {
                return ApprovalCoverage::NotCovered;
            }
        } catch (GitHubInstallationUnavailable|GitHubAppApiFailed $e) {
            return $this->unknown($pullRequest, $e->reason);
        } catch (\UnexpectedValueException) {
            return $this->unknown($pullRequest, 'malformed_body');
        }

        $offBase = array_flip(array_column($againstBase['commits'], 'sha'));
        foreach ($sinceApproval['commits'] as $commit) {
            if (!isset($offBase[$commit['sha']])) {
                continue;
            }
            $fromBase = array_filter($commit['parents'], static fn (string $parent): bool => !isset($offBase[$parent]));
            if (\count($commit['parents']) < 2 || [] === $fromBase) {
                return ApprovalCoverage::NotCovered;
            }
        }

        return ApprovalCoverage::Covered;
    }

    /**
     * @return array{status: string, complete: bool, commits: list<array{sha: string, parents: list<string>}>}
     *
     * @throws GitHubAppApiFailed
     * @throws \UnexpectedValueException
     */
    private function compare(int $installationId, string $path, string $from, string $to): array
    {
        $body = $this->api->get($installationId, GitHubPullRequestInstallations::repositoryPath($path).'/compare/'.rawurlencode($from).'...'.rawurlencode($to));
        $status = $body['status'] ?? null;
        $total = $body['total_commits'] ?? null;
        $items = $body['commits'] ?? null;
        if (!\is_string($status) || !\is_int($total) || !\is_array($items) || !array_is_list($items)) {
            throw new \UnexpectedValueException('The compare answer has no status, total or commit list.');
        }

        $commits = [];
        foreach ($items as $item) {
            $sha = \is_array($item) ? ($item['sha'] ?? null) : null;
            $parents = \is_array($item) ? ($item['parents'] ?? null) : null;
            if (!\is_string($sha) || !\is_array($parents) || !array_is_list($parents)) {
                throw new \UnexpectedValueException('A compared commit has no sha or parent list.');
            }
            $parentShas = [];
            foreach ($parents as $parent) {
                $parentSha = \is_array($parent) ? ($parent['sha'] ?? null) : null;
                if (!\is_string($parentSha)) {
                    throw new \UnexpectedValueException('A parent of a compared commit has no sha.');
                }
                $parentShas[] = $parentSha;
            }
            $commits[] = ['sha' => $sha, 'parents' => $parentShas];
        }

        return ['status' => $status, 'complete' => \count($commits) >= $total, 'commits' => $commits];
    }

    private function unknown(ForgePullRequest $pullRequest, string $reason): ApprovalCoverage
    {
        $this->logger->warning('forge.approval_coverage_unreadable', [
            'pullRequestId' => (string) $pullRequest->id,
            'repository' => $pullRequest->repository,
            'reason' => $reason,
        ]);

        return ApprovalCoverage::Unknown;
    }
}
