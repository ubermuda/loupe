<?php

declare(strict_types=1);

namespace App\Module\GitHub;

use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\ForgeDelivery;
use App\Module\Forge\ForgeEventType;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use Symfony\Component\HttpFoundation\Request;

/**
 * One verified GitHub delivery. An App and a repository hook sign the body the
 * same way, so both routes read deliveries through here.
 */
final readonly class GitHubDelivery
{
    public const string FORGE = 'github';

    private const string SIGNATURE_HEADER = 'X-Hub-Signature-256';
    private const string EVENT_HEADER = 'X-GitHub-Event';
    private const array PULL_REQUEST_ACTIONS = ['opened', 'reopened', 'synchronize', 'closed', 'edited', 'ready_for_review', 'converted_to_draft'];

    /** @param array<mixed> $payload */
    private function __construct(
        public string $event,
        private array $payload,
    ) {
    }

    /**
     * An empty secret refuses every delivery. A webhook that trusts an empty
     * string would accept anything a stranger sends.
     *
     * @throws InvalidGitHubDelivery
     */
    public static function fromRequest(Request $request, string $secret): self
    {
        if ('' === $secret) {
            throw new InvalidGitHubDelivery(InvalidGitHubDelivery::NO_SECRET);
        }

        $body = $request->getContent();
        $expected = 'sha256='.hash_hmac('sha256', $body, $secret);
        if (!hash_equals($expected, $request->headers->get(self::SIGNATURE_HEADER, ''))) {
            throw new InvalidGitHubDelivery(InvalidGitHubDelivery::BAD_SIGNATURE);
        }

        $payload = json_decode($body, true);
        if (!\is_array($payload)) {
            throw new InvalidGitHubDelivery(InvalidGitHubDelivery::BAD_PAYLOAD);
        }

        return new self($request->headers->get(self::EVENT_HEADER, ''), $payload);
    }

    public function action(): ?string
    {
        $action = $this->payload['action'] ?? null;

        return \is_string($action) ? $action : null;
    }

    public function installationId(): ?int
    {
        $id = $this->payload['installation']['id'] ?? null;

        return \is_int($id) ? $id : null;
    }

    /** The top-level selection of `installation_repositories`, or the installation's own on `installation`. */
    public function repositorySelection(): ?GitHubRepositorySelection
    {
        $selection = $this->payload['repository_selection'] ?? $this->payload['installation']['repository_selection'] ?? null;

        return \is_string($selection) ? GitHubRepositorySelection::tryFrom($selection) : null;
    }

    public function repository(): ?GitHubRepositoryRef
    {
        return $this->refOf($this->payload['repository'] ?? null);
    }

    /** @return list<GitHubRepositoryRef> the well-formed entries of a list such as `repositories_added` */
    public function repositories(string $key): array
    {
        $list = $this->payload[$key] ?? null;
        if (!\is_array($list)) {
            return [];
        }

        $refs = [];
        foreach ($list as $entry) {
            $ref = $this->refOf($entry);
            if (null !== $ref) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    /**
     * The pull request facts the lifecycle uses. A repository move is absent,
     * because the ownership record detects it by id.
     *
     * @return list<ForgeDelivery>
     */
    public function forgeDeliveries(): array
    {
        $repository = $this->repository();
        if (null === $repository) {
            return [];
        }

        return match ($this->event) {
            'pull_request' => $this->fromPullRequest($repository),
            'pull_request_review' => $this->fromReview($repository),
            'check_suite' => $this->fromCheckSuite($repository),
            default => [],
        };
    }

    /**
     * The pull requests whose state this delivery may have changed. A merge
     * also moves its base, so every other pull request on it may fall behind.
     *
     * @return list<PullRequestRefreshHint>
     */
    public function refreshHints(): array
    {
        $action = $this->action();
        $hints = match ($this->event) {
            'pull_request' => \in_array($action, self::PULL_REQUEST_ACTIONS, true) ? $this->pullRequestHints() : [],
            'pull_request_review' => \in_array($action, ['submitted', 'dismissed'], true) ? $this->numberHints([$this->payload['pull_request'] ?? null], 'submitted' === $action ? $this->verdict() : null, $this->reviewId()) : [],
            // A check that starts again turns a passed verdict back to pending, so its start is a hint too.
            'check_suite', 'check_run' => 'requested_action' !== $action ? $this->checkHints($this->payload[$this->event] ?? null) : [],
            'status' => $this->headHints($this->payload['sha'] ?? null),
            'push' => $this->pushHints(),
            default => [],
        };

        return PullRequestRefreshHint::unique($hints);
    }

    /** @return list<PullRequestRefreshHint> */
    private function pullRequestHints(): array
    {
        $pullRequest = $this->payload['pull_request'] ?? null;
        $hints = $this->numberHints([$pullRequest]);
        if (!\is_array($pullRequest) || 'closed' !== $this->action() || true !== ($pullRequest['merged'] ?? null)) {
            return $hints;
        }

        $base = \is_array($pullRequest['base'] ?? null) ? ($pullRequest['base']['ref'] ?? null) : null;
        if ([] !== $hints && \is_string($base) && '' !== $base) {
            $hints[] = PullRequestRefreshHint::base($base);
        }

        return $hints;
    }

    /** @return list<PullRequestRefreshHint> */
    private function checkHints(mixed $check): array
    {
        if (!\is_array($check)) {
            return [];
        }

        $pullRequests = $check['pull_requests'] ?? null;

        return [...$this->numberHints(\is_array($pullRequests) ? $pullRequests : []), ...$this->headHints($check['head_sha'] ?? null)];
    }

    /** @return list<PullRequestRefreshHint> */
    private function pushHints(): array
    {
        $ref = $this->payload['ref'] ?? null;
        if (true === ($this->payload['deleted'] ?? null) || !\is_string($ref) || !str_starts_with($ref, 'refs/heads/')) {
            return [];
        }

        $branch = substr($ref, \strlen('refs/heads/'));

        return '' === $branch ? [] : [PullRequestRefreshHint::base($branch)];
    }

    /**
     * @param array<mixed> $pullRequests
     *
     * @return list<PullRequestRefreshHint>
     */
    private function numberHints(array $pullRequests, ?PullRequestReview $verdict = null, ?string $reviewId = null): array
    {
        $hints = [];
        foreach ($pullRequests as $pullRequest) {
            $number = \is_array($pullRequest) ? ($pullRequest['number'] ?? null) : null;
            if (\is_int($number) && $number > 0) {
                $hints[] = PullRequestRefreshHint::number($number, $verdict, $reviewId);
            }
        }

        return $hints;
    }

    private function reviewId(): ?string
    {
        $review = $this->payload['review'] ?? null;
        $id = \is_array($review) ? ($review['id'] ?? null) : null;

        return \is_int($id) && $id > 0 ? (string) $id : null;
    }

    /** A comment review has no verdict, and must not reset the fix rounds. */
    private function verdict(): ?PullRequestReview
    {
        $review = $this->payload['review'] ?? null;
        $state = \is_array($review) ? ($review['state'] ?? null) : null;

        return match (\is_string($state) ? strtolower($state) : null) {
            'approved' => PullRequestReview::Approved,
            'changes_requested' => PullRequestReview::ChangesRequested,
            default => null,
        };
    }

    /** @return list<PullRequestRefreshHint> */
    private function headHints(mixed $sha): array
    {
        return \is_string($sha) && '' !== $sha ? [PullRequestRefreshHint::head($sha)] : [];
    }

    /**
     * A merge has no event of its own. It arrives here as `closed` with the
     * merged flag set, and every other action is noise the lifecycle ignores.
     *
     * @return list<ForgeDelivery>
     */
    private function fromPullRequest(GitHubRepositoryRef $repository): array
    {
        $pullRequest = $this->payload['pull_request'] ?? null;
        if ('closed' !== $this->action() || !\is_array($pullRequest) || true !== ($pullRequest['merged'] ?? null)) {
            return [];
        }

        return $this->one(ForgeEventType::MERGED, $repository, $pullRequest);
    }

    /** @return list<ForgeDelivery> */
    private function fromReview(GitHubRepositoryRef $repository): array
    {
        $pullRequest = $this->payload['pull_request'] ?? null;
        if ('submitted' !== $this->action() || !\is_array($pullRequest)) {
            return [];
        }

        return $this->one(ForgeEventType::REVIEW_SUBMITTED, $repository, $pullRequest);
    }

    /**
     * A check suite is the aggregate of the run. A check run fires per check,
     * and this repository gates on thirteen of them.
     *
     * @return list<ForgeDelivery>
     */
    private function fromCheckSuite(GitHubRepositoryRef $repository): array
    {
        $suite = $this->payload['check_suite'] ?? null;
        if ('completed' !== $this->action() || !\is_array($suite)) {
            return [];
        }

        $pullRequests = $suite['pull_requests'] ?? [];
        if (!\is_array($pullRequests)) {
            return [];
        }

        $deliveries = [];
        foreach ($pullRequests as $pullRequest) {
            if (\is_array($pullRequest)) {
                $deliveries = [...$deliveries, ...$this->one(ForgeEventType::CHECKS_CONCLUDED, $repository, $pullRequest)];
            }
        }

        return $deliveries;
    }

    /**
     * @param ForgeEventType::* $type
     * @param array<mixed>      $pullRequest
     *
     * @return list<ForgeDelivery>
     */
    private function one(string $type, GitHubRepositoryRef $repository, array $pullRequest): array
    {
        $number = $pullRequest['number'] ?? null;
        if (!\is_int($number) || $number <= 0) {
            return [];
        }

        return [new ForgeDelivery($type, self::FORGE, $repository->fullName, $number)];
    }

    private function refOf(mixed $repository): ?GitHubRepositoryRef
    {
        if (!\is_array($repository)) {
            return null;
        }

        $id = $repository['id'] ?? null;
        $fullName = $repository['full_name'] ?? null;
        if (!\is_int($id) || !\is_string($fullName) || '' === $fullName) {
            return null;
        }

        return new GitHubRepositoryRef($id, $fullName);
    }
}
