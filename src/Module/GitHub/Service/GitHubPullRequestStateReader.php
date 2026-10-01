<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Forge\Service\PullRequestStateReader;
use App\Module\Forge\Service\PullRequestUnreadable;
use App\Module\GitHub\GitHubDelivery;
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
        query($owner:String!,$name:String!,$n:Int!,$after:String){repository(owner:$owner,name:$name){pullRequest(number:$n){state createdAt mergedAt isDraft headRefOid baseRefName mergeable mergeStateStatus reviewDecision baseRepository{defaultBranchRef{name}} latestOpinionatedReviews(first:100,writersOnly:true){nodes{id state submittedAt commit{oid}}} commits(last:1){nodes{commit{oid parents(first:2){nodes{oid}} statusCheckRollup{contexts(first:100,after:$after){pageInfo{hasNextPage endCursor} nodes{__typename ... on CheckRun{name status conclusion isRequired(pullRequestNumber:$n)} ... on StatusContext{context state isRequired(pullRequestNumber:$n)}}}}}}}}}}
        GRAPHQL;

    private const string RULES_TTL = '+5 minutes';

    private const int RULES_PER_PAGE = 100;

    private const int MAX_RULE_PAGES = 10;

    /** A head with more contexts than this reads its first 1,000 only. */
    private const int MAX_CONTEXT_PAGES = 10;

    /** @var array<string, array{rules: GitHubBranchRules, until: \DateTimeImmutable}> */
    private array $rules = [];

    public function __construct(
        private readonly GitHubAppApi $api,
        private readonly GitHubPullRequestInstallations $installations,
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
        try {
            [$installationId, $path] = $this->installations->for($pullRequest);
        } catch (GitHubInstallationUnavailable $e) {
            throw new PullRequestUnreadable($e->reason, $e);
        }
        [$owner, $name] = explode('/', $path, 2) + [1 => ''];

        $node = $this->pullRequestNode($installationId, $owner, $name, $pullRequest->number);

        $base = $node['baseRefName'] ?? null;
        $rules = \is_string($base) && '' !== $base ? $this->rules($installationId, $path, $base) : null;
        $behindBy = null;
        if (true === $rules?->strict && 'OPEN' === ($node['state'] ?? null) && 'CONFLICTING' !== ($node['mergeable'] ?? null) && \is_string($node['headRefOid'] ?? null)) {
            $behindBy = $this->behindBy($installationId, $path, (string) $base, $node['headRefOid']);
        }

        return $this->mapper->map($node, $rules, $behindBy);
    }

    /**
     * Reads the pull request, and every page of the check contexts of its head.
     *
     * @return array<mixed>
     *
     * @throws PullRequestUnreadable
     */
    private function pullRequestNode(int $installationId, string $owner, string $name, int $number): array
    {
        $node = null;
        $contexts = [];
        $after = null;
        for ($page = 1; $page <= self::MAX_CONTEXT_PAGES; ++$page) {
            try {
                $data = $this->api->graphql($installationId, self::QUERY, ['owner' => $owner, 'name' => $name, 'n' => $number, 'after' => $after]);
            } catch (GitHubAppApiFailed $e) {
                throw new PullRequestUnreadable('api_failed_'.$e->reason, $e, transient: \in_array($e->reason, ['transport', 'http_status', 'graphql_error'], true));
            }

            $pageNode = $data['repository']['pullRequest'] ?? null;
            if (!\is_array($pageNode)) {
                throw new PullRequestUnreadable('not_found');
            }
            $node ??= $pageNode;
            // A push between two pages would mix the checks of two commits.
            if (($pageNode['commits']['nodes'][0]['commit']['oid'] ?? null) !== ($node['commits']['nodes'][0]['commit']['oid'] ?? null)) {
                throw new PullRequestUnreadable('head_moved', transient: true);
            }

            $connection = $pageNode['commits']['nodes'][0]['commit']['statusCheckRollup']['contexts'] ?? null;
            $nodes = \is_array($connection) ? ($connection['nodes'] ?? null) : null;
            if (\is_array($nodes)) {
                $contexts = [...$contexts, ...array_values($nodes)];
            }

            $cursor = \is_array($connection) ? ($connection['pageInfo']['endCursor'] ?? null) : null;
            if (!\is_array($connection) || true !== ($connection['pageInfo']['hasNextPage'] ?? null) || !\is_string($cursor)) {
                break;
            }
            $after = $cursor;
        }

        if (\is_array($node['commits']['nodes'][0]['commit']['statusCheckRollup']['contexts'] ?? null)) {
            $node['commits']['nodes'][0]['commit']['statusCheckRollup']['contexts']['nodes'] = $contexts;
        }

        return $node;
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
            $all = [];
            for ($page = 1; $page <= self::MAX_RULE_PAGES; ++$page) {
                $items = $this->api->get($installationId, GitHubPullRequestInstallations::repositoryPath($path).'/rules/branches/'.rawurlencode($base), ['per_page' => self::RULES_PER_PAGE, 'page' => $page]);
                if (!array_is_list($items)) {
                    throw new \UnexpectedValueException('The branch rules are not a list.');
                }
                $all = [...$all, ...$items];
                if (\count($items) < self::RULES_PER_PAGE) {
                    break;
                }
            }
            $rules = GitHubBranchRules::fromRules($all);
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
            $compare = $this->api->get($installationId, GitHubPullRequestInstallations::repositoryPath($path).'/compare/'.rawurlencode($base).'...'.rawurlencode($headSha));
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
}
