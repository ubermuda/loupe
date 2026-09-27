<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Service\PullRequestStateReader;
use App\Module\Forge\Service\PullRequestUnreadable;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads a pull request as the App installation that delivers its repository
 * to the project. The branch rules stay in this object's memory for a few
 * minutes, because every pull request of one base shares them.
 */
final class GitHubPullRequestStateReader implements PullRequestStateReader
{
    private const string QUERY = <<<'GRAPHQL'
        query($owner:String!,$name:String!,$n:Int!){repository(owner:$owner,name:$name){pullRequest(number:$n){state isDraft headRefOid baseRefName mergeable mergeStateStatus reviewDecision commits(last:1){nodes{commit{oid statusCheckRollup{contexts(first:100){nodes{__typename ... on CheckRun{name status conclusion isRequired(pullRequestNumber:$n)} ... on StatusContext{context state isRequired(pullRequestNumber:$n)}}}}}}}}}}
        GRAPHQL;

    private const string RULES_TTL = '+5 minutes';

    /** @var array<string, array{rules: GitHubBranchRules, until: \DateTimeImmutable}> */
    private array $rules = [];

    public function __construct(
        private readonly GitHubAppApi $api,
        private readonly ForgeRepositoryRepository $forgeRepositories,
        private readonly GitHubInstallationRepository $gitHubInstallations,
        private readonly GitHubPullRequestStateMapper $mapper,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function supports(string $forge): bool
    {
        return GitHubDelivery::FORGE === $forge;
    }

    #[\Override]
    public function read(ForgePullRequest $pullRequest): PullRequestSnapshot
    {
        [$installationId, $path] = $this->installationFor($pullRequest);
        [$owner, $name] = explode('/', $path, 2) + [1 => ''];

        try {
            $data = $this->api->graphql($installationId, self::QUERY, ['owner' => $owner, 'name' => $name, 'n' => $pullRequest->number]);
        } catch (GitHubAppApiFailed $e) {
            throw new PullRequestUnreadable('api_failed_'.$e->reason, $e);
        }

        $node = $data['repository']['pullRequest'] ?? null;
        if (!\is_array($node)) {
            throw new PullRequestUnreadable('not_found');
        }

        $base = $node['baseRefName'] ?? null;
        $rules = \is_string($base) && '' !== $base ? $this->rules($installationId, $path, $base) : null;
        $behindBy = null;
        if (true === $rules?->strict && 'OPEN' === ($node['state'] ?? null) && 'CONFLICTING' !== ($node['mergeable'] ?? null) && \is_string($node['headRefOid'] ?? null)) {
            $behindBy = $this->behindBy($installationId, $path, (string) $base, $node['headRefOid']);
        }

        return $this->mapper->map($node, $rules, $behindBy);
    }

    /**
     * @return array{int, string} the installation id, and the repository path as the forge spells it
     *
     * @throws PullRequestUnreadable
     */
    private function installationFor(ForgePullRequest $pullRequest): array
    {
        $row = $this->forgeRepositories->findInstallationRowByPath($pullRequest->project, GitHubDelivery::FORGE, $pullRequest->repository);
        $sourceRef = $row?->sourceRef;
        if (null === $row || null === $sourceRef || !ctype_digit($sourceRef)) {
            throw new PullRequestUnreadable('no_installation');
        }

        $installation = $this->gitHubInstallations->findOneByInstallationId((int) $sourceRef);
        if (null === $installation || null !== $installation->removedAt || !$installation->project->id?->equals($pullRequest->project->id)) {
            throw new PullRequestUnreadable('no_installation');
        }
        if (null !== $installation->suspendedAt) {
            throw new PullRequestUnreadable('installation_suspended');
        }

        return [$installation->installationId, $row->path];
    }

    private function rules(int $installationId, string $path, string $base): ?GitHubBranchRules
    {
        $key = $installationId.':'.mb_strtolower($path).':'.$base;
        $now = $this->clock->now();
        $cached = $this->rules[$key] ?? null;
        if (null !== $cached && $now < $cached['until']) {
            return $cached['rules'];
        }

        try {
            $rules = GitHubBranchRules::fromRules($this->api->get($installationId, self::repositoryPath($path).'/rules/branches/'.rawurlencode($base)));
        } catch (GitHubAppApiFailed|\UnexpectedValueException $e) {
            $this->logger->warning('forge.ruleset_unreadable', [
                'installationId' => $installationId,
                'repository' => $path,
                'base' => $base,
                'reason' => $e instanceof GitHubAppApiFailed ? $e->reason : 'malformed_body',
            ]);

            return null;
        }

        $this->rules[$key] = ['rules' => $rules, 'until' => $now->modify(self::RULES_TTL)];

        return $rules;
    }

    private function behindBy(int $installationId, string $path, string $base, string $headSha): ?int
    {
        try {
            $compare = $this->api->get($installationId, self::repositoryPath($path).'/compare/'.rawurlencode($base).'...'.rawurlencode($headSha));
        } catch (GitHubAppApiFailed $e) {
            $this->logger->warning('forge.compare_unreadable', [
                'installationId' => $installationId,
                'repository' => $path,
                'base' => $base,
                'reason' => $e->reason,
            ]);

            return null;
        }

        $behindBy = $compare['behind_by'] ?? null;

        return \is_int($behindBy) ? $behindBy : null;
    }

    private static function repositoryPath(string $path): string
    {
        return '/repos/'.implode('/', array_map(rawurlencode(...), explode('/', $path, 2)));
    }
}
